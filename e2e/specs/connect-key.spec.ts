import { test, expect } from '@playwright/test';
import { SITE, account, card, connectWithKey, helper, jsonLdNodes, login, openGenReady } from '../lib/helpers';

/** Non-empty lines of a robots.txt body. */
function lines(text: string): string[] {
	return text.split('\n').map((line) => line.trim()).filter((line) => line !== '');
}

/** Remove the first copy of each item from a list. */
function without(list: string[], items: string[]): string[] {
	const rest = [...list];
	for (const item of items) {
		const index = rest.indexOf(item);
		if (index !== -1) {
			rest.splice(index, 1);
		}
	}
	return rest;
}

test('connect with a Pro key: score, AI view, robots.txt apply and undo', async ({ page }) => {
	await login(page);
	const base = await (await page.request.get('/robots.txt')).text();
	expect(base).not.toContain('Content-Signal');

	const tools = await helper(page);
	await tools.resetHttpLog();
	await connectWithKey(page, account('pro').apiKey);

	// The blueprint stored a scan of this site, so the connection keeps it, starts no new scan and opens
	// the Overview (Kris 2026-11-12). The page field below still checks a scan with the key.
	await page.waitForURL(/options-general\.php\?page=genready$/);
	expect((await tools.httpLog()).filter((entry) => entry.url.includes('/api/v1/analyze'))).toEqual([]);

	// Overview from the seeded report.
	await openGenReady(page);
	const score = page.locator('.genready-score');
	await expect(score.getByRole('heading', { name: 'AI Readiness Score' })).toBeVisible();
	await expect(score.locator('svg')).toHaveCount(3);
	await expect(score.locator('.genready-band')).toHaveText(/^(Pass|Fair|Poor|Fail)$/);
	await expect(score.locator('.genready-score__items')).toContainText('Content');
	await expect(score.locator('.genready-score__items')).toContainText('Crawlability');

	// The screenshot sits next to the score; the text AI reads has its own card.
	await expect(page.locator('.genready-hero').getByRole('heading', { name: 'Homepage screenshot' })).toBeVisible();
	await expect(page.locator('.genready-hero .genready-shot-frame')).toBeVisible();
	await expect(page.locator('.genready-aiview pre.genready-ai-text')).not.toBeEmpty();

	// The page field scans any page of this site with the key. The plugin refuses another site without a call;
	// the local server refuses 127.0.0.1 (SSRF guard).
	const form = page.locator('#genready-scan-form');
	const scanResult = page.locator('#genready-scan-result');
	await expect(form).toContainText('Each scan uses 1 API credit.');
	await form.locator('#genready-page-url').fill('https://example.org/');
	await form.getByRole('button', { name: 'Check AI readiness' }).click();
	await expect(scanResult).toContainText('Enter an address on this site.');
	const other = scanResult.getByRole('link', { name: 'Check another site on genready.ai' });
	await expect(other).toHaveAttribute('href', /\/\?url=https%3A%2F%2Fexample\.org%2F$/);
	await expect(other).toHaveAttribute('target', '_blank');
	await form.locator('#genready-page-url').fill(`${SITE}/sample-page/`);
	await form.getByRole('button', { name: 'Check AI readiness' }).click();
	await expect(scanResult).toContainText('GenReady can check only pages that anyone can open on the internet.');

	// robots.txt: preview, apply, check the served file, undo.
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await expect(robots.state).toHaveText('Not applied');
	await expect(robots.button('undo')).toBeHidden();
	await robots.button('preview').click();
	await expect(robots.result).toContainText('Check the preview, then click Apply.');
	const diff = await robots.preview.locator('pre.genready-diff').innerText();
	const added = diff.split('\n').filter((line) => line.startsWith('+') && !line.startsWith('+++')).map((line) => line.slice(1).trim()).filter((line) => line !== '');
	const removed = diff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---')).map((line) => line.slice(1).trim()).filter((line) => line !== '');
	expect(added.some((line) => line.startsWith('Content-Signal:'))).toBeTruthy();
	await expect(robots.preview.locator('.genready-diff__line--add', { hasText: 'Content-Signal' })).toHaveCount(1);

	await robots.button('apply').click();
	await expect(robots.result).toContainText('Applied. Check the live file in a minute.');
	await expect(robots.state).toHaveText('Applied');

	const served = await page.request.get('/robots.txt');
	expect(served.headers()['content-type']).toContain('text/plain');
	const after = await served.text();
	expect(after).toContain('Content-Signal:');
	// The served file is the captured base plus the previewed change, nothing else.
	expect(without(lines(after), added)).toEqual(without(lines(base), removed));
	for (const line of added) {
		expect(lines(after)).toContain(line);
	}

	// Live check: the local server cannot reach 127.0.0.1.
	await robots.button('live-check').click();
	await expect(robots.result).toContainText('GenReady could not reach your site.');

	await robots.button('undo').click();
	await expect(robots.result).toContainText('Undone.');
	await expect(robots.state).toHaveText('Not applied');
	const restored = await (await page.request.get('/robots.txt')).text();
	expect(restored).not.toContain('Content-Signal');
	expect(restored).toBe(base);

	// JSON-LD without an SEO plugin: the server answers `existing` (server 35cc385), and the default theme
	// prints no JSON-LD, so apply prints GenReady's one node and undo removes it.
	const jsonld = card(page, 'jsonld');
	await expect(jsonld.state).toHaveText('Not applied');
	expect(jsonLdNodes(await (await page.request.get('/')).text())).toEqual([]);
	await jsonld.button('preview').click();
	await expect(jsonld.result).toContainText('Check the preview, then click Apply.');
	await jsonld.root.locator('[data-genready-prop]').evaluateAll((inputs) => {
		for (const input of inputs as HTMLInputElement[]) {
			const prop = input.getAttribute('data-genready-prop');
			input.value = prop === 'logo' ? 'http://127.0.0.1:9411/logo.png' : prop?.startsWith('date') ? '2026-10-01T10:00:00+00:00' : 'GenReady e2e';
		}
	});
	await jsonld.button('apply').click();
	await expect(jsonld.result).toContainText('Applied.');
	await expect(jsonld.state).toHaveText('Applied');
	const printed = jsonLdNodes(await (await page.request.get('/')).text());
	expect(printed, JSON.stringify(printed.map((node) => node['@type']))).toHaveLength(1);

	await jsonld.button('undo').click();
	await expect(jsonld.result).toContainText('Undone.');
	await expect(jsonld.state).toHaveText('Not applied');
	expect(jsonLdNodes(await (await page.request.get('/')).text())).toEqual([]);

	// The refresh lock works on the Playground SQLite database.
	const probe = await (await helper(page)).post('/lock-probe');
	expect(probe).toEqual({ first: true, second: false, row_after_release: 0, third: true });

	// The Account tab names the scans of the plan, and Disconnect reminds of them (Kris 2026-11-12).
	await openGenReady(page, 'connection');
	await expect(page.locator('[data-genready-field="credits"]')).toHaveText(/^(\d+ of \d+ left|\d+ a month|No monthly limit)$/);
	let question = '';
	page.once('dialog', async (dialog) => {
		question = dialog.message();
		await dialog.accept();
	});
	await page.getByRole('button', { name: 'Disconnect' }).click();
	await expect(page.locator('.genready-connect')).toBeVisible();
	expect(question).toMatch(/^Disconnect GenReady\? Without a connection, the plugin cannot use the (\d+ scans a month of your plan|scans of your GenReady plan)\. Applied fixes stay\. Your recent scans come back when you connect this account again\.$/);

	// The same account again: the scans are back, and no scan starts.
	await tools.resetHttpLog();
	await connectWithKey(page, account('pro').apiKey);
	await page.waitForURL(/options-general\.php\?page=genready$/);
	await expect(page.locator('.genready-score')).toBeVisible();
	expect((await tools.httpLog()).filter((entry) => entry.url.includes('/api/v1/analyze'))).toEqual([]);
});
