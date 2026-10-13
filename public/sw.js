/*
 * Keeps feuilles de route readable without network: each one is cached when
 * opened, network first, and any page that cannot load shows /hors-ligne.
 * Served from the web root so that its scope covers the whole site.
 */
const CACHE = 'feuilles-de-route-v1';
const HORS_LIGNE = '/hors-ligne';
const FEUILLE_DE_ROUTE = /^\/soirees\/\d+\/feuille-de-route$/;

const preparer = () => caches.open(CACHE).then((cache) => cache.add(HORS_LIGNE));

self.addEventListener('install', (event) => {
    event.waitUntil(preparer().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((cles) => Promise.all(cles.filter((cle) => cle !== CACHE).map((cle) => caches.delete(cle))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Contact details must not outlive the session on a shared phone.
    if ('POST' === request.method && '/deconnexion' === url.pathname) {
        event.waitUntil(caches.delete(CACHE).then(preparer));

        return;
    }
    if ('GET' !== request.method) {
        return;
    }

    // Versioned by AssetMapper: a cached file never goes stale.
    if (url.pathname.startsWith('/assets/')) {
        event.respondWith(caches.match(request).then((enCache) => enCache ?? fetch(request).then((reponse) => {
            if (reponse.ok) {
                const copie = reponse.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copie));
            }

            return reponse;
        })));

        return;
    }

    // Page loads, including Turbo's fetch() visits.
    if (!(request.headers.get('Accept') ?? '').includes('text/html')) {
        return;
    }
    const aGarder = FEUILLE_DE_ROUTE.test(url.pathname);
    event.respondWith(fetch(request)
        .then((reponse) => {
            // A redirect means the session is gone: keep the copy we have.
            if (aGarder && reponse.ok && !reponse.redirected) {
                const copie = reponse.clone();
                caches.open(CACHE).then((cache) => cache.put(url.pathname, copie));
            }

            return reponse;
        })
        .catch(async () => {
            const copie = aGarder ? await caches.match(url.pathname) : undefined;

            return copie ? marquerHorsLigne(copie) : caches.match(HORS_LIGNE);
        }));
});

// Tells the page it is an old copy: its server time, for one, is stale.
async function marquerHorsLigne(copie) {
    const html = (await copie.text()).replace('<body ', '<body data-hors-ligne ');

    return new Response(html, { headers: copie.headers });
}
