/**
 * 家計簿アプリの Service Worker。
 *
 * 方針:
 *   - HTML(画面)は絶対にキャッシュしない。
 *     CSRFトークンを含むページを配ってしまうと、次の送信が 419 で落ちるため。
 *   - オフライン時は専用のオフライン画面を返すだけにする。
 *   - アイコンなど変化しない静的ファイルだけをキャッシュする。
 *
 * キャッシュ名にバージョンを入れてあるので、
 * この値を上げれば古いキャッシュは activate 時に消える。
 */
const CACHE_VERSION = 'kakeibo-v1';
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
    OFFLINE_URL,
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/icons/apple-touch-icon.png',
    '/icons/favicon-32.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    // 画面遷移: 常にネットワークを見る。つながらないときだけオフライン画面。
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    const url = new URL(request.url);

    // 変化しない静的ファイルだけキャッシュ優先にする
    if (url.origin === self.location.origin && url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
    }

    // それ以外(CDN・アップロード画像・CSVなど)は素通しする
});
