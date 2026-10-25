const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: 'notifications.spec.cjs',
  outputDir: '../.runtime/notifications-browser-results',
  workers: 1,
  use: { browserName: 'chromium', trace: 'retain-on-failure' },
});
