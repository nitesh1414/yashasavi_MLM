/* Yashasavi service worker — offline-capable static shell for the PWA.
   Strategy:
   - static assets (css/js/images/fonts): cache-first with background refresh
   - page navigations + anything dynamic: network-first, offline fallback page
   - PHP responses are never cached (sessions, cart, panels). */
const VERSION = 'v1.8.1';
const CACHE = 'yashasavi-' + VERSION;
const OFFLINE_URL = 'offline.html';
const STATIC_EXT = /\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf)$/i;

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE).then((c) => c.addAll([OFFLINE_URL])).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') { return; }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) { return; }
    /* never intercept dynamic/administrative areas */
    if (url.pathname.endsWith('.php') || url.pathname === '/' ||
        /\/(install|admin|superadmin|user)\//i.test(url.pathname) ||
        /\/(login|register|logout|cart|checkout)\.php/i.test(url.pathname)) {
        /* page navigations fall back to the offline page when the network dies */
        if (req.mode === 'navigate') {
            e.respondWith(
                fetch(req).catch(() => caches.match(OFFLINE_URL).then((r) => r || new Response('You are offline.', { headers: { 'Content-Type': 'text/html' } })))
            );
        }
        return;
    }

    if (!STATIC_EXT.test(url.pathname)) { return; }

    /* static assets: cache-first, refresh in background */
    e.respondWith(
        caches.match(req).then((cached) => {
            const refresh = fetch(req).then((res) => {
                if (res && res.status === 200) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                }
                return res;
            }).catch(() => cached);
            return cached || refresh;
        })
    );
});
