import { expect, test, type Page } from '@playwright/test';

// Runs against production mode with the demo data loaded: the homepage of both languages ends with
// "::: novinky 3". The administration tests save nothing that stays behind.

async function signIn(page: Page) {
	await page.goto('/admin/sign-in');
	await page.getByTestId('sign-in-email').fill('admin@example.com');
	await page.getByTestId('sign-in-password').fill('demo-admin-password');
	await page.getByTestId('sign-in-submit').click();
	await expect(page.getByTestId('admin-user')).toBeVisible();
}

test('the Czech homepage shows the three latest news and a link to the list', async ({ page }) => {
	await page.goto('/');
	const component = page.getByTestId('component-news');
	await expect(component.getByTestId('component-news-item')).toHaveCount(3);
	await expect(component.getByTestId('news-item-title').first()).toBeVisible();
	await expect(component.getByTestId('news-item-date').first()).toBeVisible();
	await expect(component.getByTestId('news-item-perex').first()).toBeVisible();
	await expect(page.getByTestId('component-error')).toHaveCount(0);

	await page.getByTestId('component-news-all').click();
	await expect(page).toHaveURL(/\/novinky$/);
	await expect(page.getByTestId('page-title')).toHaveText('Novinky');
});

test('the English homepage shows only English news and links to the English list', async ({ page }) => {
	await page.goto('/en/');
	const component = page.getByTestId('component-news');
	await expect(component.getByTestId('component-news-item')).toHaveCount(3);

	const english: string[] = [];
	await page.goto('/en/news');
	english.push(...(await page.getByTestId('news-item-title').allTextContents()));
	await page.goto('/en/');
	for (const title of await component.getByTestId('news-item-title').allTextContents()) {
		expect(english).toContain(title);
	}

	await page.getByTestId('component-news-all').click();
	await expect(page).toHaveURL(/\/en\/news$/);
});

test.describe('administration', () => {
	test.beforeEach(async ({ page }) => {
		await signIn(page);
	});

	test('a misspelled component is refused with its line and the text stays', async ({ page }) => {
		const content = 'Úvodní odstavec\n\n::: novinkyy';
		await page.goto('/admin/pages/new');
		await page.getByTestId('page-title-input').fill('Chybná komponenta');
		await page.getByTestId('page-content-input').fill(content);
		await page.getByTestId('page-status-input').selectOption('draft');
		await page.getByTestId('page-save').click();

		await expect(page.getByTestId('flash')).toHaveCount(0);
		await expect(page.getByText('Řádek 3: neznámá komponenta „novinkyy“. Dostupné: novinky.')).toBeVisible();
		await expect(page.getByTestId('page-content-input')).toHaveValue(content);
		await expect(page.getByTestId('page-content-input')).toHaveAttribute('aria-invalid', 'true');
	});

	test('the preview shows the news of a valid marker and the problem of a broken one', async ({ page }) => {
		// The preview exists only on a saved page; the page is deleted at the end.
		await page.goto('/admin/pages/new');
		await page.getByTestId('page-title-input').fill(`Náhled komponenty ${Date.now().toString(36)}`);
		await page.getByTestId('page-content-input').fill('Text');
		await page.getByTestId('page-status-input').selectOption('draft');
		await page.getByTestId('page-save').click();
		await expect(page.getByTestId('flash')).toHaveText('Stránka byla vytvořena.');

		await page.getByTestId('page-content-input').fill('Text\n\n::: novinky 2');
		await page.getByTestId('page-preview').click();
		const output = page.getByTestId('page-preview-output');
		await expect(output.getByTestId('component-news-item')).toHaveCount(2);
		await expect(output.getByTestId('component-news-all')).toBeVisible();

		await page.getByTestId('page-content-input').fill('Text\n\n::: novinky 0');
		await page.getByTestId('page-preview').click();
		await expect(page.getByTestId('page-preview-output').getByTestId('component-error')).toContainText('Řádek 3');
		await expect(page.getByTestId('page-preview-output').getByTestId('component-news')).toHaveCount(0);

		await page.getByTestId('page-delete').click();
		await page.getByTestId('page-delete-confirm').click();
	});
});
