import { test, expect } from '@playwright/test';
import { account, card, connectWithKey, helper, login, openGenReady } from '../lib/helpers';

const ORIGINAL = '# Written by hand before GenReady\nUser-agent: *\nDisallow: /private/\n';

// The plugin runs from a copy (blueprints/uninstall.json), because deleting it removes its folder.
test('delete the plugin on the Plugins screen: options gone, robots.txt restored', async ({ page }) => {
	await login(page);
	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await robots.button('preview').click();
	await expect(robots.button('apply')).toBeVisible();
	await robots.button('apply').click();
	await expect(robots.state).toHaveText('Applied');

	const tools = await helper(page);
	// The onboarding options and the pause transient go too (spec 9.3).
	await tools.storeScan('11111111-1111-4111-8111-111111111111', 'http://127.0.0.1:9411/', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ');
	await tools.setOption('genready_proof', { id: 'a'.repeat(32), secret: 'b'.repeat(64), created: 1 });
	await tools.setOption('_transient_genready_anon_off', 1);
	expect(await tools.readFile('robots.txt')).toContain('Content-Signal:');
	expect(await tools.options('genready')).toEqual(expect.arrayContaining(['genready_connection', 'genready_fix_robots', 'genready_settings']));

	// Deactivate, then delete.
	await page.goto('/wp-admin/plugins.php');
	const row = page.locator('tr[data-plugin="genready/genready.php"]');
	await row.getByRole('link', { name: /^Deactivate/ }).click();
	await expect(page.locator('#message, .notice-success').first()).toContainText('deactivated');
	// With its script loaded, WordPress asks in a dialog and deletes over AJAX. Without it, it shows a confirm page.
	page.once('dialog', (dialog) => dialog.accept());
	await page.locator('tr[data-plugin="genready/genready.php"]').getByRole('link', { name: /^Delete/ }).click();
	const confirm = page.getByRole('button', { name: 'Yes, delete these files and data' });
	await expect(confirm.or(page.locator('tr.plugin-deleted-tr'))).toBeVisible();
	if (await confirm.isVisible()) {
		await confirm.click();
	}
	await page.goto('/wp-admin/plugins.php');
	await expect(page.locator('tr[data-plugin="genready/genready.php"]')).toHaveCount(0);

	const after = await helper(page);
	expect(await after.options('genready')).toEqual([]);
	expect(await after.options('_transient_genready')).toEqual([]);
	expect(await after.options('_transient_timeout_genready')).toEqual([]);
	expect(await after.readFile('robots.txt')).toBe(ORIGINAL);
	expect(await (await page.request.get('/robots.txt')).text()).toBe(ORIGINAL);
});
