# browserless — pooled Chrome for snap rendering

Snapsbrew renders snaps by screenshotting HTML with headless Chrome. The default
`browsershot` driver cold-launches node + Chrome **per render** — slow and
memory-heavy. This service runs **one warm Chrome** that every render reuses over
HTTP, killing the cold-launch and removing node/Chrome from the app servers.

The app side is `App\Services\BrowserlessRenderer` (bound behind the
`App\Contracts\SnapRenderer` seam when `RENDER_DRIVER=browserless`). It POSTs the
snap document HTML to `/screenshot` and `/pdf` and base64s the bytes that come
back. Nothing is shared between the two except the HTTP payload.

## How the app talks to it

```
Snap::getImage()
  → BrowserlessRenderer::screenshotBase64($html, …)
    → HTTP POST http://<host>:3000/screenshot?token=…  { html, viewport, options }
    ← 200, body = raw PNG/JPEG/WebP bytes
  → base64 → addMediaFromBase64() → S3/disk
```

Synchronous and blocking: the PHP worker holds the request open until bytes
return or `BROWSERLESS_TIMEOUT` (default 15s) hits. On 5xx/timeout the renderer
retries 3× then throws, so the snap goes `FAILED` rather than persisting empty
media.

Horizon kills a render job after 60 seconds. Three attempts of
`BROWSERLESS_TIMEOUT` seconds and the backoff between them must fit in that
time. With the default of 15 seconds, the worst case is about 46 seconds. If you
raise `BROWSERLESS_TIMEOUT` above 19, attempt 3 does not run.

## Run it

```bash
# 1. Generate a token (once) and put it in the deploy environment — NEVER commit it.
export BROWSERLESS_TOKEN=$(openssl rand -hex 24)

# 2. Start the service.
docker compose -f deploy/browserless/docker-compose.yml up -d

# 3. Smoke test: is the warm Chrome answering?
curl "http://127.0.0.1:3000/json/version?token=$BROWSERLESS_TOKEN"

# 4. End-to-end: render HTML the way the app does.
curl -X POST "http://127.0.0.1:3000/screenshot?token=$BROWSERLESS_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"html":"<h1>hi</h1>","viewport":{"width":400,"height":200}}' \
  --output /tmp/test.png && open /tmp/test.png
```

## Wire Snapsbrew to it

In the app's environment (not this directory's):

```
RENDER_DRIVER=browserless
BROWSERLESS_URL=http://127.0.0.1:3000      # or http://browserless:3000 if app is in compose
BROWSERLESS_TOKEN=<same token as above>
BROWSERLESS_TIMEOUT=15                      # seconds, 3 attempts must fit in 60
```

`BROWSERLESS_URL` host:
- app on the **same host** (not containerized) → `http://127.0.0.1:3000`
- app **also in compose** → `http://browserless:3000` (the service name)

Keep `RENDER_DRIVER=browsershot` locally; flip to `browserless` in staging/prod.

## Two things that bite

1. **The token is the only auth.** Anyone who reaches `:3000` with it can drive
   the browser and read files via `file://`. The compose binds `127.0.0.1:3000`
   so it isn't on a public interface. Cross-host? Private network + token, never
   public. Don't commit the token — compose reads `${BROWSERLESS_TOKEN}` from the
   environment.

2. **Asset reachability.** The HTML the app sends references font/image URLs;
   browserless's Chrome fetches them from **its own** network. Public/S3 media →
   fine. A `MEDIA_DISK=local` URL behind the app's self-signed cert →
   unreachable → blank fonts/images. That's why local dev stays on `browsershot`
   and browserless only runs where media is publicly fetchable.

## Tuning

| Env | Meaning | Keep in sync with |
|-----|---------|-------------------|
| `CONCURRENT` | parallel render sessions | Horizon worker count (`HORIZON_MAX_PROCESSES`) |
| `QUEUED` | overflow queue length | — |
| `TIMEOUT` (ms) | per-render cap | app `BROWSERLESS_TIMEOUT` (s) |
| `MAX_MEMORY_PERCENT` | recycle threshold | container `mem_limit` |

Pin the image tag (`:v2.38.1`, not `:latest`) — a browserless major can change the
`/screenshot` payload and break `BrowserlessRenderer`. Bump the renderer and this
tag together.
