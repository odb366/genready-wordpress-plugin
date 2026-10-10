import { defineConfig } from '@playwright/test';

// One Playground site per run (see run.sh), so the specs run one at a time.
// Traces, screenshots and videos are off: pages show test account data.
export default defineConfig({
	testDir: './specs',
	workers: 1,
	fullyParallel: false,
	retries: 0,
	timeout: 240_000,
	expect: { timeout: 30_000 },
	reporter: [['list']],
	outputDir: './test-results/output',
	use: {
		baseURL: 'http://127.0.0.1:9411',
		trace: 'off',
		screenshot: 'off',
		video: 'off',
		actionTimeout: 60_000,
		navigationTimeout: 120_000,
	},
});
