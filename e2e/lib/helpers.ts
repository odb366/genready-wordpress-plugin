import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

export const API_BASE = 'http://127.0.0.1:5002';
export const SITE = 'http://127.0.0.1:9411';
export const REPORT_ID = '63720e48-6d92-4e0a-a862-a141951beb22';
export const SEO = process.env.GENREADY_E2E_SEO || 'none';

const TOOLS = process.env.GENREADY_E2E_TOOLS || join(__dirname, '../../.superpowers/sdd/2026-10-08-wordpress-plugin-b-plugin/e2e-tools');

type Account = { email: string; password: string; apiKey: string };

/**
 * The test accounts. Read on every call because seed-users.cjs rotates the keys.
 * Never print or log what this returns.
 */
export function account(plan: 'free' | 'pro'): Account {
	const all = JSON.parse(readFileSync(join(homedir(), '.genready-e2e-users.json'), 'utf8'));
	return all[plan] as Account;
}

export async function login(page: Page, user = 'admin'): Promise<void> {
	await page.goto('/wp-login.php');
	await page.fill('#user_login', user);
	await page.fill('#user_pass', 'password');
	await Promise.all([page.waitForURL(/\/wp-admin\//), page.click('#wp-submit')]);
}

/** Open a GenReady tab. `site` is a sub-site path such as '/site2' on a multisite network. */
export async function openGenReady(page: Page, tab: 'overview' | 'fixes' | 'connection' = 'overview', site = ''): Promise<void> {
	const url = `${site}/wp-admin/options-general.php?page=genready&tab=${tab}`;
	await page.goto(url);
	if (!page.url().includes('page=genready')) {
		// An SEO plugin may send the first admin visit to its setup wizard.
		await page.goto(url);
	}
	await expect(page.locator('.genready-admin h1')).toHaveText('GenReady');
}

/** Run seed-anon-scan.cjs (test DB only) and return its JSON line. */
export function anonSeed(...args: string[]): any {
	const out = execFileSync('node', [join(TOOLS, 'seed-anon-scan.cjs'), ...args], { encoding: 'utf8' });
	return JSON.parse(out.trim().split('\n').pop() || '{}');
}

/** Open the connect form that the Overview shows behind "Already have a GenReady account? Connect". */
export async function openConnectForm(page: Page): Promise<void> {
	const link = page.getByRole('button', { name: 'Already have a GenReady account? Connect' }).first();
	if (await link.isVisible()) {
		await link.click();
	}
	await expect(page.getByRole('button', { name: 'Connect to GenReady' })).toBeVisible();
}

/** Connect with an API key from the Overview tab and wait for the verify and scan steps to finish. */
export async function connectWithKey(page: Page, key: string, site = ''): Promise<void> {
	await openGenReady(page, 'overview', site);
	await openConnectForm(page);
	await page.getByRole('button', { name: 'Use an API key' }).click();
	await page.locator('#genready-key').fill(key);
	await page.getByRole('button', { name: 'Connect with the key' }).click();
	await expect(page.locator('.genready-connect [data-genready-field="result"]')).toContainText('GenReady is connected.');
}

/**
 * The text the verify step may show after a connection. The seeded 127.0.0.1 domain is verified ("already
 * verified"); without it the local server cannot fetch 127.0.0.1 and sends a detail ("Could not load ...").
 * The server allows 10 verify checks per hour for one account and site, so a full suite run can also get
 * the plugin's 429 text.
 */
export const VERIFY_SHOWN = /^(Your site is verified\.|Could not load .*|GenReady is busy\. Try again in \d+ minutes?\.)/;

/** A REST client for the test-only helper routes, with the logged-in cookies and a wp_rest nonce. */
export async function helper(page: Page, base = ''): Promise<Helper> {
	const nonceResponse = await page.request.get(`${base}/wp-admin/admin-ajax.php?action=rest-nonce`);
	expect(nonceResponse.ok()).toBeTruthy();
	return new Helper(page.request, (await nonceResponse.text()).trim(), base);
}

export class Helper {
	constructor(private request: APIRequestContext, private nonce: string, private base = '') {}

	private headers() {
		return { 'X-WP-Nonce': this.nonce };
	}

	/** Every helper URL uses ?rest_route=, so the helper works with plain permalinks too. */
	private url(path: string): string {
		const [route, query] = path.split('?');
		return `${this.base}/?rest_route=/genready-e2e/v1${route}${query ? `&${query}` : ''}`;
	}

	async get(path: string): Promise<any> {
		const response = await this.request.get(this.url(path), { headers: this.headers() });
		expect(response.status(), `GET ${path}`).toBe(200);
		return response.json();
	}

	async post(path: string, data: object = {}): Promise<any> {
		const response = await this.request.post(this.url(path), { headers: this.headers(), data });
		expect(response.status(), `POST ${path}`).toBe(200);
		return response.json();
	}

	setOption(name: string, value: unknown) {
		return this.post('/option', { name, value });
	}

	mergeOption(name: string, value: Record<string, unknown>) {
		return this.post('/option', { name, value, merge: true });
	}

	deleteOption(name: string) {
		return this.post('/option', { name, delete: true });
	}

	async option(name: string): Promise<any> {
		return (await this.get(`/option?name=${encodeURIComponent(name)}`)).value;
	}

	options(prefix: string): Promise<string[]> {
		return this.get(`/options?prefix=${encodeURIComponent(prefix)}`);
	}

	async readFile(name: 'robots.txt' | 'llms.txt'): Promise<string | null> {
		return (await this.get(`/file?name=${name}`)).content;
	}

	writeFile(name: 'robots.txt' | 'llms.txt', content: string) {
		return this.post('/file', { name, content });
	}

	httpLog(): Promise<Array<{ url: string; front: boolean; page: string }>> {
		return this.get('/http-log');
	}

	resetHttpLog() {
		return this.post('/http-log/reset');
	}

	storeScan(id: string, url: string, token: string) {
		return this.post('/anon-scan', { id, url, token });
	}

	fakeWp(mode: 'off' | '') {
		return this.post('/fake-wp', { mode });
	}

	blockProof(on: boolean) {
		return this.post('/proof-block', { on });
	}

	permalinks(structure: string) {
		return this.post('/permalinks', { structure });
	}

	proofProbe(): Promise<{ first: number; second: number }> {
		return this.post('/proof-probe');
	}
}

/** Every JSON-LD node on a page: each script block, and each node inside an @graph. */
export function jsonLdNodes(html: string): Array<Record<string, unknown>> {
	const nodes: Array<Record<string, unknown>> = [];
	const pattern = /<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi;
	let match: RegExpExecArray | null;
	while ((match = pattern.exec(html)) !== null) {
		const data = JSON.parse(match[1]);
		const blocks = Array.isArray(data) ? data : [data];
		for (const block of blocks) {
			if (block && Array.isArray(block['@graph'])) {
				nodes.push(...block['@graph']);
			} else if (block && typeof block === 'object') {
				nodes.push(block);
			}
		}
	}
	return nodes;
}

export function hasType(node: Record<string, unknown>, type: string): boolean {
	const value = node['@type'];
	return Array.isArray(value) ? value.includes(type) : value === type;
}

/** The type groups of JsonLdFix::TYPE_GROUPS: two nodes of one group on a page are a duplicate. */
const TYPE_GROUPS = [
	['Article', 'NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle', 'LiveBlogPosting', 'Report'],
	['WebPage', 'CollectionPage', 'ProfilePage', 'AboutPage', 'ContactPage', 'ItemPage', 'SearchResultsPage', 'CheckoutPage', 'MedicalWebPage', 'RealEstateListing'],
	['Organization', 'Corporation', 'LocalBusiness', 'OnlineBusiness', 'NGO', 'EducationalOrganization', 'NewsMediaOrganization'],
];

/** True when the node has the type, or a type of the same group. */
export function hasKind(node: Record<string, unknown>, type: string): boolean {
	const group = TYPE_GROUPS.find((types) => types.includes(type)) || [type];
	return group.some((member) => hasType(node, member));
}

/** A fix card on the Fixes tab. */
export function card(page: Page, kind: 'robots' | 'llms' | 'jsonld') {
	const root = page.locator(`#genready-${kind}`);
	return {
		root,
		state: root.locator('[data-genready-field="state"]'),
		message: root.locator('[data-genready-field="message"]'),
		live: root.locator('[data-genready-field="live"]'),
		result: root.locator('[data-genready-field="result"]'),
		preview: root.locator('[data-genready-field="preview"]'),
		button: (action: string) => root.locator(`[data-genready-action="${action}"]`),
	};
}
