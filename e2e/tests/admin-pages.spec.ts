import { expect, test, type Page } from '@playwright/test';

// Runs against production mode with the demo data loaded. Every test creates its own pages with a unique suffix.

let counter = 0;
function unique(prefix: string): { title: string; slug: string } {
	const suffix = `${Date.now().toString(36)}${counter++}`;
	return { title: `${prefix} ${suffix}`, slug: `${prefix.toLowerCase()}-${suffix}` };
}

async function signIn(page: Page) {
	await page.goto('/admin/sign-in');
	await page.getByTestId('sign-in-email').fill('admin@example.com');
	await page.getByTestId('sign-in-password').fill('demo-admin-password');
	await page.getByTestId('sign-in-submit').click();
	await expect(page.getByTestId('admin-user')).toBeVisible();
}

/** Creates a page on the top level through the form; returns the detail URL. */
async function createPage(
	page: Page,
	title: string,
	options: { slug?: string; content?: string; published?: boolean } = {},
): Promise<string> {
	await page.goto('/admin/pages/new');
	await page.getByTestId('page-title-input').fill(title);
	if (options.slug !== undefined) {
		await page.getByTestId('page-slug-input').fill(options.slug);
	}
	await page.getByTestId('page-content-input').fill(options.content ?? `Obsah ${title}`);
	await page.getByTestId('page-status-input').selectOption(options.published === false ? 'draft' : 'published');
	await page.getByTestId('page-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla vytvořena.');
	return page.url();
}

function treeRow(page: Page, title: string) {
	return page.getByTestId('pages-item').filter({ has: page.getByTestId('pages-item-title').filter({ hasText: title }) });
}

test.beforeEach(async ({ page }) => {
	await signIn(page);
});

test('a page created with an empty slug is published on a path derived from its title', async ({ page }) => {
	const { title, slug } = unique('Novinka');
	await createPage(page, title);
	await expect(page.getByTestId('page-slug-input')).toHaveValue(slug);

	const response = await page.goto(`/${slug}`);
	expect(response?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText(title);
});

test('a draft page is not public', async ({ page }) => {
	const { title, slug } = unique('Koncept');
	await createPage(page, title, { published: false });
	const response = await page.goto(`/${slug}`);
	expect(response?.status()).toBe(404);
});

test('changing the slug redirects the old path with 301 to the new one', async ({ page }) => {
	const { title, slug } = unique('Adresa');
	await createPage(page, title);
	const newSlug = `${slug}-nova`;

	await page.getByTestId('page-slug-input').fill(newSlug);
	await page.getByTestId('page-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla uložena.');

	const old = await page.request.get(`/${slug}`, { maxRedirects: 0 });
	expect(old.status()).toBe(301);
	expect(old.headers()['location']).toMatch(new RegExp(`/${newSlug}$`));
	const current = await page.goto(`/${newSlug}`);
	expect(current?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText(title);
});

test('the preview renders the content and saves nothing', async ({ page }) => {
	const { title, slug } = unique('Nahled');
	const detail = await createPage(page, title, { content: 'Původní obsah' });

	await page.getByTestId('page-content-input').fill('# Náhledový nadpis\n\nodstavec');
	await page.getByTestId('page-preview').click();
	await expect(page.getByTestId('page-preview-output').getByRole('heading', { name: 'Náhledový nadpis' })).toBeVisible();

	await page.goto(detail);
	await expect(page.getByTestId('page-content-input')).toHaveValue('Původní obsah');
	await page.goto(`/${slug}`);
	await expect(page.getByTestId('page-content')).not.toContainText('Náhledový nadpis');
	await expect(page.getByTestId('page-content')).toContainText('Původní obsah');
});

test('moving a page up and down changes the order of the public menu', async ({ page }) => {
	const first = unique('Prvni');
	const second = unique('Druha');
	await createPage(page, first.title);
	await createPage(page, second.title);

	const menuTitles = async () => {
		await page.goto('/');
		return page.getByTestId('menu-item').allTextContents();
	};
	const order = (titles: string[]) => [first.title, second.title].map((t) => titles.findIndex((x) => x.includes(t)));

	let [a, b] = order(await menuTitles());
	expect(a).toBeGreaterThanOrEqual(0);
	expect(b).toBeGreaterThanOrEqual(0);
	expect(a).toBeLessThan(b);

	await page.goto('/admin/pages');
	await treeRow(page, second.title).getByTestId('pages-up').click();
	await expect(page.getByTestId('flash')).toHaveText('Pořadí bylo změněno.');
	[a, b] = order(await menuTitles());
	expect(a).toBeGreaterThanOrEqual(0);
	expect(b).toBeGreaterThanOrEqual(0);
	expect(b).toBeLessThan(a);

	await page.goto('/admin/pages');
	await treeRow(page, second.title).getByTestId('pages-down').click();
	await expect(page.getByTestId('flash')).toHaveText('Pořadí bylo změněno.');
	[a, b] = order(await menuTitles());
	expect(a).toBeGreaterThanOrEqual(0);
	expect(b).toBeGreaterThanOrEqual(0);
	expect(a).toBeLessThan(b);
});

test('deleting a page needs a confirmation and makes its path 404', async ({ page }) => {
	const { title, slug } = unique('Smazat');
	await createPage(page, title);
	expect((await page.goto(`/${slug}`))?.status()).toBe(200);

	await page.goto('/admin/pages');
	await treeRow(page, title).getByTestId('pages-item-title').click();
	await page.getByTestId('page-delete').click();
	await expect(page.getByTestId('page-delete-question')).toContainText(title);

	await page.getByTestId('page-delete-back').click();
	expect((await page.goto(`/${slug}`))?.status()).toBe(200);

	await page.goto('/admin/pages');
	await treeRow(page, title).getByTestId('pages-item-title').click();
	await page.getByTestId('page-delete').click();
	await page.getByTestId('page-delete-confirm').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla smazána.');
	await expect(page.getByTestId('pages-item-title').filter({ hasText: title })).toHaveCount(0);
	expect((await page.goto(`/${slug}`))?.status()).toBe(404);
});

test('after a slug change and a delete the old path is 404 too, not a redirect', async ({ page }) => {
	const { title, slug } = unique('Prejmenovat');
	await createPage(page, title);
	const newSlug = `${slug}-nova`;
	await page.getByTestId('page-slug-input').fill(newSlug);
	await page.getByTestId('page-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla uložena.');
	expect((await page.request.get(`/${slug}`, { maxRedirects: 0 })).status()).toBe(301);

	await page.getByTestId('page-delete').click();
	await page.getByTestId('page-delete-confirm').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla smazána.');

	expect((await page.request.get(`/${slug}`, { maxRedirects: 0 })).status()).toBe(404);
	expect((await page.request.get(`/${newSlug}`, { maxRedirects: 0 })).status()).toBe(404);
});

test('a page in the menu under a parent outside the menu gets a note at the checkbox', async ({ page }) => {
	const parent = unique('Skryty');
	const child = unique('Podstranka');
	const parentDetail = await createPage(page, parent.title);
	await page.getByTestId('page-in-menu').uncheck();
	await page.getByTestId('page-properties-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla uložena.');
	await expect(page.getByTestId('page-menu-hidden-note')).toHaveCount(0);

	await page.goto('/admin/pages/new');
	await page.getByTestId('page-parent').selectOption({ label: parent.title });
	await page.getByTestId('page-title-input').fill(child.title);
	await page.getByTestId('page-status-input').selectOption('published');
	await page.getByTestId('page-save').click();
	await expect(page.getByTestId('flash')).toHaveText('Stránka byla vytvořena.');

	const note = page.getByTestId('page-menu-hidden-note');
	await expect(note).toHaveText('V menu se nezobrazí, protože nadřazená stránka v menu není.');
	await expect(page.getByTestId('page-in-menu')).toHaveAccessibleDescription('V menu se nezobrazí, protože nadřazená stránka v menu není.');
	await page.goto(parentDetail);
	await expect(page.getByTestId('page-menu-hidden-note')).toHaveCount(0);
});

test('saving a page deleted in another tab keeps the typed text with a message', async ({ page, context }) => {
	const { title, slug } = unique('Mazana');
	// The URL after creating carries the flash parameter.
	const detail = new URL(await createPage(page, title)).pathname;
	await page.getByTestId('page-content-input').fill('Rozepsaný text, který nesmí zmizet');

	const other = await context.newPage();
	await other.goto(`${detail}/delete`);
	await other.getByTestId('page-delete-confirm').click();
	await expect(other.getByTestId('flash')).toHaveText('Stránka byla smazána.');
	await other.close();

	await page.getByTestId('page-save').click();
	await expect(page.getByTestId('form-error')).toHaveText('Stránka mezitím byla smazána. Text zůstal ve formuláři – zkopírujte si ho.');
	await expect(page.getByTestId('page-content-input')).toHaveValue('Rozepsaný text, který nesmí zmizet');
	expect((await page.request.get(detail)).status()).toBe(404);
	expect((await page.request.get(`/${slug}`)).status()).toBe(404);
});

test('the homepage cannot be deleted and the reason is shown', async ({ page }) => {
	await page.goto('/admin/pages');
	const home = page.getByTestId('pages-item').filter({ has: page.getByTestId('pages-homepage-badge') });
	await home.getByTestId('pages-item-title').click();
	await page.getByTestId('page-delete').click();
	await expect(page.getByTestId('page-delete-reason')).toHaveText(
		'Úvodní stránku nejde smazat ani skrýt, dokud nevyberete jinou.',
	);
	await expect(page.getByTestId('page-delete-confirm')).toHaveCount(0);
	expect((await page.goto('/'))?.status()).toBe(200);
});

test('the English translation of the homepage is public at /en/', async ({ page }) => {
	const response = await page.goto('/en/');
	expect(response?.status()).toBe(200);
	await expect(page.getByTestId('page-title')).toHaveText('Welcome');
});
