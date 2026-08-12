/**
 * Service worker: the part of the application that runs when the page does not.
 *
 * A push arrives at the browser, not at a tab, so this is what the browser
 * wakes up to display it. It has to sit at the document root - a service worker
 * only controls pages at or below its own path.
 *
 * Registered by assets/js/push-notification.js.
 */

self.addEventListener('push', event => {
    // A push with no payload is legal, and the browser insists a notification
    // is shown either way - one that stays silent gets deregistered.
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || self.registration.scope;

    const options = {
        body: payload.body || '',
        icon: payload.icon || undefined,
        badge: payload.badge || undefined,
        image: payload.image || undefined,
        // Same tag replaces the notification already on screen instead of
        // stacking under it; renotify makes that replacement alert again.
        tag: payload.tag || undefined,
        renotify: payload.renotify || false,
        // Stays on screen until acted on. Reserve it for things that genuinely
        // need an answer.
        requireInteraction: payload.requireInteraction || false,
        silent: payload.silent || false,
        actions: Array.isArray(payload.actions) ? payload.actions.slice(0, 2) : [],
        timestamp: payload.timestamp || Date.now(),
        // Carried through to the click handler; `url` is what it navigates to.
        data: Object.assign({ url: payload.url || '/' }, payload.data || {})
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();

    // An action button carries its own url when the payload gave it one.
    const data = event.notification.data || {};
    const action = (data.actions || {})[event.action];
    const url = action || data.url || '/';

    event.waitUntil(
        // Focus a tab already on the target instead of opening a second one.
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            const target = new URL(url, self.registration.scope).href;

            for (const client of clients) {
                if (client.url === target && 'focus' in client) return client.focus();
            }

            if (self.clients.openWindow) return self.clients.openWindow(target);
        })
    );
});

/**
 * The push service may hand out a new endpoint. The browser tells the worker,
 * not the page, and a subscription not re-registered here goes quiet without
 * anything reporting a failure.
 */
self.addEventListener('pushsubscriptionchange', event => {
    event.waitUntil(
        fetch('/push-notification/config')
            .then(response => response.json())
            .then(config => self.registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: config.public_key
            }))
            .then(subscription => {
                const json = subscription.toJSON();
                const body = new URLSearchParams({
                    endpoint: subscription.endpoint,
                    p256dh: json.keys.p256dh,
                    auth: json.keys.auth
                });

                // No csrf token here - there is no page to read one from.
                // Either allow this route without one ('no-csrf'), or accept
                // that it re-registers next time the user opens the site.
                return fetch('/push-notification/subscribe', { method: 'POST', body: body, credentials: 'include' });
            })
    );
});
