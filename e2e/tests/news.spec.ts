import { expect, test } from '@playwright/test';

// Runs against production mode with the demo data loaded (12 Czech news items, 3 of them also English,
// one draft and one scheduled item that must never be public).

test('the news list shows the newest items and leads to the second page and back', async ({ page }) => {
	const response = await page.goto('/novinky');
	expect(response?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText('Novinky');
	await expect(page.getByTestId('news-item')).toHaveCount(10);
	await expect(page.getByTestId('news-newer')).toHaveCount(0);
	await expect(page.getByTestId('news-pagination')).toContainText('Strana 1 z 2');
	await expect(page.getByTestId('menu-news')).toHaveAttribute('aria-current', 'page');

	await page.getByTestId('news-older').click();
	await expect(page).toHaveURL(/\/novinky\?strana=2$/);
	await expect(page.getByTestId('news-item')).toHaveCount(2);
	await expect(page.getByTestId('news-older')).toHaveCount(0);

	await page.getByTestId('news-newer').click();
	await expect(page).toHaveURL(/\/novinky$/);
	await expect(page.getByTestId('news-item')).toHaveCount(10);
});

test('the list shows neither the draft nor the scheduled item', async ({ page }) => {
	await page.goto('/novinky');
	const first = await page.getByTestId('news-item-title').allTextContents();
	await page.goto('/novinky?strana=2');
	const second = await page.getByTestId('news-item-title').allTextContents();
	const titles = [...first, ...second];
	expect(titles).toHaveLength(12);
	expect(titles).not.toContain('Rozpracovaná novinka');
	expect(titles).not.toContain('Připravovaná novinka');
});

test('a news detail shows the item and links back to the list', async ({ page }) => {
	await page.goto('/novinky');
	const item = page.getByTestId('news-item').first();
	const title = (await item.getByTestId('news-item-title').textContent()) ?? '';
	await item.getByTestId('news-item-title').click();

	await expect(page).toHaveURL(/\/novinky\/[a-z0-9-]+$/);
	await expect(page.getByTestId('page-title')).toHaveText(title);
	await expect(page.getByTestId('news-detail-date')).toBeVisible();
	await expect(page.getByTestId('page-content')).toBeVisible();
	await expect(page.getByTestId('menu-news')).toHaveAttribute('aria-current', 'page');

	await page.getByTestId('news-back').click();
	await expect(page).toHaveURL(/\/novinky$/);
	await expect(page.getByTestId('news-list')).toBeVisible();
});

test('the English list has the three translated items', async ({ page }) => {
	const response = await page.goto('/en/news');
	expect(response?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText('News');
	await expect(page.getByTestId('news-item')).toHaveCount(3);
	await expect(page.getByTestId('news-pagination')).toHaveCount(0);
});

test('pages out of range, a bad page value and the first page are handled', async ({ page }) => {
	for (const path of ['/novinky?strana=0', '/novinky?strana=3', '/novinky?strana=abc']) {
		const response = await page.request.get(path, { maxRedirects: 0 });
		expect(response.status(), path).toBe(404);
	}
	const first = await page.request.get('/novinky?strana=1', { maxRedirects: 0 });
	expect(first.status()).toBe(301);
	expect(first.headers()['location']).toMatch(/\/novinky$/);
});

test('a segment of another language is 404', async ({ page }) => {
	for (const path of ['/news', '/en/novinky']) {
		const response = await page.request.get(path, { maxRedirects: 0 });
		expect(response.status(), path).toBe(404);
	}
});
