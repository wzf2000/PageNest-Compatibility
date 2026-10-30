const { defineConfig } = require('@playwright/test');
const path = require('node:path');
const base = require('../playwright.config.cjs');
// Reuse the actual reader/admin regressions without multiplying the full browser suite.
module.exports = defineConfig({
  ...base,
  testDir: __dirname,
  testMatch: [
    'reading-center.spec.cjs',
    'reading-article-scope.spec.cjs',
    'account-reading.spec.cjs',
    'reading-sync.spec.cjs',
    'admin-design.spec.cjs',
    'pdf-viewer.spec.cjs',
    'notifications.spec.cjs',
  ],
  testIgnore: [],
  outputDir: '../.runtime/reader-ux-webkit-results',
  forbidOnly: !!process.env.CI,
  workers: 1,
  projects: [{ name: 'webkit', use: { browserName: 'webkit' } }],
  webServer: { ...base.webServer, cwd: path.resolve(__dirname, '..') },
});
