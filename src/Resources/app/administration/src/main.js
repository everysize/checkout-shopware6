const { Component } = Shopware;
import './component/entity/everysize-order-state-select';
import './component/entity/everysize-payment-state-select';
import './component/entity/everysize-delivery-state-select';
import './extension/sw-order-detail';
import './extension/sw-order-detail-general';
import './service/EverysizeApiClient';
import './component/everysize-api-test-button';

// Snippets (snippet/*.json, module/*/snippet/*.json) are loaded by Shopware
// itself (SnippetFinder: <locale>.json below Resources/app/administration/src,
// 6.6 and 6.7) — no imports into the bundle.

import './module/everysize-protocol';
import './module/everysize-payment';

// The column shows customFields.everysize_id through the grid's default
// rendering; a template override is not needed (its Vue 2 `slot=` syntax did
// nothing in Vue 3).
Component.override('sw-order-list', {
    computed: {
        orderColumns() {
            const columns = this.getOrderColumns();
            columns.push({
                property: 'customFields.everysize_id',
                dataIndex: 'customFields.everysize_id',
                label: 'everysize',
                inlineEdit: 'string',
                allowResize: true,
                align: 'left',
            });

            return columns;
        },
    },
});
