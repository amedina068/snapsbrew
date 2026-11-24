import { expect, test, waitForVue } from './fixtures'

// The spec stops before "Finish". Finish queues a sample render, and a render
// needs the browserless service, which the suite does not run.
test('a visitor creates an account and starts the onboarding wizard', async ({ page }) => {
  const email = `new-user-${Date.now()}@example.test`

  await page.goto('/login')
  await page.getByRole('link', { name: 'Create an account' }).click()

  await expect(page.getByRole('heading', { name: 'Create your account' })).toBeVisible()
  await waitForVue(page)
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password').fill('e2e-Password-123!')
  await page.getByRole('button', { name: 'Create free account' }).click()

  await expect(page).toHaveURL(/\/onboarding$/)
  // Vue renders this heading, so the app is mounted from here on.
  await expect(page.getByRole('heading', { name: 'Welcome aboard' })).toBeVisible()

  await page.getByLabel('Your name').fill('New E2E User')
  await page.getByLabel('Company name').fill('New E2E Diner')
  await page.getByRole('button', { name: 'Continue' }).click()

  await expect(page.getByRole('heading', { name: 'What are you making?' })).toBeVisible()
})
