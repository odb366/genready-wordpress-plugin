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
		<p>
			<button type="button" data-genready-action="preview"${blocked ? ' disabled' : ''}>Preview</button>
			<button type="button" data-genready-action="apply" hidden>Apply</button>
			<button type="button" data-genready-action="undo"${applied ? '' : ' disabled'}>Undo</button>
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
	<section class="genready-connection">
		<button type="button" data-genready-action="verify">Verify</button>
		<button type="button" data-genready-action="remove-tag">Remove the verification tag</button>
		<button type="button" data-genready-action="disconnect">Disconnect</button>
		<div data-genready-field="result" role="status" tabindex="-1"></div>
	</section>
</div>
</body></html>`;

async function open(page: Page): Promise<void> {
	await page.setContent(PAGE);
	await page.evaluate(() => {
		const w = window as unknown as Record<string, unknown>;
		const calls: Array<{ path: string; resolve: (v: unknown) => void }> = [];
		w.genreadyCalls = calls;
		w.genreadyAdmin = { ns: 'genready/v1' };
		w.wp = {
			apiFetch: (options: { path: string }) =>
				new Promise((resolve) => {
					calls.push({ path: options.path, resolve });
				}),
		};
	});
	await page.addScriptTag({ path: SCRIPT });
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
	await expect(action(page, robots, 'undo')).toBeDisabled();
	await expect(action(page, robots, 'preview')).toBeEnabled();
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
