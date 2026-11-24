const { execFileSync } = require('child_process')
const { readFileSync } = require('fs')
const path = require('path')
const puppeteer = require('puppeteer')

const root = path.resolve(__dirname, '../..')

const fail = message => {
  throw new Error(`Editor smoke test failed: ${message}`)
}

const appUrl = () => {
  if (process.env.EDITOR_BASE_URL) {
    return process.env.EDITOR_BASE_URL
  }

  const env = readFileSync(path.join(root, '.env'), 'utf8')
  const match = env.match(/^APP_URL=(.+)$/m)

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

const buttonWithText = async (page, text) => {
  const buttons = await page.$$('button')

  for (const button of buttons) {
    const label = await button.evaluate(element => element.textContent.trim())
    if (label.includes(text)) {
      return button
    }
  }

  return null
}

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
    await page.setViewport({ width: 1440, height: 1000 })

    await page.goto(new URL('/login', baseUrl).toString(), { waitUntil: 'networkidle2' })
    await page.type('#email', user.email)
    await page.type('#password', user.password)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('button[type="submit"]'),
    ])

    await page.goto(new URL(`/templates/${user.template_uuid}/editor`, baseUrl).toString(), {
      waitUntil: 'networkidle2',
    })
    await page.waitForSelector('#editor-content', { visible: true })

    const addLayer = await buttonWithText(page, 'Add layer')
    if (!addLayer) {
      fail('the Add layer control is unavailable')
    }

    await addLayer.focus()
    const focusedText = await page.evaluate(() => document.activeElement.textContent.trim())
    if (focusedText !== 'Add layer') {
      fail('the Add layer control cannot receive keyboard focus')
    }

    await addLayer.click()
    await page.waitForFunction(() => Array.from(document.querySelectorAll('button'))
      .some(button => button.textContent.includes('Text layer')))

    const textLayer = await buttonWithText(page, 'Text layer')
    if (!textLayer) {
      fail('the text-layer option is unavailable')
    }
    await textLayer.click()

    await page.waitForSelector('#text', { visible: true })
    await page.click('#text', { clickCount: 3 })
    await page.keyboard.type('Browser smoke content')
    await page.keyboard.press('Tab')

    const textContent = await page.$eval('#text', element => element.value)
    if (textContent !== 'Browser smoke content') {
      fail(`the text inspector lost its edit (${JSON.stringify(textContent)})`)
    }
  } finally {
    await browser.close()
  }
}

run().then(
  () => console.log('Editor browser smoke test passed.'),
  error => {
    console.error(error.stack || error.message)
    process.exitCode = 1
  }
)
