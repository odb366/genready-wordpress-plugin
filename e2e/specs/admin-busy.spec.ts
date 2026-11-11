import { test, expect as baseExpect, type Page } from '@playwright/test';
import path from 'node:path';

// Runs without WordPress: the page holds the admin markup and admin.js, and
// wp.apiFetch is a stub whose calls stay open until the test answers them.
// Run: cd e2e && npx playwright test specs/admin-busy.spec.ts

const expect = baseExpect.configure({ timeout: 5_000 });
const SCRIPT = path.resolve(__dirname, '../../assets/admin.js');

function fixCard(kind: string, applied: boolean, blocked = false): string {
	return `
	<section class="genready-fix" data-kind="${kind}">
		<span data-genready-field="state">${applied ? 'Applied' : 'Not applied'}</span>
		<p data-genready-field="message" hidden></p>
		<p data-genready-field="live" hidden></p>
		${kind === 'robots' ? '<label><input type="checkbox" data-genready-input="content_signal" checked> Content-Signal</label>' : ''}
		<p>
			<button type="button" data-genready-action="apply"${blocked ? ' disabled' : ''}>Apply</button>
			<button type="button" data-genready-action="preview"${blocked ? ' disabled' : ''}>Preview</button>
			<button type="button" data-genready-action="undo"${applied ? '' : ' hidden'}>Undo</button>
			<button type="button" data-genready-action="live-check">Check the live file</button>
			<button type="button" data-genready-action="download" hidden>Download the file</button>
		</p>
		<div data-genready-field="preview" hidden></div>
		<details data-genready-field="versions" hidden><ol></ol></details>
		<div data-genready-field="result" role="status" tabindex="-1"></div>
	</section>`;
}

const PAGE = `<!doctype html><html><body>
<div class="genready-admin">
	<div id="genready-setup" hidden></div>
	<button type="button" data-genready-action="scan">Scan again</button>
	${fixCard('robots', true)}
	${fixCard('llms', false, true)}
	${fixCard('jsonld', false)}
	<section class="genready-connection">
		<span data-genready-field="verified">Not verified</span>
		<button type="button" data-genready-action="verify">Verify</button>
		<div data-genready-field="tag-on"><button type="button" data-genready-action="remove-tag">Remove the verification tag</button></div>
		<div data-genready-field="tag-off" hidden><button type="button" data-genready-action="add-tag">Add the verification tag</button></div>
		<button type="button" data-genready-action="disconnect">Disconnect</button>
		<div data-genready-field="result" role="status" tabindex="-1"></div>
	</section>
</div>
</body></html>`;

async function open(page: Page, content = PAGE, admin: Record<string, unknown> = { ns: 'genready/v1' }): Promise<void> {
	await page.setContent(content);
	await page.evaluate((settings) => {
		const w = window as unknown as Record<string, unknown>;
		const calls: Array<{ path: string; method?: string; data?: unknown; resolve: (v: unknown) => void; reject: (e: unknown) => void }> = [];
		w.genreadyCalls = calls;
		w.genreadyAdmin = settings;
		w.wp = {
			apiFetch: (options: { path: string; method?: string; data?: unknown }) =>
				new Promise((resolve, reject) => {
					calls.push({ path: options.path, method: options.method, data: options.data, resolve, reject });
				}),
		};
	}, admin);
	await page.addScriptTag({ path: SCRIPT });
}

/** The method and body of the oldest open call. */
async function request(page: Page): Promise<{ path: string; method?: string; data?: unknown }> {
	return page.evaluate(() => {
		const call = (window as unknown as { genreadyCalls: Array<{ path: string; method?: string; data?: unknown }> }).genreadyCalls[0];
		if (!call) {
			throw new Error('no open call');
		}
		return { path: call.path, method: call.method, data: call.data };
	});
}

/** Answer the oldest open call. */
async function answer(page: Page, value: unknown): Promise<string> {
	return page.evaluate((v) => {
		const calls = (window as unknown as { genreadyCalls: Array<{ path: string; resolve: (x: unknown) => void }> }).genreadyCalls;
		const call = calls.shift();
		if (!call) {
			throw new Error('no open call');
		}
		call.resolve(v);
		return call.path;
	}, value);
}

/** Fail the oldest open call, as wp.apiFetch does for a REST error. */
async function fail(page: Page, error: unknown): Promise<string> {
	return page.evaluate((e) => {
		const calls = (window as unknown as { genreadyCalls: Array<{ path: string; reject: (x: unknown) => void }> }).genreadyCalls;
		const call = calls.shift();
		if (!call) {
			throw new Error('no open call');
		}
		call.reject(e);
		return call.path;
	}, error);
}

const card = (page: Page, kind: string) => page.locator(`.genready-fix[data-kind="${kind}"]`);
const action = (page: Page, scope: string, name: string) => page.locator(`${scope} [data-genready-action="${name}"]`);

test('a card call disables every action of that card until it ends', async ({ page }) => {
	await open(page);
	const robots = '.genready-fix[data-kind="robots"]';
	await action(page, robots, 'preview').click();
	for (const name of ['preview', 'undo', 'live-check', 'apply', 'download']) {
		await expect(action(page, robots, name)).toBeDisabled();
	}
	// The other card and the connection actions stay usable.
	await expect(action(page, '.genready-fix[data-kind="llms"]', 'live-check')).toBeEnabled();
	await expect(action(page, '.genready-connection', 'verify')).toBeEnabled();

	expect(await answer(page, { summary: [], notes: [], diff: '+a', changed: true })).toBe('/genready/v1/fixes/robots/preview');
	await expect(card(page, 'robots').locator('[data-genready-field="result"]')).toContainText('Check the preview');
	for (const name of ['preview', 'undo', 'live-check', 'apply']) {
		await expect(action(page, robots, name)).toBeEnabled();
	}
	await expect(action(page, robots, 'apply')).toBeVisible();
});

test('a state change during the call survives the end of the busy state', async ({ page }) => {
	await open(page);
	const robots = '.genready-fix[data-kind="robots"]';
	await action(page, robots, 'undo').click();
	await expect(action(page, robots, 'preview')).toBeDisabled();
	await answer(page, {
		ok: true,
		card: { state: 'not_applied', state_text: 'Not applied', message: '', applied: false, versions: [], live: null, live_check: false, can_write: true, as_file: false },
	});
	await expect(card(page, 'robots').locator('[data-genready-field="result"]')).toContainText('Undone.');
	await expect(action(page, robots, 'undo')).toBeHidden();
	await expect(action(page, robots, 'preview')).toBeEnabled();
});

test('the Account tab removes the tag and adds it back without a reload', async ({ page }) => {
	await open(page);
	const conn = '.genready-connection';
	const tagOn = page.locator(`${conn} [data-genready-field="tag-on"]`);
	const tagOff = page.locator(`${conn} [data-genready-field="tag-off"]`);
	const result = page.locator(`${conn} [data-genready-field="result"]`);

	await action(page, conn, 'remove-tag').click();
	expect(await answer(page, { ok: true })).toBe('/genready/v1/verification-tag/remove');
	await expect(result).toContainText('The verification tag is removed from your home page.');
	await expect(tagOn).toBeHidden();
	await expect(tagOff).toBeVisible();

	await action(page, conn, 'add-tag').click();
	await expect(action(page, conn, 'disconnect')).toBeDisabled();
	expect(await answer(page, { verified: true, detail: '', tag_on: true })).toBe('/genready/v1/verify');
	await expect(result).toHaveText('The verification tag is back on your home page, and your site is verified.');
	await expect(page.locator(`${conn} [data-genready-field="verified"]`)).toHaveText('Verified');
	await expect(tagOn).toBeVisible();
	await expect(tagOff).toBeHidden();
});

test('the tag block follows the tag, also when GenReady does not confirm the site', async ({ page }) => {
	await open(page);
	const conn = '.genready-connection';
	await action(page, conn, 'remove-tag').click();
	await answer(page, { ok: true });
	await action(page, conn, 'add-tag').click();
	// The plugin printed the tag before it asked GenReady.
	await answer(page, { verified: false, detail: 'GenReady did not find the tag.', tag_on: true });
	await expect(page.locator(`${conn} [data-genready-field="result"]`)).toContainText('GenReady did not find the tag.');
	await expect(page.locator(`${conn} [data-genready-field="tag-on"]`)).toBeVisible();
	await expect(page.locator(`${conn} [data-genready-field="tag-off"]`)).toBeHidden();
	await expect(page.locator(`${conn} [data-genready-field="verified"]`)).toHaveText('Not verified');
});

test('a failed call shows the error and the true tag state', async ({ page }) => {
	await open(page);
	const conn = '.genready-connection';
	const result = page.locator(`${conn} [data-genready-field="result"]`);
	const tagOn = page.locator(`${conn} [data-genready-field="tag-on"]`);
	const tagOff = page.locator(`${conn} [data-genready-field="tag-off"]`);

	await action(page, conn, 'remove-tag').click();
	expect(await fail(page, { message: 'GenReady could not do this. Try again.', data: { status: 400, genready_code: 'GENREADY_ERROR' } })).toBe('/genready/v1/verification-tag/remove');
	await expect(result).toContainText('GenReady could not do this. Try again.');
	await expect(tagOn).toBeVisible();
	await expect(tagOff).toBeHidden();

	await action(page, conn, 'remove-tag').click();
	await answer(page, { ok: true });
	await action(page, conn, 'add-tag').click();
	await fail(page, { message: 'GenReady did not answer. Try again.', data: { status: 400, genready_code: 'GENREADY_ERROR', tag_on: true } });
	await expect(result).toContainText('GenReady did not answer. Try again.');
	await expect(tagOn).toBeVisible();
	await expect(tagOff).toBeHidden();
	await expect(action(page, conn, 'add-tag')).toBeEnabled();
});

test('a button that was disabled before the call stays disabled after it', async ({ page }) => {
	await open(page);
	const llms = '.genready-fix[data-kind="llms"]';
	await action(page, llms, 'live-check').click();
	await expect(action(page, llms, 'live-check')).toBeDisabled();
	await answer(page, { ok: true, card: { state: 'applied', state_text: 'Applied', applied: true, versions: [], live: null, live_check: true } });
	await expect(action(page, llms, 'live-check')).toBeEnabled();
	await expect(action(page, llms, 'preview')).toBeDisabled();
});

test('verify, scan and the connection actions lock each other', async ({ page }) => {
	await page.clock.install();
	await open(page);
	await action(page, '.genready-connection', 'verify').click();
	await expect(action(page, '.genready-admin', 'scan')).toBeDisabled();
	await expect(action(page, '.genready-connection', 'disconnect')).toBeDisabled();
	await expect(action(page, '.genready-connection', 'remove-tag')).toBeDisabled();
	// Fix cards are not connection actions.
	await expect(action(page, '.genready-fix[data-kind="robots"]', 'preview')).toBeEnabled();
	expect(await answer(page, { verified: true, detail: '' })).toBe('/genready/v1/verify');
	await expect(action(page, '.genready-admin', 'scan')).toBeEnabled();
	await expect(action(page, '.genready-connection', 'disconnect')).toBeEnabled();

	await action(page, '.genready-admin', 'scan').click();
	await expect(action(page, '.genready-connection', 'verify')).toBeDisabled();
	expect(await answer(page, { report_id: 'x' })).toBe('/genready/v1/scan');
	// The lock lasts until the poll ends, not only until the scan call returns.
	await expect(action(page, '.genready-connection', 'verify')).toBeDisabled();
	await page.clock.runFor(5_000);
	await expect.poll(() => page.evaluate(() => (window as unknown as { genreadyCalls: unknown[] }).genreadyCalls.length)).toBe(1);
	expect(await answer(page, { state: 'none' })).toBe('/genready/v1/report');
	await expect(action(page, '.genready-connection', 'verify')).toBeEnabled();
});

const APPLIED_CARD = {
	state: 'applied',
	state_text: 'Applied',
	message: '',
	applied: true,
	versions: [],
	live: null,
	live_check: false,
	can_write: true,
	as_file: false,
};
const READY_PREVIEW = { summary: ['Adds rules'], notes: [], diff: '+a', changed: true };

test('Apply without a preview asks for one, applies it and shows Undo', async ({ page }) => {
	await open(page);
	const jsonld = '.genready-fix[data-kind="jsonld"]';
	await expect(action(page, jsonld, 'undo')).toBeHidden();
	await action(page, jsonld, 'apply').click();
	expect(await answer(page, { summary: [], notes: [], code: '{}', fillable: [], changed: true })).toBe('/genready/v1/fixes/jsonld/preview');
	await expect.poll(() => page.evaluate(() => (window as unknown as { genreadyCalls: unknown[] }).genreadyCalls.length)).toBe(1);
	expect(await answer(page, { ok: true, card: APPLIED_CARD })).toBe('/genready/v1/fixes/jsonld/apply');
	await expect(card(page, 'jsonld').locator('[data-genready-field="result"]')).toContainText('Applied.');
	await expect(action(page, jsonld, 'undo')).toBeVisible();
	await expect(card(page, 'jsonld').locator('[data-genready-field="preview"]')).toBeHidden();
});

test('Apply stops at the preview when there is nothing to apply', async ({ page }) => {
	await open(page);
	const robots = '.genready-fix[data-kind="robots"]';
	await action(page, robots, 'apply').click();
	expect(await answer(page, { summary: [], notes: [], diff: '', changed: false })).toBe('/genready/v1/fixes/robots/preview');
	await expect(card(page, 'robots').locator('[data-genready-field="result"]')).toContainText('GenReady cannot apply this yet.');
	await expect(card(page, 'robots').locator('[data-genready-field="preview"]')).toContainText('Nothing to change.');
	await expect(action(page, robots, 'apply')).toBeEnabled();
	expect(await page.evaluate(() => (window as unknown as { genreadyCalls: unknown[] }).genreadyCalls.length)).toBe(0);
});

test('Apply uses the preview on screen, and a changed choice asks for a new one', async ({ page }) => {
	await open(page);
	const robots = '.genready-fix[data-kind="robots"]';
	await action(page, robots, 'preview').click();
	await answer(page, READY_PREVIEW);
	await action(page, robots, 'apply').click();
	expect(await answer(page, { ok: true, card: APPLIED_CARD })).toBe('/genready/v1/fixes/robots/apply');

	await action(page, robots, 'preview').click();
	await answer(page, READY_PREVIEW);
	await card(page, 'robots').locator('[data-genready-input="content_signal"]').uncheck();
	await action(page, robots, 'apply').click();
	expect(await answer(page, READY_PREVIEW)).toBe('/genready/v1/fixes/robots/preview');
});

test('Apply on JSON-LD with fields to fill in waits for the second click', async ({ page }) => {
	await open(page);
	const jsonld = '.genready-fix[data-kind="jsonld"]';
	await action(page, jsonld, 'apply').click();
	await answer(page, { summary: [], notes: [], code: '{}', fillable: ['name'], changed: true });
	await expect(card(page, 'jsonld').locator('[data-genready-field="result"]')).toContainText('Fill in the missing fields');
	expect(await page.evaluate(() => (window as unknown as { genreadyCalls: unknown[] }).genreadyCalls.length)).toBe(0);
	await card(page, 'jsonld').locator('[data-genready-prop="name"]').fill('Example');
	await action(page, jsonld, 'apply').click();
	expect(await answer(page, { ok: true, card: APPLIED_CARD })).toBe('/genready/v1/fixes/jsonld/apply');
});

// The connected Overview: the page field scans any page of the site with the key.
const SCAN_PAGE = `<!doctype html><html><body>
<div class="genready-admin">
	<div id="genready-setup" hidden></div>
	<form id="genready-scan-form" novalidate>
		<input type="url" id="genready-page-url" data-genready-input="url" value="https://example.com/">
		<button type="submit" data-genready-action="check">Check AI readiness</button>
	</form>
	<div id="genready-scan-result" role="status" tabindex="-1"></div>
	<button type="button" data-genready-action="scan">Scan again</button>
</div>
</body></html>`;

const CONNECTED = { ns: 'genready/v1', connected: true, overviewUrl: '#overview' };

test('with a connection the page field scans the address and opens the new report', async ({ page }) => {
	await page.clock.install();
	await open(page, SCAN_PAGE, CONNECTED);
	await page.locator('#genready-page-url').fill('  https://example.com/pricing/ ');
	await page.getByRole('button', { name: 'Check AI readiness' }).click();
	await expect(page.getByRole('button', { name: 'Check AI readiness' })).toBeDisabled();
	await expect(page.getByRole('button', { name: 'Scan again' })).toBeDisabled();
	await expect(page.locator('#genready-scan-result')).toContainText('Analyzing the page');
	expect(await request(page)).toEqual({ path: '/genready/v1/scan', method: 'POST', data: { url: 'https://example.com/pricing/' } });

	await answer(page, { report_id: '11111111-2222-3333-4444-555555555555' });
	await page.clock.runFor(5_000);
	expect(await answer(page, { state: 'pending' })).toBe('/genready/v1/report');
	await page.clock.runFor(5_000);
	expect(await answer(page, { state: 'complete' })).toBe('/genready/v1/report');
	await expect(page.locator('#genready-scan-result')).toContainText('The scan is done. Loading the report');
	await expect(page).toHaveURL(/#overview$/);
});

test('with a connection the page field shows a refusal under the field', async ({ page }) => {
	await open(page, SCAN_PAGE, CONNECTED);
	await page.locator('#genready-page-url').fill('https://example.org/');
	await page.getByRole('button', { name: 'Check AI readiness' }).click();
	expect(await fail(page, { code: 'NOT_THIS_SITE', message: 'Enter an address on this site.', data: { status: 400 } })).toBe('/genready/v1/scan');
	await expect(page.locator('#genready-scan-result')).toContainText('Enter an address on this site.');
	await expect(page.getByRole('button', { name: 'Check AI readiness' })).toBeEnabled();
	await expect(page.getByRole('button', { name: 'Scan again' })).toBeEnabled();
});
