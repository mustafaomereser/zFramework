/**
 * Client half of the push system.
 *
 *   <script src="/assets/js/push-notification.js"></script>
 *   <button onclick="PushNotification.subscribe({ topics: ['orders'] })">Notify me</button>
 *
 * Registers the service worker, asks for permission, sends the subscription to
 * the server - and reports which of the three refused.
 *
 * A browser gives a site one good chance at the permission prompt: a user who
 * says no is not asked again until they change it in settings. Call subscribe()
 * from a click on something that explains what will be sent, never on load.
 */
window.PushNotification = {

    /**
     * Overridable at any point before the first call.
     */
    settings: {
        worker: '/service-worker.js',
        scope: '/',
        routes: {
            config: '/push-notification/config',
            subscribe: '/push-notification/subscribe',
            unsubscribe: '/push-notification/unsubscribe'
        },
        // Application key. Null lets the server decide.
        app: null,
        // Csrf token. Read from <meta name="csrf-token"> or a hidden _token
        // input when not given.
        token: null
    },

    /**
     * Can this browser be pushed to at all?
     *
     * @return {boolean}
     */
    support() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },

    /**
     * Where this browser currently stands.
     *
     * @return {Promise<string>} unsupported | denied | prompt | subscribed | unsubscribed
     */
    async status() {
        if (!this.support()) return 'unsupported';
        if (Notification.permission === 'denied') return 'denied';
        if (Notification.permission === 'default') return 'prompt';

        const registration = await navigator.serviceWorker.getRegistration(this.settings.scope);
        if (!registration) return 'unsubscribed';

        return (await registration.pushManager.getSubscription()) ? 'subscribed' : 'unsubscribed';
    },

    /**
     * Register, ask, subscribe, store.
     *
     * @param {object} options topics, app
     * @return {Promise<object>} { status, subscription } or { status: 0, reason }
     */
    async subscribe(options = {}) {
        if (!this.support()) return { status: 0, reason: 'unsupported' };

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return { status: 0, reason: permission };

        const app = options.app || this.settings.app;
        const config = await this.config(app);

        if (!config.public_key) return { status: 0, reason: 'no-key' };

        // updateViaCache none: without it a worker cached for a week by the
        // asset headers keeps running a week-old copy of itself.
        const registration = await navigator.serviceWorker.register(this.settings.worker, {
            scope: this.settings.scope,
            updateViaCache: 'none'
        });

        await navigator.serviceWorker.ready;

        // userVisibleOnly is not optional in any current browser.
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.key(config.public_key)
        }).catch(error => {
            // Subscribing with a key other than the one this browser already
            // holds fails here - what a rotated key pair looks like from the
            // client side. Drop the old subscription and try once.
            return registration.pushManager.getSubscription()
                .then(existing => existing ? existing.unsubscribe() : null)
                .then(() => registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.key(config.public_key)
                }))
                .catch(() => { throw error; });
        });

        const keys = subscription.toJSON().keys;
        const body = {
            endpoint: subscription.endpoint,
            p256dh: keys.p256dh,
            auth: keys.auth
        };

        if (app) body.app = app;
        (options.topics || []).forEach((topic, index) => body['topics[' + index + ']'] = topic);

        const response = await this.post(this.settings.routes.subscribe, body);

        return response.status ? { status: 1, subscription: subscription } : { status: 0, reason: response.message || 'rejected' };
    },

    /**
     * Stop being notified. Both sides, or one is left holding something that no
     * longer works.
     *
     * @return {Promise<object>}
     */
    async unsubscribe() {
        if (!this.support()) return { status: 0, reason: 'unsupported' };

        const registration = await navigator.serviceWorker.getRegistration(this.settings.scope);
        const subscription = registration ? await registration.pushManager.getSubscription() : null;

        if (!subscription) return { status: 1 };

        const endpoint = subscription.endpoint;

        await subscription.unsubscribe();
        await this.post(this.settings.routes.unsubscribe, { endpoint: endpoint });

        return { status: 1 };
    },

    /**
     * The application's public key, from the server.
     *
     * @param {string|null} app
     * @return {Promise<object>}
     */
    async config(app) {
        const url = this.settings.routes.config + (app ? '?app=' + encodeURIComponent(app) : '');
        const response = await fetch(url, { credentials: 'same-origin' });

        return await response.json();
    },

    /**
     * base64url to the Uint8Array the subscribe API insists on.
     *
     * @param {string} key
     * @return {Uint8Array}
     */
    key(key) {
        const padded = (key + '='.repeat((4 - key.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(padded);
        const output = new Uint8Array(raw.length);

        for (let i = 0; i < raw.length; i++) output[i] = raw.charCodeAt(i);

        return output;
    },

    /**
     * Form post: that is what the csrf check and request() read.
     *
     * @param {string} url
     * @param {object} data
     * @return {Promise<object>}
     */
    async post(url, data) {
        const body = new URLSearchParams(data);
        const token = this.settings.token || this.token();

        if (token) body.append('_token', token);

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        });

        return await response.json().catch(() => ({ status: 0, message: 'invalid response' }));
    },

    /**
     * Find a csrf token already on the page.
     *
     * @return {string|null}
     */
    token() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');

        const input = document.querySelector('input[name="_token"]');
        return input ? input.value : null;
    }
};
