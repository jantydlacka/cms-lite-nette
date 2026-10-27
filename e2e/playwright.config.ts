import { defineConfig } from '@playwright/test';

export default defineConfig({
	testDir: './tests',
	// The news tests share one database and count its items.
	workers: 1,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080',
	},
	projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
