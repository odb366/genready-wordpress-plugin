import { test, expect } from '@playwright/test';
import { type APIRequestContext } from '@playwright/test';
import { SEO, account, card, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

/** True when /llms.txt answers 200 text/plain itself (no redirect to a WordPress page). */
async function servesLlms(request: APIRequestContext): Promise<boolean> {
	const response = await request.get('/llms.txt', { maxRedirects: 0 });
	return response.status() === 200 && (response.headers()['content-type'] || '').startsWith('text/plain');
}

test('llms.txt: apply serves a text file that lists the sample page, undo removes it', async ({ page }) => {
	test.skip(SEO !== 'none', 'runs on the base blueprint');
	await login(page);
	expect(await servesLlms(page.request)).toBe(false);

	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const llms = card(page, 'llms');
	await llms.root.locator('[data-genready-input="tagline"]').fill('A small WordPress site that GenReady uses in its browser tests.');
	await llms.button('preview').click();
	await expect(llms.result).toContainText('Check the preview, then click Apply.');
	await llms.button('apply').click();
	await expect(llms.result).toContainText('Applied. Check the live file in a minute.');
	await expect(llms.state).toHaveText('Applied');

	const served = await page.request.get('/llms.txt', { maxRedirects: 0 });
	expect(served.status()).toBe(200);
	expect(served.headers()['content-type']).toBe('text/plain; charset=utf-8');
	const body = await served.text();
	expect(body).toContain('Sample Page');
	expect(body).toContain('http://127.0.0.1:9411/sample-page/');
	expect(body).toContain('A small WordPress site that GenReady uses in its browser tests.');

	await llms.button('undo').click();
	await expect(llms.result).toContainText('Undone.');
	expect(await servesLlms(page.request)).toBe(false);
});

test('llms.txt with Yoast llms.txt on: no preview or apply, and the setting is named', async ({ page }) => {
	test.skip(SEO !== 'yoast', 'runs on the Yoast blueprint');
	await login(page);
	await connectWithKey(page, account('pro').apiKey);
	await (await helper(page)).mergeOption('wpseo', { enable_llms_txt: true });

	await openGenReady(page, 'fixes');
	const llms = card(page, 'llms');
	await expect(llms.message).toHaveText("Yoast SEO already makes your llms.txt. To use GenReady's file, turn this off: Yoast SEO → Settings → Site features → AI tools → llms.txt.");
	await expect(llms.button('preview')).toBeDisabled();
	await expect(llms.button('apply')).toBeHidden();
});
