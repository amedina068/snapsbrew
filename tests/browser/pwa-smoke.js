const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const publicPath = path.join(__dirname, '../../public');
const manifest = JSON.parse(fs.readFileSync(path.join(publicPath, 'manifest.webmanifest'), 'utf8'));

assert.equal(manifest.start_url, '/');
assert.equal(manifest.display, 'standalone');
for (const icon of manifest.icons) {
  const bytes = fs.readFileSync(path.join(publicPath, icon.src));
  const size = Number(icon.sizes.split('x')[0]);
  assert.equal(bytes.readUInt32BE(16), size);
  assert.equal(bytes.readUInt32BE(20), size);
}

const listeners = {};
const stored = new Map();
const cache = {
  addAll: async (urls) => {
    for (const url of urls) stored.set(url, { offline: true });
  },
  match: async (url) => stored.get(url),
};
let networkCalls = 0;
let offline = false;
const context = {
  self: {
    addEventListener: (type, callback) => { listeners[type] = callback; },
    skipWaiting: () => {},
    clients: { claim: async () => {} },
  },
  caches: {
    open: async () => cache,
    keys: async () => ['snapsbrew-offline-old', 'another-app-cache'],
    delete: async (key) => key === 'snapsbrew-offline-old',
  },
  fetch: async () => {
    networkCalls++;
    if (offline) throw new Error('Network unavailable');
    return { online: true };
  },
  Response: { error: () => ({ error: true }) },
};

vm.runInNewContext(fs.readFileSync(path.join(publicPath, 'service-worker.js'), 'utf8'), context);

async function dispatch(type, request) {
  let promise;
  listeners[type]({
    request,
    waitUntil: (work) => { promise = work; },
    respondWith: (work) => { promise = work; },
  });
  return promise;
}

(async () => {
  await dispatch('install');
  assert.deepEqual([...stored.keys()], ['/offline.html', '/icons/icon-192.png']);

  const page = { mode: 'navigate', method: 'GET', url: 'https://snapsbrew.com/dashboard' };
  assert.equal((await dispatch('fetch', page)).online, true);
  assert.equal(stored.size, 2, 'account HTML must never be cached');

  offline = true;
  assert.equal((await dispatch('fetch', page)).offline, true);
  assert.equal(stored.size, 2);

  const before = networkCalls;
  assert.equal(await dispatch('fetch', { mode: 'cors', method: 'GET' }), undefined);
  assert.equal(await dispatch('fetch', { mode: 'navigate', method: 'POST' }), undefined);
  assert.equal(networkCalls, before, 'API and writes must bypass the worker');

  console.log('PWA manifest, icons, offline fallback, and cache safety passed.');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
