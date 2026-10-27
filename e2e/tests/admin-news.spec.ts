import { expect, test, type Page } from '@playwright/test';

// Runs against production mode with the demo data loaded. Every test creates its own news item with a unique suffix.

let counter = 0;
const created: string[] = [];
function unique(prefix: string): { title: string; slug: string } {
	const suffix = `${Date.now().toString(36)}${counter++}`;
	return { title: `${prefix} ${suffix}`, slug: `${prefix.toLowerCase()}-${suffix}` };
}

/** `datetime-local` value `days` from now; the exact time of day does not matter for the checks. */
function localDateTime(days: number): string {
	const date = new Date(Date.now() + days * 86_400_000);
	const pad = (n: number) => String(n).padStart(2, '0');
	return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T12:00`;
}

async function signIn(page: Page) {
	await page.goto('/admin/sign-in');
	await page.getByTestId('sign-in-email').fill('admin@example.com');
	await page.getByTestId('sign-in-password').fill('demo-admin-password');
	await page.getByTestId('sign-in-submit').click();
	await expect(page.getByTestId('admin-user')).toBeVisible();
}

/** Creates a published news item through the form; returns its detail URL. */
async function createNews(page: Page, title: string, publishedAt: string, slug?: string): Promise<string> {
	await page.goto('/admin/news/new');
	await page.getByTestId('news-published-at').fill(publishedAt);
	await page.getByTestId('news-title-input').fill(title);
	if (slug !== undefined) {
		await page.getByTestId('news-slug-input').fill(slug);
	}
	await page.getByTestId('news-perex-input').fill(`Perex ${title}`);
	await page.getByTestId('news-content-input').fill(`Obsah ${title}`);
	await page.getByTestId('news-status-input').selectOption('published');
	await page.getByTestId('news-save').click();
	// Registered for cleanup as soon as the item exists, before any assertion can fail.
	await page.waitForURL(/\/admin\/news\/\d+(\?|$)/);
	const detail = new URL(page.url()).pathname;
	created.push(detail);
	await expect(page.getByTestId('flash')).toHaveText('Novinka byla vytvořena.');
	return detail;
}

test.beforeEach(async ({ page }) => {
	await signIn(page);
});

// The public list tests count the demo items, so items created here are removed again.
test.afterEach(async ({ page }) => {
	for (const detail of created.splice(0)) {
		await page.goto(`${detail}/delete`);
		await page.getByTestId('news-delete-confirm').click({ timeout: 5_000 });
		await expect(page.getByTestId('flash')).toHaveText('Novinka byla smazána.');
	}
});

test('a news item scheduled in the future is labelled and 404 on the website until its date is in the past', async ({ page }) => {
	const { title, slug } = unique('Plan');
	await createNews(page, title, localDateTime(10));
	await expect(page.getByTestId('news-slug-input')).toHaveValue(slug);
	await expect(page.getByTestId('news-scheduled-note')).toBeVisible();

	await page.goto('/admin/news');
	const row = page.getByTestId('admin-news-item').filter({ hasText: title });
	await expect(row.getByTestId('admin-news-state').first()).toContainText('Naplánováno na');
	expect((await page.request.get(`/novinky/${slug}`, { maxRedirects: 0 })).status()).toBe(404);

	await row.getByTestId('admin-news-item-title').click();
	await page.getByTestId('news-published-at').fill(localDateTime(-1));
	await page.getByTestId('news-properties-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Novinka byla uložena.');
	await expect(page.getByTestId('news-scheduled-note')).toHaveCount(0);

	const response = await page.goto(`/novinky/${slug}`);
	expect(response?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText(title);
});

test('changing the slug redirects the old address with 301 to the new one', async ({ page }) => {
	const { title, slug } = unique('Adresa');
	await createNews(page, title, localDateTime(-1));
	const newSlug = `${slug}-nova`;

	await page.getByTestId('news-slug-input').fill(newSlug);
	await page.getByTestId('news-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Novinka byla uložena.');

	const old = await page.request.get(`/novinky/${slug}`, { maxRedirects: 0 });
	expect(old.status()).toBe(301);
	expect(old.headers()['location']).toMatch(new RegExp(`/novinky/${newSlug}$`));
	const current = await page.goto(`/novinky/${newSlug}`);
	expect(current?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText(title);
});

test('deleting a news item makes its address and its old address 404', async ({ page }) => {
	const { title, slug } = unique('Smazat');
	const detail = await createNews(page, title, localDateTime(-1));
	const newSlug = `${slug}-nova`;
	await page.getByTestId('news-slug-input').fill(newSlug);
	await page.getByTestId('news-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Novinka byla uložena.');

	await page.getByTestId('news-delete').click();
	await page.getByTestId('news-delete-confirm').click();
	await expect(page.getByTestId('flash')).toHaveText('Novinka byla smazána.');
	created.splice(created.indexOf(detail), 1);

	expect((await page.request.get(`/novinky/${newSlug}`, { maxRedirects: 0 })).status()).toBe(404);
	expect((await page.request.get(`/novinky/${slug}`, { maxRedirects: 0 })).status()).toBe(404);
	expect((await page.request.get(detail, { maxRedirects: 0 })).status()).toBe(404);
});

test('the administration list shows the demo items with their states', async ({ page }) => {
	await page.goto('/admin/news');
	await expect(page.getByTestId('admin-nav-news')).toHaveAttribute('aria-current', 'page');
	const draft = page.getByTestId('admin-news-item').filter({ hasText: 'Rozpracovaná novinka' });
	await expect(draft.getByTestId('admin-news-state').first()).toContainText('Koncept');
	const scheduled = page.getByTestId('admin-news-item').filter({ hasText: 'Připravovaná novinka' });
	await expect(scheduled.getByTestId('admin-news-state').first()).toContainText('Naplánováno na');
});
