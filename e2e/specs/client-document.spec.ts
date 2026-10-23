import { test, expect } from '@playwright/test';
import { SITE, login, openConnectForm, openGenReady } from '../lib/helpers';

test('the OAuth client document is 404 until a connect starts', async ({ page, playwright }) => {
	// A visitor with no cookies, so the answer does not depend on a login.
	const visitor = await playwright.request.newContext({ baseURL: SITE });
	const before = await visitor.get('/wp-json/genready/v1/client');
	expect(before.status()).toBe(404);

	await login(page);
	await openGenReady(page);
	await openConnectForm(page);
	expect((await visitor.get('/wp-json/genready/v1/client')).status()).toBe(404);
	await page.getByRole('button', { name: 'Connect to GenReady' }).click();
	await page.waitForURL(/^http:\/\/127\.0\.0\.1:5002\//);

	const after = await visitor.get('/wp-json/genready/v1/client');
	expect(after.status()).toBe(200);
	const document = await after.json();
	expect(document.client_id).toBe(`${SITE}/wp-json/genready/v1/client`);
	expect(document.redirect_uris).toEqual([`${SITE}/wp-admin/admin.php?page=genready&genready_cb=1`]);
	expect(document.token_endpoint_auth_method).toBe('none');
	await visitor.dispose();
});
