/* ================================================================
   Service Worker - 离线缓存支持 (v2.0)
================================================================ */

const CACHE_NAME = 'nav-site-v2.0';
const urlsToCache = [
  '/',
  '/index.html',
  '/js/style.css',
  '/js/app.js',
  '/js/base64.js',
  '/favicon.ico'
];

// 安装事件 - 缓存核心资源
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('✅ Service Worker: 缓存核心资源');
        return cache.addAll(urlsToCache);
      })
      .then(() => self.skipWaiting())
  );
});

// 激活事件 - 清理旧缓存
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('🗑️ Service Worker: 删除旧缓存', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// 请求拦截 - 缓存优先策略
self.addEventListener('fetch', event => {
  // 只处理 GET 请求
  if (event.request.method !== 'GET') return;
  
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        // 缓存命中，返回缓存
        if (response) {
          // 后台更新缓存
          fetchAndCache(event.request);
          return response;
        }
        
        // 缓存未命中，发起网络请求
        return fetchAndCache(event.request);
      })
      .catch(() => {
        // 离线且缓存未命中
        if (event.request.headers.get('accept')?.includes('text/html')) {
          return caches.match('/index.html');
        }
      })
  );
});

// 获取并缓存
function fetchAndCache(request) {
  return fetch(request).then(response => {
    if (!response || response.status !== 200 || response.type === 'opaque') {
      return response;
    }
    
    const responseToCache = response.clone();
    caches.open(CACHE_NAME).then(cache => {
      cache.put(request, responseToCache);
    });
    
    return response;
  }).catch(error => {
    console.log('❌ Service Worker: 请求失败', error);
    throw error;
  });
}

// 监听消息
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
