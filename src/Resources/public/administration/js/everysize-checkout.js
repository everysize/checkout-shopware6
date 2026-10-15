(()=>{var{Component:C}=Shopware,{Criteria:d}=Shopware.Data;C.extend("everysize-order-state-select","sw-entity-single-select",{props:{criteria:{type:Object,required:!1,default(e){let t=new d(1,e?.resultLimit??25);return t.addFilter(d.equals("stateMachine.technicalName","order.state")),t}}}});var{Component:S}=Shopware,{Criteria:p}=Shopware.Data;S.extend("everysize-payment-state-select","sw-entity-single-select",{props:{criteria:{type:Object,required:!1,default(e){let t=new p(1,e?.resultLimit??25);return t.addFilter(p.equals("stateMachine.technicalName","order_transaction.state")),t}}}});var{Component:A}=Shopware,{Criteria:c}=Shopware.Data;A.extend("everysize-delivery-state-select","sw-entity-single-select",{props:{criteria:{type:Object,required:!1,default(e){let t=new c(1,e?.resultLimit??25);return t.addFilter(c.equals("stateMachine.technicalName","order_delivery.state")),t}}}});var m=`{% block sw_order_detail_content_tabs_general %}
    {% parent %}

    <sw-tabs-item v-if="isEverysizePayment"
                  :route="{ name: 'everysize.payment.detail', params: { id: $route.params.id } }"
                  :title="$t('everysize-order-tab.tabTitle')">
        {{ $t('everysize-order-tab.tabTitle') }}
    </sw-tabs-item>
{% endblock %}


{% block sw_order_detail_actions_slot_smart_bar_actions %}
    <template v-if="isEditableEverySizeOrder">
        {% parent %}
    </template>
{% endblock %}
`;var{Component:x,Context:R}=Shopware,{hasOwnProperty:D}=Shopware.Utils.object,u=Shopware.Data.Criteria;x.override("sw-order-detail",{template:m,data(){return{isEverysizePayment:!1}},computed:{isEditableEverySizeOrder(){return!this.isEverysizePayment||this.$route.name!=="everysize.payment.detail"}},watch:{orderId:{deep:!0,handler(){if(!this.orderId)return;let e=this.repositoryFactory.create("order"),t=new u(1,1);t.addAssociation("transactions"),t.getAssociation("transactions").addSorting(u.sort("createdAt","DESC")).setLimit(1),e.get(this.orderId,R.api,t).then(r=>{let i=r?.transactions?.last(),s=!!i?.customFields&&D(i.customFields,"everysize_paypal_capture_id");this.isEverysizePayment=s||!!r?.customFields?.everysize_id})},immediate:!0}}});var y=`{% block sw_order_detail_general_info_card %}
    <sw-alert variant="warning" class="sw-card" v-if="order.customFields.everysize_id">
        <p>{{ $t('everysize-admin-order-details-warning.title') }}</p>
        <span v-html="$t('everysize-admin-order-details-warning.message', { ezOrderId: order.customFields.everysize_id })"></span>
        <p v-if="everysizeOriginalEmail">
            {{ $t('everysize-admin-order-details-warning.originalEmail') }}:
            <strong>{{ everysizeOriginalEmail }}</strong>
        </p>
    </sw-alert>

    {% parent %}
{% endblock %}
`;var{Component:k}=Shopware,{Criteria:h}=Shopware.Data;k.override("sw-order-detail-general",{template:y,inject:["repositoryFactory"],data(){return{everysizeOriginalEmail:null}},watch:{"order.id":{immediate:!0,handler(e){this.loadEverysizeOriginalEmail(e)}}},methods:{loadEverysizeOriginalEmail(e){if(this.everysizeOriginalEmail=null,!e||!this.order?.customFields?.everysize_id)return;let t=new h(1,1);t.addFilter(h.equals("orderId",e)),this.repositoryFactory.create("everysize_checkout_order").search(t,Shopware.Context.api).then(r=>{this.everysizeOriginalEmail=r.first()?.originalEmail??null}).catch(()=>{this.everysizeOriginalEmail=null})}}});var a=Shopware.Classes.ApiService,{Application:f}=Shopware,o=class extends a{constructor(t,r,i="everysize"){super(t,r,i)}checkApi(t){return this.httpClient.post(`${this.getApiBasePath("","_action")}/checkApi`,t,this.getDefaultOptions()).then(r=>a.handleResponse(r))}getOrder(t){return this.httpClient.get(`${this.getApiBasePath("","_action")}/order/${t}`,this.getDefaultOptions()).then(r=>a.handleResponse(r))}getRefunds(t){return this.httpClient.get(`${this.getApiBasePath("","_action")}/order/${t}/refunds`,this.getDefaultOptions()).then(r=>a.handleResponse(r))}refund(t,r,i){return this.httpClient.post(`${this.getApiBasePath("","_action")}/order/${t}/refunds`,{amount:r,idempotencyKey:i},this.getDefaultOptions()).then(s=>a.handleResponse(s))}getDefaultOptions(){return{headers:this.getBasicHeaders(),version:Shopware.Context.api.apiVersion}}};f.addServiceProvider("EverysizeApiClient",e=>{let t=f.getContainer("init");return new o(t.httpClient,e.loginService)});var v=`<div>
    <sw-button-process
        :isLoading="isLoading"
        :processSuccess="isSaveSuccessful"
        @process-finish="saveFinish"
        @click="checkApi"
    >API Test</sw-button-process>
</div>
`;var{Component:F,Mixin:N}=Shopware;F.register("everysize-api-test-button",{template:v,inject:["EverysizeApiClient"],mixins:[N.getByName("notification")],data(){return{isLoading:!1,isSaveSuccessful:!1,label:this.$t("everysize-api-test-button.title")}},methods:{saveFinish(){this.isSaveSuccessful=!1},getCredentials(){let e=this.$parent;for(;e&&e.actualConfigData===void 0;)e=e.$parent;let t="EverysizeCheckout.config.",r=e?.actualConfigData?.[e.currentSalesChannelId]??{},i=e?.actualConfigData?.null??{},s=l=>r[t+l]||i[t+l]||"";return{clientId:s("clientId"),clientSecret:s("clientSecret")}},checkApi(){this.isLoading=!0,this.EverysizeApiClient.checkApi(this.getCredentials()).then(e=>{e.success?(this.isSaveSuccessful=!0,this.createNotificationSuccess({title:this.$t("everysize-api-test-button.title"),message:this.$t("everysize-api-test-button.success")})):this.createNotificationError({title:this.$t("everysize-api-test-button.title"),message:this.$t("everysize-api-test-button.error")}),this.isLoading=!1}).catch(e=>{try{this.createNotificationError({message:`${e.response.data.errors[0].title}: ${e.response.data.errors[0].detail}`,autoClose:!1})}catch{this.createNotificationError({message:`${e.title}: ${e.message}`,autoClose:!1})}finally{this.isLoading=!1}})}}});var z=`<sw-page class="everysize-protocol-list">
    <template #content>
        <sw-entity-listing
            v-if="protocolRows"
            :items="protocolRows"
            :repository="repository"
            :columns="columns"
            :isLoading="isLoading"
            :showActions="true"
            :showSelection="false"
            :showSettings="false"
            :allowEdit="false"
            :allowInlineEdit="false"
            :allowDelete="false"
        >
            <template #column-createdAt="{ item }">
                {{ dateFilter(item.createdAt, { hour: '2-digit', minute: '2-digit' }) }} Uhr
            </template>
            <template #column-title="{ item }">
                {{ item.title }}
            </template>
            <template #column-state="{ item }">
                <template v-if="item.state">
                    {{ $t('everysize-protocol.list.state-success') }}
                </template>
                <template v-else>
                    {{ $t('everysize-protocol.list.state-fail') }}
                </template>
            </template>
            <template #column-details="{ item }">
                <template v-if="item.message">
                    <sw-label variant="danger" size="default" appearance="default" :ghost="true" :caps="false" :dismissable="false">
                        {% verbatim %}
                        <pre>{{ item.message }}</pre>
                        {% endverbatim %}
                    </sw-label>
                </template>
            </template>
        </sw-entity-listing>

        <sw-loader v-if="isLoading"></sw-loader>
    </template>
</sw-page>
`;var{Component:T,Mixin:g,Filter:M}=Shopware,{Criteria:w}=Shopware.Data;T.register("everysize-protocol-list",{template:z,inject:["repositoryFactory"],mixins:[g.getByName("notification"),g.getByName("listing")],data(){return{protocolRows:null,isLoading:!0}},metaInfo(){return{title:this.$createTitle()}},computed:{dateFilter(){return M.getByName("date")},columns(){return this.getColumns()},repository(){return this.getRepository()}},methods:{getColumns(){return[{property:"createdAt",dataIndex:"createdAt",label:this.$t("everysize-protocol.list.column-created-at"),allowResize:!0},{property:"title",dataIndex:"title",label:this.$t("everysize-protocol.list.column-title"),allowResize:!0},{property:"state",dataIndex:"state",label:this.$t("everysize-protocol.list.column-state"),allowResize:!0},{property:"details",dataIndex:"details",label:this.$t("everysize-protocol.list.column-details"),allowResize:!0}]},getRepository(){return this.repositoryFactory.create("everysize_checkout_protocol")},getList(){this.isLoading=!0;let e=new w;e.addSorting(w.sort("createdAt","DESC")),e.setTerm(this.term),this.repository.search(e,Shopware.Context.api).then(t=>{this.protocolRows=t,this.isLoading=!1})}},created(){}});var{Module:P}=Shopware;P.register("everysize-protocol",{type:"plugin",name:"everysize-protocol.general.name",title:"everysize-protocol.general.menu-item-name",description:"everysize-protocol.general.menu-item-description",color:"#57D9A3",icon:"default-symbol-content",routes:{list:{component:"everysize-protocol-list",path:"list"}},navigation:[{id:"everysize-protocol",label:"everysize-protocol.general.menu-item-name",color:"#57D9A3",position:195,path:"everysize.protocol.list",icon:"default-symbol-content",parent:"sw-order"}]});var b=`{% block everysize_payment_detail %}
    <div class="everysize-payment-detail">
        <sw-loader v-if="isLoading"></sw-loader>

        <template v-else>
            {% block everysize_payment_detail_order %}
                <sw-card position-identifier="everysize-order-tab-order"
                         :title="$t('everysize-order-tab.order.title')">
                    <p v-if="!everysizeOrder" class="everysize-payment-detail__empty">
                        {{ $t('everysize-order-tab.order.notFound') }}
                    </p>
                    <table v-else class="everysize-payment-detail__info">
                        <tbody>
                            <tr>
                                <th>{{ $t('everysize-order-tab.order.everysizeOrderId') }}</th>
                                <td><strong>{{ everysizeOrder.everysizeOrderId }}</strong></td>
                            </tr>
                            <tr>
                                <th>{{ $t('everysize-order-tab.order.importedAt') }}</th>
                                <td>{{ formatDateTime(everysizeOrder.importedAt) }}</td>
                            </tr>
                            <tr>
                                <th>{{ $t('everysize-order-tab.order.orderNumberReportedAt') }}</th>
                                <td>{{ formatDateTime(everysizeOrder.orderNumberReportedAt) }}</td>
                            </tr>
                            <tr>
                                <th>{{ $t('everysize-order-tab.order.captureId') }}</th>
                                <td>{{ captureId || '\u2013' }}</td>
                            </tr>
                            <tr>
                                <th>{{ $t('everysize-order-tab.order.originalEmail') }}</th>
                                <td>
                                    {{ everysizeOrder.originalEmail || '\u2013' }}
                                    <span class="everysize-payment-detail__hint">
                                        {{ $t('everysize-order-tab.order.originalEmailHint') }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </sw-card>
            {% endblock %}

            {% block everysize_payment_detail_refund %}
                <sw-card v-if="captureId"
                         position-identifier="everysize-order-tab-refund"
                         :title="$t('everysize-order-tab.refund.title')">
                    <sw-alert v-if="refundError" variant="warning" class="everysize-payment-detail__message">
                        {{ $t('everysize-order-tab.refund.loadError') }} {{ refundError }}
                    </sw-alert>

                    <template v-else>
                        <div class="everysize-payment-detail__totals">
                            <div class="everysize-payment-detail__total">
                                <span>{{ $t('everysize-order-tab.refund.paid') }}</span>
                                <strong>{{ formatMoney(paidAmount) }}</strong>
                            </div>
                            <div class="everysize-payment-detail__total">
                                <span>{{ $t('everysize-order-tab.refund.refunded') }}</span>
                                <strong>{{ formatMoney(refundedAmount) }}</strong>
                            </div>
                            <div class="everysize-payment-detail__total"
                                 :class="{ 'is--open': openAmount > 0 }">
                                <span>{{ $t('everysize-order-tab.refund.open') }}</span>
                                <strong>{{ formatMoney(openAmount) }}</strong>
                            </div>
                        </div>

                        <sw-data-grid v-if="refundRows.length"
                                      class="everysize-payment-detail__grid"
                                      :dataSource="refundRows"
                                      :columns="refundColumns"
                                      :showSelection="false"
                                      :showActions="false"
                                      :plainAppearance="true">
                            <template #column-refundId="{ item }">
                                <span class="everysize-payment-detail__mono">{{ item.refundId }}</span>
                            </template>
                        </sw-data-grid>

                        <div v-if="canRefund" class="everysize-payment-detail__form">
                            <sw-number-field v-model:value="refundAmount"
                                             numberType="float"
                                             :digits="2"
                                             :min="0"
                                             :max="openAmount"
                                             :label="$t('everysize-order-tab.refund.amount')">
                                <template #suffix>{{ currency }}</template>
                            </sw-number-field>
                            <sw-button variant="primary"
                                       :disabled="!isRefundAmountValid || isRefunding"
                                       :isLoading="isRefunding"
                                       @click="showRefundConfirm = true">
                                {{ $t('everysize-order-tab.refund.button') }}
                            </sw-button>
                        </div>
                    </template>

                    <sw-alert variant="info" class="everysize-payment-detail__message">
                        {{ $t('everysize-order-tab.refund.notice') }}
                    </sw-alert>
                </sw-card>
            {% endblock %}

            {% block everysize_payment_detail_reports %}
                <sw-card position-identifier="everysize-order-tab-reports"
                         :title="$t('everysize-order-tab.reports.title')">
                    <p v-if="!reportRows.length" class="everysize-payment-detail__empty">
                        {{ $t('everysize-order-tab.reports.none') }}
                    </p>
                    <sw-data-grid v-else
                                  class="everysize-payment-detail__grid"
                                  :dataSource="reportRows"
                                  :columns="reportColumns"
                                  :showSelection="false"
                                  :showActions="false"
                                  :plainAppearance="true">
                    </sw-data-grid>
                </sw-card>
            {% endblock %}
        </template>

        <sw-modal v-if="showRefundConfirm"
                  variant="small"
                  :title="$t('everysize-order-tab.refund.confirmTitle')"
                  @modal-close="showRefundConfirm = false">
            <p>{{ $t('everysize-order-tab.refund.confirmText', { amount: formatMoney(refundAmount) }) }}</p>

            <template #modal-footer>
                <sw-button size="small" @click="showRefundConfirm = false">
                    {{ $t('global.default.cancel') }}
                </sw-button>
                <sw-button variant="primary" size="small" @click="refund">
                    {{ $t('everysize-order-tab.refund.button') }}
                </sw-button>
            </template>
        </sw-modal>
    </div>
{% endblock %}
`;var{Component:j,Filter:_,Context:q,Utils:n}=Shopware,$=Shopware.Data.Criteria;j.register("everysize-payment-detail",{template:b,inject:["EverysizeApiClient","repositoryFactory","acl"],mixins:["notification"],data(){return{isLoading:!0,order:null,orderTransaction:null,everysizeOrder:null,reports:[],refundSummary:null,refundError:"",refundAmount:0,idempotencyKey:n.createId(),showRefundConfirm:!1,isRefunding:!1}},computed:{captureId(){return this.orderTransaction?.customFields?.everysize_paypal_capture_id??""},currency(){return this.refundSummary?.currency??this.order?.currency?.isoCode??"EUR"},paidAmount(){return Number(this.refundSummary?.paid??0)},refundedAmount(){return Number(this.refundSummary?.refunded??0)},openAmount(){return Number(this.refundSummary?.open??0)},canRefund(){return this.acl.can("order.editor")&&this.openAmount>0},isRefundAmountValid(){let e=Number(this.refundAmount);return e>0&&e<=this.openAmount},refundColumns(){return[{property:"created",label:this.$t("everysize-order-tab.refund.columns.date"),rawData:!0},{property:"amount",label:this.$t("everysize-order-tab.refund.columns.amount"),rawData:!0},{property:"status",label:this.$t("everysize-order-tab.refund.columns.status"),rawData:!0},{property:"refundId",label:this.$t("everysize-order-tab.refund.columns.refundId"),rawData:!0}]},refundRows(){return[...this.refundSummary?.refunds??[]].sort((e,t)=>new Date(e.created)-new Date(t.created)).map((e,t)=>({id:e.refundId??`${t}`,created:this.formatDateTime(e.created),amount:this.formatMoney(Number(e.amount??0),e.currency),status:e.status??"\u2013",refundId:e.refundId??"\u2013"}))},reportColumns(){return[{property:"time",label:this.$t("everysize-order-tab.reports.columns.time"),rawData:!0},{property:"kind",label:this.$t("everysize-order-tab.reports.columns.kind"),rawData:!0},{property:"item",label:this.$t("everysize-order-tab.reports.columns.item"),rawData:!0},{property:"reason",label:this.$t("everysize-order-tab.reports.columns.reason"),rawData:!0},{property:"result",label:this.$t("everysize-order-tab.reports.columns.result"),rawData:!0}]},reportRows(){return this.reports.map((e,t)=>({id:`${t}`,time:this.formatDateTime(e.time),kind:this.$t(`everysize-order-tab.reports.kinds.${e.kind}`),item:e.item||"\u2013",reason:e.reason||"\u2013",result:this.$t(`everysize-order-tab.reports.results.${e.result}`)}))}},watch:{$route(){this.createdComponent()},isLoading(e){this.$emit("loading-change",e)}},created(){this.createdComponent()},methods:{createdComponent(){this.isLoading=!0,this.$emit("loading-change",!0),this.loadAll().finally(()=>{this.isLoading=!1})},loadAll(){let e=this.$route.params.id;return Promise.all([this.loadOrder(e),this.loadEverysizeOrder(e)]).then(()=>this.loadRefunds())},loadOrder(e){let t=new $(1,1);return t.addAssociation("currency"),t.addAssociation("transactions.stateMachineState"),t.getAssociation("transactions").addSorting($.sort("createdAt","DESC")).setLimit(1),this.repositoryFactory.create("order").get(e,q.api,t).then(r=>{this.order=r,this.orderTransaction=r?.transactions?.first()??null,this.$emit("identifier-change",r?.orderNumber??"")})},loadEverysizeOrder(e){return this.EverysizeApiClient.getOrder(e).then(t=>{this.everysizeOrder=t.everysizeOrder??null,this.reports=t.reports??[]}).catch(t=>{this.everysizeOrder=null,this.reports=[],this.notifyError(t)})},loadRefunds(){return this.refundSummary=null,this.refundError="",this.captureId?this.EverysizeApiClient.getRefunds(this.$route.params.id).then(e=>{this.setRefundSummary(e)}).catch(e=>{this.refundError=this.errorText(e)}):Promise.resolve()},setRefundSummary(e){this.refundSummary=e,this.refundAmount=this.openAmount},refund(){this.showRefundConfirm=!1,this.isRefundAmountValid&&(this.isRefunding=!0,this.EverysizeApiClient.refund(this.$route.params.id,this.round(Number(this.refundAmount)).toFixed(2),this.idempotencyKey).then(e=>(this.idempotencyKey=n.createId(),e?.refund?.status==="ALREADY_REFUNDED"?this.createNotificationInfo({message:this.$t("everysize-order-tab.refund.alreadyRefunded")}):this.createNotificationSuccess({message:this.$t("everysize-order-tab.refund.success")}),this.loadOrder(this.$route.params.id).then(()=>this.loadRefunds()))).catch(e=>{e?.response?.data?.errors?.[0]?.code&&(this.idempotencyKey=n.createId()),this.notifyError(e)}).finally(()=>{this.isRefunding=!1}))},notifyError(e){this.createNotificationError({message:this.errorText(e),autoClose:!1})},errorText(e){let t=e?.response?.data?.errors?.[0];if(t){let r=`everysize-order-tab.refund.errors.${t.code}`;return[t.code&&this.$te(r)?this.$t(r):"",t.title,t.detail].filter(Boolean).join(" \u2013 ")}return e?.message??String(e)},formatMoney(e,t=null){return _.getByName("currency")(e,t??this.currency)},formatDateTime(e){return e?_.getByName("date")(e,{hour:"2-digit",minute:"2-digit"}):"\u2013"},round(e){return Math.round(e*100)/100}}});var{Module:G}=Shopware;G.register("everysize-payment",{type:"plugin",name:"Everysize",title:"everysize-payment.general.title",description:"everysize-payment.general.descriptionTextModule",version:"1.0.0",targetVersion:"1.0.0",color:"#2b52ff",routeMiddleware(e,t){t.name==="sw.order.detail"&&t.children.push({component:"everysize-payment-detail",name:"everysize.payment.detail",isChildren:!0,path:"/sw/order/detail/:id/everysize",meta:{parentPath:"sw.order.index",privilege:"order.viewer"}}),e(t)}});var{Component:V}=Shopware;V.override("sw-order-list",{computed:{orderColumns(){let e=this.getOrderColumns();return e.push({property:"customFields.everysize_id",dataIndex:"customFields.everysize_id",label:"everysize",inlineEdit:"string",allowResize:!0,align:"left"}),e}}});})();
