import './page/everysize-payment-detail';

const { Module } = Shopware;

Module.register('everysize-payment', {
    type: 'plugin',
    name: 'Everysize',
    title: 'everysize-payment.general.title',
    description: 'everysize-payment.general.descriptionTextModule',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#2b52ff',

    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            currentRoute.children.push({
                component: 'everysize-payment-detail',
                name: 'everysize.payment.detail',
                isChildren: true,
                path: '/sw/order/detail/:id/everysize',
                meta: {
                    parentPath: 'sw.order.index',
                    privilege: 'order.viewer',
                },
            });
        }
        next(currentRoute);
    },
});
