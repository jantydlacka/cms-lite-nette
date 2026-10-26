import { expect, test, type Page } from '@playwright/test';

const theme = (page: Page) => page.evaluate(() => document.documentElement.dataset.theme);

/** Records data-theme at DOMContentLoaded, i.e. in the first rendered DOM. */
async function recordThemeAtDomReady(page: Page): Promise<Array<string | undefined>> {
	const seen: Array<string | undefined> = [];
	await page.exposeFunction('recordTheme', (value: string | undefined) => seen.push(value));
	await page.addInitScript(() => {
		document.addEventListener('DOMContentLoaded', () => {
			(window as any).recordTheme(document.documentElement.dataset.theme);
		});
	});
	return seen;
}

test('site name is visible', async ({ page }) => {
	await page.goto('/');
	await expect(page.getByTestId('site-name')).toBeVisible();
});

test('theme toggle changes the theme and the choice survives a reload', async ({ page }) => {
	await page.goto('/');
	const before = await theme(page);
	await page.getByTestId('theme-toggle').click();
	const after = await theme(page);
	expect(after).not.toBe(before);

	await page.reload();
	expect(await theme(page)).toBe(after);
});

test('toggle switches dark to light and keeps aria-pressed in sync', async ({ page }) => {
	await page.goto('/');
	await page.evaluate(() => localStorage.setItem('cms-theme', 'dark'));
	await page.reload();
	const toggle = page.getByTestId('theme-toggle');
	expect(await theme(page)).toBe('dark');
	await expect(toggle).toHaveAttribute('aria-pressed', 'true');

	await toggle.click();
	expect(await theme(page)).toBe('light');
	await expect(toggle).toHaveAttribute('aria-pressed', 'false');

	await page.reload();
	expect(await theme(page)).toBe('light');
});

test.describe('localStorage unavailable', () => {
	test.use({ colorScheme: 'dark' });

	test('toggle works, initial theme follows the preference, nothing throws', async ({ page }) => {
		const errors: string[] = [];
		page.on('pageerror', (error) => errors.push(error.stack ?? error.message));
		page.on('console', (message) => {
			if (message.type() === 'error') {
				errors.push(message.text());
			}
		});
		await page.addInitScript(() => {
			const fail = () => {
				throw new Error('storage disabled');
			};
			Storage.prototype.getItem = fail;
			Storage.prototype.setItem = fail;
		});

		await page.goto('/');
		expect(await theme(page)).toBe('dark');
		await page.getByTestId('theme-toggle').click();
		expect(await theme(page)).toBe('light');
		await page.waitForTimeout(300);

		// The debug bar (dev mode only) is not ours and does not guard its own storage access.
		expect(errors.filter((message) => !message.includes('_tracy_bar'))).toEqual([]);
	});
});

test.describe('dark preference without a saved choice', () => {
	test.use({ colorScheme: 'dark' });

	test('data-theme is dark in the first rendered DOM', async ({ page }) => {
		const seen = await recordThemeAtDomReady(page);
		await page.goto('/');
		await expect.poll(() => seen.length).toBeGreaterThan(0);
		expect(seen[0]).toBe('dark');
	});

	test('an invalid stored value falls back to the preference', async ({ page }) => {
		await page.goto('/');
		await page.evaluate(() => localStorage.setItem('cms-theme', 'blue'));
		const seen = await recordThemeAtDomReady(page);
		await page.reload();
		await expect.poll(() => seen.length).toBeGreaterThan(0);
		expect(seen[0]).toBe('dark');
	});
});

test('saved dark choice is applied at domcontentloaded after reload', async ({ page }) => {
	await page.goto('/');
	await page.evaluate(() => localStorage.setItem('cms-theme', 'dark'));

	const seen = await recordThemeAtDomReady(page);
	await page.reload();
	await expect.poll(() => seen.length).toBeGreaterThan(0);
	expect(seen[0]).toBe('dark');
});
