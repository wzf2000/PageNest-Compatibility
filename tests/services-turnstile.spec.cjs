const { test, expect } = require('@playwright/test');
const path = require('node:path');
const source = path.resolve(__dirname, '../assets/services-turnstile.js');
for (const width of [390, 1280]) {
  test(`native verification and accessible retry at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.setContent(
      `<form style="width:260px"><input name="log"><div class="pagenest-turnstile cf-turnstile-comments" data-sitekey="test" data-action="comments"><div class="pagenest-turnstile-widget"></div><p class="pagenest-turnstile-status" role="status"></p><button type="button" class="pagenest-turnstile-retry">Retry security check</button></div><input type="submit" value="Post"><button type="submit" disabled>Previously unavailable</button></form>`,
    );
    await page.evaluate(() => {
      window.turnstile = {
        render(container, options) {
          window.widgetOptions = options;
          return 'test-widget';
        },
        reset(id) {
          window.resetId = id;
        },
      };
    });
    await page.addScriptTag({ path: source });
    await expect(page.locator('input[type=submit]')).toBeDisabled();
    expect(await page.evaluate(() => window.widgetOptions.size)).toBe('compact');
    expect(await page.evaluate(() => window.widgetOptions.action)).toBe('comments');
    await page.evaluate(() => window.widgetOptions.callback('mock-token'));
    await expect(page.locator('input[type=submit]')).toBeEnabled();
    await expect(page.locator('button[type=submit]')).toBeDisabled();
    await expect(page.locator('.pagenest-turnstile-retry')).toBeHidden();
    await page.evaluate(() => window.widgetOptions['expired-callback']());
    await expect(page.locator('input[type=submit]')).toBeDisabled();
    await expect(page.locator('.pagenest-turnstile-retry')).toBeVisible();
    await page.locator('.pagenest-turnstile-retry').focus();
    await page.keyboard.press('Enter');
    expect(await page.evaluate(() => window.resetId)).toBe('test-widget');
    await page.evaluate(() => window.widgetOptions['error-callback']());
    await expect(page.locator('.pagenest-turnstile-status')).toContainText('重试');
    await page.evaluate(() => window.widgetOptions.callback('new-token'));
    await expect(page.locator('input[type=submit]')).toBeEnabled();
  });
}
test('blocked API leaves keyboard reachable retry and blocked submission', async ({ page }) => {
  await page.route('https://challenges.cloudflare.com/**', (route) => route.abort());
  await page.setContent(
    '<form><div class="pagenest-turnstile" data-sitekey="test" data-action="login"><div class="pagenest-turnstile-widget"></div><p class="pagenest-turnstile-status"></p><button type="button" class="pagenest-turnstile-retry">Retry</button></div><button type="submit">Log in</button></form>',
  );
  await page.addScriptTag({ path: source });
  await expect(page.locator('button[type=submit]')).toBeDisabled();
  await page.locator('.pagenest-turnstile-retry').focus();
  await expect(page.locator('.pagenest-turnstile-retry')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('button[type=submit]')).toBeDisabled();
});

test('profile display options reach the vendor widget', async ({ page }) => {
  await page.setContent(
    '<form><div class="pagenest-turnstile" style="width:400px" data-theme="dark" data-language="en" data-size="flexible" data-appearance="interaction-only" data-action="register"><div class="pagenest-turnstile-widget"></div><p class="pagenest-turnstile-status"></p><button type="button" class="pagenest-turnstile-retry">Retry</button></div><button type="submit">Register</button></form>',
  );
  await page.evaluate(() => {
    window.turnstile = {
      render(element, options) {
        window.profileOptions = options;
        return 'profile';
      },
    };
  });
  await page.addScriptTag({ path: source });
  expect(
    await page.evaluate(() => [
      window.profileOptions.theme,
      window.profileOptions.language,
      window.profileOptions.size,
      window.profileOptions.appearance,
    ]),
  ).toEqual(['dark', 'en', 'flexible', 'interaction-only']);
});
