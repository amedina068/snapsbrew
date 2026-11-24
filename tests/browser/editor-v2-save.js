const { execFileSync } = require('child_process')
const { readFileSync } = require('fs')
const path = require('path')
const puppeteer = require('puppeteer')

const root = path.resolve(__dirname, '../..')

const fail = message => {
  throw new Error(`Editor v2 save test failed: ${message}`)
}

const appUrl = () => {
  if (process.env.EDITOR_BASE_URL) {
    return process.env.EDITOR_BASE_URL
  }

  const match = readFileSync(path.join(root, '.env'), 'utf8').match(/^APP_URL=(.+)$/m)

  if (!match) {
    fail('set EDITOR_BASE_URL or APP_URL in .env')
  }

  return match[1].trim().replace(/^['"]|['"]$/g, '')
}

const fixture = () => JSON.parse(execFileSync(
  'php',
  ['tests/browser/prepare-editor-smoke.php'],
  { cwd: root, encoding: 'utf8' }
))

// The API answers a save of a template with a v2 editor document with 409.
// Before this test, the Save button showed nothing and the browser logged an
// unhandled promise rejection.
const run = async () => {
  const baseUrl = appUrl()
  const user = fixture()
  const launchOptions = { headless: true }
  if (process.env.EDITOR_BROWSER_NO_SANDBOX === '1') {
    launchOptions.args = ['--no-sandbox']
  }
  const browser = await puppeteer.launch(launchOptions)

  try {
    const page = await browser.newPage()
    const unhandled = []
    page.on('pageerror', error => unhandled.push(error.message))

    await page.goto(new URL('/login', baseUrl).toString(), { waitUntil: 'networkidle2' })
    await page.type('#email', user.email)
    await page.type('#password', user.password)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('button[type="submit"]'),
    ])

    await page.goto(new URL(`/templates/${user.v2_template_uuid}/editor`, baseUrl).toString(), {
      waitUntil: 'networkidle2',
    })
    await page.waitForSelector('#editor-content', { visible: true })

    const save = await page.waitForFunction(() => Array.from(document.querySelectorAll('button'))
      .find(button => button.textContent.trim() === 'Save'))
    const [response] = await Promise.all([
      page.waitForResponse(candidate => candidate.request().method() === 'PATCH'
        && candidate.url().includes('/api/v1/templates/')),
      save.click(),
    ])

    if (response.status() !== 409) {
      fail(`the API answered ${response.status()}, not 409`)
    }

    const alert = await page.waitForSelector('[data-test="template-save-error"]', { visible: true, timeout: 5000 })
      .catch(() => fail('the editor showed no message after the 409'))
    const message = await alert.evaluate(element => element.textContent.trim())

    if (!message.includes('cannot save this template')) {
      fail(`the message does not explain the 409 (${JSON.stringify(message)})`)
    }

    if (unhandled.length > 0) {
      fail(`the page threw: ${unhandled.join('; ')}`)
    }
  } finally {
    await browser.close()
  }
}

run().then(
  () => console.log('Editor v2 save test passed.'),
  error => {
    console.error(error.stack || error.message)
    process.exitCode = 1
  }
)
