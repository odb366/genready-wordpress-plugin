import { test, expect } from '@playwright/test';
import { API_BASE, SITE, account, card, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

test('visitors get no cookie, script, style or GenReady call from the plugin', async ({ page, browser }) => {
	// Connect and apply the robots.txt and llms.txt fixes, so every front-end hook of the plugin is active.
	await login(page);
	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await robots.button('preview').click();
	await expect(robots.button('apply')).toBeVisible();
	await robots.button('apply').click();
	await expect(robots.state).toHaveText('Applied');
	const llms = card(page, 'llms');
	await llms.root.locator('[data-genready-input="tagline"]').fill('A small WordPress site that GenReady uses in its browser tests.');
	await llms.button('preview').click();
	await expect(llms.button('apply')).toBeVisible();
	await llms.button('apply').click();
	await expect(llms.state).toHaveText('Applied');

	const tools = await helper(page);
	await tools.resetHttpLog();

	// A visitor with no cookies.
	const visitor = await browser.newContext();
	const requests: string[] = [];
	const visitorPage = await visitor.newPage();
	visitorPage.on('request', (request) => requests.push(request.url()));
	let html = '';
	for (const path of ['/', '/sample-page/', '/hello-world/']) {
		const response = await visitorPage.goto(`${SITE}${path}`);
		expect(response?.status(), path).toBe(200);
		html += await visitorPage.content();
	}
	for (const path of ['/robots.txt', '/llms.txt']) {
		const response = await visitor.request.get(`${SITE}${path}`, { maxRedirects: 0 });
		expect(response.status(), path).toBe(200);
		expect(response.headers()['set-cookie'], `${path} sets a cookie`).toBeUndefined();
	}

	expect(await visitor.cookies()).toEqual([]);
	expect(requests.filter((url) => url.startsWith(API_BASE) || /genready\.ai/i.test(url))).toEqual([]);
	expect(requests.filter((url) => /genready/i.test(url))).toEqual([]);
	expect(html).not.toMatch(/<(script|link)[^>]+genready/i);
	// The verification meta tag is the only GenReady markup a visitor may get (home page only).
	expect(html.replace(/<meta name="genready-site-verification"[^>]*>/g, '')).not.toMatch(/genready/i);

	// The server side made no call either: the helper logs every outbound request.
	const log = await tools.httpLog();
	expect(log.filter((entry) => entry.url.startsWith(API_BASE) || /genready\.ai/i.test(entry.url))).toEqual([]);
	await visitor.close();
});
