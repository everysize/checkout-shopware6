const { Component, Mixin } = Shopware;
import template from './everysize-api-test-button.html.twig';

Component.register('everysize-api-test-button', {
    template,
    inject: ['EverysizeApiClient'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            label: this.$t('everysize-api-test-button.title'),
        };
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        // The values as typed, from the config page (sw-system-config) — the
        // fields' DOM ids are generated in Shopware 6.7, getElementById found
        // nothing there. Unsaved input counts; a sales channel without own
        // access data inherits those of "All sales channels".
        getCredentials() {
            let systemConfig = this.$parent;
            while (systemConfig && systemConfig.actualConfigData === undefined) {
                systemConfig = systemConfig.$parent;
            }

            const prefix = 'EverysizeCheckout.config.';
            const channel = systemConfig?.actualConfigData?.[systemConfig.currentSalesChannelId] ?? {};
            const inherited = systemConfig?.actualConfigData?.null ?? {};
            const read = (key) => channel[prefix + key] || inherited[prefix + key] || '';

            return {
                clientId: read('clientId'),
                clientSecret: read('clientSecret'),
            };
        },

        checkApi() {
            this.isLoading = true;
            this.EverysizeApiClient.checkApi(this.getCredentials()).then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$t('everysize-api-test-button.title'),
                        message: this.$t('everysize-api-test-button.success'),
                    });
                } else {
                    this.createNotificationError({
                        title: this.$t('everysize-api-test-button.title'),
                        message: this.$t('everysize-api-test-button.error'),
                    });
                }

                this.isLoading = false;
            }).catch((errorResponse) => {
                try {
                    this.createNotificationError({
                        message: `${errorResponse.response.data.errors[0].title}: ${
                            errorResponse.response.data.errors[0].detail}`,
                        autoClose: false,
                    });
                } catch {
                    this.createNotificationError({
                        message: `${errorResponse.title}: ${errorResponse.message}`,
                        autoClose: false,
                    });
                } finally {
                    this.isLoading = false;
                }
            });
        },
    },
});
