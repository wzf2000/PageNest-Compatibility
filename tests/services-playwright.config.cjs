const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'services-turnstile.spec.cjs',
  outputDir: '/tmp/pagenest-services-browser-results',
  use: { trace: 'retain-on-failure' },
});
