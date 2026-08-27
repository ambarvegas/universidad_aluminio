/**
 * sw.js — Service Worker para la Universidad del Aluminio
 * Estrategia: Cache First para assets estáticos, Network First para API/datos.
 */

const CACHE_NAME    = 'unialuminio-v1';
const CACHE_DYNAMIC = 'unialuminio-dynamic-v1';

// Assets estáticos que se cachean al instalar el SW
const STATIC_ASSETS = [
    './',
    './index.html',
    './login.html',
    './admin.html',
    './style.css',
    './script.js',
    './manifest.json',
    './icon-192.png',
    './icon-512.png',
    './js/api.js',
    './js/utils.js',
    './js/ui/modal.js',
    './js/ui/toast.js',
    './js/features/config.js',
    './js/features/images.js',
    './js/features/reports.js',
    // Bootstrap y fuentes CDN (cache on first load)
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
];

// ============================================================
// INSTALL — Pre-cachear assets estáticos
// ============================================================
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS.map(url => {
                // Usar Request con mode: 'no-cors' para recursos externos
                if (url.startsWith('http')) {
                    return new Request(url, { mode: 'no-cors' });
                }
                return url;
            })).catch(err => {
                console.warn('[SW] Error al pre-cachear algunos assets:', err);
            });
        })
    );
    self.skipWaiting();
});

// ============================================================
// ACTIVATE — Limpiar cachés antiguas
// ============================================================
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(k => k !== CACHE_NAME && k !== CACHE_DYNAMIC)
                    .map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

// ============================================================
// FETCH — Estrategia de caché
// ============================================================
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Nunca interceptar peticiones a la API o uploads
    if (url.pathname.includes('api.php') || url.pathname.includes('/uploads/')) {
        return; // Pasar directo a red
    }

    // Para peticiones GET, usar Cache First con fallback a red
    if (event.request.method === 'GET') {
        event.respondWith(
            caches.match(event.request).then(cached => {
                if (cached) return cached;

                return fetch(event.request).then(response => {
                    // Cachear respuestas válidas de assets propios
                    if (response && response.status === 200 && event.request.url.startsWith(self.location.origin)) {
                        const resClone = response.clone();
                        caches.open(CACHE_DYNAMIC).then(cache => cache.put(event.request, resClone));
                    }
                    return response;
                }).catch(() => {
                    // Fallback offline para HTML
                    if (event.request.headers.get('accept')?.includes('text/html')) {
                        return caches.match('./login.html');
                    }
                });
            })
        );
    }
});
