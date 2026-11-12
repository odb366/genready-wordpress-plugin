import { test, expect } from '@playwright/test';
import { API_BASE, anonSeed, helper, login, openGenReady } from '../lib/helpers';

test.describe.configure({ mode: 'serial' });

test('a loopback page: the server refuses it and nothing is stored', async ({ page }) => {
	anonSeed('reset');
	await login(page);
	const tools = await helper(page);
	await tools.deleteOption('genready_anonymous_scans');
	await openGenReady(page);
	await page.getByRole('button', { name: 'Check AI readiness' }).click();
	await expect(page.locator('#genready-scan-result')).toContainText('GenReady can check only pages that anyone can open on the internet.');
	expect(await tools.options('genready_anonymous_scans')).toEqual([]);
});

test('an address on another site: refused in WordPress with no call', async ({ page }) => {
	await login(page);
	const tools = await helper(page);
	await openGenReady(page);
	await tools.resetHttpLog();
	await page.getByLabel('Page to analyze').fill('https://example.org/');
	await page.getByRole('button', { name: 'Check AI readiness' }).click();
	const result = page.locator('#genready-scan-result');
	await expect(result.locator('p').first()).toHaveText('Enter an address on this site.');
	// The next step for another site (Kris 2026-11-12): genready.ai with the address filled in.
	await expect(result.getByRole('link', { name: 'Check another site on genready.ai' })).toHaveAttribute('href', `${API_BASE}/?url=https%3A%2F%2Fexample.org%2F`);
	expect((await tools.httpLog()).filter((entry) => entry.url.startsWith(API_BASE))).toEqual([]);
});

test('the 4th scan of a day: the limit text', async ({ page }) => {
	anonSeed('limit', 'wpscan-host:127.0.0.1', '3');
	await login(page);
	await openGenReady(page);
	await page.getByRole('button', { name: 'Check AI readiness' }).click();
	await expect(page.locator('#genready-scan-result')).toHaveText(/^(You used today's free scans|Free scans start again at .+)\. Create a free account for 25 scans a month\.$/);
	anonSeed('reset');
});
