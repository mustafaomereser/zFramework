/**
 * Client half of Pusher Channels (config/pusher.php).
 *
 *   <script src="/assets/js/pusher.js"></script>
 *   <script>
 *   LivePusher.on('orders', 'created', order => console.log('new order', order));
 *   LivePusher.app('admin').on('audit', { login: e => ..., logout: e => ... });
 *   </script>
 *
 * One handle per application in config `apps`: LivePusher.app('admin') is the
 * admin app's, and LivePusher itself answers for the default one. A handle
 * connects on its first use - loading Pusher's own library when the page has
 * not, reading the public key and cluster from /pusher/config so nothing is
 * pasted in by hand, and signing private-/presence- channels through
 * /pusher/auth with the page's csrf token.
 *
 * Named LivePusher because `Pusher` is the library's global.
 */
(function () {

    const settings = {
        library: 'https://js.pusher.com/8.4/pusher.min.js',
        routes: {
            config: '/pusher/config',
            auth: '/pusher/auth'
        },
        // Csrf token. Read from <meta name="csrf-token">, a hidden _token input,
        // or /pusher/config when not given.
        token: null,
        // Merged into every Pusher constructor call.
        options: {}
    };

    let loading = null;

    /**
     * Pusher's library - the page's copy, or one script tag.
     */
    function library() {
        if (window.Pusher) return Promise.resolve();
        if (loading) return loading;

        return loading = new Promise((resolve, reject) => {
            const script   = document.createElement('script');
            script.src     = settings.library;
            script.onload  = () => resolve();
            script.onerror = () => reject(new Error('pusher.js: could not load ' + settings.library));
            document.head.appendChild(script);
        });
    }

    async function getJson(url, query) {
        const qs = new URLSearchParams(query).toString();
        const response = await fetch(url + (qs ? '?' + qs : ''), { credentials: 'same-origin' });
        if (!response.ok) throw new Error('pusher.js: ' + url + ' answered ' + response.status);
        return response.json();
    }

    function findToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.content;
        const input = document.querySelector('input[name="_token"]');
        return input ? input.value : null;
    }

    /**
     * One application's handle.
     *
     * @param {string|null} app key in config `apps`; null is the server's default
     */
    function Handle(app) {
        this.name = app;
        this.connection = null;
    }

    Handle.prototype = {

        /**
         * The pusher-js instance, connecting on the first call.
         *
         * @return {Promise<object>}
         */
        connect() {
            if (this.connection) return this.connection;

            return this.connection = (async () => {
                await library();

                const query  = this.name ? { app: this.name } : {};
                const config = await getJson(settings.routes.config, query);
                const params = Object.assign({ _token: settings.token || findToken() || config._token }, query);

                const options = Object.assign({}, config, {
                    channelAuthorization: { endpoint: settings.routes.auth, transport: 'ajax', params },
                    userAuthentication: { endpoint: settings.routes.auth, transport: 'ajax', params }
                }, settings.options);
                delete options.key;
                delete options._token;

                return new Pusher(config.key, options);
            })();
        },

        /**
         * Subscribe to a channel and bind handlers.
         *
         *   on('orders', 'created', fn)
         *   on('orders', { created: fn, cancelled: fn })
         *   on('presence-room', { 'pusher:member_added': fn })
         *
         * @param {string} channel
         * @param {string|object} event one name, or event → handler
         * @param {function} [handler]
         * @return {Promise<object>} the pusher-js channel
         */
        async on(channel, event, handler) {
            const handlers = typeof event === 'string' ? { [event]: handler } : (event || {});
            const pusher   = await this.connect();
            const chan     = pusher.subscribe(channel);
            for (const [name, fn] of Object.entries(handlers)) chan.bind(name, fn);
            return chan;
        },

        /**
         * Unbind one handler, one event, or leave the channel.
         *
         * @param {string} channel
         * @param {string} [event]
         * @param {function} [handler]
         */
        async off(channel, event, handler) {
            const pusher = await this.connect();
            if (!event) return pusher.unsubscribe(channel);
            const chan = pusher.channel(channel);
            if (chan) chan.unbind(event, handler);
        },

        /**
         * Same as on(); the name pusher-js users expect.
         */
        subscribe(channel, handlers) {
            return this.on(channel, handlers || {});
        },

        /**
         * The socket id, for a trigger that should not echo back to this page
         * (Pusher::trigger(..., $socketId)).
         *
         * @return {Promise<string>}
         */
        async socketId() {
            const pusher = await this.connect();
            if (pusher.connection.socket_id) return pusher.connection.socket_id;
            return new Promise(resolve => pusher.connection.bind('connected', () => resolve(pusher.connection.socket_id)));
        }
    };

    const handles = {};
    const main    = new Handle(null);

    /**
     * The default app's handle, with app() for the others.
     */
    window.LivePusher = Object.assign(main, {
        settings,

        /**
         * @param {string} app key in config/pusher.php `apps`
         * @return {Handle}
         */
        app(app) {
            return handles[app] || (handles[app] = new Handle(app));
        }
    });
})();
