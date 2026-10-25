import { test, expect } from '@playwright/test';
import { API_BASE, SITE, helper, login, openGenReady } from '../lib/helpers';

test('first screen: heading, page field and list, consent, Connect link, and no call', async ({ page }) => {
	await login(page);
	const tools = await helper(page);
	await tools.deleteOption('genready_anonymous_scans');
	await tools.resetHttpLog();

	await page.goto('/wp-admin/plugins.php');
	await expect(page.locator('.genready-notice')).toContainText('See how AI search engines and agents read your site. No account needed.');

	await openGenReady(page);
	await expect(page.getByRole('heading', { name: 'See how AI search engines and agents read your site' })).toBeVisible();
	const field = page.getByLabel('Page to analyze');
	await expect(field).toHaveAttribute('type', 'url');
	await expect(field).toHaveValue(`${SITE}/`);
	const options = await page.locator('#genready-pages option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
	expect(options).toEqual(expect.arrayContaining([`${SITE}/sample-page/`, `${SITE}/hello-world/`]));
	expect(options.length).toBeLessThanOrEqual(50);
	const start = page.locator('.genready-start');
	await expect(start).toContainText('Sends your site address to genready.ai. No account needed.');
	await expect(start).toContainText("GenReady's Terms and Privacy Policy apply.");
	await expect(page.locator('#genready-account')).toHaveCount(0);

	const link = page.getByRole('button', { name: 'Already have a GenReady account? Connect' });
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeHidden();
	await link.click();
	await expect(link).toHaveAttribute('aria-expanded', 'true');
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
	await expect(page.locator('#genready-send-email')).not.toBeChecked();

	// Opening the page and the form made no call to GenReady (spec 3.2).
	const log = await tools.httpLog();
	expect(log.filter((entry) => entry.url.startsWith(API_BASE) || /genready\.ai/i.test(entry.url))).toEqual([]);

	// Spec 16.5: a second DELETE of one option row affects no row on SQLite.
	expect(await tools.proofProbe()).toEqual({ first: 1, second: 0 });
});
