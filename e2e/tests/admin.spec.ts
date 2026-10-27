import { expect, test, type Page } from '@playwright/test';

/** Name of the PHP session cookie (session_name() default, not overridden in config). */
const SESSION_COOKIE = 'PHPSESSID';

async function signIn(page: Page, email: string, password: string) {
	await page.goto('/admin/sign-in');
	await page.getByTestId('sign-in-email').fill(email);
	await page.getByTestId('sign-in-password').fill(password);
	await page.getByTestId('sign-in-submit').click();
}

test('the administration requires signing in and returns to it afterwards', async ({ page }) => {
	await page.goto('/admin?x=1');
	await expect(page).toHaveURL(/\/admin\/sign-in\?.*backlink=/);
	await expect(page.getByTestId('sign-in-form')).toBeVisible();
	await expect(page.getByTestId('flash')).toHaveText('Pro pokračování se přihlaste.');

	await page.getByTestId('sign-in-email').fill('admin@example.com');
	await page.getByTestId('sign-in-password').fill('demo-admin-password');
	await page.getByTestId('sign-in-submit').click();
	await expect(page).toHaveURL(/\/admin\?x=1$/);
	await expect(page.getByTestId('admin-title')).toBeVisible();
	await expect(page.getByTestId('admin-user')).toHaveText('Demo Admin');
	await expect(page.getByTestId('demo-banner')).toHaveText(
		'Běží demo data – účty se známým heslem, nepoužívat na ostrém webu.',
	);
});

// Relies on migrations:reset before the run: failures are rate-limited per e-mail+IP pair (5 / 15 min).
test('a wrong password shows an error and does not sign in', async ({ page }) => {
	await signIn(page, 'nobody@example.com', 'wrong-password-123');
	await expect(page.getByTestId('form-error')).toHaveText('Nesprávný e-mail nebo heslo.');
	await expect(page.getByTestId('sign-in-form')).toBeVisible();
});

test('signing out returns to the sign-in page and the administration requires signing in again', async ({ page }) => {
	await signIn(page, 'editor@example.com', 'demo-editor-password');
	await expect(page.getByTestId('admin-user')).toHaveText('Demo Editor');

	await page.getByTestId('sign-out').click();
	await expect(page.getByTestId('sign-in-form')).toBeVisible();
	await expect(page.getByTestId('flash')).toHaveText('Byli jste odhlášeni.');

	await page.goto('/admin');
	await expect(page).toHaveURL(/\/admin\/sign-in/);
});

test('public pages set no session cookie', async ({ page, context }) => {
	for (const path of ['/', '/o-nas', '/o-nas/', '/neexistuje']) {
		await page.goto(path);
	}
	const names = (await context.cookies()).map((cookie) => cookie.name);
	expect(names.filter((name) => name !== '_nss')).toEqual([]);
});

test('the session cookie lasts for the browser session only and is not readable by scripts', async ({ page, context }) => {
	await signIn(page, 'admin@example.com', 'demo-admin-password');
	await expect(page.getByTestId('admin-user')).toBeVisible();

	const cookie = (await context.cookies()).find((c) => c.name === SESSION_COOKIE);
	expect(cookie).toBeDefined();
	expect(cookie!.expires).toBe(-1);
	expect(cookie!.httpOnly).toBe(true);
	expect(cookie!.sameSite).toBe('Lax');
});
