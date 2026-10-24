const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const origin = 'http://notifications.fixture.test';
const text = {
  load: 'Load notifications',
  refresh: 'Refresh notifications',
  window: 'Only the latest 100 eligible replies are checked.',
  loading: 'Loading notifications…',
  empty: 'No recent replies to show.',
  error: 'Notifications could not be loaded. Try again.',
  expired: 'Your session expired. Sign in again.',
  off: 'Notifications are currently unavailable.',
  login: 'Sign in to view your notifications.',
  read: 'Read',
  unread: 'Unread',
  mark: 'Mark as read',
  previous: 'Previous',
  next: 'Next',
  preference: 'Receive future comment reply notifications',
  independent: 'This preference does not change comment email settings.',
  recent: 'Unread in recent replies:',
  saved: 'Notification preference saved.',
  settings: 'Notification preferences',
};
async function fixture(
  page,
  { plain = false, signedIn = true, status = 200, code = '', delayed = false } = {},
) {
  const calls = [];
  let read = false;
  let enabled = true;
  let release;
  const waiting = new Promise((resolve) => {
    release = resolve;
  });
  const endpoint = (suffix = '') =>
    plain
      ? `${origin}/?rest_route=/pagenest/v1/notifications${suffix}`
      : `${origin}/wp-json/pagenest/v1/notifications${suffix}`;
  const config = {
    api: endpoint(),
    readApi: endpoint('/read'),
    preferenceApi: endpoint('/preference'),
    nonce: signedIn ? 'synthetic' : '',
    login: origin + '/login',
    text,
  };
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== origin) return route.abort();
    if (!url.pathname.includes('notifications') && !url.searchParams.has('rest_route'))
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Notifications fixture</title><body></body>',
      });
    calls.push({ url: route.request().url(), body: route.request().postDataJSON() });
    if (delayed) await waiting;
    if (status !== 200) return route.fulfill({ status, json: { code } });
    const action = plain ? url.searchParams.get('rest_route') : url.pathname;
    if (action.endsWith('/read')) read = true;
    if (action.endsWith('/preference')) enabled = route.request().postDataJSON().enabled;
    const pageNumber = Number(url.searchParams.get('page') || 1);
    await route.fulfill({
      json: action.endsWith('/read')
        ? { read: true }
        : action.endsWith('/preference')
          ? { enabled }
          : {
              items: [
                {
                  id: pageNumber,
                  title: 'Synthetic public article',
                  author: 'Synthetic Writer',
                  url: origin + '/article/#comment-' + pageNumber,
                  created: '2026-10-24T12:00:00Z',
                  read,
                },
              ],
              page: pageNumber,
              pages: 2,
              recentUnread: read ? 0 : 2,
              enabled,
            },
    });
  });
  await page.goto(origin);
  await page.setContent(
    `<style>body{font:16px system-ui;margin:16px}${fs.readFileSync(path.join(root, 'assets/notifications.css'), 'utf8')}</style><button data-pn-open-notifications hidden>Notifications</button><dialog class="pn-notifications-dialog" aria-labelledby="notification-title"><div class="pn-notifications-toolbar"><h2 id="notification-title">Notifications</h2><button data-pn-close-notifications>Close</button></div><section class="pn-notifications" data-pn-notifications><h2>Notifications</h2><p>Load to check the latest 100 eligible replies.</p><button data-pn-notifications-load hidden>Load notifications</button><div data-pn-notifications-status role="status"></div><div data-pn-notifications-result></div></section></dialog>`,
  );
  await page.evaluate((value) => {
    window.PageNestNotifications = value;
  }, config);
  await page.addScriptTag({
    content: fs.readFileSync(path.join(root, 'assets/notifications.js'), 'utf8'),
  });
  return { calls, release };
}
for (const plain of [false, true]) {
  test(`explicit open, pagination, read and opt-out with ${plain ? 'plain' : 'pretty'} permalinks`, async ({
    page,
  }) => {
    const { calls } = await fixture(page, { plain });
    expect(calls).toHaveLength(0);
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await expect(page.getByRole('link', { name: /Synthetic Writer/ })).toHaveCount(1);
    expect(calls).toHaveLength(1);
    await page.getByRole('button', { name: 'Mark as read' }).click();
    await expect(page.getByRole('button', { name: 'Mark as read' })).toHaveCount(0);
    await expect(page.locator('[data-pn-notifications-status]')).toHaveText(
      'Unread in recent replies: 1',
    );
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.locator('.pn-notifications nav span')).toHaveText('2 / 2');
    await page.getByText('Notification preferences', { exact: true }).click();
    await page.getByRole('checkbox').uncheck();
    await expect(page.locator('[data-pn-notifications-status]')).toHaveText(text.saved);
    expect(calls.some((call) => call.body?.enabled === false)).toBeTruthy();
    expect(await page.evaluate(() => localStorage.length)).toBe(0);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.locator('[data-pn-notifications-result]')).toBeEmpty();
  });
}
for (const [status, code, expected] of [
  [403, 'rest_cookie_invalid_nonce', text.expired],
  [403, 'notifications_disabled', text.off],
  [503, 'notifications_storage', text.error],
]) {
  test(`${code} shows an actionable state`, async ({ page }) => {
    await fixture(page, { status, code });
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await expect(page.locator('[data-pn-notifications-status]')).toContainText(expected);
    if (code === 'rest_cookie_invalid_nonce')
      await expect(page.getByRole('link', { name: text.login })).toBeVisible();
    await expect(page.getByRole('button', { name: text.load })).toBeEnabled();
  });
}
test('close and pagehide purge memory and prevent late responses restoring private DOM', async ({
  page,
}) => {
  const { calls, release } = await fixture(page, { delayed: true });
  await page.getByRole('button', { name: 'Notifications', exact: true }).click();
  await expect.poll(() => calls.length).toBe(1);
  await page.keyboard.press('Escape');
  release();
  await expect(page.locator('[data-pn-notifications-result]')).toBeEmpty();
  await page.getByRole('button', { name: 'Notifications', exact: true }).click();
  await expect(page.locator('[data-pn-notifications-result]')).toContainText('Synthetic Writer');
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  await expect(page.locator('[data-pn-notifications-result]')).toBeEmpty();
});
for (const width of [1440, 390]) {
  test(`responsive keyboard view at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.emulateMedia({ colorScheme: 'dark', reducedMotion: 'reduce' });
    await fixture(page);
    await page.evaluate(() => {
      document.documentElement.dir = 'rtl';
    });
    await page.getByRole('button', { name: 'Notifications', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-pn-notifications-result]')).toContainText('Synthetic Writer');
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    expect(
      await page
        .locator('dialog')
        .evaluate((element) => element.scrollWidth <= element.clientWidth),
    ).toBeTruthy();
    await page.screenshot({ path: `.runtime/notifications-${width}.png`, fullPage: true });
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Notifications', exact: true })).toBeFocused();
  });
}
