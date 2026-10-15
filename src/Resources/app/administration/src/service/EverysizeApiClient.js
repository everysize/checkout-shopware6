const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class EverysizeApiClient extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'everysize') {
        super(httpClient, loginService, apiEndpoint);
    }

    /**
     * @param {Object} values
     */
    checkApi(values) {
        return this.httpClient.post(
            `${this.getApiBasePath('', '_action')}/checkApi`,
            values,
            this.getDefaultOptions()
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * The everysize order and the reports to everysize (tab "everysize").
     *
     * @param {String} orderId
     */
    getOrder(orderId) {
        return this.httpClient.get(
            `${this.getApiBasePath('', '_action')}/order/${orderId}`,
            this.getDefaultOptions(),
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Paid, refunded and open amount and the refunds of the order's PayPal
     * capture, read from everysize (refunds made in PayPal directly included).
     *
     * @param {String} orderId
     */
    getRefunds(orderId) {
        return this.httpClient.get(
            `${this.getApiBasePath('', '_action')}/order/${orderId}/refunds`,
            this.getDefaultOptions(),
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    /**
     * Refunds through everysize. The same idempotency key again refunds only
     * once (double click, timeout and retry).
     *
     * @param {String} orderId
     * @param {String} amount "10.00"
     * @param {String} idempotencyKey
     */
    refund(orderId, amount, idempotencyKey) {
        return this.httpClient.post(
            `${this.getApiBasePath('', '_action')}/order/${orderId}/refunds`,
            { amount, idempotencyKey },
            this.getDefaultOptions(),
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    getDefaultOptions() {
        return {
            headers: this.getBasicHeaders(),
            version: Shopware.Context.api.apiVersion,
        };
    }
}

Application.addServiceProvider('EverysizeApiClient', (container) => {
    const initContainer = Application.getContainer('init');
    return new EverysizeApiClient(
        initContainer.httpClient,
        container.loginService
    );
});
