import { test, expect } from '@playwright/test';
import { SEO, account, card, connectWithKey, hasKind, jsonLdNodes, login, openGenReady } from '../lib/helpers';

function blockCount(html: string): number {
	return (html.match(/<script[^>]*application\/ld\+json/gi) || []).length;
}

const FILL: Record<string, string> = {
	headline: 'GenReady browser test',
	name: 'GenReady browser test',
	author: 'GenReady e2e',
	description: 'A small WordPress site that GenReady uses in its browser tests.',
	datePublished: '2026-10-01T10:00:00+00:00',
	dateModified: '2026-10-02T10:00:00+00:00',
	logo: 'http://127.0.0.1:9411/wp-includes/images/w-logo-blue.png',
};

test(`JSON-LD with ${SEO}: apply merges into the SEO graph, one node of the type`, async ({ page }) => {
	test.skip(SEO === 'none', 'runs on an SEO plugin blueprint');
	await login(page);
	const beforeHtml = await (await page.request.get('/')).text();
	const before = jsonLdNodes(beforeHtml);
	expect(before.length).toBeGreaterThan(0);

	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const jsonld = card(page, 'jsonld');
	await jsonld.button('preview').click();
	await expect(jsonld.result).toContainText('Check the preview, then click Apply.');
	const inputs = jsonld.root.locator('[data-genready-prop]');
	for (let i = 0; i < (await inputs.count()); i++) {
		const input = inputs.nth(i);
		const prop = (await input.getAttribute('data-genready-prop')) || '';
		await input.fill(FILL[prop] ?? 'GenReady e2e');
	}
	await jsonld.button('apply').click();
	await expect(jsonld.result).toContainText('Applied.');
	await expect(jsonld.state).toHaveText('Applied');
	const message = await jsonld.message.innerText();
	const type = /GenReady adds (\S+) markup/.exec(message)?.[1];
	expect(type, message).toBeTruthy();

	const html = await (await page.request.get('/')).text();
	const nodes = jsonLdNodes(html);
	// Exactly one node of the type (or of its group: Yoast marks a blog home page CollectionPage).
	const ofKind = nodes.filter((node) => hasKind(node, type as string));
	expect(ofKind, `${type} nodes: ${JSON.stringify(nodes.map((node) => node['@type']))}`).toHaveLength(1);
	// GenReady merged into the SEO plugin's graph: it printed no block of its own, and it added properties.
	expect(blockCount(html)).toBe(blockCount(beforeHtml));
	const earlier = before.filter((node) => hasKind(node, type as string));
	if (earlier.length === 1) {
		console.log(`${SEO}: ${type} merged into ${JSON.stringify(earlier[0]['@type'])}; keys before ${Object.keys(earlier[0]).sort()} after ${Object.keys(ofKind[0]).sort()}`);
		expect(Object.keys(ofKind[0]).length).toBeGreaterThan(Object.keys(earlier[0]).length);
	} else {
		console.log(`${SEO}: ${type} added as a new node; keys ${Object.keys(ofKind[0]).sort()}`);
	}

	await jsonld.button('undo').click();
	await expect(jsonld.result).toContainText('Undone.');
	const after = jsonLdNodes(await (await page.request.get('/')).text());
	expect(after.filter((node) => hasKind(node, type as string))).toEqual(before.filter((node) => hasKind(node, type as string)));
});
