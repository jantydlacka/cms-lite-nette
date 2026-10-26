import { expect, test } from '@playwright/test';

// Runs against production mode with the demo data loaded (see spec/urls.md).

test('menu navigation opens a page on its flat path', async ({ page }) => {
	await page.goto('/');
	await page.getByTestId('menu-item').filter({ hasText: 'O nás' }).click();
	await expect(page).toHaveURL(/\/o-nas$/);
	await expect(page.getByTestId('page-title')).toHaveText('O nás');
	await expect(page.getByTestId('page-content')).toBeVisible();
});

test('a nested page shows breadcrumbs', async ({ page }) => {
	await page.goto('/tym');
	await expect(page.getByTestId('page-title')).toHaveText('Tým');
	const breadcrumbs = page.getByTestId('breadcrumbs');
	await expect(breadcrumbs).toContainText('O nás');
	await expect(breadcrumbs).toContainText('Tým');
});

test('an old path redirects to the current page', async ({ page }) => {
	await page.goto('/o-firme');
	await expect(page).toHaveURL(/\/o-nas$/);
	await expect(page.getByTestId('page-title')).toHaveText('O nás');
});

test('an unknown path answers 404 with the error page', async ({ page }) => {
	const response = await page.goto('/neexistuje');
	expect(response?.status()).toBe(404);
	await expect(page.getByTestId('error-title')).toHaveText('Stránka nenalezena');
});

test('a translated page renders in its language', async ({ page }) => {
	const response = await page.goto('/en/about-us');
	expect(response?.status()).toBe(200);
	await expect(page.locator('html')).toHaveAttribute('lang', 'en');
	await expect(page.getByTestId('page-title')).toHaveText('About us');
});

test('a page does not fall back to another language', async ({ page }) => {
	const response = await page.goto('/en/o-nas');
	expect(response?.status()).toBe(404);
});

test('health check answers ok', async ({ request }) => {
	const response = await request.get('/_health');
	expect(response.status()).toBe(200);
	expect(await response.text()).toBe('ok');
});
