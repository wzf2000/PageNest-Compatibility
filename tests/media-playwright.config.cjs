const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: ['media.spec.cjs', 'media-library.spec.cjs', 'pdf-viewer.spec.cjs'],
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  use: {
    browserName: 'chromium',
    viewport: { width: 1000, height: 900 },
    reducedMotion: 'reduce',
    trace: 'retain-on-failure',
  },
});
