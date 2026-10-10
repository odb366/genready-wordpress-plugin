import { test, expect } from '@playwright/test';
import { VERIFY_SHOWN, account, card, connectWithKey, login, openGenReady } from '../lib/helpers';

// Run after `seed-users.cjs --owner=free`, so the 127.0.0.1 domain belongs to the Free account.
test('Free key: no report yet, and the robots.txt fix is for Pro and Agency', async ({ page }) => {
	await login(page);
	await connectWithKey(page, account('free').apiKey);
	const setup = page.locator('#genready-setup');
	await expect(setup.locator('[data-genready-step="verify"]')).toHaveText(VERIFY_SHOWN);
	console.log(`verify step: ${await setup.locator('[data-genready-step="verify"]').innerText()}`);
	await expect(setup.locator('[data-genready-step="scan"].genready-result--error')).toBeVisible();

	// The Free account has no report for this site.
	await openGenReady(page);
	const empty = page.locator('.genready-empty');
	await expect(empty).toHaveAttribute('data-genready-state', 'none');
	await expect(empty.getByRole('heading', { name: 'No scan yet' })).toBeVisible();
	await expect(empty.getByRole('button', { name: 'Scan now' })).toBeVisible();

	await openGenReady(page, 'connection');
	await expect(page.locator('.genready-connection .form-table')).toContainText('Free');

	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await robots.button('preview').click();
	await expect(robots.result).toContainText('GenReady Pro and Agency generate this file.');
	await expect(robots.result.getByRole('link', { name: 'See plans' })).toBeVisible();
	await expect(robots.button('apply')).toBeHidden();
	await expect(robots.preview).toBeHidden();
	await expect(robots.state).toHaveText('Not applied');
	await expect(page.locator('.genready-admin')).not.toContainText(/unlock/i);
});
