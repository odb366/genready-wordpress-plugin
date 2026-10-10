import { test, expect } from '@playwright/test';
import { account, card, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

const ORIGINAL = '# Written by hand before GenReady\nUser-agent: *\nDisallow: /private/\n';

test('physical robots.txt: apply writes the file, and undo refuses after a hand edit', async ({ page }) => {
	await login(page);
	const tools = await helper(page);
	expect(await tools.readFile('robots.txt')).toBe(ORIGINAL);
	expect(await (await page.request.get('/robots.txt')).text()).toBe(ORIGINAL);

	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await expect(robots.button('download')).toBeHidden();
	await robots.button('preview').click();
	await expect(robots.result).toContainText('Check the preview, then click Apply.');
	await robots.button('apply').click();
	await expect(robots.result).toContainText('Applied. Check the live file in a minute.');

	const written = await tools.readFile('robots.txt');
	expect(written).toContain('Content-Signal:');
	expect(written).toContain('Disallow: /private/');
	expect(await (await page.request.get('/robots.txt')).text()).toBe(written);

	// Someone edits the file by hand.
	const edited = `${written}\n# Edited by hand after GenReady\n`;
	await tools.writeFile('robots.txt', edited);

	await openGenReady(page, 'fixes');
	await robots.button('undo').click();
	await expect(robots.result).toContainText('robots.txt was edited after GenReady changed it, so GenReady did not undo it. Check the file yourself.');
	expect(await tools.readFile('robots.txt')).toBe(edited);
	await expect(robots.state).toHaveText('Applied');
});
