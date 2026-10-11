import { test, expect, type BrowserContext } from '@playwright/test';
import { account, card, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

// Runs on blueprints/multisite.json with GENREADY_E2E_SITE_URL=http://127.0.0.1 (see run-all.sh):
// WordPress multisite cannot run on a port, so the network's URLs have none. The browser maps
// http://127.0.0.1/ back to the Playground on 9411 and aborts every other address, so nothing
// reaches port 80 on this host. API requests from the specs go to 9411 directly.
const PLAYGROUND = 'http://127.0.0.1:9411';
const ROBOTS = '# Written by hand before GenReady\nUser-agent: *\nDisallow: /private/\n';
const LLMS = '# Hand-written llms.txt\n\n> Written before GenReady.\n';
const NETWORK_LLMS = 'This site is part of a multisite network. Only a network admin on the main site can change llms.txt in the root folder.';
const SUBDIR_ROBOTS = 'robots.txt works only at the root of a domain. This site lives in a subfolder, so GenReady cannot change robots.txt from WordPress.';

async function mapPortlessSite(context: BrowserContext): Promise<void> {
	await context.route('**/*', async (route) => {
		const url = new URL(route.request().url());
		if (url.hostname === '127.0.0.1' && (url.port === '' || url.port === '80')) {
			const target = `${PLAYGROUND}${url.pathname}${url.search}`;
			if (!route.request().isNavigationRequest()) {
				await route.continue({ url: target });
				return;
			}
			const response = await route.fetch({ url: target, maxRedirects: 0 });
			// Send redirects to 9411 too: Chromium upgrades a top-level http://127.0.0.1/ redirect to https.
			const headers = { ...response.headers() };
			if (headers.location) {
				headers.location = headers.location.replace(/^https?:\/\/127\.0\.0\.1(:80)?\//, `${PLAYGROUND}/`);
			}
			await route.fulfill({ response, headers });
			return;
		}
		if (url.hostname === '127.0.0.1' && (url.port === '9411' || url.port === '5002')) {
			await route.continue();
			return;
		}
		await route.abort();
	});
}

test.skip(process.env.GENREADY_E2E_MULTISITE !== '1', 'runs only on the multisite blueprint');
// Chromium may upgrade http://127.0.0.1/ (no port) to https, which the mapping cannot serve.
test.use({ launchOptions: { args: ['--disable-features=HttpsUpgrades,HttpsFirstBalancedMode,HttpsFirstBalancedModeAutoEnable,HttpsFirstModeV2ForEngagedSites'] } });

test('sub-site admin: the shared files are refused and nothing is written', async ({ page, context }) => {
	await mapPortlessSite(context);
	await login(page, 'subadmin');
	await connectWithKey(page, account('pro').apiKey, '/site2');

	await openGenReady(page, 'fixes', '/site2');
	const llms = card(page, 'llms');
	await expect(llms.message).toHaveText(NETWORK_LLMS);
	await expect(llms.button('preview')).toBeDisabled();
	await expect(llms.button('apply')).toBeHidden();
	await expect(llms.button('as-file')).toBeHidden();
	// In a subdirectory network a sub-site lives in a subfolder, so robots.txt gives the subfolder refusal.
	const robots = card(page, 'robots');
	await expect(robots.message).toHaveText(SUBDIR_ROBOTS);
	await expect(robots.button('preview')).toBeDisabled();

	// The REST routes refuse too, with no call to GenReady.
	const answers = await page.evaluate(async () => {
		const wp = (window as unknown as { wp: { apiFetch: (o: object) => Promise<unknown> } }).wp;
		const out: Record<string, string> = {};
		for (const [kind, data] of [['llms', {}], ['robots', { content_signal: true }]] as const) {
			try {
				await wp.apiFetch({ path: `/genready/v1/fixes/${kind}/preview`, method: 'POST', data });
				out[kind] = 'accepted';
			} catch (error) {
				out[kind] = (error as { message?: string }).message || 'error';
			}
		}
		try {
			await wp.apiFetch({ path: '/genready/v1/fixes/llms/apply', method: 'POST', data: { as_file: true } });
			out.asFile = 'accepted';
		} catch (error) {
			out.asFile = (error as { message?: string }).message || 'error';
		}
		return out;
	});
	expect(answers.llms).toBe(NETWORK_LLMS);
	expect(answers.robots).toBe(SUBDIR_ROBOTS);
	expect(answers.asFile).not.toBe('accepted');

	// The files in the network root are unchanged.
	expect(await (await page.request.get(`${PLAYGROUND}/robots.txt`)).text()).toBe(ROBOTS);
	expect(await (await page.request.get(`${PLAYGROUND}/llms.txt`)).text()).toBe(LLMS);
});

test('network admin on the main site: robots.txt applies in file mode and undoes', async ({ page, context }) => {
	await mapPortlessSite(context);
	await login(page);
	await connectWithKey(page, account('pro').apiKey);

	await openGenReady(page, 'fixes');
	await expect(card(page, 'llms').message).toBeHidden();
	await expect(card(page, 'llms').button('preview')).toBeEnabled();
	const robots = card(page, 'robots');
	await expect(robots.message).toBeHidden();
	await robots.button('preview').click();
	await expect(robots.result).toContainText('Check the preview, then click Apply.');
	await robots.button('apply').click();
	await expect(robots.result).toContainText('Applied. Check the live file in a minute.');

	const tools = await helper(page, PLAYGROUND);
	const written = await tools.readFile('robots.txt');
	expect(written).toContain('Content-Signal:');
	expect(written).toContain('Disallow: /private/');

	await robots.button('undo').click();
	await expect(robots.result).toContainText('Undone.');
	expect(await tools.readFile('robots.txt')).toBe(ROBOTS);
});
