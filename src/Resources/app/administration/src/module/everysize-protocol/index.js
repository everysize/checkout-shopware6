import './page/everysize-protocol-list';

const { Module } = Shopware;

Module.register('everysize-protocol', {
    type: 'plugin',
    name: 'everysize-protocol.general.name',
    title: 'everysize-protocol.general.menu-item-name',
    description: 'everysize-protocol.general.menu-item-description',
    color: '#57D9A3',
    icon: 'default-symbol-content',

    routes: {
        list: {
            component: 'everysize-protocol-list',
            path: 'list'
        }
    },

    navigation: [{
        id: 'everysize-protocol',
        label: 'everysize-protocol.general.menu-item-name',
        color: '#57D9A3',
        position: 195,
        path: 'everysize.protocol.list',
        icon: 'default-symbol-content',
        parent: 'sw-order'
    }]
});
