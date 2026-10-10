const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
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
    const themeRoot =
      process.env.PAGENEST_SOCIAL_THEME_TEST_ROOT || path.resolve(__dirname, '../../PageNest');
    const themeCss = fs.readFileSync(path.join(themeRoot, 'assets/login.css'), 'utf8');
    const socialCss = fs.readFileSync(path.join(__dirname, '../assets/social-ui.css'), 'utf8');
    const wpRoot = process.env.PAGENEST_SOCIAL_WP_TEST_ROOT;
    const coreCss = wpRoot
      ? [
          'wp-includes/css/buttons.min.css',
          'wp-admin/css/forms.min.css',
          'wp-admin/css/login.min.css',
        ]
          .map((file) => fs.readFileSync(path.join(wpRoot, file), 'utf8'))
          .join('\n')
      : '';
    for (const width of [390, 1440]) {
      for (const mode of ['anonymous', 'authenticated']) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        await page.route('**/*', (route) => route.abort());
        const panel = execFileSync(
          'php',
          [path.join(__dirname, 'social-login-fixture.php'), mode],
          { encoding: 'utf8' },
        );
        await page.setContent(
          `<style>${coreCss} ${themeCss} ${socialCss}</style><body class="login pagenest-login"><div id="login"><form id="loginform"><p><label>Username<input id="user_login" class="input" value="synthetic-autofill"></label></p><p><label>Password<input type="password" class="input"></label></p>${panel}<div class="pagenest-turnstile" data-action="login" data-sitekey="synthetic"><div class="pagenest-turnstile-widget"></div><p class="pagenest-turnstile-status"></p><button type="button" class="pagenest-turnstile-retry">Retry</button></div><input id="wp-submit" type="submit" value="Login"></form></div></body>`,
        );
        await page.evaluate(() => {
          window.turnstile = {
            render: (el, options) => {
              el.style.height = '65px';
              window.syntheticCaptchaSuccess = options.callback;
              return 'synthetic';
            },
            reset: () => {},
          };
        });
        await page.addScriptTag({ path: path.join(__dirname, '../assets/services-turnstile.js') });
        for (const phase of ['pending', 'success']) {
          if (phase === 'success')
            await page.evaluate(() => window.syntheticCaptchaSuccess('synthetic-no-submit'));
          assert.equal(
            await page.locator('#wp-submit').evaluate((el) => el.disabled),
            phase === 'pending',
          );
          const links = page.locator('.pagenest-social-buttons a');
          assert.equal(await links.count(), mode === 'anonymous' ? 3 : 2);
          for (const link of await links.all()) {
            assert(await link.isVisible());
            await link.scrollIntoViewIfNeeded();
            await link.focus();
            assert(
              await link.evaluate((el) => {
                const rect = el.getBoundingClientRect();
                const hit = document.elementFromPoint(
                  rect.x + rect.width / 2,
                  rect.y + rect.height / 2,
                );
                for (let parent = el; parent; parent = parent.parentElement) {
                  const style = getComputedStyle(parent);
                  if (
                    style.visibility !== 'visible' ||
                    style.display === 'none' ||
                    Number(style.opacity) === 0
                  )
                    return false;
                }
                return (
                  rect.width > 0 &&
                  rect.height > 0 &&
                  (hit === el || el.contains(hit)) &&
                  document.activeElement === el
                );
              }),
            );
          }
          if (mode === 'authenticated')
            assert.equal(await page.locator('.pagenest-social-account-notice').count(), 1);
          assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
        }
        await page.close();
      }
    }
    console.log(
      'Social browser: desktop/mobile QR and anonymous/authenticated login visibility, clipping, focus and Turnstile success passed.',
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
