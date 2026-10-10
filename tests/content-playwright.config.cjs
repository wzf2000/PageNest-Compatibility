for (const key of ['NO_PROXY', 'no_proxy']) {
  process.env[key] = [process.env[key], '127.0.0.1', 'localhost'].filter(Boolean).join(',');
}
const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'content-browser.spec.cjs',
  use: { baseURL: 'http://127.0.0.1:18783', trace: 'retain-on-failure' },
  webServer: {
    cwd: require('node:path').resolve(__dirname, '..'),
    command: 'php tests/content-browser-fixture.php && php -S 127.0.0.1:18783 -t .runtime/content',
    url: 'http://127.0.0.1:18783/resources.html',
    reuseExistingServer: false,
  },
});
