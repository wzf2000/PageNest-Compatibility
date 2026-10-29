const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const origin = 'http://notifications.fixture.test';
const text = {
  all: 'All',
  filter: 'Show notifications',
  batch: 'Mark this page as read',
  partial: 'Some notifications could not be marked as read. The list has been refreshed.',
  paragraph: 'Public paragraph comment',
  reply: 'Comment reply',
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
  {
    plain = false,
    signedIn = true,
    status = 200,
    code = '',
    delayed = false,
    delayedRead = false,
    panels = 0,
    listResponse = null,
    itemCount = 1,
    withCount = false,
    count = 2,
    delayedCount = false,
    initiallyHidden = false,
  } = {},
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
    batchApi: endpoint('/read-page'),
    readApi: endpoint('/read'),
    preferenceApi: endpoint('/preference'),
    nonce: signedIn ? 'synthetic' : '',
    login: origin + '/login',
    text,
    ...(withCount ? { countApi: endpoint('/count') } : {}),
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
    if (delayed || (delayedRead && url.pathname.endsWith('/read'))) await waiting;
    if (status !== 200) return route.fulfill({ status, json: { code } });
    const action = plain ? url.searchParams.get('rest_route') : url.pathname;
    if (action.endsWith('/count')) {
      if (delayedCount) await waiting;
      return route.fulfill({ json: { recentUnread: count } });
    }
    if (action.endsWith('/read') || action.endsWith('/read-page')) read = true;
    if (action.endsWith('/preference')) enabled = route.request().postDataJSON().enabled;
    const pageNumber = Number(url.searchParams.get('page') || 1);
    const list = listResponse?.({
      read,
      pageNumber,
      enabled,
      filter: url.searchParams.get('filter') || 'all',
    }) ?? {
      items: Array.from({ length: itemCount }, (_, index) => ({
        id: pageNumber * 10 + index,
        title: 'Synthetic public article',
        author: 'Synthetic Writer',
        url: origin + '/article/#comment-' + index,
        created: '2026-10-24T12:00:00Z',
        read,
      })),
      page: pageNumber,
      pages: 2,
      recentUnread: read ? 0 : 2,
      enabled,
    };
    await route.fulfill({
      json:
        action.endsWith('/read') || action.endsWith('/read-page')
          ? { read: true }
          : action.endsWith('/preference')
            ? { enabled }
            : list,
    });
  });
  await page.goto(origin);
  const panel =
    '<section class="pn-notifications" data-pn-notifications><h2>Notifications</h2><button data-pn-notifications-load hidden>Load notifications</button><div data-pn-notifications-status role="status"></div><div data-pn-notifications-result></div></section>';
  await page.setContent(
    `<style>body{font:16px system-ui;margin:16px}${fs.readFileSync(path.join(root, 'assets/notifications.css'), 'utf8')}</style>${panel.repeat(panels)}<button data-pn-open-notifications hidden>Notifications${withCount ? '<span data-pn-notifications-badge hidden aria-hidden="true"></span>' : ''}</button><dialog class="pn-notifications-dialog" aria-labelledby="notification-title"><div class="pn-notifications-toolbar"><h2 id="notification-title">Notifications</h2><button data-pn-close-notifications>Close</button></div><section class="pn-notifications" data-pn-notifications><h2>Notifications</h2><p>Load to check the latest 100 eligible replies.</p><button data-pn-notifications-load hidden>Load notifications</button><div data-pn-notifications-status role="status"></div><div data-pn-notifications-result></div></section></dialog>`,
  );
  await page.evaluate((value) => {
    window.PageNestNotifications = value;
  }, config);
  if (initiallyHidden)
    await page.evaluate(() =>
      Object.defineProperty(document, 'hidden', { configurable: true, value: true }),
    );
  await page.addScriptTag({
    content: fs.readFileSync(path.join(root, 'assets/notifications.js'), 'utf8'),
  });
  return {
    calls,
    release,
    setError: (nextStatus, nextCode) => {
      status = nextStatus;
      code = nextCode;
    },
  };
}
test('recent unread badge loads count only, survives closing and follows visible periods and marking read', async ({
  page,
}) => {
  const { calls } = await fixture(page, { withCount: true });
  const badge = page.locator('[data-pn-notifications-badge]');
  await expect(badge).toHaveText('2');
  expect(calls).toHaveLength(1);
  expect(calls[0].url).toContain('/count');
  await expect(page.locator('[data-notification-id]')).toHaveCount(0);
  await page.locator('[data-pn-open-notifications]').click();
  await expect(page.locator('[data-notification-id]')).toHaveCount(1);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(badge).toBeVisible();
  await expect(page.locator('[data-notification-id]')).toHaveCount(0);
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(badge).toBeHidden();
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect(badge).toHaveText('2');
  expect(calls.filter((call) => call.url.includes('/count'))).toHaveLength(2);
  await page.locator('[data-pn-open-notifications]').click();
  await page.getByRole('button', { name: text.mark, exact: true }).click();
  await expect(page.locator('[data-pn-notifications-status]')).toHaveText(`${text.recent} 0`);
  await expect(badge).toBeHidden();
});
for (const options of [{ count: 0 }, { status: 401 }, { signedIn: false }]) {
  test(`recent unread badge hides zero, expired and anonymous states: ${JSON.stringify(options)}`, async ({
    page,
  }) => {
    const { calls } = await fixture(page, { withCount: true, ...options });
    if (options.signedIn !== false) await expect.poll(() => calls.length).toBe(1);
    await expect(page.locator('[data-pn-notifications-badge]')).toBeHidden();
    await expect(page.locator('[data-notification-id]')).toHaveCount(0);
    if (options.signedIn === false) expect(calls).toHaveLength(0);
  });
}
test('recent unread badge waits for visibility and rejects a late count after a fresh read', async ({
  page,
}) => {
  const { calls, release } = await fixture(page, {
    withCount: true,
    initiallyHidden: true,
    delayedCount: true,
    count: 99,
  });
  expect(calls).toHaveLength(0);
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await expect.poll(() => calls.length).toBe(1);
  await page.locator('[data-pn-open-notifications]').click();
  await page.getByRole('button', { name: text.mark, exact: true }).click();
  await expect(page.locator('[data-pn-notifications-status]')).toHaveText(`${text.recent} 0`);
  release();
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
  await expect(page.locator('[data-pn-notifications-badge]')).toBeHidden();
});
test('recent unread badge and private rows clear when another reader feature reports authentication loss', async ({
  page,
}) => {
  await fixture(page, { withCount: true });
  await expect(page.locator('[data-pn-notifications-badge]')).toBeVisible();
  await page.locator('[data-pn-open-notifications]').click();
  await expect(page.locator('[data-notification-id]')).toHaveCount(1);
  await page.evaluate(() => document.dispatchEvent(new Event('pagenest-pc-auth-change')));
  await expect(page.locator('[data-pn-notifications-badge]')).toBeHidden();
  await expect(page.locator('[data-notification-id]')).toHaveCount(0);
});
test('recent unread badge cannot be restored by a count that outlives authentication loss', async ({
  page,
}) => {
  const { calls, release } = await fixture(page, { withCount: true, delayedCount: true });
  await expect.poll(() => calls.length).toBe(1);
  await page.evaluate(() => document.dispatchEvent(new Event('pagenest-pc-auth-change')));
  release();
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
  await expect(page.locator('[data-pn-notifications-badge]')).toBeHidden();
});
for (const width of [1440, 390]) {
  test(`marking a reply read preserves the title column at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await fixture(page, {
      listResponse: ({ read, enabled }) => ({
        items: [
          {
            id: 11,
            title: 'A complete long notification title that should remain readable',
            author: 'Writer',
            url: origin + '/article',
            created: '2026-10-24T12:00:00Z',
            read,
          },
          {
            id: 12,
            title: 'An already read notification',
            author: 'Writer',
            url: origin + '/article',
            created: '2026-10-24T12:00:00Z',
            read: true,
          },
        ],
        page: 1,
        pages: 1,
        recentUnread: read ? 0 : 1,
        enabled,
      }),
    });
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    const links = page.locator('[data-notification-id] a');
    const before = await links.first().boundingBox();
    expect(Math.abs(before.x - (await links.nth(1).boundingBox()).x)).toBeLessThan(1);
    await page.getByRole('button', { name: 'Mark as read' }).click();
    await expect(page.locator('[data-notification-id="11"]')).toHaveAttribute('data-read', 'true');
    expect(Math.abs((await links.first().boundingBox()).x - before.x)).toBeLessThan(1);
  });
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
      'Unread in recent replies: 0',
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

test('mark read reloads the server count and removes newly revoked entries', async ({ page }) => {
  const { calls } = await fixture(page, {
    listResponse: ({ read, enabled }) => ({
      items: read
        ? []
        : [
            {
              id: 7,
              title: 'Will be revoked',
              author: 'Writer',
              url: origin + '/#comment-7',
              created: '2026-10-24T12:00:00Z',
              read: false,
            },
          ],
      page: 1,
      pages: 1,
      recentUnread: read ? 0 : 12,
      enabled,
    }),
  });
  await page.getByRole('button', { name: 'Notifications', exact: true }).click();
  await page.getByRole('button', { name: text.mark }).click();
  await expect(page.locator('[data-pn-notifications-status]')).toHaveText(`${text.recent} 0`);
  await expect(page.getByRole('link', { name: /Will be revoked/ })).toHaveCount(0);
  await expect(page.getByText(text.empty)).toBeVisible();
  await expect(page.getByRole('button', { name: text.refresh, exact: true })).toBeFocused();
  expect(calls).toHaveLength(3);
  expect(calls[2].url).toContain('page=1');
});
test('mutations and authentication failures invalidate other loaded panels', async ({ page }) => {
  const { calls, setError } = await fixture(page, { panels: 2 });
  const panels = page.locator('[data-pn-notifications]').filter({ visible: true });
  for (let index = 0; index < 2; index++)
    await panels.nth(index).getByRole('button', { name: text.load, exact: true }).click();
  await expect(panels.nth(1).locator('li')).toHaveCount(1);
  await panels.nth(0).getByRole('button', { name: text.mark }).click();
  await expect(panels.nth(0).locator('[data-pn-notifications-status]')).toHaveText(
    `${text.recent} 0`,
  );
  await expect(panels.nth(1).locator('[data-pn-notifications-result]')).toBeEmpty();
  expect(calls).toHaveLength(4);
  await panels.nth(1).getByRole('button', { name: text.load, exact: true }).click();
  await expect(panels.nth(1).locator('li')).toHaveCount(1);
  await panels.nth(0).getByText(text.settings, { exact: true }).click();
  await panels.nth(0).getByRole('checkbox').uncheck();
  await expect(panels.nth(0).locator('[data-pn-notifications-status]')).toHaveText(text.saved);
  await expect(panels.nth(1).locator('[data-pn-notifications-result]')).toBeEmpty();
  await panels.nth(1).getByRole('button', { name: text.load, exact: true }).click();
  await expect(panels.nth(1).locator('li')).toHaveCount(1);
  setError(403, 'rest_cookie_invalid_nonce');
  await panels.nth(0).getByRole('button', { name: text.refresh, exact: true }).click();
  await expect(panels.nth(0).locator('[data-pn-notifications-status]')).toContainText(text.expired);
  await expect(panels.nth(1).locator('[data-pn-notifications-result]')).toBeEmpty();
});
test('anonymous shortcode keeps its sign-in prompt after pagehide and visibility purge', async ({
  page,
}) => {
  const { calls } = await fixture(page, { signedIn: false, panels: 1 });
  const panel = page.locator('[data-pn-notifications]').first();
  await expect(panel.getByRole('link', { name: text.login })).toBeVisible();
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  await expect(panel.getByRole('link', { name: text.login })).toBeVisible();
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
    Object.defineProperty(document, 'hidden', { configurable: true, value: false });
  });
  await expect(panel.getByRole('link', { name: text.login })).toBeVisible();
  await expect(panel.getByRole('button', { name: text.load })).toHaveCount(0);
  expect(calls).toHaveLength(0);
});
for (const viewport of [
  { width: 320, height: 640 },
  { width: 390, height: 844 },
  { width: 390, height: 520 },
  { width: 740, height: 320 },
]) {
  test(`close remains reachable after a long list scroll at ${viewport.width}x${viewport.height} and 200% text`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize(viewport);
    await fixture(page, { itemCount: 12 });
    await page.addStyleTag({ content: 'body { font-size: 200%; }' });
    if (process.env.PAGENEST_TEST_THEME_ROOT) {
      await page.addStyleTag({
        path: path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/presentation.css'),
      });
      await page.evaluate(() => (document.documentElement.dataset.pagenestColorScheme = 'dark'));
    }
    await page.getByRole('button', { name: 'Notifications', exact: true }).click();
    await expect(page.locator('.pn-notifications li')).toHaveCount(12);
    const dateWidth = await page
      .locator('.pn-notifications li p')
      .first()
      .evaluate((element) => ({
        width: element.getBoundingClientRect().width,
        rowWidth: element.closest('li').getBoundingClientRect().width,
        fontSize: parseFloat(getComputedStyle(element).fontSize),
      }));
    expect(dateWidth.width).toBeGreaterThanOrEqual(
      Math.min(dateWidth.rowWidth - 16, dateWidth.fontSize * 8),
    );
    await page.locator('dialog').evaluate((element) => {
      element.scrollTop = element.scrollHeight;
    });
    const close = page.getByRole('button', { name: 'Close', exact: true });
    await expect(close).toBeInViewport();
    await page.locator('.pn-notifications li a').last().focus();
    const focusIsBelowToolbar = await page.locator('dialog').evaluate((element) => {
      const active = document.activeElement.getBoundingClientRect();
      const toolbar = element.querySelector('.pn-notifications-toolbar').getBoundingClientRect();
      return {
        activeTop: active.top,
        activeBottom: active.bottom,
        toolbarBottom: toolbar.bottom,
        dialogBottom: element.getBoundingClientRect().bottom,
        scrollPadding: getComputedStyle(element).scrollPaddingBlockStart,
      };
    });
    expect(
      focusIsBelowToolbar.activeTop,
      JSON.stringify(focusIsBelowToolbar),
    ).toBeGreaterThanOrEqual(focusIsBelowToolbar.toolbarBottom);
    expect(
      focusIsBelowToolbar.activeBottom,
      JSON.stringify(focusIsBelowToolbar),
    ).toBeLessThanOrEqual(focusIsBelowToolbar.dialogBottom);
    expect(
      await page
        .locator('dialog')
        .evaluate((element) => element.scrollWidth <= element.clientWidth),
    ).toBeTruthy();
    await expect(page.locator('.pn-notifications li')).toHaveCount(12);
    await page.screenshot({ path: testInfo.outputPath('notifications-short-list.png') });
    await close.focus();
    await expect(close).toBeFocused();
    await page.keyboard.press('Tab');
    await close.focus();
    expect(await close.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
    await page.screenshot({ path: testInfo.outputPath('notifications-short-close.png') });
    await page.keyboard.press('Enter');
    await expect(page.locator('dialog')).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Notifications', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
    await page.locator('dialog').evaluate((dialog) => {
      if (!dialog.open) throw new Error('Expected the reopened panel');
      dialog.dispatchEvent(new Event('close'));
    });
    await expect(page.locator('.pn-notifications li')).toHaveCount(12);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Notifications', exact: true })).toBeFocused();
  });
}

test('a canceled mutation response cannot clear another panel loaded afterward', async ({
  page,
}) => {
  const { calls, release } = await fixture(page, { panels: 2, delayedRead: true });
  await page.evaluate(() => {
    const nativeFetch = window.fetch.bind(window);
    // Model a response already in flight when AbortController can no longer stop it.
    window.fetch = async (url, options) => {
      const read = String(url).endsWith('/read');
      const response = await nativeFetch(url, read ? { ...options, signal: undefined } : options);
      if (read) {
        const json = response.json.bind(response);
        response.json = async () => {
          const body = await json();
          window.delayedReadFinished = true;
          return body;
        };
      }
      return response;
    };
  });
  const panels = page.locator('[data-pn-notifications]').filter({ visible: true });
  await panels.nth(0).getByRole('button', { name: text.load, exact: true }).click();
  await panels.nth(0).getByRole('button', { name: text.mark }).click();
  await expect.poll(() => calls.length).toBe(2);
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  await panels.nth(1).getByRole('button', { name: text.load, exact: true }).click();
  await expect(panels.nth(1).locator('li')).toHaveCount(1);
  release();
  await page.waitForFunction(() => window.delayedReadFinished);
  await expect(panels.nth(1).locator('li')).toHaveCount(1);
  await expect(panels.nth(0).locator('[data-pn-notifications-result]')).toBeEmpty();
  expect(calls).toHaveLength(3);
});

test('filters paginate within the current window and page read sends typed identities', async ({
  page,
}) => {
  const { calls } = await fixture(page, {
    withCount: true,
    listResponse: ({ read, filter, pageNumber }) => ({
      items:
        filter === 'unread' && read
          ? []
          : [
              {
                id: 42,
                kind: 'paragraph_comment',
                title: 'Paragraph article',
                author: 'Writer',
                url: origin + '/#block-42',
                created: '2026-10-24T12:00:00Z',
                read,
              },
              ...(filter === 'all'
                ? [
                    {
                      id: 42,
                      kind: 'comment_reply',
                      title: 'Ordinary article',
                      author: 'Writer',
                      url: origin + '/#comment-42',
                      created: '2026-10-24T11:00:00Z',
                      read: true,
                    },
                  ]
                : []),
            ],
      page: pageNumber,
      pages: 2,
      recentUnread: read ? 0 : 1,
      enabled: true,
    }),
  });
  await page.locator('[data-pn-open-notifications]').click();
  await expect(page.locator('[data-notification-key="paragraph_comment:42"]')).toBeVisible();
  await expect(page.locator('[data-notification-key="comment_reply:42"]')).toBeVisible();
  await page.getByRole('button', { name: 'Unread', exact: true }).click();
  await expect(page.locator('li')).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'Unread', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect
    .poll(() =>
      calls.some((call) => call.url.includes('page=2') && call.url.includes('filter=unread')),
    )
    .toBeTruthy();
  await page.getByRole('button', { name: text.batch, exact: true }).click();
  await expect(page.locator('li')).toHaveCount(0);
  expect(calls.find((call) => call.url.endsWith('/read-page')).body).toEqual({
    items: [{ kind: 'paragraph_comment', id: 42 }],
  });
  await expect(page.getByRole('button', { name: text.batch, exact: true })).toBeDisabled();
});

test('partial page write failure refreshes actual state and remains explicit', async ({ page }) => {
  const { calls } = await fixture(page, { itemCount: 2 });
  let attempted = false;
  await page.route('**/wp-json/pagenest/v1/notifications/read-page', (route) => {
    attempted = true;
    return route.fulfill({
      status: 503,
      json: {
        code: 'notifications_partial',
        data: { written: [{ kind: 'comment_reply', id: 10 }] },
      },
    });
  });
  await page.getByRole('button', { name: 'Notifications', exact: true }).click();
  await page.getByRole('button', { name: text.batch, exact: true }).click();
  await expect(page.locator('[data-pn-notifications-status]')).toHaveText(text.partial);
  expect(attempted).toBeTruthy();
  expect(calls.filter((call) => call.url.includes('page=1'))).toHaveLength(2);
  await expect(page.locator('li')).toHaveCount(2);
});

test('keyboard preference and filter changes retain expanded settings and focus', async ({
  page,
}) => {
  await fixture(page);
  await page.getByRole('button', { name: 'Notifications', exact: true }).click();
  await page.getByText(text.settings, { exact: true }).click();
  const choice = page.getByRole('checkbox');
  await choice.focus();
  await page.keyboard.press('Space');
  await expect(page.locator('[data-pn-notifications-status]')).toHaveText(text.saved);
  await expect(choice).toBeFocused();
  await expect(choice).not.toBeChecked();
  await expect(page.locator('details')).toHaveAttribute('open', '');
  await page.getByRole('button', { name: 'Unread', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Unread', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await expect(page.getByRole('button', { name: 'Unread', exact: true })).toBeFocused();
  await expect(page.locator('details')).toHaveAttribute('open', '');
  await page.getByRole('button', { name: 'All', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'All', exact: true })).toBeFocused();
  await expect(page.getByRole('button', { name: 'All', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
});
test('notification shortcode keeps dialog list and metadata spacing under real article CSS', async ({
  page,
}) => {
  test.skip(!process.env.PAGENEST_TEST_THEME_ROOT, 'requires the actual theme content stylesheet');
  await fixture(page, { panels: 1 });
  await page.evaluate(() => {
    const root = document.querySelector('body > [data-pn-notifications]');
    const article = document.createElement('article');
    article.className = 'pagenest-article-body';
    article.style.cssText = '--pagenest-reading-size:16px;--pagenest-reading-line-height:1.6';
    root.before(article);
    article.append(root);
  });
  // Load theme CSS last: component specificity must survive either enqueue order.
  await page.addStyleTag({
    path: path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/content.css'),
  });
  const shortcode = page.locator('article [data-pn-notifications]');
  await shortcode.locator('[data-pn-notifications-load]').click();
  await page.locator('[data-pn-open-notifications]').click();
  const measure = (scope) =>
    scope.evaluate((root) => {
      const ul = getComputedStyle(root.querySelector('ul'));
      const li = getComputedStyle(root.querySelector('li'));
      const meta = getComputedStyle(root.querySelector('li p'));
      return {
        indent: ul.paddingInlineStart,
        listMargin: ul.marginBottom,
        rowMargin: li.marginTop,
        rowHeight: li.lineHeight,
        metaSize: meta.fontSize,
        metaSpace: meta.marginBottom,
        metaTop: meta.marginTop,
        metaColor: meta.color,
      };
    });
  const actual = await measure(shortcode);
  expect(actual.indent).toBe('0px');
  expect(actual.metaSpace).toBe('0px');
  expect(actual).toEqual(await measure(page.locator('dialog [data-pn-notifications]')));
});
