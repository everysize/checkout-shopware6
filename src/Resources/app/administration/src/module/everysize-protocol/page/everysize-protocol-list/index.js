import template from './everysize-protocol-list.html.twig';
import './everysize-protocol-list.scss';

const { Component, Mixin, Filter } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('everysize-protocol-list', {
    template,

    inject: [
        'repositoryFactory'
    ],

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('listing')
    ],

    data() {
        return {
            protocolRows: null,
            isLoading: true
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        dateFilter() {
            return Filter.getByName('date');
        },

        columns() {
            return this.getColumns();
        },

        repository() {
            return this.getRepository();
        },
    },

    methods: {
        getColumns() {
            return [{
                property: 'createdAt',
                dataIndex: 'createdAt',
                label: this.$t('everysize-protocol.list.column-created-at'),
                allowResize: true
            }, {
                property: 'title',
                dataIndex: 'title',
                label: this.$t('everysize-protocol.list.column-title'),
                allowResize: true
            }, {
                property: 'state',
                dataIndex: 'state',
                label: this.$t('everysize-protocol.list.column-state'),
                allowResize: true
            }, {
                property: 'details',
                dataIndex: 'details',
                label: this.$t('everysize-protocol.list.column-details'),
                allowResize: true
            }];
        },

        getRepository() {
            return this.repositoryFactory.create('everysize_checkout_protocol');
        },

        getList() {
            this.isLoading = true;

            const criteria = new Criteria();
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
            criteria.setTerm(this.term);

            this.repository
                .search(criteria, Shopware.Context.api)
                .then((result) => {
                    this.protocolRows = result;
                    this.isLoading = false;
                });
        },
    },

    created() {
    }
});
