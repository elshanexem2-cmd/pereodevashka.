// Minimal service worker: the app always loads fresh from the network (accounts, projects and images must never be served stale).
// It exists so the browser treats the site as an installable app and shows a friendly page when offline.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => new Response(
    '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Нет интернета</title><body style="font-family:system-ui;background:#fbf3ea;color:#3a1530;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px"><div><h2>Нет интернета</h2><p>Переодевашке нужна связь. Проверь сеть и открой приложение снова.</p></div>',
    {headers: {'Content-Type': 'text/html; charset=utf-8'}})));
});
