# Snapsbrew PWA

Snapsbrew can be installed from a supported browser while served over HTTPS (or localhost). The manifest starts at `/`, which sends signed-in users to the dashboard and other visitors to the site.

The service worker caches only the static offline page and its icon. Navigations use the network and show that page if the connection fails. Templates, account pages, API responses, and form submissions are not stored for offline use; editing and generation still require a connection. The offline page's **Try again** button reloads the requested URL.

Run `npm run test:pwa` to check the manifest, icon sizes, offline fallback, and cache behavior. When changing the offline page or icon, bump `CACHE_NAME` in `public/service-worker.js` so installed clients receive fresh assets.
