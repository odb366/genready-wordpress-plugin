import { test, expect } from '@playwright/test';
import { VERIFY_SHOWN, account, helper, login, openConnectForm, openGenReady } from '../lib/helpers';

test('connect with OAuth: sign in, approve, back on the plugin page connected', async ({ page }) => {
	await login(page);
	await openGenReady(page);
	await openConnectForm(page);
	// The admin email goes to GenReady only when the admin ticks the box.
	await expect(page.locator('#genready-send-email')).not.toBeChecked();
	const authorize = page.waitForRequest(/^http:\/\/127\.0\.0\.1:5002\/oauth\/authorize/);
	await page.getByRole('button', { name: 'Connect to GenReady' }).click();
	expect(new URL((await authorize).url()).searchParams.has('login_hint')).toBe(false);

	// Sign in on the local GenReady server.
	await page.waitForURL(/^http:\/\/127\.0\.0\.1:5002\/auth\/login/);
	const pro = account('pro');
	await page.locator('#login-username').fill(pro.email);
	await page.locator('#login-password').fill(pro.password);
	await page.getByRole('button', { name: 'Sign In', exact: true }).click();

	// The consent page names this site.
	await page.waitForURL(/^http:\/\/127\.0\.0\.1:5002\/oauth\/authorize/);
	await expect(page.getByRole('heading', { name: 'Connect 127.0.0.1 to GenReady' })).toBeVisible();
	await page.getByRole('button', { name: 'Approve' }).click();

	// Back on the plugin page, connected.
	await page.waitForURL(/^http:\/\/127\.0\.0\.1:9411\/wp-admin\/.*page=genready/);
	await expect(page.locator('.notice-success')).toContainText('GenReady is connected.');
	const setup = page.locator('#genready-setup');
	await expect(setup.locator('[data-genready-step="verify"]')).toHaveText(VERIFY_SHOWN);
	console.log(`verify step: ${await setup.locator('[data-genready-step="verify"]').innerText()}`);
	await expect(setup.locator('[data-genready-step="scan"].genready-result--error')).toBeVisible();

	await openGenReady(page, 'connection');
	const table = page.locator('.genready-connection .form-table');
	await expect(table).toContainText('GenReady account (OAuth)');
	await expect(table).toContainText('Pro');

	// One refresh under the lock: a real refresh_token grant, and the lock row is gone afterwards (SQLite).
	const refresh = await (await helper(page)).post('/oauth-refresh');
	expect(refresh).toEqual({ refreshed: true, lock_rows: 0 });

	// The new access token works: the seeded report still loads.
	await openGenReady(page);
	await expect(page.locator('.genready-score')).toBeVisible();
});
