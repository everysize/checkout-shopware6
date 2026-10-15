const { Component } = Shopware;
const { Criteria } = Shopware.Data;
import template  from './sw-order-detail-general.html.twig';

Component.override('sw-order-detail-general', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            everysizeOriginalEmail: null,
        };
    },

    watch: {
        'order.id': {
            immediate: true,
            handler(orderId) {
                this.loadEverysizeOriginalEmail(orderId);
            },
        },
    },

    methods: {
        // The customer's own address lives at the everysize order
        // (everysize_checkout_order.original_email), never in
        // order.customFields: the administration saves those back as a whole,
        // and flows and exports would see the address.
        loadEverysizeOriginalEmail(orderId) {
            this.everysizeOriginalEmail = null;
            if (!orderId || !this.order?.customFields?.everysize_id) {
                return;
            }

            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('orderId', orderId));

            this.repositoryFactory.create('everysize_checkout_order')
                .search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.everysizeOriginalEmail = result.first()?.originalEmail ?? null;
                })
                .catch(() => {
                    // Users without read access to the entity just do not see it.
                    this.everysizeOriginalEmail = null;
                });
        },
    },
});
