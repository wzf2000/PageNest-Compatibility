const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const accountText = {
  title: 'Account reading',
  intro: 'Nothing uploads automatically.',
  load: 'Load account reading',
  refresh: 'Refresh account reading',
  consent: 'I agree to save public reading positions to my account',
  enable: 'Enable account reading',
  off: 'Account reading is off.',
  enabled: 'Account reading is enabled.',
  merge: 'Merge device and account records',
  mergeHelp: 'Keep the most recent 100 public articles.',
  manage: 'Manage account records',
  deleteHelp: 'Device records remain.',
  remove: 'Remove from account',
  clear: 'Clear account records',
  withdraw: 'Withdraw consent and clear account records',
  confirmRemove: 'Remove?',
  confirmClear: 'Clear?',
  confirmWithdraw: 'Withdraw?',
  localRequired: 'Enable device memory before merging.',
  conflict: 'Account records changed. Refresh and confirm again.',
  error: 'Account reading failed.',
  serverPaused: 'Account saved; syncing is paused and device records were kept.',
  paused: 'Account reading is paused.',
  savedReadback: 'Saved, but the list could not be loaded. Refresh first.',
  loginRequired: 'Sign in again before loading account reading.',
  featureUnavailable: 'Account reading unavailable.',
  needConsent: 'Consent changed.',
  mergeSummary: 'Manual sync, recent 100 articles.',
  changed: 'Account records changed in another panel.',
  merged: 'Account and device records merged.',
  serverOnly: 'Account merge succeeded; device records were not updated.',
  saved: 'Account reading updated.',
};
const text = {
  reading: 'My reading',
  notes: 'Comments and notes',
  recent: 'Recent reading',
  later: 'Read later',
  consent: 'Remember on this device',
  deviceSummary: 'Device memory only.',
  unavailable: 'Storage unavailable',
  devicePrompt: 'Enable device memory.',
  help: 'Help',
  deviceHelp: 'Device help',
  manage: 'Manage device records',
  clear: 'Clear device records',
  empty: 'No records to show.',
  login: 'Sign in',
  loading: 'Loading…',
  records: 'My comments and private notes',
  q: 'Search',
  all: 'All',
  public: 'Public',
  author_only: 'Private',
  needs_review: 'Review',
  search: 'Search',
  privateIntro: 'Private records load on request.',
  load: 'Load my records',
  more: 'More',
  filter: 'Filter',
  backup: 'Backup',
  import: 'Import',
  export: 'Export',
  continue: 'Continue reading',
  remove: 'Remove',
  refresh: 'Refresh records',
  error: 'Error',
  deviceLoadError: 'Device list failed.',
  retry: 'Retry',
};
const item = (id, offset = 0, later = false) => ({
  id,
  time: Date.now() - 10000 + offset,
  later,
  anchor: 'stable',
});
async function fixture(page, options = {}) {
  const calls = [];
  let state = options.state || { enabled: true, revision: 'r1', items: [] };
  await page.route('**/account-origin', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }),
  );
  await page.goto('/account-origin');
  await page.setViewportSize({ width: options.width || 900, height: 844 });
  const panel =
    '<section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section>';
  await page.setContent(
    `<style>body{font:16px system-ui;margin:16px}${fs.readFileSync(path.join(root, 'assets/reading-center.css'), 'utf8')}</style><button data-pn-open-reading hidden>My reading</button><dialog class="pn-reading-dialog"><div class="pn-reading-dialog-toolbar"><h2>My reading</h2><button data-pn-close-reading>Close</button></div>${panel}</dialog>${panel.repeat(options.panels || (options.multiple ? 1 : 0))}`,
  );
  await page.evaluate(
    ({ text, accountText, options }) => {
      localStorage.clear();
      if (options.device)
        localStorage.setItem('pagenest.reading.v1', JSON.stringify(options.device));
      window.PageNestReading = {
        text,
        accountText,
        accountApi: options.off ? '' : location.origin + '/account',
        api: location.origin + '/notes/',
        resolve: location.origin + '/resolve',
        nonce: options.anonymous ? '' : 'synthetic',
        login: '/login',
        current: null,
      };
    },
    {
      text,
      accountText,
      options: { device: options.device, off: options.off, anonymous: options.anonymous },
    },
  );
  await page.route('**/resolve', async (route) => {
    const { ids } = route.request().postDataJSON();
    await route.fulfill({
      json: {
        items: ids.map((id) => ({
          id,
          title: 'Article ' + id,
          url: '/article/' + id,
          headings: ['stable'],
        })),
      },
    });
  });
  await page.route(/\/account(?:\/[^?]+)?$/, async (route) => {
    const action = new URL(route.request().url()).pathname
      .replace('/account', '')
      .replace(/^\//, '');
    const body = route.request().method() === 'POST' ? route.request().postDataJSON() : undefined;
    calls.push({ action, body });
    if (options.hook && (await options.hook({ route, action, body, state }))) return;
    if (body) {
      if (action === 'consent') state.enabled = true;
      if (action === 'merge') {
        const merged = new Map();
        for (const entry of [...state.items, ...body.items]) {
          const old = merged.get(entry.id);
          merged.set(entry.id, {
            ...(!old || old.time < entry.time ? entry : old),
            later: entry.later || old?.later === true,
          });
        }
        state.items = [...merged.values()].sort((a, b) => b.time - a.time).slice(0, 100);
      }
      if (action === 'remove') state.items = state.items.filter((entry) => entry.id !== body.id);
      if (['clear', 'withdraw'].includes(action)) state.items = [];
      if (action === 'withdraw') state.enabled = false;
      state.revision += 'n';
    }
    await route.fulfill({
      json: {
        ...state,
        items: state.items.map((entry) => ({
          ...entry,
          title: 'Article ' + entry.id,
          url: '/article/' + entry.id,
        })),
      },
    });
  });
  await page.addScriptTag({
    content: fs.readFileSync(path.join(root, 'assets/reading-center.js'), 'utf8'),
  });
  await page.locator('[data-pn-open-reading]').click();
  const view = page.locator('dialog [data-pn-account]');
  async function open() {
    await page.locator('dialog [data-pn-tab=account]').click();
  }
  async function load() {
    await open();
    await view.locator('[data-pn-account-load]').click();
    await expect(view.locator('[data-pn-account-load]')).toHaveText(accountText.refresh);
  }
  return { calls, view, open, load };
}
test('account tab and consent never load or upload without explicit actions', async ({ page }) => {
  const { calls, view, open } = await fixture(page, {
    state: { enabled: false, revision: '', items: [] },
    device: { enabled: true, items: [item(1)] },
  });
  await open();
  expect(calls).toEqual([]);
  await view.locator('[data-pn-account-load]').click();
  await expect(view.locator('[data-pn-account-enable]')).toBeDisabled();
  await view.locator('[data-pn-account-consent]').check();
  await view.locator('[data-pn-account-enable]').click();
  await expect(view.locator('[data-pn-account-merge]')).toBeVisible();
  expect(calls.map((call) => call.action)).toEqual(['', 'consent']);
  expect(calls[1].body).toEqual({ enabled: true, revision: '' });
});
test('manual merge combines both lists and stores only minimal records on device', async ({
  page,
}) => {
  const a = item(1),
    b = item(2, 100, true);
  const { calls, view, load } = await fixture(page, {
    device: { enabled: true, items: [a] },
    state: {
      enabled: true,
      revision: 'r1',
      items: [b, { ...a, time: a.time - 1000, later: true }],
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.merged);
  expect(calls[1].action).toBe('merge');
  expect(calls[1].body.items).toEqual([a]);
  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1')));
  expect(saved.items).toEqual([b, { ...a, later: true }]);
});
test('without device consent account links work and merging makes no request', async ({ page }) => {
  const { calls, view, load } = await fixture(page, {
    state: { enabled: true, revision: 'r1', items: [item(2)] },
  });
  await load();
  await expect(view.getByRole('link', { name: 'Article 2' })).toHaveAttribute(
    'href',
    /\/article\/2#stable$/,
  );
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.localRequired);
  expect(calls.length).toBe(1);
});
test('storage write failure prevents upload', async ({ page }) => {
  const { calls, view, load } = await fixture(page, {
    device: { enabled: true, items: [item(1)] },
  });
  await load();
  await page.evaluate(() => {
    Storage.prototype.setItem = () => {
      throw Error('synthetic storage failure');
    };
  });
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.localRequired);
  expect(calls.length).toBe(1);
});
test('server success and device storage failure are reported separately', async ({ page }) => {
  const { view, load } = await fixture(page, {
    device: { enabled: true, items: [item(1)] },
    hook: async ({ action }) => {
      if (action === 'merge')
        await page.evaluate(() => {
          Storage.prototype.setItem = () => {
            throw Error('synthetic storage failure');
          };
        });
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.serverOnly);
});
test('conflict discards old account state and requires explicit reload', async ({ page }) => {
  const { calls, view, load } = await fixture(page, {
    device: { enabled: true, items: [item(1)] },
    hook: async ({ route, action }) => {
      if (action !== 'merge') return false;
      await route.fulfill({ status: 409, json: { code: 'account_reading_conflict' } });
      return true;
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.conflict);
  await expect(view.locator('[data-pn-account-merge]')).toHaveCount(0);
  expect(calls.length).toBe(2);
});
for (const exit of ['tab', 'close', 'hidden', 'pagehide', 'auth'])
  test(`${exit} clears memory and ignores late account responses`, async ({ page }) => {
    let release, started;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const start = new Promise((resolve) => {
      started = resolve;
    });
    const { view, open } = await fixture(page, {
      hook: async ({ route }) => {
        started();
        await gate;
        await route.fulfill({
          json: {
            enabled: true,
            revision: 'late',
            items: [{ ...item(7), title: 'Late private history', url: '/article/7' }],
          },
        });
        return true;
      },
    });
    await open();
    await view.locator('[data-pn-account-load]').click();
    await start;
    if (exit === 'tab') await page.locator('dialog [data-pn-tab=recent]').click();
    else if (exit === 'close') await page.locator('[data-pn-close-reading]').click();
    else
      await page.evaluate((exit) => {
        if (exit === 'hidden') {
          Object.defineProperty(document, 'hidden', { configurable: true, value: true });
          document.dispatchEvent(new Event('visibilitychange'));
        }
        if (exit === 'pagehide') dispatchEvent(new Event('pagehide'));
        if (exit === 'auth') document.dispatchEvent(new Event('pagenest-pc-auth-change'));
      }, exit);
    release();
    await expect(view).not.toContainText('Late private history');
    await expect(view.locator('[data-pn-account-merge]')).toHaveCount(0);
  });
test('a successful write invalidates another account panel without exposing its cached list', async ({
  page,
}) => {
  const { view, load } = await fixture(page, {
    multiple: true,
    state: { enabled: true, revision: 'r1', items: [item(2)] },
  });
  await load();
  await page.locator('[data-pn-close-reading]').click();
  const other = page.locator('body > .pn-reading');
  await other.locator('[data-pn-tab=account]').click();
  await other.locator('[data-pn-account-load]').click();
  await expect(other.getByRole('link', { name: 'Article 2' })).toBeVisible();
  await page.locator('[data-pn-open-reading]').click();
  await view.locator('[data-pn-account-load]').click();
  await view.locator('[data-pn-account-manage] summary').click();
  page.once('dialog', (dialog) => dialog.accept());
  await view.locator('[data-pn-account-clear]').click();
  await expect(other.locator('[data-pn-account]')).toContainText(accountText.changed);
  await expect(other.getByRole('link', { name: 'Article 2' })).toHaveCount(0);
});
test('account management changes cloud only and withdraw keeps device records', async ({
  page,
}) => {
  const local = { enabled: true, items: [item(1)] };
  const { view, load } = await fixture(page, {
    device: local,
    state: { enabled: true, revision: 'r1', items: [item(2)] },
  });
  await load();
  await view.locator('[data-pn-account-manage] summary').click();
  page.once('dialog', (dialog) => dialog.accept());
  await view.locator('[data-pn-account-remove="2"]').click();
  await expect(view).toContainText(text.empty);
  await expect(view.locator('[data-pn-account-manage]')).toHaveAttribute('open', '');
  page.once('dialog', (dialog) => dialog.accept());
  await view.locator('[data-pn-account-withdraw]').click();
  await expect(view.locator('[data-pn-account-enable]')).toBeDisabled();
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1'))),
  ).toEqual(local);
});
test('phone tab keyboard selection and action focus remain visible', async ({ page }) => {
  const { view, load } = await fixture(page, {
    width: 390,
    state: { enabled: false, revision: '', items: [] },
  });
  await page.locator('dialog [data-pn-tab=recent]').focus();
  await page.keyboard.press('End');
  await expect(page.locator('dialog [data-pn-tab=account]')).toBeFocused();
  await load();
  await view.locator('[data-pn-account-consent]').check();
  await view.locator('[data-pn-account-enable]').click();
  await expect(view.locator('[data-pn-account-load]')).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
test('administrator off hides the tab and anonymous account reading only asks to sign in', async ({
  page,
}) => {
  await fixture(page, { off: true });
  await expect(page.locator('[data-pn-tab=account]')).toHaveCount(0);
  const { calls, view, open } = await fixture(page, { anonymous: true });
  await open();
  await expect(view.getByRole('link', { name: 'Sign in' })).toBeVisible();
  expect(calls).toEqual([]);
});
for (const status of [401, 403])
  test(`${status} clears account memory and explains the failure`, async ({ page }) => {
    const { view, load } = await fixture(page, {
      device: { enabled: true, items: [item(1)] },
      hook: async ({ route, action }) => {
        if (action !== 'merge') return false;
        await route.fulfill({
          status,
          json: {
            code: status === 401 ? 'account_reading_authentication' : 'account_reading_disabled',
          },
        });
        return true;
      },
    });
    await load();
    await view.locator('[data-pn-account-merge]').click();
    await expect(view.getByRole('status')).toHaveText(
      status === 401 ? accountText.loginRequired : accountText.featureUnavailable,
    );
    await expect(view.locator('[data-pn-account-merge]')).toHaveCount(0);
  });
test('changed device consent during merge prevents response from repopulating local records', async ({
  page,
}) => {
  const { view, load } = await fixture(page, {
    device: { enabled: true, items: [item(1)] },
    hook: async ({ action }) => {
      if (action === 'merge')
        await page.evaluate(() =>
          localStorage.setItem(
            'pagenest.reading.v1',
            JSON.stringify({ enabled: false, items: [] }),
          ),
        );
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.serverOnly);
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1'))),
  ).toEqual({ enabled: false, items: [] });
});
test('paused account feature offers fresh status and withdrawal without loading history', async ({
  page,
}) => {
  const { calls, view, load } = await fixture(page, {
    state: { available: false, enabled: true, revision: 'r1', items: [] },
  });
  await load();
  await expect(view).toContainText(accountText.paused);
  await expect(view.locator('[data-pn-account-merge]')).toHaveCount(0);
  page.once('dialog', (dialog) => dialog.accept());
  await view.locator('[data-pn-account-withdraw]').click();
  await expect(view.locator('[data-pn-account-withdraw]')).toHaveCount(0);
  expect(calls.map((call) => call.action)).toEqual(['', 'withdraw']);
});
test('notes authentication loss clears account history in another panel', async ({ page }) => {
  await fixture(page, {
    multiple: true,
    state: { enabled: true, revision: 'r1', items: [item(2)] },
  });
  await page.locator('[data-pn-close-reading]').click();
  const standalone = page.locator('body > .pn-reading');
  await standalone.locator('[data-pn-tab=account]').click();
  await standalone.locator('[data-pn-account-load]').click();
  await expect(standalone.getByRole('link', { name: 'Article 2' })).toBeVisible();
  await page.route('**/notes/my-records?*', (route) =>
    route.fulfill({ status: 401, json: { code: 'rest_not_logged_in' } }),
  );
  await page.locator('[data-pn-open-reading]').click();
  await page.locator('dialog [data-pn-tab=records]').click();
  await page
    .locator('dialog [data-pn-records]')
    .getByRole('button', { name: 'Load my records', exact: true })
    .click();
  await expect(standalone.getByRole('link', { name: 'Article 2' })).toHaveCount(0);
  await expect(standalone.locator('[data-pn-account]')).toContainText(accountText.loginRequired);
});
test('a saved write with failed response projection asks for refresh without claiming rollback', async ({
  page,
}) => {
  const { view, load } = await fixture(page, {
    device: { enabled: true, items: [item(1)] },
    hook: async ({ route, action }) => {
      if (action !== 'merge') return false;
      await route.fulfill({ status: 500, json: { code: 'account_reading_readback' } });
      return true;
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.savedReadback);
  await expect(view.locator('[data-pn-account-merge]')).toHaveCount(0);
});
test('a feature pause after merge commit preserves the original device list', async ({ page }) => {
  const local = { enabled: true, items: [item(1)] };
  const { view, load } = await fixture(page, {
    device: local,
    hook: async ({ route, action }) => {
      if (action !== 'merge') return false;
      await route.fulfill({
        json: { available: false, enabled: true, revision: 'paused', items: [] },
      });
      return true;
    },
  });
  await load();
  await view.locator('[data-pn-account-merge]').click();
  await expect(view.getByRole('status')).toHaveText(accountText.serverPaused);
  await expect(view).toContainText(accountText.paused);
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1'))),
  ).toEqual(local);
});
test('phone keyboard removal keeps account management open and chooses the nearest action', async ({
  page,
}) => {
  const { view, load } = await fixture(page, {
    width: 390,
    state: { enabled: true, revision: 'r1', items: [item(2), item(3), item(4)] },
  });
  await load();
  const management = view.locator('[data-pn-account-manage]');
  await management.locator('summary').focus();
  await page.keyboard.press('Enter');
  for (const [id, next] of [
    [3, 4],
    [4, 2],
    [2, null],
  ]) {
    await management.locator(`[data-pn-account-remove="${id}"]`).focus();
    page.once('dialog', (dialog) => dialog.accept());
    await page.keyboard.press('Enter');
    await expect(management).toHaveAttribute('open', '');
    await expect(
      management.locator(next ? `[data-pn-account-remove="${next}"]` : '[data-pn-account-clear]'),
    ).toBeFocused();
  }
  page.once('dialog', (dialog) => dialog.accept());
  await page.keyboard.press('Enter');
  await expect(management).toHaveAttribute('open', '');
  await expect(management.locator('[data-pn-account-withdraw]')).toBeFocused();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
test('account removal does not steal focus after the reader moves elsewhere', async ({ page }) => {
  let release, started;
  const gate = new Promise((resolve) => {
    release = resolve;
  });
  const start = new Promise((resolve) => {
    started = resolve;
  });
  const { view, load } = await fixture(page, {
    state: { enabled: true, revision: 'r1', items: [item(2), item(3)] },
    hook: async ({ action }) => {
      if (action === 'remove') {
        started();
        await gate;
      }
    },
  });
  await load();
  await view.locator('[data-pn-account-manage] summary').click();
  page.once('dialog', (dialog) => dialog.accept());
  await view.locator('[data-pn-account-remove="2"]').click();
  await start;
  const close = page.locator('[data-pn-close-reading]');
  await close.focus();
  release();
  await expect(view.locator('[data-pn-account-remove="2"]')).toHaveCount(0);
  await expect(close).toBeFocused();
});
async function loadedReadingPanels(page) {
  await fixture(page, { panels: 2, state: { enabled: true, revision: 'r1', items: [item(2)] } });
  await page.locator('[data-pn-close-reading]').click();
  const panels = page.locator('body > .pn-reading');
  const account = panels.nth(0).locator('[data-pn-account]');
  const notes = panels.nth(1).locator('[data-pn-records]');
  await panels.nth(0).locator('[data-pn-tab=account]').click();
  await account.locator('[data-pn-account-load]').click();
  await expect(account.getByRole('link', { name: 'Article 2' })).toBeVisible();
  await page.route('**/notes/my-records?*', (route) =>
    route.fulfill({
      json: {
        items: [
          {
            id: 91,
            post_id: 1,
            post_title: 'Owned note article',
            location_url: '/article/1#stable',
            visibility: 'author_only',
            quote: 'Owned synthetic quotation',
            text: 'Cross-module private note',
            association: 'attached',
            version: 'synthetic',
          },
        ],
        next_cursor: null,
      },
    }),
  );
  await panels.nth(1).locator('[data-pn-tab=records]').click();
  await notes.getByRole('button', { name: text.load, exact: true }).click();
  await expect(notes).toContainText('Cross-module private note');
  await page.evaluate(() => {
    window.crossAuthChanges = 0;
    document.addEventListener('pagenest-pc-auth-change', () => {
      window.crossAuthChanges++;
    });
  });
  return { account, notes };
}
const notificationText = {
  all: 'All',
  filter: 'Show notifications',
  batch: 'Mark this page as read',
  paragraph: 'Paragraph comment',
  reply: 'Comment reply',
  load: 'Load notifications',
  refresh: 'Refresh notifications',
  window: 'Latest replies.',
  loading: 'Loading notifications…',
  empty: 'No replies.',
  error: 'Notifications failed.',
  expired: 'Sign in again.',
  off: 'Notifications unavailable.',
  login: 'Sign in for notifications',
  read: 'Read',
  unread: 'Unread',
  mark: 'Mark as read',
  previous: 'Previous',
  next: 'Next',
  preference: 'Receive future notifications',
  independent: 'Email is separate.',
  recent: 'Recent unread:',
  saved: 'Preference saved.',
  settings: 'Notification preferences',
};
async function addNotifications(page, action, status, code, hook) {
  await page.evaluate(
    ({ notificationText, action }) => {
      window.crossNotificationsSettled = 0;
      const fetch = window.fetch.bind(window);
      window.fetch = async (...args) => {
        const response = await fetch(...args);
        if (String(args[0]).includes('/cross-notifications')) {
          const json = response.json.bind(response);
          response.json = async () => {
            try {
              return await json();
            } finally {
              setTimeout(() => {
                window.crossNotificationsSettled++;
              }, 0);
            }
          };
        }
        return response;
      };
      document.body.insertAdjacentHTML(
        'beforeend',
        '<button data-pn-open-notifications hidden>Notifications<span data-pn-notifications-badge hidden></span></button><section class="pn-notifications" data-pn-notifications><button data-pn-notifications-load hidden>Load notifications</button><div role="status" data-pn-notifications-status></div><div data-pn-notifications-result></div></section>',
      );
      const api = location.origin + '/cross-notifications';
      window.PageNestNotifications = {
        api,
        readApi: api + '/read',
        batchApi: api + '/read-page',
        preferenceApi: api + '/preference',
        ...(action === 'count' ? { countApi: api + '/count' } : {}),
        nonce: 'synthetic',
        login: '/login',
        text: notificationText,
      };
    },
    { notificationText, action },
  );
  await page.route(
    /\/cross-notifications(?:\/(?:read|read-page|preference|count))?(?:\?.*)?$/,
    async (route) => {
      const path = new URL(route.request().url()).pathname;
      const current = path.split('/').pop();
      if (hook && (await hook(route, current))) return;
      if ((action === 'list' && current === 'cross-notifications') || current === action)
        return route.fulfill({ status, json: { code } });
      await route.fulfill({
        json: {
          enabled: true,
          page: 1,
          pages: 1,
          recentUnread: 1,
          items: [
            {
              id: 7,
              kind: 'comment_reply',
              title: 'Public reply article',
              author: 'Reader',
              url: '/article/7',
              created: '2026-10-24T12:00:00Z',
              read: false,
            },
          ],
        },
      });
    },
  );
  await page.addScriptTag({
    content: fs.readFileSync(path.join(root, 'assets/notifications.js'), 'utf8'),
  });
  return page.locator('section[data-pn-notifications]');
}
for (const action of ['list', 'preference', 'read', 'count']) {
  for (const [status, code] of [
    [401, 'rest_not_logged_in'],
    [403, 'rest_cookie_invalid_nonce'],
  ])
    test(`notifications ${action} ${code} clears loaded account and own notes through the shared auth event`, async ({
      page,
    }) => {
      const { account, notes } = await loadedReadingPanels(page);
      const notifications = await addNotifications(page, action, status, code);
      if (action !== 'count') {
        await notifications.locator('[data-pn-notifications-load]').click();
        if (action === 'preference') {
          await notifications.locator('details summary').click();
          await notifications.getByRole('checkbox').uncheck();
        }
        if (action === 'read')
          await notifications
            .getByRole('button', { name: notificationText.mark, exact: true })
            .click();
      }
      await expect(account.getByRole('link', { name: 'Article 2' })).toHaveCount(0);
      await expect(notes).not.toContainText('Cross-module private note');
      expect(await page.evaluate(() => window.crossAuthChanges)).toBe(1);
    });
}
for (const action of ['list', 'count'])
  test(`notification ${action} business 403 keeps other modules authenticated`, async ({
    page,
  }) => {
    const { account, notes } = await loadedReadingPanels(page);
    const notifications = await addNotifications(page, action, 403, 'notifications_disabled');
    if (action === 'list') await notifications.locator('[data-pn-notifications-load]').click();
    await expect
      .poll(() => page.evaluate(() => window.crossNotificationsSettled))
      .toBeGreaterThan(0);
    await expect(notifications.locator('[data-pn-notifications-status]')).toHaveText(
      action === 'list' ? notificationText.off : '',
    );
    expect(await page.evaluate(() => window.crossAuthChanges)).toBe(0);
    await expect(account.getByRole('link', { name: 'Article 2' })).toBeVisible();
    await expect(notes).toContainText('Cross-module private note');
  });
for (const [status, code] of [
  [401, 'rest_not_logged_in'],
  [403, 'rest_cookie_invalid_nonce'],
  [403, 'forbidden'],
])
  test(`paragraph comments ${code} distinguish shared auth loss from source permission failure`, async ({
    page,
  }) => {
    const { account, notes } = await loadedReadingPanels(page);
    await page.evaluate(() => {
      document.body.insertAdjacentHTML(
        'beforeend',
        '<article class="pagenest-comments-body"><p data-pagenest-block="cross-block">Synthetic paragraph</p></article>',
      );
      window.PageNestComments = {
        post: 10,
        api: location.origin + '/cross-comments/',
        nonce: 'synthetic',
        user: 1,
        displayName: 'Reader',
        loginUrl: '/login',
      };
    });
    await page.route('**/cross-comments/comments-page?*', (route) =>
      route.fulfill({ status, json: { code } }),
    );
    await page.addScriptTag({
      content: fs.readFileSync(path.join(root, 'assets/paragraph-comments.js'), 'utf8'),
    });
    const auth = status === 401 || code === 'rest_cookie_invalid_nonce';
    await expect.poll(() => page.evaluate(() => window.PageNestComments.user)).toBe(0);
    expect(await page.evaluate(() => window.crossAuthChanges)).toBe(auth ? 1 : 0);
    if (auth) {
      await expect(account.getByRole('link', { name: 'Article 2' })).toHaveCount(0);
      await expect(notes).not.toContainText('Cross-module private note');
    } else {
      await expect(account.getByRole('link', { name: 'Article 2' })).toBeVisible();
      await expect(notes).toContainText('Cross-module private note');
    }
  });
for (const action of ['list', 'count'])
  test(`a late notification ${action} auth error cannot clear a newer authenticated reading state`, async ({
    page,
  }) => {
    const { account, notes } = await loadedReadingPanels(page);
    await page.evaluate(() => {
      const fetch = window.fetch.bind(window);
      window.fetch = (url, options = {}) =>
        fetch(
          url,
          String(url).includes('/cross-notifications')
            ? { ...options, signal: undefined }
            : options,
        );
    });
    let release, started;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const start = new Promise((resolve) => {
      started = resolve;
    });
    let held = false;
    const notifications = await addNotifications(page, action, 200, '', async (route, current) => {
      if (held || current !== (action === 'count' ? 'count' : 'cross-notifications')) return false;
      held = true;
      started();
      await gate;
      await route.fulfill({ status: 401, json: { code: 'rest_not_logged_in' } });
      return true;
    });
    if (action === 'list') await notifications.locator('[data-pn-notifications-load]').click();
    await start;
    if (action === 'count') {
      // A newer explicit list operation cancels the badge generation without changing reading state.
      await notifications.locator('[data-pn-notifications-load]').click();
      await expect(notifications.locator('[data-notification-id]')).toHaveCount(1);
    } else {
      // The shared event purges the old operation. A subsequent explicit load represents fresh state.
      await page.evaluate(() => document.dispatchEvent(new Event('pagenest-pc-auth-change')));
      await account.locator('[data-pn-account-load]').click();
      await notes.getByRole('button', { name: text.load, exact: true }).click();
      await expect(account.getByRole('link', { name: 'Article 2' })).toBeVisible();
      await expect(notes).toContainText('Cross-module private note');
    }
    const settled = await page.evaluate(() => window.crossNotificationsSettled);
    release();
    await expect
      .poll(() => page.evaluate(() => window.crossNotificationsSettled))
      .toBeGreaterThan(settled);
    expect(await page.evaluate(() => window.crossAuthChanges)).toBe(action === 'count' ? 0 : 1);
    await expect(account.getByRole('link', { name: 'Article 2' })).toBeVisible();
    await expect(notes).toContainText('Cross-module private note');
  });
for (const target of ['article link', 'management summary', 'help summary'])
  test(`pending account removal preserves focus on the reader's ${target} after redraw`, async ({
    page,
  }) => {
    let release, started;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const start = new Promise((resolve) => {
      started = resolve;
    });
    const { view, load } = await fixture(page, {
      width: 390,
      state: { enabled: true, revision: 'r1', items: [item(2), item(3)] },
      hook: async ({ action }) => {
        if (action === 'remove') {
          started();
          await gate;
        }
      },
    });
    await load();
    await view.locator('[data-pn-account-manage] > summary').click();
    page.once('dialog', (dialog) => dialog.accept());
    await view.locator('[data-pn-account-remove="3"]').click();
    await start;
    const focusTarget =
      target === 'article link'
        ? view.getByRole('link', { name: 'Article 2' })
        : target === 'management summary'
          ? view.locator('[data-pn-account-manage] > summary')
          : view.locator('.pn-reading-help > summary');
    if (target === 'help summary') await focusTarget.click();
    else await focusTarget.focus();
    release();
    await expect(view.locator('[data-pn-account-remove="3"]')).toHaveCount(0);
    await expect(focusTarget).toBeFocused();
    if (target === 'help summary')
      await expect(view.locator('.pn-reading-help')).toHaveAttribute('open', '');
  });
