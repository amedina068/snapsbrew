import { expect, ownTemplateTitle, signIn, starterTemplateTitle, test } from './fixtures'

// "Generate" on a template renders a snap through the browserless service, and
// the suite does not run that service. These specs stop before a render.
test('a signed-in user copies a starting template into the account', async ({ page }) => {
  await signIn(page)
  await expect(page).toHaveURL(/\/dashboard$/)

  await page.goto('/templates/new')
  await expect(page.getByRole('heading', { level: 1, name: 'New template' })).toBeVisible()
  await page.getByRole('button', { name: `Use ${starterTemplateTitle}` }).click()

  await expect(page).toHaveURL(/\/templates\/[0-9a-f-]{36}$/)
  await expect(page.getByRole('heading', { level: 1, name: starterTemplateTitle })).toBeVisible()
  await expect(page.getByText('1200 × 630')).toBeVisible()

  await page.goto('/templates')
  await expect(page.getByRole('link', { name: starterTemplateTitle, exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: ownTemplateTitle, exact: true })).toBeVisible()
})
