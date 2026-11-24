import { test as base, expect, type Page } from '@playwright/test'

// The values of database/seeders/E2eSeeder.php. The factory password is
// "password".
export const seededUser = {
  email: 'e2e@example.test',
  password: 'password',
}

export const starterTemplateTitle = 'E2E Starter'
export const ownTemplateTitle = 'E2E Seeded Template'

// Each page blocks every request to a host other than the app, for example
// Google Fonts. The suite then needs no network and calls no outside service.
export const test = base.extend({
  page: async ({ page, baseURL }, use) => {
    const appOrigin = new URL(baseURL!).origin
    await page.route((url) => url.origin !== appOrigin, (route) => route.abort())
    await use(page)
  },
})

// Every layout mounts one Vue app on #app, and the mount renders the server
// HTML again. A value typed before the mount is lost, so wait for the mount
// before the first keystroke. Vue sets data-v-app on the mounted element.
export async function waitForVue(page: Page) {
  await page.locator('#app[data-v-app]').waitFor({ state: 'attached' })
}

export async function signIn(page: Page, email = seededUser.email, password = seededUser.password) {
  await page.goto('/login')
  await waitForVue(page)
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

export { expect }
