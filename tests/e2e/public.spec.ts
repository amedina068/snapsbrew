import { expect, test } from './fixtures'

test('a guest who opens the home page gets the sign-in page', async ({ page }) => {
  await page.goto('/')

  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Create an account' })).toBeVisible()
})

test('a guest can read the API documentation', async ({ page }) => {
  await page.goto('/documentation')

  await expect(page.getByRole('heading', { level: 1, name: 'Snapsbrew API' })).toBeVisible()
})
