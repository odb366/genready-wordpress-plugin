import { test, expect } from '@playwright/test';
import { VERIFY_SHOWN, account, card, connectWithKey, helper, jsonLdNodes, login, openGenReady } from '../lib/helpers';

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

	await connectWithKey(page, account('pro').apiKey);

	// Verify: the seeded domain is verified ("already verified"); without it the local server cannot reach
	// 127.0.0.1 and answers not verified with a detail. The scan of 127.0.0.1 always fails (SSRF guard).
	const setup = page.locator('#genready-setup');
	await expect(setup.locator('[data-genready-step="verify"]')).toHaveText(VERIFY_SHOWN);
	console.log(`verify step: ${await setup.locator('[data-genready-step="verify"]').innerText()}`);
	await expect(setup.locator('[data-genready-step="scan"].genready-result--error')).toContainText('GenReady could not do this. Try again.');

	// Overview from the seeded report.
	await openGenReady(page);
	const score = page.locator('.genready-score');
	await expect(score.getByRole('heading', { name: 'AI Readiness Score' })).toBeVisible();
	await expect(score.locator('svg')).toHaveCount(3);
	await expect(score.locator('.genready-band')).toHaveText(/^(Pass|Fair|Poor|Fail)$/);
	await expect(score.locator('.genready-score__items')).toContainText('Content');
	await expect(score.locator('.genready-score__items')).toContainText('Crawlability');

	const shotButton = page.getByRole('button', { name: 'Screenshot', exact: true });
	const textButton = page.getByRole('button', { name: 'What AI reads' });
	const shotPanel = page.locator('[data-genready-panel="screenshot"]');
	const textPanel = page.locator('[data-genready-panel="text"]');
	await textButton.click();
	await expect(textButton).toHaveAttribute('aria-pressed', 'true');
	await expect(shotButton).toHaveAttribute('aria-pressed', 'false');
	await expect(textPanel).toBeVisible();
	await expect(shotPanel).toBeHidden();
	await expect(textPanel.locator('pre.genready-ai-text')).not.toBeEmpty();
	await shotButton.click();
	await expect(shotButton).toHaveAttribute('aria-pressed', 'true');
	await expect(shotPanel).toBeVisible();
	await expect(textPanel).toBeHidden();

	// robots.txt: preview, apply, check the served file, undo.
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await expect(robots.state).toHaveText('Not applied');
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

	// JSON-LD without an SEO plugin: the server answer has no `existing` yet, so apply refuses and nothing is printed.
	const jsonld = card(page, 'jsonld');
	await jsonld.button('preview').click();
	await expect(jsonld.result).not.toBeEmpty();
	if (await jsonld.button('apply').isVisible()) {
		await jsonld.root.locator('[data-genready-prop]').evaluateAll((inputs) => {
			for (const input of inputs as HTMLInputElement[]) {
				const prop = input.getAttribute('data-genready-prop');
				input.value = prop === 'logo' ? 'http://127.0.0.1:9411/logo.png' : prop?.startsWith('date') ? '2026-10-01T10:00:00+00:00' : 'GenReady e2e';
			}
		});
		await jsonld.button('apply').click();
	}
	await expect(jsonld.result).toContainText('GenReady needs a newer server answer to check this page for existing markup. Try again later.');
	await expect(jsonld.state).toHaveText('Not applied');
	const home = await (await page.request.get('/')).text();
	// The default theme prints no JSON-LD, so any block would be GenReady's.
	expect(jsonLdNodes(home)).toEqual([]);

	// The refresh lock works on the Playground SQLite database.
	const probe = await (await helper(page)).post('/lock-probe');
	expect(probe).toEqual({ first: true, second: false, row_after_release: 0, third: true });
});
