/* MPIDS service worker — offline support for the PUBLIC site only.
 * Must live at the site root so its scope covers every page.
 * Admin pages, the login screen and authenticated API traffic are never cached. */
const CACHE_NAME = 'mpids-v9';
// This worker is served from the app root, so its natural scope is the app
// directory. Requests outside that prefix belong to other apps that may share
// this origin (XAMPP), so they are never intercepted.
const APP_BASE_PATH = new URL('./', self.location).pathname;
const OFFLINE_URL = 'public-dashboard.html';
const OFFLINE_FALLBACK = [
    'public-dashboard.html',
    'subscriptions.html',
    'ussd.html',
    'css/style.css',
    'css/layout.css',
    'css/responsive.css',
    'js/api.js',
    'js/app.js',
    'js/public-ui.js',
    'js/public-dashboard.js',
    'js/subscriptions.js',
    'js/ussd.js',
    'pwa/manifest.json',
        'img/favicon.png',
    'img/market-hero.jpg',
    'img/logo.png'
];
const CACHEABLE_PAGES = ['public-dashboard.html', 'subscriptions.html', 'ussd.html'];
// Derived from the worker's own location so the app can live in any subdirectory.
const API_BASE_PATH = APP_BASE_PATH + 'api/';
const PRICES_API_PATH = API_BASE_PATH + 'public/dashboard/';

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // Cache entries individually so one missing asset doesn't block install.
            return Promise.allSettled(OFFLINE_FALLBACK.map(function (asset) {
                return cache.add(new Request(asset, { cache: 'reload' }));
            }));
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            // Only ever touch this app's own caches: the worker shares an origin
            // (http://localhost) with anything else the user has installed.
            return Promise.all(keys.filter(function (key) {
                return key !== CACHE_NAME && key.indexOf('mpids-') === 0;
            }).map(function (key) {
                return caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

function isCacheable(response) {
    if (!response || !response.ok || response.type === 'opaque') return false;
    const cacheControl = response.headers.get('Cache-Control') || '';
    if (/no-store|no-cache|private/i.test(cacheControl)) return false;
    return true;
}

function pageName(pathname) {
    const parts = pathname.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : '';
}

function isPublicPage(pathname) {
    return CACHEABLE_PAGES.indexOf(pageName(pathname)) !== -1;
}

function isPublicPricesApi(pathname) {
    return pathname.replace(/\/+$/, '').endsWith(PRICES_API_PATH.replace(/\/+$/, ''));
}

function isStaticAsset(pathname) {
    return /\.(css|js|png|svg|jpg|jpeg|webp|ico|woff2?)$/.test(pathname);
}

function isServiceWorkerPath(pathname) {
    return pageName(pathname) === 'service-worker.js';
}

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return; // let CDN fonts/icons pass through
    if (url.pathname.indexOf(APP_BASE_PATH) !== 0) return; // another app on this origin
    if (isServiceWorkerPath(url.pathname)) return; // never shadow our own update

    // Public prices only: stale-while-revalidate so the last published prices work offline.
    if (isPublicPricesApi(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    // Any other API traffic (admin, auth, subscriptions) is always live.
    if (url.pathname.indexOf(API_BASE_PATH) === 0) return;

    if (request.mode === 'navigate') {
        if (!isPublicPage(url.pathname)) return; // admin/login pages: network only
        event.respondWith(networkFirstPage(request));
        return;
    }

    if (isStaticAsset(url.pathname)) {
        // Assets are not content-hashed, so revalidate in the background: offline
        // visitors still get the cached copy, online visitors pick up new builds.
        event.respondWith(staleWhileRevalidate(request));
    }
});

async function networkFirstPage(request) {
    const cache = await caches.open(CACHE_NAME);
    try {
        const response = await fetch(request);
        if (isCacheable(response)) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await cache.match(request);
        if (cached) return cached;
        const fallback = await cache.match(new Request(OFFLINE_URL));
        if (fallback) return fallback;
        return new Response(
            '<!doctype html><meta charset="utf-8"><title>MPIDS offline</title>' +
            '<p style="font-family:system-ui;padding:24px">You are offline and this page is not cached yet. ' +
            'Please reconnect and try again.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
        );
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);
    const network = fetch(request).then(function (response) {
        if (isCacheable(response)) cache.put(request, response.clone());
        return response;
    }).catch(function () {
        return cached || new Response(
            JSON.stringify({ detail: 'Prices are unavailable while offline.' }),
            { status: 503, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } }
        );
    });
    return cached || network;
}
