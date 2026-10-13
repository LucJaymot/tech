/*
 * Custom part of the service worker, appended by spomky-labs/pwa-bundle
 * after Workbox and the rules of config/packages/pwa.yaml (assets, images,
 * offline page for document requests).
 *
 * Feuilles de route stay readable without network: cached when opened,
 * network first. Turbo visits are fetch() requests, not documents: they get
 * the offline page here.
 */
const FEUILLES_DE_ROUTE = 'feuilles-de-route';
const FEUILLE_DE_ROUTE = /^\/soirees\/\d+\/feuille-de-route$/;
// Precached by the bundle's offline fallback (route app_hors_ligne).
const HORS_LIGNE = '/hors-ligne';

const pageHorsLigne = async () => (await caches.match(HORS_LIGNE, { ignoreSearch: true })) ?? Response.error();

workbox.routing.registerRoute(
    ({ request, url }) => FEUILLE_DE_ROUTE.test(url.pathname) && (request.headers.get('Accept') ?? '').includes('text/html'),
    new workbox.strategies.NetworkFirst({
        cacheName: FEUILLES_DE_ROUTE,
        matchOptions: { ignoreVary: true },
        plugins: [
            // A redirect means the session is gone: keep the copy we have.
            { cacheWillUpdate: async ({ response }) => (response.ok && !response.redirected ? response : null) },
            // Tells the page it is an old copy: its server time, for one, is stale.
            { cachedResponseWillBeUsed: async ({ cachedResponse }) => (cachedResponse ? marquerHorsLigne(cachedResponse) : null) },
            { handlerDidError: pageHorsLigne },
        ],
    }),
);

workbox.routing.registerRoute(
    ({ request }) => 'navigate' !== request.mode && (request.headers.get('Accept') ?? '').includes('text/html'),
    new workbox.strategies.NetworkOnly({ plugins: [{ handlerDidError: pageHorsLigne }] }),
);

async function marquerHorsLigne(copie) {
    const html = (await copie.text()).replace('<body ', '<body data-hors-ligne ');

    return new Response(html, { status: copie.status, headers: copie.headers });
}

// Contact details must not outlive the session on a shared phone.
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if ('POST' === event.request.method && url.origin === self.location.origin && '/deconnexion' === url.pathname) {
        event.waitUntil(caches.delete(FEUILLES_DE_ROUTE));
    }
});

// Web Push, sent by App\Notifier\WebPushTransport.
self.addEventListener('push', (event) => {
    const message = event.data?.json() ?? {};
    event.waitUntil(self.registration.showNotification(message.title ?? 'Caisson', {
        body: message.body ?? '',
        icon: message.icon,
        data: { url: message.url ?? '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(self.clients.openWindow(event.notification.data.url));
});
