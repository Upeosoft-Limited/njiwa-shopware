/**
 * The two /api/_action endpoints, as something the screen can call.
 *
 * Nothing new is exposed here. These are the same two endpoints the README
 * documents for curl, with the same permissions on them, so the button and the
 * command line are checking exactly the same thing.
 */

const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class NjiwaApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'upeo-njiwa') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'njiwaApiService';
    }

    /**
     * A null sales channel means the shop-wide settings, which is what a shop
     * with one sales channel means every time.
     */
    testConnection(salesChannelId) {
        return this.httpClient
            .post(
                '_action/upeo-njiwa/test-connection',
                { salesChannelId: salesChannelId || null },
                { headers: this.getBasicHeaders() },
            )
            .then((response) => ApiService.handleResponse(response));
    }

    sendTestMessage(to, salesChannelId) {
        return this.httpClient
            .post(
                '_action/upeo-njiwa/send-test-message',
                { to, salesChannelId: salesChannelId || null },
                { headers: this.getBasicHeaders() },
            )
            .then((response) => ApiService.handleResponse(response));
    }
}

Application.addServiceProvider('njiwaApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new NjiwaApiService(initContainer.httpClient, container.loginService);
});
