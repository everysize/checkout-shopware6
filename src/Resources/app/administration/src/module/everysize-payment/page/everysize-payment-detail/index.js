import template from './everysize-payment-detail.html.twig';
import './everysize-payment-detail.scss';

const { Component, Filter, Context, Utils } = Shopware;
const Criteria = Shopware.Data.Criteria;

/**
 * Tab "everysize" of an order: the everysize order, the refund of the PayPal
 * capture and what the plugin reported to everysize.
 *
 * Refunds go through everysize (External API), which refunds the PayPal
 * capture on the merchant's behalf — the same way as every other everysize
 * plugin. Paid, refunded and open amount and the refunds come from the
 * plugin's API (EverysizeRefundApiController), which reads them from
 * everysize; refunds made in PayPal directly show up as well.
 */
Component.register('everysize-payment-detail', {
    template,

    inject: [
        'EverysizeApiClient',
        'repositoryFactory',
        'acl',
    ],

    mixins: ['notification'],

    data() {
        return {
            isLoading: true,
            order: null,
            orderTransaction: null,
            everysizeOrder: null,
            reports: [],
            refundSummary: null,
            refundError: '',
            refundAmount: 0,
            // One key per refund the user starts: the same key again (double
            // click, timeout and retry) refunds only once at everysize.
            idempotencyKey: Utils.createId(),
            showRefundConfirm: false,
            isRefunding: false,
        };
    },

    computed: {
        captureId() {
            return this.orderTransaction?.customFields?.everysize_paypal_capture_id ?? '';
        },

        currency() {
            return this.refundSummary?.currency ?? this.order?.currency?.isoCode ?? 'EUR';
        },

        paidAmount() {
            return Number(this.refundSummary?.paid ?? 0);
        },

        refundedAmount() {
            return Number(this.refundSummary?.refunded ?? 0);
        },

        openAmount() {
            return Number(this.refundSummary?.open ?? 0);
        },

        canRefund() {
            return this.acl.can('order.editor') && this.openAmount > 0;
        },

        isRefundAmountValid() {
            const amount = Number(this.refundAmount);

            return amount > 0 && amount <= this.openAmount;
        },

        refundColumns() {
            return [
                { property: 'created', label: this.$t('everysize-order-tab.refund.columns.date'), rawData: true },
                { property: 'amount', label: this.$t('everysize-order-tab.refund.columns.amount'), rawData: true },
                { property: 'status', label: this.$t('everysize-order-tab.refund.columns.status'), rawData: true },
                { property: 'refundId', label: this.$t('everysize-order-tab.refund.columns.refundId'), rawData: true },
            ];
        },

        refundRows() {
            return [...(this.refundSummary?.refunds ?? [])]
                .sort((a, b) => new Date(a.created) - new Date(b.created))
                .map((refund, index) => ({
                    id: refund.refundId ?? `${index}`,
                    created: this.formatDateTime(refund.created),
                    amount: this.formatMoney(Number(refund.amount ?? 0), refund.currency),
                    status: refund.status ?? '–',
                    refundId: refund.refundId ?? '–',
                }));
        },

        reportColumns() {
            return [
                { property: 'time', label: this.$t('everysize-order-tab.reports.columns.time'), rawData: true },
                { property: 'kind', label: this.$t('everysize-order-tab.reports.columns.kind'), rawData: true },
                { property: 'item', label: this.$t('everysize-order-tab.reports.columns.item'), rawData: true },
                { property: 'reason', label: this.$t('everysize-order-tab.reports.columns.reason'), rawData: true },
                { property: 'result', label: this.$t('everysize-order-tab.reports.columns.result'), rawData: true },
            ];
        },

        reportRows() {
            return this.reports.map((report, index) => ({
                id: `${index}`,
                time: this.formatDateTime(report.time),
                kind: this.$t(`everysize-order-tab.reports.kinds.${report.kind}`),
                item: report.item || '–',
                reason: report.reason || '–',
                result: this.$t(`everysize-order-tab.reports.results.${report.result}`),
            }));
        },
    },

    watch: {
        '$route'() {
            this.createdComponent();
        },

        isLoading(value) {
            this.$emit('loading-change', value);
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.isLoading = true;
            this.$emit('loading-change', true);

            this.loadAll().finally(() => {
                this.isLoading = false;
            });
        },

        loadAll() {
            const orderId = this.$route.params.id;

            return Promise.all([
                this.loadOrder(orderId),
                this.loadEverysizeOrder(orderId),
            ]).then(() => this.loadRefunds());
        },

        loadOrder(orderId) {
            const criteria = new Criteria(1, 1);
            criteria.addAssociation('currency');
            criteria.addAssociation('transactions.stateMachineState');
            criteria.getAssociation('transactions')
                .addSorting(Criteria.sort('createdAt', 'DESC'))
                .setLimit(1);

            return this.repositoryFactory.create('order').get(orderId, Context.api, criteria).then((order) => {
                this.order = order;
                this.orderTransaction = order?.transactions?.first() ?? null;
                this.$emit('identifier-change', order?.orderNumber ?? '');
            });
        },

        loadEverysizeOrder(orderId) {
            return this.EverysizeApiClient.getOrder(orderId).then((data) => {
                this.everysizeOrder = data.everysizeOrder ?? null;
                this.reports = data.reports ?? [];
            }).catch((error) => {
                this.everysizeOrder = null;
                this.reports = [];
                this.notifyError(error);
            });
        },

        loadRefunds() {
            this.refundSummary = null;
            this.refundError = '';

            if (!this.captureId) {
                return Promise.resolve();
            }

            return this.EverysizeApiClient.getRefunds(this.$route.params.id)
                .then((summary) => {
                    this.setRefundSummary(summary);
                })
                .catch((error) => {
                    this.refundError = this.errorText(error);
                });
        },

        setRefundSummary(summary) {
            this.refundSummary = summary;
            this.refundAmount = this.openAmount;
        },

        refund() {
            this.showRefundConfirm = false;
            if (!this.isRefundAmountValid) {
                return;
            }

            this.isRefunding = true;
            this.EverysizeApiClient.refund(
                this.$route.params.id,
                this.round(Number(this.refundAmount)).toFixed(2),
                this.idempotencyKey,
            ).then((result) => {
                this.idempotencyKey = Utils.createId();

                if (result?.refund?.status === 'ALREADY_REFUNDED') {
                    this.createNotificationInfo({
                        message: this.$t('everysize-order-tab.refund.alreadyRefunded'),
                    });
                } else {
                    this.createNotificationSuccess({
                        message: this.$t('everysize-order-tab.refund.success'),
                    });
                }

                // Payment state changed: read the order and the refunds again.
                return this.loadOrder(this.$route.params.id).then(() => this.loadRefunds());
            }).catch((error) => {
                // everysize answered (amount, PayPal): a new attempt is a new
                // refund. Without an answer the refund may have happened, so a
                // retry keeps the key and cannot refund twice.
                if (error?.response?.data?.errors?.[0]?.code) {
                    this.idempotencyKey = Utils.createId();
                }
                this.notifyError(error);
            }).finally(() => {
                this.isRefunding = false;
            });
        },

        notifyError(error) {
            this.createNotificationError({
                message: this.errorText(error),
                autoClose: false,
            });
        },

        errorText(error) {
            const apiError = error?.response?.data?.errors?.[0];
            if (apiError) {
                const key = `everysize-order-tab.refund.errors.${apiError.code}`;
                const known = apiError.code && this.$te(key) ? this.$t(key) : '';

                return [known, apiError.title, apiError.detail].filter(Boolean).join(' – ');
            }

            return error?.message ?? String(error);
        },

        formatMoney(value, currency = null) {
            return Filter.getByName('currency')(value, currency ?? this.currency);
        },

        // The date filter shows times in the user's time zone, like the
        // rest of the administration.
        formatDateTime(value) {
            if (!value) {
                return '–';
            }

            return Filter.getByName('date')(value, {
                hour: '2-digit',
                minute: '2-digit',
            });
        },

        round(value) {
            return Math.round(value * 100) / 100;
        },
    },
});
