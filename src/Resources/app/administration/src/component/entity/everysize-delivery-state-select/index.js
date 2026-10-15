const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.extend('everysize-delivery-state-select', 'sw-entity-single-select', {
    props: {
        criteria: {
            type: Object,
            required: false,
            // Vue 3 passes the props to a default factory; `this` is not
            // available there in Shopware 6.7 (6.6 only had it via vue/compat).
            default(props) {
                const criteria = new Criteria(1, props?.resultLimit ?? 25);
                criteria.addFilter(
                    Criteria.equals(
                        'stateMachine.technicalName',
                        'order_delivery.state'
                    )
                );
                return criteria;
            },
        },
    },
});
