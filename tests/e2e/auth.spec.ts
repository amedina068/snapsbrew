import { expect, seededUser, signIn, test } from './fixtures'

test('a wrong password shows an error and keeps the user on the sign-in page', async ({ page }) => {
  await signIn(page, seededUser.email, 'not-the-password')

  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByText('These credentials do not match our records.')).toBeVisible()
})

test('the seeded user signs in and gets the dashboard', async ({ page }) => {
  await signIn(page)

  await expect(page).toHaveURL(/\/dashboard$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
})
