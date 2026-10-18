import { test, expect, type Page } from '@playwright/test';
import { SITE, anonSeed, helper, login, openGenReady } from '../lib/helpers';

// Runs with 2 Playground workers (run-all.sh sets GENREADY_E2E_WORKERS=2): GenReady calls the
// proof route while the account request still waits (spec 7.5).
test.describe.configure({ mode: 'serial' });

const EMAIL = `e2e-wp-${Date.now()}@genready.test`;

async function seedScan(page: Page): Promise<{ scanId: string; token: string }> {
	const scan = anonSeed('scan', `${SITE}/`);
	await (await helper(page)).storeScan(scan.scanId, `${SITE}/`, scan.token);
	return scan;
}

async function createAccount(page: Page, email: string): Promise<void> {
	await openGenReady(page);
	const banner = page.locator('#genready-account');
	await expect(banner.getByRole('heading', { name: 'Save your site and track your score' })).toBeVisible();
	await banner.getByLabel('Email', { exact: true }).fill(email);
	await banner.getByRole('button', { name: 'Create my free account' }).click();
}

test('Create my free account: connected and verified, scans adopted, tag on, proof gone', async ({ page }) => {
	anonSeed('reset');
	await login(page);
	const tools = await helper(page);
	await tools.deleteOption('genready_anonymous_scans');
	const scan = await seedScan(page);
	await createAccount(page, EMAIL);

	await expect(page.locator('.notice-success')).toContainText('Your free GenReady account is ready. This site is connected and verified.');
	await expect(page.locator('.notice-info')).toContainText(`GenReady sent a link to ${EMAIL}. Use it to set your password.`);
	await expect(page.locator('#genready-setup [data-genready-step="verify"]')).toHaveCount(0);
	// The adopted report, read with the new one-site key.
	await expect(page.locator('.genready-score')).toBeVisible();
	await expect(page.locator('#genready-account')).toHaveCount(0);
	expect((await tools.option('genready_settings')).last_report_id).toBe(scan.scanId);

	await openGenReady(page, 'connection');
	const table = page.locator('.genready-connection .form-table');
	await expect(table).toContainText('API key');
	await expect(table).toContainText('Verified');
	const home = await (await page.request.get('/')).text();
	expect(home).toMatch(/<meta name="genready-site-verification" content="[A-Za-z0-9_-]{8,128}">/);
	expect(await tools.options('genready_proof')).toEqual([]);
	expect(await tools.options('genready_anonymous_scans')).toEqual([]);
});

test('the same email again: "This email already has a GenReady account." and Sign in to connect', async ({ page }) => {
	await login(page);
	await openGenReady(page, 'connection');
	page.once('dialog', (dialog) => dialog.accept());
	await page.getByRole('button', { name: 'Disconnect' }).click();
	await expect(page.locator('.genready-connect')).toBeVisible();
	await openGenReady(page);
	await expect(page.getByRole('heading', { name: 'See how AI search engines and agents read your site' })).toBeVisible();

	await seedScan(page);
	await createAccount(page, EMAIL);
	const result = page.locator('#genready-account-result');
	await expect(result).toContainText('This email already has a GenReady account.');
	await expect(result.getByRole('button', { name: 'Sign in to connect' })).toBeVisible();
});

test('the proof route answers 404: the fallback text and the connect form', async ({ page }) => {
	await login(page);
	const tools = await helper(page);
	await tools.blockProof(true);
	try {
		await createAccount(page, `e2e-wp-${Date.now()}-b@genready.test`);
		const result = page.locator('#genready-account-result');
		await expect(result).toContainText('GenReady could not confirm that this site is yours. Connect with a GenReady account instead.');
		await expect(page.locator('#genready-connect-panel')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
		expect(await tools.options('genready_connection')).toEqual([]);
		expect(await tools.options('genready_proof')).toEqual([]);
	} finally {
		await tools.blockProof(false);
	}
});

test('plain permalinks: the proof passes through ?rest_route=', async ({ page }) => {
	anonSeed('reset');
	await login(page);
	const tools = await helper(page);
	await tools.permalinks('');
	try {
		await tools.deleteOption('genready_anonymous_scans');
		await seedScan(page);
		await createAccount(page, `e2e-wp-${Date.now()}-c@genready.test`);
		await expect(page.locator('.notice-success')).toContainText('Your free GenReady account is ready. This site is connected and verified.');
	} finally {
		await tools.permalinks('/%postname%/');
	}
});
