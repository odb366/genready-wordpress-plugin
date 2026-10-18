import { test, expect } from '@playwright/test';
import { account, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

// The helper plugin answers every /api/v1/wp/ call with the server's 503 (spec 5.2), so the
// test server keeps its switch on. Then the API key, the last fallback, still connects.
test('switch off: only Connect for an hour, and the API key still works', async ({ page }) => {
	await login(page);
	const tools = await helper(page);
	await tools.deleteOption('genready_anonymous_scans');
	await tools.fakeWp('off');
	try {
		await openGenReady(page);
		await page.getByRole('button', { name: 'Check this page' }).click();
		const paused = 'GenReady paused scans without an account. Connect with a GenReady account.';
		await expect(page.locator('#genready-scan-result')).toHaveText(paused);
		await expect(page.locator('#genready-scan-form')).toBeHidden();
		await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();

		await openGenReady(page);
		await expect(page.locator('.genready-connect')).toContainText(paused);
		await expect(page.getByRole('button', { name: 'Check this page' })).toHaveCount(0);
		expect(await tools.options('_transient_genready_anon_off')).toEqual(['_transient_genready_anon_off']);
	} finally {
		await tools.fakeWp('');
	}
	await connectWithKey(page, account('pro').apiKey);
	await tools.deleteOption('_transient_genready_anon_off');
	await tools.deleteOption('_transient_timeout_genready_anon_off');
});
