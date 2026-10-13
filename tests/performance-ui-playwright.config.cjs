for (const key of ['NO_PROXY', 'no_proxy']) {
  process.env[key] = [process.env[key], '127.0.0.1', 'localhost'].filter(Boolean).join(',');
}
const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'performance-ui.spec.cjs',
  outputDir: '/tmp/pagenest-performance-ui-results',
  use: {
    baseURL: 'http://127.0.0.1:18786',
    trace: 'retain-on-failure',
    launchOptions: {
      executablePath: process.env.PAGENEST_UI_BROWSER || undefined,
      chromiumSandbox: true,
    },
  },
  webServer: {
    cwd: require('node:path').resolve(__dirname, '..'),
    command: 'php tests/performance-ui-generate.php && php -S 127.0.0.1:18786 -t .',
    url: 'http://127.0.0.1:18786/.runtime/performance/completed.html',
    reuseExistingServer: false,
  },
});
