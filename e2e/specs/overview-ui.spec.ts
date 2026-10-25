import { test, expect as baseExpect, type Page } from '@playwright/test';
import path from 'node:path';

// Runs without WordPress, like admin-busy.spec.ts: the page holds the "How AI sees this page"
// markup and admin.js, and wp.apiFetch is a stub whose calls stay open until the test answers them.
// Run: cd e2e && npx playwright test specs/overview-ui.spec.ts

const expect = baseExpect.configure({ timeout: 5_000 });
const SCRIPT = path.resolve(__dirname, '../../assets/admin.js');
const SCAN_ID = '11111111-2222-3333-4444-555555555555';
const SHOT = 'https://genready.ai/screenshots/a.png';

const PAGE = `<!doctype html><html><body>
<div class="genready-admin">
	<div id="genready-setup" hidden></div>
	<div data-genready-panel="screenshot">
		<p class="genready-wait" data-genready-screenshot-wait data-genready-scan="${SCAN_ID}" data-genready-alt="Screenshot of your homepage"><span class="spinner is-active" aria-hidden="true"></span>Making the screenshot…</p>
	</div>
	<div data-genready-panel="text">
		<div data-genready-text="formatted" tabindex="0"><h3>Home</h3><p>Hello</p></div>
		<pre data-genready-text="raw" tabindex="0" hidden># Home
Hello</pre>
		<p><button type="button" data-genready-toggle="raw-text" data-genready-other="Show the formatted text">Show the raw text</button></p>
	</div>
</div>
</body></html>`;

async function open(page: Page): Promise<void> {
	await page.clock.install();
	await page.route('https://genready.ai/**', (route) => route.fulfill({ status: 200, contentType: 'image/png', body: '' }));
	await page.setContent(PAGE);
	await page.evaluate(() => {
		const w = window as unknown as Record<string, unknown>;
		const calls: Array<{ path: string; resolve: (v: unknown) => void; reject: (e: unknown) => void }> = [];
		w.genreadyCalls = calls;
		w.genreadyAdmin = { ns: 'genready/v1' };
		w.wp = {
			apiFetch: (options: { path: string }) =>
				new Promise((resolve, reject) => {
					calls.push({ path: options.path, resolve, reject });
				}),
		};
	});
	await page.addScriptTag({ path: SCRIPT });
}

/** Wait for an open call, then answer it (or fail it with `error`). */
async function answer(page: Page, value: unknown, error = false): Promise<string> {
	await expect
		.poll(() => page.evaluate(() => ((window as any).genreadyCalls as unknown[]).length))
		.toBeGreaterThan(0);
	return page.evaluate(
		({ v, e }) => {
			const call = ((window as any).genreadyCalls as Array<any>).shift();
			if (e) {
				call.reject({ message: 'busy', data: { status: 429 } });
			} else {
				call.resolve(v);
			}
			return call.path;
		},
		{ v: value, e: error },
	);
}

const report = (screenshot: string | null, pending: boolean) => ({
	state: 'complete',
	scan_id: SCAN_ID,
	report: { ai_view: { screenshot, screenshot_pending: pending } },
});

test('the screenshot replaces the wait line when GenReady has made it', async ({ page }) => {
	await open(page);
	await page.clock.runFor(10_000);
	expect(await answer(page, report(null, true))).toBe(`/genready/v1/report?scan=${SCAN_ID}`);
	await expect(page.getByText('Making the screenshot…')).toBeVisible();
	await page.clock.runFor(10_000);
	await answer(page, null, true); // A failed poll keeps waiting.
	await page.clock.runFor(10_000);
	await answer(page, report(SHOT, false));
	const image = page.getByRole('img', { name: 'Screenshot of your homepage' });
	await expect(image).toHaveAttribute('src', SHOT);
	await expect(page.locator('[data-genready-screenshot-wait]')).toHaveCount(0);
});

test('the wait ends with "This scan has no screenshot." when GenReady stops waiting', async ({ page }) => {
	await open(page);
	await page.clock.runFor(10_000);
	await answer(page, report(null, false));
	await expect(page.getByText('This scan has no screenshot.')).toBeVisible();
	await expect(page.locator('img')).toHaveCount(0);
});

test('a screenshot address that is not http(s) is never loaded', async ({ page }) => {
	await open(page);
	await page.clock.runFor(10_000);
	await answer(page, report('javascript:alert(1)', false));
	await expect(page.getByText('This scan has no screenshot.')).toBeVisible();
	await expect(page.locator('img')).toHaveCount(0);
});

test('the raw text toggle switches between the formatted and the raw text', async ({ page }) => {
	await open(page);
	const toggle = page.getByRole('button', { name: 'Show the raw text' });
	await toggle.click();
	await expect(page.locator('[data-genready-text="raw"]')).toBeVisible();
	await expect(page.locator('[data-genready-text="formatted"]')).toBeHidden();
	await page.getByRole('button', { name: 'Show the formatted text' }).click();
	await expect(page.locator('[data-genready-text="formatted"]')).toBeVisible();
	await expect(page.locator('[data-genready-text="raw"]')).toBeHidden();
});
