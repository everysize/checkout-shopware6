import template from './sw-order-detail.html.twig';

const { Component, Context } = Shopware;
const { hasOwnProperty } = Shopware.Utils.object;
const Criteria = Shopware.Data.Criteria;

Component.override('sw-order-detail', {
    template,

    data() {
        return {
            isEverysizePayment: false,
        };
    },

    computed: {
        isEditableEverySizeOrder() {
            return !this.isEverysizePayment || this.$route.name !== 'everysize.payment.detail';
        },
    },

    watch: {
        orderId: {
            deep: true,
            handler() {
                if (!this.orderId) {
                    return;
                }

                // The tab shows for every everysize order, with or without a
                // PayPal capture (order number, reports).
                const orderRepository = this.repositoryFactory.create('order');
                const orderCriteria = new Criteria(1, 1);
                orderCriteria.addAssociation('transactions');
                orderCriteria
                    .getAssociation('transactions')
                    .addSorting(Criteria.sort('createdAt', 'DESC'))
                    .setLimit(1);

                orderRepository.get(this.orderId, Context.api, orderCriteria).then((order) => {
                    const transaction = order?.transactions?.last();
                    const hasCapture = !!transaction?.customFields &&
                        hasOwnProperty(transaction.customFields, 'everysize_paypal_capture_id');

                    this.isEverysizePayment = hasCapture || !!order?.customFields?.everysize_id;
                });
            },
            immediate: true,
        },
    },
});
