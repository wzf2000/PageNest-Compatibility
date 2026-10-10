const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('@playwright/test');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.route('**/*', (route) => route.abort());
      const css = fs.readFileSync(path.join(__dirname, '../assets/social-ui.css'), 'utf8');
      await page.setContent(
        `<style>body{margin:16px} ${css}</style><div class="pagenest-social-buttons"><a class="button" href="/start/qq">QQ 登录</a><a class="button" href="/start/sina">微博 登录</a><a class="button" href="/start/baidu">百度 登录</a></div><aside class="pagenest-social-share" aria-label="分享文章"><span>分享：</span><a href="https://connect.qq.com/widget/shareqq/index.html" rel="noopener noreferrer">QQ</a><a href="https://service.weibo.com/share/share.php" rel="noopener noreferrer">微博</a><a href="https://sns.qzone.qq.com/cgi-bin/qzshare/cgi_qzshare_onekey" rel="noopener noreferrer">QQ 空间</a><details class="pagenest-social-qr"><summary>微信</summary><div data-pagenest-social-qr="https://example.test/reading/%E7%AB%A0%E8%8A%82/"></div><p>使用微信扫描二维码打开文章。</p><a href="https://example.test/reading/">文章链接</a></details></aside>`,
      );
      await page.addScriptTag({ path: path.join(__dirname, '../assets/social-qrcode.js') });
      await page.addScriptTag({ path: path.join(__dirname, '../assets/social-share.js') });
      await page.getByText('微信', { exact: true }).click();
      assert.equal(await page.locator('details').evaluate((el) => el.open), true);
      assert.equal(await page.getByRole('img', { name: '文章链接二维码' }).count(), 1);
      assert.equal(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
        true,
      );
      await page.getByText('微信', { exact: true }).press('Enter');
      assert.equal(await page.locator('details').evaluate((el) => el.open), false);
      await page.close();
    }
    console.log('Social browser: desktop/mobile local QR, keyboard details and width passed.');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
