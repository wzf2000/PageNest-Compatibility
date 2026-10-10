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
      await page.route('**/*', (route) => {
        if (route.request().url().endsWith('/social-google-icon-d1ce9c2af0b1.png'))
          return route.fulfill({
            contentType: 'image/png',
            body: fs.readFileSync(
              path.join(__dirname, '../assets/social-google-icon-d1ce9c2af0b1.png'),
            ),
          });
        return route.abort();
      });
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
      for (const mode of ['anonymous', 'authenticated', 'all-providers']) {
        const page = await browser.newPage({ viewport: { width, height: 1000 } });
        await page.route('**/*', (route) => {
          if (route.request().url().endsWith('/social-google-icon-d1ce9c2af0b1.png'))
            return route.fulfill({
              contentType: 'image/png',
              body: fs.readFileSync(
                path.join(__dirname, '../assets/social-google-icon-d1ce9c2af0b1.png'),
              ),
            });
          return route.abort();
        });
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
          assert.equal(
            await links.count(),
            mode === 'authenticated' ? 2 : mode === 'all-providers' ? 6 : 3,
          );
          if (mode !== 'authenticated') {
            assert.equal(
              await page.locator('.pagenest-social-provider svg[aria-hidden="true"]').count(),
              mode === 'all-providers' ? 5 : 3,
            );
            if (mode === 'all-providers') {
              const logo = page.locator('.pagenest-social-provider img');
              await logo.evaluate((el) => el.decode());
              assert(await logo.evaluate((el) => el.naturalWidth > 0 && el.alt === ''));
            }
            for (const control of await page.locator('.pagenest-social-provider').all()) {
              assert((await control.boundingBox()).height >= 44);
              assert((await control.innerText()).includes('登录'));
              assert.equal(await control.locator('svg, img').count(), 1);
              await control.focus();
              assert(await control.evaluate((el) => getComputedStyle(el).outlineStyle !== 'none'));
            }
          }
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
    const securityUiRoot = process.env.PAGENEST_SOCIAL_SECURITY_UI_ROOT;
    if (securityUiRoot) {
      for (const width of [390, 1440]) {
        for (const mode of [
          'profile',
          'registration',
          'registration-details',
          'registration-errors',
          'email-confirmation',
          ...['profile-account', 'profile-error'].filter((name) =>
            fs.existsSync(path.join(securityUiRoot, `${name}.html`)),
          ),
        ]) {
          const page = await browser.newPage({ viewport: { width, height: 1000 } });
          await page.route('**/*', (route) => {
            if (route.request().url().endsWith('/social-google-icon-d1ce9c2af0b1.png'))
              return route.fulfill({
                contentType: 'image/png',
                body: fs.readFileSync(
                  path.join(__dirname, '../assets/social-google-icon-d1ce9c2af0b1.png'),
                ),
              });
            return route.abort();
          });
          const markup = fs.readFileSync(path.join(securityUiRoot, `${mode}.html`), 'utf8');
          await page.setContent(
            `<style>${coreCss} ${socialCss} body{margin:16px;max-width:760px} input{max-width:100%;box-sizing:border-box}</style>${markup}`,
          );
          assert.equal(await page.locator('form form').count(), 0);
          assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
          for (const control of await page.locator('[form]').all()) {
            assert(await control.evaluate((el) => el.form?.id === el.getAttribute('form')));
          }
          await page.evaluate(() => {
            document.addEventListener('submit', (event) => {
              event.preventDefault();
              window.syntheticSubmission = Object.fromEntries(
                new FormData(event.target, event.submitter),
              );
            });
          });
          if (mode === 'profile-account' || mode === 'profile-error') {
            assert.equal(await page.locator('.pn-social-provider-grid .pn-social-card').count(), 4);
            assert.equal(await page.locator('.pn-social-provider-heading svg').count(), 3);
            const googleIcon = page.locator('.pn-social-provider-heading img');
            assert.equal(await googleIcon.count(), 1);
            await googleIcon.evaluate((image) => image.decode());
            assert(await googleIcon.evaluate((image) => image.naturalWidth > 0));
            assert.equal(await page.locator('#pn-social-bind-qq').count(), 0);
            assert.equal(await page.locator('#pn-social-bind-github').count(), 1);
            assert.equal(
              await page
                .locator(mode === 'profile-error' ? '[role="alert"]' : '[role="status"]')
                .count(),
              1,
            );
            assert(
              await page
                .getByText('GitHub · 历史连接，当前不可用于登录', { exact: true })
                .isVisible(),
            );
            for (const button of await page.locator('.pagenest-social-account button').all()) {
              assert((await button.innerText()).trim().length > 0);
              await button.focus();
              assert(await button.evaluate((el) => el === document.activeElement));
            }
            await page.locator('button[form="pn-social-reauth-qq"]').click();
            assert(
              await page.evaluate(
                () =>
                  window.syntheticSubmission.action === 'pagenest_social_start' &&
                  window.syntheticSubmission.mode === 'reauth' &&
                  !!window.syntheticSubmission._wpnonce &&
                  !('password' in window.syntheticSubmission),
              ),
            );
          } else if (mode === 'profile') {
            await page.getByLabel('当前密码').fill('synthetic-current-password');
            await page.locator('button[form="pn-social-password-form"]').click();
            assert(
              await page.evaluate(
                () =>
                  window.syntheticSubmission.action === 'pagenest_social_password' &&
                  window.syntheticSubmission.password === 'synthetic-current-password' &&
                  !!window.syntheticSubmission._wpnonce,
              ),
            );
            await page.getByRole('button', { name: '发送验证邮件', exact: true }).click();
            assert(
              await page.evaluate(
                () =>
                  window.syntheticSubmission.action === 'pagenest_social_email_request' &&
                  !('password' in window.syntheticSubmission),
              ),
            );
          } else if (mode === 'registration') {
            for (const [label, choice] of [
              ['创建新的本站账号', 'create'],
              ['登录已有账号', 'existing'],
            ]) {
              await page.getByRole('button', { name: label, exact: true }).click();
              assert(
                await page.evaluate(
                  (expected) =>
                    window.syntheticSubmission.choice === expected &&
                    !!window.syntheticSubmission._wpnonce,
                  choice,
                ),
              );
            }
          } else if (mode === 'registration-details' || mode === 'registration-errors') {
            const login = page.locator('input[name="user_login"]');
            const email = page.locator('input[name="user_email"]');
            const nickname = page.locator('input[name="nickname"]');
            assert(await login.evaluate((el) => el.required));
            assert(await email.evaluate((el) => el.required && el.type === 'email'));
            assert(!(await nickname.evaluate((el) => el.required)));
            for (const control of [login, email, nickname]) {
              assert(await control.isVisible());
              assert(await control.evaluate((el) => el.labels.length > 0));
            }
            if (mode === 'registration-errors') {
              assert((await page.locator('[role="alert"]').count()) > 0);
              assert((await login.inputValue()).length > 0);
            }
            await login.fill('');
            await email.fill('');
            await page.locator('button[name="choice"][value="register"]').click();
            assert(await page.evaluate(() => !window.syntheticSubmission));
            await login.fill('synthetic_reader');
            await email.fill('synthetic-reader@example.test');
            await nickname.fill('合成昵称');
            await page.locator('button[name="choice"][value="register"]').click();
            assert(
              await page.evaluate(
                () =>
                  window.syntheticSubmission.choice === 'register' &&
                  window.syntheticSubmission.user_login === 'synthetic_reader' &&
                  window.syntheticSubmission.user_email === 'synthetic-reader@example.test' &&
                  window.syntheticSubmission.nickname === '合成昵称' &&
                  !!window.syntheticSubmission.pending &&
                  !!window.syntheticSubmission._wpnonce,
              ),
            );
          } else {
            await page.getByRole('button', { name: '确认邮箱', exact: true }).click();
            assert(
              await page.evaluate(
                () =>
                  window.syntheticSubmission.action === 'pagenest_social_email_confirm' &&
                  !!window.syntheticSubmission._wpnonce &&
                  /^[a-f0-9]{64}$/.test(window.syntheticSubmission.token),
              ),
            );
          }
          await page.close();
        }
      }
      console.log(
        'Synthetic WordPress account forms: desktop/mobile layout, form ownership and submitted fields passed; no requests submitted.',
      );
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
