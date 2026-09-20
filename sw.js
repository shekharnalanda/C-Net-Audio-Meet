const CACHE='cnet-meet-v20';
const CORE=['./','manifest.webmanifest','assets/app.css?v=20260910-4','assets/addon.css?v=14','assets/app.js?v=20260920-1','assets/addon.js?v=9','assets/livekit-client.umd.min.js?v=1','assets/livekit-adapter.js?v=20260912-19','assets/cnet-meet-logo-web.png'];

self.addEventListener('install',event=>event.waitUntil(
  caches.open(CACHE).then(cache=>cache.addAll(CORE)).then(()=>self.skipWaiting())
));

self.addEventListener('activate',event=>event.waitUntil(
  caches.keys()
    .then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key))))
    .then(()=>self.clients.claim())
));

self.addEventListener('fetch',event=>{
  const request=event.request,url=new URL(request.url);
  if(request.method!=='GET'||url.origin!==self.location.origin||url.pathname.endsWith('/api.php')||url.pathname.endsWith('/livekit-token.php'))return;

  if(request.mode==='navigate'){
    event.respondWith(
      fetch(request)
        .then(response=>{if(response.ok)caches.open(CACHE).then(cache=>cache.put(request,response.clone()));return response})
        .catch(()=>caches.match(request).then(cached=>cached||caches.match('./')))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(cached=>cached||fetch(request).then(response=>{
      if(response.ok)caches.open(CACHE).then(cache=>cache.put(request,response.clone()));
      return response;
    }))
  );
});
