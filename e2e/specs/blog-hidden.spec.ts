import { test, expect } from '@playwright/test';
import { account, card, connectWithKey, login, openGenReady } from '../lib/helpers';

test('site hidden from search engines: the robots.txt card explains and offers no preview', async ({ page }) => {
	await login(page);
	await connectWithKey(page, account('pro').apiKey);
	await openGenReady(page, 'fixes');
	const robots = card(page, 'robots');
	await expect(robots.message).toHaveText('This site asks search engines not to index it (Settings → Reading), so GenReady does not open it to AI crawlers.');
	await expect(robots.button('preview')).toBeDisabled();
	await expect(robots.button('apply')).toBeHidden();
	// The llms.txt card is not blocked by this setting.
	await expect(card(page, 'llms').button('preview')).toBeEnabled();
});
