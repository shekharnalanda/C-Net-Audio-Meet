(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || document.documentElement.dataset.tileEngagement === 'ready') return;
  document.documentElement.dataset.tileEngagement = 'ready';
  const selector = script.dataset.tiles;
  if (!selector) return;
  const endpoint = new URL(script.dataset.endpoint || '/tile-engagement.php', location.origin);
  const cache = new Map(), nodes = new Map(), mounted = new WeakSet(), observed = new WeakSet();
  const visible = new Set(), viewed = new Set();
  let token = '', serverDay = '', busy = false, rescan = false, failures = 0, scanTimer, viewTimer;
  const number = new Intl.NumberFormat('en', {notation:'compact', maximumFractionDigits:1});
  const exact = new Intl.NumberFormat('en-IN');
  const heart = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg>';
  const eye = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
  function hash(text) {
    let a = 2166136261, b = 2246822519;
    for (let i = 0; i < text.length; i++) { a = Math.imul(a ^ text.charCodeAt(i), 16777619); b = Math.imul(b ^ text.charCodeAt(i), 3266489917); }
    return (a >>> 0).toString(16).padStart(8,'0') + (b >>> 0).toString(16).padStart(8,'0');
  }
  function canonical(value) {
    try { const url = new URL(value, location.href); ['lang','language','utm_source','utm_medium','utm_campaign'].forEach(k => url.searchParams.delete(k)); return url.origin + url.pathname.replace(/\/$/,'') + url.search + url.hash; } catch (_) { return value; }
  }
  function identify(card) {
    const course = card.matches('.course') ? card.querySelector('[data-code]') : null;
    const code = course?.dataset.code || card.dataset.courseCode;
    if (code && /^[a-z0-9-]{1,64}$/i.test(code)) return 'course-' + code.toLowerCase();
    const explicit = card.dataset.engagementId;
    const link = card.matches('a[href]') ? card : card.querySelector('a[href]:not([href="#"])');
    const image = card.querySelector('img');
    const heading = card.querySelector('h2,h3,h4,h5') || card.querySelector('b,strong');
    const group = card.closest('section[id]')?.id || card.parentElement?.className || 'home';
    const identity = explicit || (link ? canonical(link.getAttribute('href')) + ':' + (heading?.textContent?.trim() || '') : image ? canonical(image.getAttribute('src')) : heading?.textContent?.trim() || card.id || card.textContent.trim().slice(0,160));
    return 'tile-' + hash(group + ':' + identity);
  }
  function paint(key) {
    const data = cache.get(key);
    if (!data) return;
    for (const entry of nodes.get(key) || []) {
      if (!entry.card.isConnected) { nodes.get(key).delete(entry); continue; }
      entry.like.querySelector('[data-count]').textContent = number.format(data.likes);
      entry.like.setAttribute('aria-pressed', String(data.liked));
      entry.like.setAttribute('aria-label', `${data.liked ? 'Unlike / पसंद हटाएँ' : 'Like / पसंद करें'}: ${entry.title}, ${exact.format(data.likes)} likes`);
      entry.like.title = `${exact.format(data.likes)} Likes / पसंद`;
      entry.views.querySelector('[data-count]').textContent = number.format(data.views);
      entry.views.title = `${exact.format(data.views)} Views / बार देखा गया`;
      entry.views.setAttribute('aria-label', entry.views.title);
    }
  }
  async function request(action, keys, extra = {}) {
    const response = await fetch(endpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action,keys,token,...extra}), cache:'no-store'});
    if (!response.ok) throw new Error('कनेक्शन नहीं मिला। फिर प्रयास करें।');
    const result = await response.json();
    if (!result.cards || !result.token) throw new Error('कनेक्शन नहीं मिला।');
    token = result.token;
    if (serverDay && serverDay !== result.day) viewed.clear();
    serverDay = result.day;
    for (const [key, value] of Object.entries(result.cards)) { cache.set(key, value); paint(key); }
    return result;
  }
  function scheduleViews() { clearTimeout(viewTimer); viewTimer = setTimeout(flushViews, 800); }
  async function flushViews() {
    if (!token || document.visibilityState !== 'visible') return;
    const keys = [...visible].filter(k => !viewed.has(k)).slice(0,120);
    if (!keys.length) return;
    keys.forEach(k => viewed.add(k));
    try { await request('view', keys); } catch (_) { keys.forEach(k => viewed.delete(k)); }
  }
  const observer = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
    for (const entry of entries) {
      const key = entry.target.dataset.tileEngagementKey;
      if (entry.isIntersecting && !entry.target.closest('[aria-hidden="true"]')) visible.add(key);
      else if (![...(nodes.get(key) || [])].some(n => n.card !== entry.target && n.card.isConnected && n.card.getBoundingClientRect().top >= 0 && n.card.getBoundingClientRect().bottom <= innerHeight)) visible.delete(key);
    }
    scheduleViews();
  }, {threshold:0.25}) : null;
  function mount(card) {
    if (mounted.has(card) || card.closest('.tile-engagement-shell') || card.closest('nav,footer,dialog,.modal,[role="dialog"]')) return;
    // Select one outer tile; never insert nested interactive controls inside links/buttons.
    const parentTile = card.parentElement?.closest(selector);
    if (parentTile) return;
    mounted.add(card);
    const key = identify(card);
    const title = (card.querySelector('h2,h3,h4,h5') || card.querySelector('b,strong'))?.textContent?.trim() || card.querySelector('img')?.alt || 'यह विकल्प';
    let host = card;
    const interactive = card.matches('a,button') ? card : card.closest('a,button');
    if (interactive) {
      const shell = document.createElement('div');
      shell.className = 'tile-engagement-shell';
      interactive.before(shell); shell.append(interactive); host = shell;
    }
    card.dataset.tileEngagementKey = key;
    host.classList.add('has-tile-engagement');
    // A carousel clone may already contain markup copied from its original tile.
    host.querySelectorAll(':scope > .tile-engagement-bar').forEach(old => old.remove());
    const bar = document.createElement('div');
    bar.className = 'tile-engagement-bar';
    bar.setAttribute('role','group'); bar.setAttribute('aria-label','Likes and views / पसंद और व्यू');
    const like = document.createElement('button');
    like.type = 'button'; like.className = 'tile-like'; like.setAttribute('aria-pressed','false');
    like.innerHTML = heart + '<span data-count>…</span><span class="tile-metric-label">Likes</span>';
    if (card.closest('[aria-hidden="true"]')) like.tabIndex = -1;
    const views = document.createElement('span'); views.className = 'tile-views';
    views.innerHTML = eye + '<span data-count>…</span><span class="tile-metric-label">Views</span>';
    const status = document.createElement('span'); status.className = 'tile-engagement-status'; status.setAttribute('role','status');
    bar.append(like, views, status); host.append(bar);
    const entry = {card, like, views, title, host};
    if (!nodes.has(key)) nodes.set(key,new Set()); nodes.get(key).add(entry);
    like.addEventListener('click', async event => {
      event.preventDefault(); event.stopPropagation();
      if (like.disabled) return;
      like.disabled = true; status.textContent = '';
      try {
        if (!cache.has(key) || !token) await request('sync',[key]);
        await request('like',[key],{liked:!cache.get(key).liked});
      } catch (error) { status.textContent = error.message; }
      finally { like.disabled = false; }
    });
    paint(key);
    if (observer) { observer.observe(card); observed.add(card); }
    else { visible.add(key); }
  }
  async function scan() {
    if (busy) { rescan = true; return; }
    busy = true;
    try {
      document.querySelectorAll(selector).forEach(mount);
      const keys = [...nodes.keys()].filter(k => !cache.has(k));
      for (let i = 0; i < keys.length; i += 120) await request('sync',keys.slice(i,i+120));
      failures = 0; scheduleViews();
    } catch (_) { if (failures++ < 2) setTimeout(queueScan,5000); }
    finally { busy = false; if (rescan) { rescan = false; queueScan(); } }
  }
  const queueScan = () => { clearTimeout(scanTimer); scanTimer = setTimeout(scan,120); };
  new MutationObserver(changes => {
    if (changes.some(c => !c.target.parentElement?.closest('.tile-engagement-bar') && !c.target.closest?.('.tile-engagement-bar') && [...c.addedNodes].some(n => n.nodeType === 1 && !n.matches?.('.tile-engagement-bar')))) queueScan();
  }).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') { refresh(); scheduleViews(); }
  });
  async function refresh() {
    const keys = [...nodes.keys()].filter(k => [...nodes.get(k)].some(n => n.card.isConnected));
    try { for (let i = 0; i < keys.length; i += 120) await request('sync',keys.slice(i,i+120)); scheduleViews(); } catch (_) { /* Retry on the next visible refresh. */ }
  }
  // One quiet refresh per hour handles midnight in India without a server cron process.
  setInterval(() => { if (document.visibilityState === 'visible') refresh(); },3600000);
  window.addEventListener('online', () => { queueScan(); refresh(); });
  scan();
})();
