// ============================================================
// sw.js — Service Worker de la app Casa Bosque (la usa el personal del hotel)
// ------------------------------------------------------------
// Estrategia segura para un sitio con sesión y datos en vivo:
//   - Las PÁGINAS (navigate) van a la red primero; si no hay
//     conexión, se muestra offline.html. Nunca se cachean páginas
//     con sesión (para no mostrar datos ajenos/viejos).
//   - Los ASSETS estáticos (iconos, manifest) van de caché primero.
//   - Nada de POST se cachea (logins, formularios, pagos).
// ============================================================
const CACHE = 'casabosque-v4';   // v4: offline.html rediseñada (si no se sube el nombre, el navegador seguiría mostrando la copia vieja)
const ASSETS = [
  '/offline.html',
  '/manifest.json',
  '/favicon.svg?v=3',
  '/icon-192.png?v=3',
  '/icon-512.png?v=3',
  // El emblema que sale en offline.html: sin precargarlo, la pagina sin
  // conexion se quedaria sin logo, que es justo cuando no hay red para pedirlo.
  '/assets/img/emblema-64.png?v=3'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting())
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
  if (req.method !== 'GET') return;                 // no tocar POST/PUT (login, pagos, formularios)
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;  // solo mismo origen

  // Páginas: red primero, offline.html como respaldo
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Assets estáticos: caché primero, luego red
  e.respondWith(
    caches.match(req).then((cached) => cached || fetch(req).then((res) => {
      // guardar en caché solo assets propios cacheables
      if (res.ok && (url.pathname.match(/\.(png|svg|ico|css|js|woff2?)$/) || url.pathname === '/manifest.json')) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
      }
      return res;
    }).catch(() => cached))
  );
});
