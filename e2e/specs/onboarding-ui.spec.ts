import { test, expect as baseExpect, type Page } from '@playwright/test';
import path from 'node:path';

// Runs without WordPress, like admin-busy.spec.ts: the page holds the markup of the banner,
// the scan form and the connect panel, admin.js and onboarding.js, and a wp.apiFetch stub
// whose calls stay open until the test answers or fails them.
// Run: cd e2e && npx playwright test specs/onboarding-ui.spec.ts

const expect = baseExpect.configure({ timeout: 5_000 });
const ADMIN = path.resolve(__dirname, '../../assets/admin.js');
const ONBOARDING = path.resolve(__dirname, '../../assets/onboarding.js');
const OVERVIEW = 'https://site.test/wp-admin/options-general.php?page=genready';
const SCAN_ID = '11111111-2222-3333-4444-555555555555';

const PAGE = `<!doctype html><html><body>
<div class="genready-admin">
	<div id="genready-setup" hidden></div>
	<section id="genready-account">
		<form id="genready-account-form" novalidate data-genready-onboarding>
			<label for="genready-account-email">Email</label>
			<input type="email" id="genready-account-email" value="admin@site.test" data-genready-input="email">
			<button type="submit" data-genready-action="create-account">Create my free account</button>
		</form>
		<div id="genready-account-result" role="status" aria-live="polite" tabindex="-1"></div>
	</section>
	<form id="genready-scan-form" novalidate data-genready-onboarding>
		<label for="genready-page-url">Page address</label>
		<input type="url" id="genready-page-url" value="https://site.test/" data-genready-input="url">
		<button type="submit" data-genready-action="check">Check this page</button>
	</form>
	<div id="genready-scan-result" role="status" aria-live="polite" tabindex="-1"></div>
	<button type="button" data-genready-action="show-connect" aria-expanded="false" aria-controls="genready-connect-panel">Already have a GenReady account? Connect</button>
	<div id="genready-connect-panel" hidden>
		<section class="genready-connect">
			<button type="button" data-genready-action="connect">Connect to GenReady</button>
			<div data-genready-field="result" role="status" tabindex="-1"></div>
		</section>
	</div>
</div>
</body></html>`;

type Call = { path: string; method: string; data: unknown };

async function open(page: Page): Promise<void> {
	await page.clock.install();
	await page.route('https://site.test/**', (route) =>
		route.fulfill({ body: '<html><body>next page</body></html>', contentType: 'text/html' }),
	);
	await page.setContent(PAGE);
	await page.evaluate((overview) => {
		const w = window as unknown as Record<string, unknown>;
		const calls: Array<Record<string, unknown>> = [];
		w.genreadyCalls = calls;
		w.genreadyAdmin = { ns: 'genready/v1', overviewUrl: overview };
		w.wp = {
			apiFetch: (options: Record<string, unknown>) =>
				new Promise((resolve, reject) => {
					calls.push({
						path: options.path,
						method: options.method,
						data: options.data,
						resolve,
						reject,
					});
				}),
		};
	}, OVERVIEW);
	await page.addScriptTag({ path: ADMIN });
	await page.addScriptTag({ path: ONBOARDING });
}

/** Wait for an open call, then answer it, or fail it like a REST WP_Error with a genready_code. */
async function settle(
	page: Page,
	outcome: { value?: unknown; code?: string; message?: string },
): Promise<Call> {
	await expect
		.poll(() => page.evaluate(() => ((window as any).genreadyCalls as unknown[]).length))
		.toBeGreaterThan(0);
	return page.evaluate((o) => {
		const call = ((window as any).genreadyCalls as Array<any>).shift();
		if (o.code) {
			call.reject({ message: o.message, data: { status: 400, genready_code: o.code } });
		} else {
			call.resolve(o.value);
		}
		return { path: call.path, method: call.method, data: call.data ?? null };
	}, outcome);
}

test('Check this page: Enter submits, the poll uses the scan ID, then the report opens', async ({
	page,
}) => {
	await open(page);
	const field = page.getByLabel('Page address');
	await field.fill('https://site.test/pricing/');
	await field.press('Enter');
	await expect(page.locator('#genready-scan-result')).toHaveText(
		'Scanning this page. This takes about 30 seconds.',
	);
	await expect(page.getByRole('button', { name: 'Create my free account' })).toBeDisabled();
	expect(await settle(page, { value: { scan_id: SCAN_ID } })).toEqual({
		path: '/genready/v1/scan',
		method: 'POST',
		data: { url: 'https://site.test/pricing/' },
	});
	await page.clock.runFor(5_000);
	expect((await settle(page, { value: { state: 'pending', scan_id: SCAN_ID } })).path).toBe(
		`/genready/v1/report?scan=${SCAN_ID}`,
	);
	await page.clock.runFor(5_000);
	await settle(page, { value: { state: 'complete', scan_id: SCAN_ID } });
	await page.waitForURL(`${OVERVIEW}&scan=${SCAN_ID}`);
});

test('both forms stay disabled until the scan poll ends', async ({ page }) => {
	await open(page);
	const check = page.getByRole('button', { name: 'Check this page' });
	const create = page.getByRole('button', { name: 'Create my free account' });
	await check.click();
	await settle(page, { value: { scan_id: SCAN_ID } });
	await page.clock.runFor(5_000);
	await settle(page, { value: { state: 'pending', scan_id: SCAN_ID } });
	await expect(check).toBeDisabled();
	await expect(create).toBeDisabled();
	await page.clock.runFor(5_000);
	await settle(page, { value: { state: 'complete', scan_id: SCAN_ID } });
	await page.waitForURL(`${OVERVIEW}&scan=${SCAN_ID}`);
});

test('a scan error gets the focus; a 503 hides both forms and opens Connect', async ({ page }) => {
	await open(page);
	const region = page.locator('#genready-scan-result');
	await page.getByRole('button', { name: 'Check this page' }).click();
	await settle(page, {
		code: 'RATE_LIMITED',
		message: 'Scan again tomorrow, or create a free account.',
	});
	await expect(region).toHaveText('Scan again tomorrow, or create a free account.');
	await expect(region).toBeFocused();
	await page.getByRole('button', { name: 'Check this page' }).click();
	await settle(page, {
		code: 'WP_ONBOARDING_OFF',
		message: 'GenReady paused scans without an account. Connect with a GenReady account.',
	});
	await expect(region).toHaveText(
		'GenReady paused scans without an account. Connect with a GenReady account.',
	);
	await expect(page.locator('#genready-scan-form')).toBeHidden();
	await expect(page.locator('#genready-account-form')).toBeHidden();
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
});

test('Create my free account: success opens the Overview again', async ({ page }) => {
	await open(page);
	await page.getByLabel('Email').press('Enter');
	await expect(page.locator('#genready-account-result')).toHaveText('Creating your account.');
	await expect(page.getByRole('button', { name: 'Check this page' })).toBeDisabled();
	expect(await settle(page, { value: { ok: true } })).toEqual({
		path: '/genready/v1/account',
		method: 'POST',
		data: { email: 'admin@site.test' },
	});
	await page.waitForURL(OVERVIEW);
});

test('ACCOUNT_EXISTS offers Sign in to connect, which starts Connect without the admin email', async ({
	page,
}) => {
	await open(page);
	await page.getByRole('button', { name: 'Create my free account' }).click();
	await settle(page, {
		code: 'ACCOUNT_EXISTS',
		message: 'This email already has a GenReady account.',
	});
	const region = page.locator('#genready-account-result');
	await expect(region).toContainText('This email already has a GenReady account.');
	await region.getByRole('button', { name: 'Sign in to connect' }).click();
	expect(await settle(page, { value: { url: 'https://site.test/oauth/authorize' } })).toEqual({
		path: '/genready/v1/connect/oauth',
		method: 'POST',
		data: { send_email: false },
	});
	await page.waitForURL('https://site.test/oauth/authorize');
});

test('PROOF_FAILED opens the connect form under the message', async ({ page }) => {
	await open(page);
	await page.getByRole('button', { name: 'Create my free account' }).click();
	await settle(page, {
		code: 'PROOF_FAILED',
		message:
			'GenReady could not confirm that this site is yours. Connect with a GenReady account instead.',
	});
	await expect(page.locator('#genready-account-result')).toHaveText(
		'GenReady could not confirm that this site is yours. Connect with a GenReady account instead.',
	);
	await expect(page.locator('#genready-account-result')).toBeFocused();
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
	await expect(page.locator('#genready-account-form')).toBeVisible();
});

test('the Connect link opens and closes the connect form', async ({ page }) => {
	await open(page);
	const link = page.getByRole('button', { name: 'Already have a GenReady account? Connect' });
	await link.click();
	await expect(link).toHaveAttribute('aria-expanded', 'true');
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeFocused();
	await link.click();
	await expect(link).toHaveAttribute('aria-expanded', 'false');
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeHidden();
});
