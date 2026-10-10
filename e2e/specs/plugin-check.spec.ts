import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { login } from '../lib/helpers';

type Finding = { type: 'ERROR' | 'WARNING'; file: string; line: string; column: string; code: string; message: string };

/** Flatten Plugin Check's { file: { line: { column: [ { code, message } ] } } } shape. */
function flatten(type: Finding['type'], byFile: Record<string, any> | undefined): Finding[] {
	const out: Finding[] = [];
	for (const [file, lines] of Object.entries(byFile || {})) {
		for (const [line, columns] of Object.entries(lines as Record<string, any>)) {
			for (const [column, items] of Object.entries(columns as Record<string, any>)) {
				for (const item of items as Array<Record<string, string>>) {
					out.push({ type, file, line, column, code: item.code, message: item.message });
				}
			}
		}
	}
	return out;
}

// Runs on blueprints/plugin-check.json with the release copy mounted (see run-all.sh).
// WP-CLI `wp plugin check` crashes in Playground's PHP (RuntimeError: unreachable), so this uses the Tools screen.
test('Plugin Check: no errors on the release copy', async ({ page }) => {
	test.skip(process.env.GENREADY_E2E_PLUGIN_CHECK !== '1', 'runs only in the Plugin Check run');
	test.setTimeout(900_000);
	await login(page);
	const findings: Finding[] = [];
	page.on('response', async (response) => {
		if (!response.url().includes('admin-ajax.php') || response.request().method() !== 'POST') {
			return;
		}
		const body = response.request().postData() || '';
		if (!body.includes('plugin_check_run_checks')) {
			return;
		}
		const json = await response.json().catch(() => null);
		if (json && json.success && json.data) {
			findings.push(...flatten('ERROR', json.data.errors), ...flatten('WARNING', json.data.warnings));
		}
	});

	await page.goto('/wp-admin/tools.php?page=plugin-check');
	await page.locator('#plugin-check__plugins-dropdown').selectOption('genready/genready.php');
	for (const box of await page.locator('#plugin-check__categories input[type="checkbox"]').all()) {
		await box.check();
	}
	await page.locator('#plugin-check__submit').click();
	await expect(page.locator('#plugin-check__results')).toContainText(/Checks complete|No errors found|errors|warnings/i, { timeout: 840_000 });
	await expect(page.locator('#plugin-check__spinner.is-active')).toHaveCount(0, { timeout: 840_000 });

	const dir = join(__dirname, '..', 'test-results');
	mkdirSync(dir, { recursive: true });
	writeFileSync(join(dir, 'plugin-check.json'), JSON.stringify(findings, null, 2));
	for (const finding of findings) {
		console.log(`${finding.type} ${finding.file}:${finding.line} ${finding.code} ${finding.message}`);
	}
	console.log(`Plugin Check: ${findings.filter((f) => f.type === 'ERROR').length} errors, ${findings.filter((f) => f.type === 'WARNING').length} warnings`);
	console.log('RESULTS TEXT:', (await page.locator('#plugin-check__results').innerText()).slice(0, 400));
	expect(findings.filter((finding) => finding.type === 'ERROR')).toEqual([]);
});
