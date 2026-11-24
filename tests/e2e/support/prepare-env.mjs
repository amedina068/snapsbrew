// Writes .env.e2e and an empty SQLite file for the Playwright suite.
//
// The values start from .env.example. The overrides below make the app run
// with no outside service: SQLite, mail to an array, a sync queue, no
// broadcasting, no MCP routes, and a renderer address that refuses every
// connection. Each run makes a new APP_KEY. .env.e2e is in .gitignore, and
// the database file is outside the checkout.
import { createHash, randomBytes } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const port = process.env.E2E_PORT ?? '8787'
const appUrl = `http://127.0.0.1:${port}`
// The database goes to the temp directory. Each migration syncs the file to
// disk, and on a copy-on-write disk (btrfs) migrate:fresh then takes minutes
// instead of seconds. The hash of the checkout path keeps worktrees apart.
const checkoutHash = createHash('sha1').update(root).digest('hex').slice(0, 10)
const databasePath = process.env.E2E_DB_DATABASE
  ?? path.join(tmpdir(), `snapsbrew-e2e-${checkoutHash}.sqlite`)

if (existsSync(path.join(root, 'public', 'hot'))) {
  console.error('public/hot exists, so the app loads assets from the Vite dev server. Stop `npm run dev` and delete public/hot.')
  process.exit(1)
}

const overrides = {
  APP_ENV: 'e2e',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_DEBUG: 'true',
  APP_URL: appUrl,
  LOG_CHANNEL: 'single',
  ROLLBAR_TOKEN: '',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: databasePath,
  BROADCAST_DRIVER: 'null',
  // The array store keeps nothing between requests, so the login rate limit
  // never blocks a second run of the suite.
  CACHE_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  MAIL_MAILER: 'array',
  MEDIA_DISK: 'public',
  // No browserless service runs in the suite. A render fails at once on the
  // discard port, and no local Chrome starts.
  RENDER_DRIVER: 'browserless',
  BROWSERLESS_URL: 'http://127.0.0.1:9',
  BROWSERLESS_TOKEN: '',
  REGISTRATION_ENABLED: 'true',
  MCP_ENABLED: 'false',
  SNAPSBREW_FREE_TOOLS_ENABLED: 'false',
  SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${port}`,
}

const lines = readFileSync(path.join(root, '.env.example'), 'utf8').split('\n')
const seen = new Set()
const output = lines.map((line) => {
  const match = line.match(/^([A-Z0-9_]+)=/)
  if (!match || !(match[1] in overrides)) {
    return line
  }
  seen.add(match[1])
  return `${match[1]}=${overrides[match[1]]}`
})

for (const [key, value] of Object.entries(overrides)) {
  if (!seen.has(key)) {
    output.push(`${key}=${value}`)
  }
}

writeFileSync(path.join(root, '.env.e2e'), output.join('\n'))
writeFileSync(databasePath, '')

console.log(`Wrote .env.e2e and an empty ${databasePath} for ${appUrl}.`)
