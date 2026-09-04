/**
 * Test connection, and send test message.
 *
 * Both call the endpoints and print back exactly what came out of them,
 * failures included. The whole point of this screen is to be the place a wrong
 * key is caught, so a refusal is shown in full rather than reduced to
 * "something went wrong".
 */

import template from './upeo-njiwa-check.html.twig';
import './upeo-njiwa-check.scss';

const { Component } = Shopware;

Component.register('upeo-njiwa-check', {
    template,

    inject: ['njiwaApiService'],

    data() {
        return {
            salesChannelId: null,
            phone: '',
            isTesting: false,
            isSending: false,
            result: '',
            resultIsError: false,
        };
    },

    methods: {
        onTestConnection() {
            this.isTesting = true;
            this.result = '';

            this.njiwaApiService.testConnection(this.salesChannelId)
                .then((response) => {
                    this.show(this.messageFrom(response), !(response && response.ok));
                })
                .catch((error) => {
                    this.show(this.errorFrom(error), true);
                })
                .finally(() => {
                    this.isTesting = false;
                });
        },

        onSendTestMessage() {
            this.isSending = true;
            this.result = '';

            this.njiwaApiService.sendTestMessage(this.phone, this.salesChannelId)
                .then((response) => {
                    this.show(this.messageFrom(response), !(response && response.ok));
                })
                .catch((error) => {
                    this.show(this.errorFrom(error), true);
                })
                .finally(() => {
                    this.isSending = false;
                });
        },

        show(message, isError) {
            this.result = message;
            this.resultIsError = isError;
        },

        messageFrom(response) {
            if (response && typeof response.message === 'string' && response.message !== '') {
                return response.message;
            }

            return this.$tc('upeo-njiwa.check.noAnswer');
        },

        /**
         * A refusal arrives in one of two shapes: this plugin's own body, which
         * carries the reason Njiwa gave, or Shopware's, which is what a
         * permission failure looks like. Both are worth reading.
         */
        errorFrom(error) {
            const data = error && error.response ? error.response.data : null;

            if (data && typeof data.message === 'string' && data.message !== '') {
                return data.code ? `${data.message} (${data.code})` : data.message;
            }

            if (data && Array.isArray(data.errors) && data.errors.length > 0) {
                const first = data.errors[0];

                return first.detail || first.title || this.$tc('upeo-njiwa.check.noAnswer');
            }

            return (error && error.message) ? error.message : this.$tc('upeo-njiwa.check.noAnswer');
        },
    },
});
