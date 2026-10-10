/** Synthetic administrator markup only; all network requests are blocked. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('@playwright/test');
(async () => {
  const root = process.env.PAGENEST_SOCIAL_SECURITY_UI_ROOT;
  assert(root, 'Synthetic UI directory required');
  const markup = fs.readFileSync(path.join(root, 'provider-settings.html'), 'utf8');
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      await page.route('**/*', (route) => route.abort());
      await page.setContent(
        `<style>body{margin:20px;font-family:sans-serif}input{box-sizing:border-box}fieldset{min-width:0}</style>${markup}`,
      );
      assert.equal(await page.locator('form form').count(), 0);
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      assert((await page.locator('form').count()) >= 7);
      for (const password of await page.locator('input[type="password"]').all()) {
        assert.equal(await password.inputValue(), '');
        assert(await password.evaluate((el) => el.labels.length > 0));
      }
      for (const form of await page.locator('form').all()) {
        assert.equal(await form.getAttribute('method'), 'post');
        assert.equal(await form.locator('input[name="_wpnonce"]').count(), 1);
      }
      await page.close();
    }
    console.log(
      'Social provider settings: synthetic desktop/mobile layout, independent POST forms and empty labelled secret inputs passed.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
