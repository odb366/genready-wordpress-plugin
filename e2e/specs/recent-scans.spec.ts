import { test, expect } from '@playwright/test';
import { SITE, anonSeed, card, helper, login, openGenReady } from '../lib/helpers';

test('two stored scans: the page heading, the recent list, the home page, and the Fixes text', async ({ page }) => {
	anonSeed('reset');
	await login(page);
	const tools = await helper(page);
	await tools.deleteOption('genready_anonymous_scans');
	const home = anonSeed('scan', `${SITE}/`);
	const other = anonSeed('scan', `${SITE}/sample-page/`);
	await tools.storeScan(home.scanId, `${SITE}/`, home.token);
	await tools.storeScan(other.scanId, `${SITE}/sample-page/`, other.token);

	// The newest scan opens first. GenReady serves it through GET /api/v1/wp/scans/:id with its token.
	await openGenReady(page);
	await expect(page.getByRole('heading', { name: 'Save your site and track your score' })).toBeVisible();
	await expect(page.getByRole('heading', { name: 'How AI sees this page' })).toBeVisible();
	await expect(page.locator('.genready-aiview__url')).toHaveText(`${SITE}/sample-page/`);
	await expect(page.locator('.genready-score')).toBeVisible();
	const recent = page.locator('.genready-recent');
	await expect(recent.getByRole('heading', { name: 'Recent scans' })).toBeVisible();
	await expect(recent.locator('[aria-current="true"]')).toHaveText('/sample-page/');

	await recent.getByRole('link', { name: 'Home page' }).click();
	await expect(page.getByRole('heading', { name: 'How AI sees your homepage' })).toBeVisible();
	await expect(recent.locator('[aria-current="true"]')).toHaveText('Home page');

	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await expect(robots.message).toHaveText('Fixes come with GenReady Pro and Agency. Create a free account first, then upgrade when you are ready.');
	await expect(robots.button('preview')).toBeDisabled();
	await expect(robots.root.getByRole('link', { name: 'Create my free account' })).toHaveAttribute('href', /#genready-account$/);
});
