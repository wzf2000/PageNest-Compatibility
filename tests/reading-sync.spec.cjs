const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const actor = 'a'.repeat(64);
const syncText = {
  title: 'Automatic reading and reading preferences',
  load: 'Load sync settings',
  automatic: 'Automatically save future reading positions on this device',
  preferences: 'Sync reading size, width and palette on this device',
  help: 'Automatic reading saves only the current public article after consent. Earlier device history still needs a manual merge. Reading preferences have separate consent; withdrawing them keeps device preferences. Stopping automatic reading keeps saved reading records. Stopping automatic reading or withdrawing preferences also pauses other devices until they confirm again.',
  enabled: 'Enabled on this device.',
  withdrawnPreferences: 'Account reading preferences cleared. Device preferences were kept.',
  stoppedAutomatic: 'Automatic saving stopped. Saved account and device records were kept.',
  saved: 'Saved to your account.',
  applied: 'Account reading preferences applied to this page. Device values were kept.',
  reconfirm:
    'This device is paused. Load the latest settings, then check the option to confirm again.',
  storage: 'Browser storage is unavailable. Automatic syncing is paused.',
  savedStorage:
    'Account consent was saved, but this device could not save its sync marker. Syncing on this device is paused.',
  localRequired:
    'Enable device reading memory with available browser storage before automatic saving.',
  failed: 'Syncing failed and is paused. Load the latest settings and confirm again.',
  conflict:
    'Account state changed. Syncing is paused; load settings and confirm again. Older changes will not be resent.',
  login: 'Sign-in expired. Device preferences were restored; sign in and confirm syncing again.',
  paused: 'Account syncing is paused on this site. You can still withdraw existing consent.',
  savedPaused:
    'The account update was saved, but this site paused syncing. Device values were kept.',
  accountFirst: 'Enable account reading above before separately allowing automatic positions.',
  noTheme:
    'This theme has no compatible reading-preference interface. Nothing was applied; you can still withdraw account preferences.',
  withdrawPreferences: 'Withdraw consent and clear account preferences',
  stopAutomatic: 'Stop automatic reading',
  saving: 'Saving reading preferences. You can withdraw after this request finishes.',
};
const text = {
  reading: 'My reading',
  notes: 'Comments and notes',
  recent: 'Recent',
  later: 'Later',
  consent: 'Remember on device',
  deviceSummary: 'Device history',
  help: 'Help',
  deviceHelp: 'Device memory',
  manage: 'Manage device',
  clear: 'Clear device',
  empty: 'Empty',
  login: 'Sign in',
  loading: 'Loading',
  records: 'Own records',
  q: 'Search',
  all: 'All',
  public: 'Public',
  author_only: 'Private',
  needs_review: 'Review',
  search: 'Search',
  privateIntro: 'Load privately',
  load: 'Load records',
  more: 'More',
  filter: 'Filter',
  backup: 'Backup',
  import: 'Import',
  export: 'Export',
  continue: 'Continue',
  remove: 'Remove',
  refresh: 'Refresh',
  error: 'Error',
  deviceLoadError: 'Device failed',
  retry: 'Retry',
};
const accountText = {
  title: 'Account reading',
  intro: 'Load manually',
  load: 'Load account',
  refresh: 'Refresh account',
  consent: 'Account consent',
  enable: 'Enable account',
  off: 'Account off',
  enabled: 'Account enabled',
  merge: 'Merge',
  mergeSummary: 'Recent 100',
  mergeHelp: 'Explicit merge',
  manage: 'Manage account',
  deleteHelp: 'Device records remain',
  remove: 'Remove account',
  clear: 'Clear account',
  withdraw: 'Withdraw account',
  confirmRemove: 'Remove?',
  confirmClear: 'Clear?',
  confirmWithdraw: 'Withdraw?',
  saved: 'Saved',
  error: 'Error',
  conflict: 'Conflict',
  localRequired: 'Device required',
  loginRequired: 'Sign in',
  featureUnavailable: 'Unavailable',
  needConsent: 'Consent required',
  changed: 'Changed',
};
const localPreferences = { size: '18', width: 'compact', palette: 'paper' };
// A network-free protocol provider for Companion CI; theme behavior is tested in the theme repo.
function minimalPreferenceProtocol() {
  const controls = document.querySelector('[data-pagenest-reader-preferences]');
  const article = document.querySelector('.pagenest-article');
  const key = 'pagenest-reader-preferences-v1';
  let local = JSON.parse(localStorage.getItem(key)).preferences,
    remote = {};
  const values = () => ({ ...local, ...remote });
  const emit = (kind, source, changed = [], requestId = '') =>
    document.dispatchEvent(
      new CustomEvent('pagenest-reader-preferences-' + kind, {
        detail: { version: 1, source, changed, preferences: values(), requestId },
      }),
    );
  const render = () => {
    for (const [name, value] of Object.entries(values())) {
      article.dataset['reader' + name[0].toUpperCase() + name.slice(1)] = value;
      controls.querySelectorAll(`[data-reader-preference="${name}"]`).forEach((input) => {
        input.checked = input.value === value;
      });
    }
  };
  controls.addEventListener('change', (event) => {
    const name = event.target.dataset.readerPreference;
    local[name] = event.target.value;
    if (Object.keys(remote).length) remote[name] = event.target.value;
    localStorage.setItem(key, JSON.stringify({ version: 1, preferences: local }));
    render();
    emit('change', 'user', [name]);
  });
  controls.querySelector('[data-reader-reset]').addEventListener('click', () => {
    local = { size: 'default', width: 'default', palette: 'default' };
    remote = {};
    localStorage.removeItem(key);
    render();
    emit('change', 'user', Object.keys(local));
  });
  document.addEventListener('pagenest-reader-preferences-request', (event) =>
    emit('ready', 'local', [], event.detail.requestId),
  );
  document.addEventListener('pagenest-reader-preferences-apply', (event) => {
    remote =
      event.detail.source === 'local'
        ? {}
        : Object.fromEntries(
            event.detail.changed.map((name) => [name, event.detail.preferences[name]]),
          );
    render();
    emit('applied', event.detail.source, event.detail.changed, event.detail.requestId);
  });
  controls.hidden = false;
  render();
  emit('ready', 'local');
}
async function fixture(page, options = {}) {
  await page.clock.install();
  await page.setViewportSize({ width: options.width || 390, height: 844 });
  await page.route('**/sync-origin', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }),
  );
  await page.goto('/sync-origin');
  let revision = 3;
  const next = () => String(++revision).padStart(32, '0');
  const state = {
    available: true,
    readingEnabled: !options.manualOff,
    automatic: {
      enabled: !!options.resumeAutomatic,
      epoch: options.resumeAutomatic ? '1'.repeat(32) : '',
      started: 0,
    },
    preferences: {
      enabled: !!(options.resumePreferences || options.cloudPreferences),
      epoch: options.resumePreferences || options.cloudPreferences ? '2'.repeat(32) : '',
      revision: options.resumePreferences || options.cloudPreferences ? '3'.repeat(32) : '',
      values:
        options.resumePreferences || options.cloudPreferences
          ? { size: '20', width: 'wide', palette: 'dark' }
          : {},
    },
  };
  const manual = { available: true, enabled: !options.manualOff, revision: next(), items: [] };
  const calls = [];
  await page.route(/\/account(?:\/.*)?(?:\?.*)?$/, async (route) => {
    const action = new URL(route.request().url()).pathname
      .replace('/account', '')
      .replace(/^\//, '');
    const body = route.request().method() === 'POST' ? route.request().postDataJSON() : undefined;
    calls.push({ action, body });
    if (options.hook && (await options.hook({ action, body, route, state }))) return;
    if (!action) return route.fulfill({ json: manual });
    if (action === 'consent') {
      manual.enabled = true;
      manual.revision = next();
      state.readingEnabled = true;
      return route.fulfill({ json: manual });
    }
    if (action === 'sync') return route.fulfill({ json: state });
    if (action.startsWith('automatic/')) {
      if (body.epoch !== state.automatic.epoch)
        return route.fulfill({ status: 409, json: { code: 'account_reading_conflict' } });
      if (action === 'automatic/progress')
        return route.fulfill({
          json: { available: true, epoch: state.automatic.epoch, revision: next() },
        });
      if (action === 'automatic/withdraw')
        state.automatic = { enabled: false, epoch: next(), started: 0 };
      else if (!state.automatic.enabled)
        state.automatic = { enabled: true, epoch: next(), started: Date.now() };
      return route.fulfill({ json: { ...state.automatic, available: true } });
    }
    if (action.startsWith('preferences/')) {
      if (body.revision !== state.preferences.revision)
        return route.fulfill({ status: 409, json: { code: 'account_reading_conflict' } });
      if (action === 'preferences/withdraw')
        state.preferences = { enabled: false, epoch: next(), revision: next(), values: {} };
      else if (action === 'preferences/consent') {
        if (!state.preferences.enabled)
          state.preferences = { enabled: true, epoch: next(), revision: next(), values: {} };
      } else
        state.preferences = {
          ...state.preferences,
          revision: next(),
          values: { ...state.preferences.values, ...body.values },
        };
      return route.fulfill({ json: { ...state.preferences, available: true } });
    }
    await route.fulfill({ json: { enabled: true, revision: next(), items: [] } });
  });
  await page.route('**/resolve', (route) =>
    route.fulfill({
      json: {
        items: route
          .request()
          .postDataJSON()
          .ids.map((id) => ({
            id,
            title: 'Public article ' + id,
            url: '/article/' + id,
            headings: ['stable'],
          })),
      },
    }),
  );
  const theme = process.env.PAGENEST_TEST_THEME_ROOT;
  const themeSource =
    theme && fs.existsSync(path.join(theme, 'assets/reader-preferences.js'))
      ? fs.readFileSync(path.join(theme, 'assets/reader-preferences.js'), 'utf8')
      : '';
  const realProtocol =
    options.theme !== false &&
    !options.stub &&
    themeSource.includes('pagenest-reader-preferences-apply');
  test.info().annotations.push({
    type: 'preference-interface',
    description:
      options.theme === false
        ? 'unavailable'
        : realProtocol
          ? 'actual theme source'
          : 'explicit minimal protocol stub; independent Companion contract',
  });
  const controls =
    '<section data-pagenest-reader-preferences hidden><h3>Reader preferences</h3>' +
    Object.entries({
      size: ['default', '18', '20', '22'],
      width: ['default', 'compact', 'wide'],
      palette: ['default', 'paper', 'dark'],
    })
      .map(
        ([name, values]) =>
          '<fieldset><legend>' +
          name +
          '</legend>' +
          values
            .map(
              (value) =>
                `<label><input type="radio" name="${name}" data-reader-preference="${name}" value="${value}">${value}</label>`,
            )
            .join('') +
          '</fieldset>',
      )
      .join('') +
    '<button data-reader-reset>Reset reader</button><p role="status"></p></section>';
  const reading =
    '<section class="pn-reading" data-pn-reading><div data-pn-device></div><div data-pn-records></div></section>';
  await page.setContent(
    `<style>body{font:16px system-ui;margin:16px}${fs.readFileSync(path.join(root, 'assets/reading-center.css'), 'utf8')}</style><div class="pagenest-reading-grid"><aside>${controls}</aside><article class="pagenest-article"><div class="pagenest-article-body entry-content"><h2 id="stable">Current public article</h2><p>Current reading</p></div></article></div><button data-pn-open-reading hidden>My reading</button><dialog class="pn-reading-dialog"><div class="pn-reading-dialog-toolbar"><h2>My reading</h2><button data-pn-close-reading>Close</button></div>${reading}</dialog>`,
  );
  await page.evaluate(
    ({ text, accountText, syncText, options, localPreferences, actor, state }) => {
      window.syncParsed = 0;
      const fetch = window.fetch.bind(window);
      window.fetch = async (url, init = {}) => {
        const response = await fetch(
          url,
          options.ignoreAbort ? { ...init, signal: undefined } : init,
        );
        if (!String(url).includes('/account')) return response;
        const json = response.json.bind(response);
        response.json = async () => {
          const value = await json();
          queueMicrotask(() => {
            window.syncParsed++;
          });
          return value;
        };
        return response;
      };
      localStorage.clear();
      localStorage.setItem(
        'pagenest.reading.v1',
        JSON.stringify({
          enabled: true,
          items: [{ id: 9, time: Date.now() - 86400000, later: true, anchor: '' }],
        }),
      );
      localStorage.setItem(
        'pagenest-reader-preferences-v1',
        JSON.stringify({ version: 1, preferences: localPreferences }),
      );
      for (const [name, resume] of [
        ['automatic', options.resumeAutomatic],
        ['preferences', options.resumePreferences],
      ])
        if (resume)
          localStorage.setItem(
            'pagenest.reading.' + name + '.v1',
            JSON.stringify({ version: 1, actor, epoch: state[name].epoch }),
          );
      window.PageNestReading = {
        text,
        accountText,
        sync: { actor, text: syncText },
        nonce: 'synthetic',
        accountApi: location.origin + '/account',
        api: '/records/',
        resolve: location.origin + '/resolve',
        current: { id: 1, headings: ['stable'] },
        login: '/login',
      };
    },
    {
      text,
      accountText,
      syncText,
      options: {
        resumeAutomatic: options.resumeAutomatic,
        resumePreferences: options.resumePreferences,
        ignoreAbort: options.ignoreAbort,
      },
      localPreferences,
      actor,
      state,
    },
  );
  const loadTheme = async () => {
    if (options.theme !== false)
      await page.addScriptTag({
        content: realProtocol ? themeSource : '(' + minimalPreferenceProtocol.toString() + ')();',
      });
  };
  if (!options.themeAfter) await loadTheme();
  await page.addScriptTag({
    content: fs.readFileSync(path.join(root, 'assets/reading-center.js'), 'utf8'),
  });
  if (options.themeAfter) await loadTheme();
  const view = page.locator('[data-pn-sync-settings]');
  async function open() {
    await page.locator('[data-pn-open-reading]').click();
    await page.locator('[data-pn-tab=account]').click();
    if ((await view.locator('details').getAttribute('open')) === null)
      await view.locator('summary').click();
    await view.getByRole('button', { name: syncText.load, exact: true }).click();
    await expect(view.locator('[data-pn-sync-consent=automatic]')).toBeVisible();
  }
  async function choose(name) {
    await view.locator(`[data-pn-sync-consent=${name}]`).focus();
    await page.keyboard.press('Space');
  }
  return { calls, state, view, open, choose, flush: () => page.clock.fastForward(1000) };
}
for (const width of [1440, 390])
  test(`future current-article positions remain separate from device history at ${width}px`, async ({
    page,
  }) => {
    const f = await fixture(page, { width });
    expect(f.calls).toEqual([]);
    await f.open();
    await f.choose('automatic');
    await expect(f.view.locator('[data-pn-sync-consent=automatic]')).toBeChecked();
    expect(f.calls.filter((call) => call.action === 'automatic/progress')).toHaveLength(0);
    if (process.env.PAGENEST_SYNC_SCREEN_DIR)
      await page.screenshot({
        path: path.join(
          process.env.PAGENEST_SYNC_SCREEN_DIR,
          `reading-sync-${test.info().project.name}-${width}.png`,
        ),
      });
    await page.locator('[data-pn-close-reading]').click();
    await page.evaluate(() => dispatchEvent(new Event('scroll')));
    await f.flush();
    await f.flush();
    await expect
      .poll(() => f.calls.filter((call) => call.action === 'automatic/progress').length)
      .toBe(1);
    expect(f.calls.find((call) => call.action === 'automatic/progress').body.item).toEqual({
      id: 1,
      later: false,
      anchor: 'stable',
    });
    expect(f.calls.some((call) => call.body?.items)).toBe(false);
  });
test('first manual account consent leaves separately unloaded sync settings usable', async ({
  page,
}) => {
  const f = await fixture(page, { manualOff: true });
  await page.locator('[data-pn-open-reading]').click();
  await page.locator('[data-pn-tab=account]').click();
  await page.locator('[data-pn-account-load]').click();
  await page.locator('[data-pn-account-consent]').check();
  await page.getByRole('button', { name: accountText.enable, exact: true }).click();
  await expect(page.locator('[data-pn-account-load]')).toBeEnabled();
  expect(f.calls.filter((call) => call.action === 'sync')).toHaveLength(0);
  await f.view.locator('summary').click();
  const load = f.view.getByRole('button', { name: syncText.load, exact: true });
  await expect(load).toBeEnabled();
  await load.focus();
  await page.keyboard.press('Enter');
  await expect(f.view.locator('[data-pn-sync-consent=automatic]')).toBeVisible();
  expect(f.calls.filter((call) => call.action === 'sync')).toHaveLength(1);
});
test('remote preferences preserve local fields and user-only patches do not loop', async ({
  page,
}) => {
  const f = await fixture(page, { resumePreferences: true, themeAfter: true });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '20');
  expect(
    await page.evaluate(
      () => JSON.parse(localStorage.getItem('pagenest-reader-preferences-v1')).preferences,
    ),
  ).toEqual(localPreferences);
  await page.locator('[data-reader-preference=size][value="22"]').check();
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'preferences/update').length)
    .toBe(1);
  expect(f.calls.find((call) => call.action === 'preferences/update').body.values).toEqual({
    size: '22',
  });
  expect(
    await page.evaluate(
      () => JSON.parse(localStorage.getItem('pagenest-reader-preferences-v1')).preferences,
    ),
  ).toEqual({ ...localPreferences, size: '22' });
  await page.evaluate(() => document.dispatchEvent(new Event('pagenest-pc-auth-change')));
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'compact');
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '22');
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'preferences/update')).toHaveLength(1);
});
test('preferences without a compatible theme remain revocable and never claim application', async ({
  page,
}) => {
  const f = await fixture(page, { theme: false, cloudPreferences: true });
  await f.open();
  await f.choose('preferences');
  await expect(f.view.locator('[data-pn-sync-status=preferences]')).toHaveText(syncText.noTheme);
  await f.view.locator('[data-pn-sync-withdraw=preferences]').click();
  await expect(f.view.locator('[data-pn-sync-withdraw=preferences]')).toHaveCount(0);
  expect(f.state.preferences.enabled).toBe(false);
});
test('slow initial preferences GET retains the newer user field and reschedules its only patch', async ({
  page,
}) => {
  let release, started;
  const gate = new Promise((resolve) => {
    release = resolve;
  });
  const start = new Promise((resolve) => {
    started = resolve;
  });
  const f = await fixture(page, {
    resumePreferences: true,
    hook: async ({ action }) => {
      if (action === 'sync') {
        started();
        await gate;
      }
    },
  });
  await start;
  await page.locator('[data-reader-preference=size][value="22"]').check();
  await f.flush(); // Longer than debounce while load() is still busy.
  expect(f.calls.filter((call) => call.action === 'preferences/update')).toHaveLength(0);
  release();
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'wide');
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '22');
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'preferences/update').length)
    .toBe(1);
  expect(f.calls.find((call) => call.action === 'preferences/update').body.values).toEqual({
    size: '22',
  });
});
test('inflight preference save keeps newer input and makes revocation wait for its confirmed revision', async ({
  page,
}) => {
  let release,
    started,
    held = false;
  const gate = new Promise((resolve) => {
    release = resolve;
  });
  const start = new Promise((resolve) => {
    started = resolve;
  });
  const f = await fixture(page, {
    resumePreferences: true,
    hook: async ({ action }) => {
      if (action === 'preferences/update' && !held) {
        held = true;
        started();
        await gate;
      }
    },
  });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '20');
  await f.open();
  await page.locator('[data-pn-close-reading]').click();
  await page.locator('[data-reader-preference=size][value="22"]').check();
  await f.flush();
  await start;
  await expect(f.view.locator('[data-pn-sync-withdraw=preferences]')).toBeDisabled();
  await page.locator('[data-reader-preference=width][value=compact]').check();
  release();
  await expect(f.view.locator('[data-pn-sync-withdraw=preferences]')).toBeEnabled();
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'compact');
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'preferences/update').length)
    .toBe(2);
  expect(
    f.calls.filter((call) => call.action === 'preferences/update').map((call) => call.body.values),
  ).toEqual([{ size: '22' }, { width: 'compact' }]);
  await f.open();
  await f.view.locator('[data-pn-sync-withdraw=preferences]').click();
  await expect(f.view.locator('[data-pn-sync-withdraw=preferences]')).toHaveCount(0);
  expect(f.state.preferences.enabled).toBe(false);
});
test('automatic conflict pauses without retrying or rebasing old events while device memory continues', async ({
  page,
}) => {
  const f = await fixture(page, {
    resumeAutomatic: true,
    hook: async ({ action, route }) => {
      if (action !== 'automatic/progress') return false;
      await route.fulfill({ status: 409, json: { code: 'account_reading_conflict' } });
      return true;
    },
  });
  await expect(f.view.locator('[data-pn-sync-consent=automatic]')).toBeChecked();
  await f.flush();
  await expect
    .poll(() => page.evaluate(() => localStorage.getItem('pagenest.reading.automatic.v1')))
    .toBe(null);
  await page.evaluate(() => dispatchEvent(new Event('scroll')));
  await f.flush();
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'automatic/progress')).toHaveLength(1);
  const device = await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1')));
  expect(device.enabled).toBe(true);
  expect(device.items.some((item) => item.id === 1)).toBe(true);
});
for (const event of ['hidden', 'pagehide', 'auth'])
  test(`${event} restores device preferences and rejects a late remote GET even when fetch ignores abort`, async ({
    page,
  }) => {
    let release, started;
    const gate = new Promise((resolve) => {
      release = resolve;
    });
    const start = new Promise((resolve) => {
      started = resolve;
    });
    const f = await fixture(page, {
      resumePreferences: true,
      ignoreAbort: true,
      hook: async ({ action }) => {
        if (action === 'sync') {
          started();
          await gate;
        }
      },
    });
    await start;
    await page.evaluate((event) => {
      if (event === 'hidden') {
        Object.defineProperty(document, 'hidden', { configurable: true, value: true });
        document.dispatchEvent(new Event('visibilitychange'));
      } else if (event === 'auth') document.dispatchEvent(new Event('pagenest-pc-auth-change'));
      else dispatchEvent(new Event('pagehide'));
    }, event);
    release();
    await expect.poll(() => page.evaluate(() => window.syncParsed)).toBe(1);
    await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'compact');
    await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '18');
    await f.flush();
    expect(f.calls.filter((call) => call.action === 'preferences/update')).toHaveLength(0);
  });
test('removing a local account binding restores local preferences and does not reuse another actor', async ({
  page,
}) => {
  const f = await fixture(page, { resumePreferences: true });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'wide');
  await page.evaluate(() => {
    localStorage.setItem(
      'pagenest.reading.preferences.v1',
      JSON.stringify({ version: 1, actor: 'b'.repeat(64), epoch: '2'.repeat(32) }),
    );
    dispatchEvent(new StorageEvent('storage', { key: 'pagenest.reading.preferences.v1' }));
  });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'compact');
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.preferences.v1'))),
  ).toEqual({
    version: 1,
    actor: 'b'.repeat(64),
    epoch: '2'.repeat(32),
  });
  await page.locator('[data-reader-preference=size][value="22"]').check();
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'preferences/update')).toHaveLength(0);
});
test('an old tab pauses without deleting a newer explicit binding from another tab', async ({
  page,
}) => {
  const f = await fixture(page, { resumePreferences: true });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'wide');
  const replacement = { version: 1, actor, epoch: '8'.repeat(32) };
  await page.evaluate((value) => {
    localStorage.setItem('pagenest.reading.preferences.v1', JSON.stringify(value));
    dispatchEvent(new StorageEvent('storage', { key: 'pagenest.reading.preferences.v1' }));
  }, replacement);
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-width', 'compact');
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.preferences.v1'))),
  ).toEqual(replacement);
  await page.locator('[data-reader-preference=size][value="22"]').check();
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'preferences/update')).toHaveLength(0);
});
test('storage failure stops a queued automatic upload before it reaches the server', async ({
  page,
}) => {
  const f = await fixture(page, { resumeAutomatic: true });
  await expect(f.view.locator('[data-pn-sync-consent=automatic]')).toBeChecked();
  await page.evaluate(() => {
    Storage.prototype.getItem = () => {
      throw Error('synthetic storage failure');
    };
  });
  await f.flush();
  await expect(f.view.locator('[data-pn-sync-status=automatic]')).toHaveText(
    syncText.localRequired,
  );
  expect(f.calls.filter((call) => call.action === 'automatic/progress')).toHaveLength(0);
});
test('reopening the account panel continues to hold automatic positions until it closes', async ({
  page,
}) => {
  const f = await fixture(page);
  await f.open();
  await f.choose('automatic');
  await expect(f.view.locator('[data-pn-sync-consent=automatic]')).toBeChecked();
  const closePanel = async () => {
    // Native close is asynchronous; finish its handlers before advancing timer callbacks.
    await page.evaluate(() => {
      window.testDialogClosed = new Promise((resolve) =>
        document
          .querySelector('.pn-reading-dialog')
          .addEventListener('close', () => resolve(true), { once: true }),
      );
    });
    await page.locator('[data-pn-close-reading]').click();
    await page.evaluate(() => window.testDialogClosed);
  };
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'automatic/progress')).toHaveLength(0);
  await closePanel();
  await page.evaluate(() => dispatchEvent(new Event('scroll')));
  await f.flush();
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'automatic/progress').length)
    .toBe(1);
  await expect(f.view.locator('[data-pn-sync-status=automatic]')).toHaveText(syncText.saved);
  await page.locator('[data-pn-open-reading]').click();
  await page.evaluate(() => dispatchEvent(new Event('scroll')));
  await f.flush();
  await f.flush();
  expect(f.calls.filter((call) => call.action === 'automatic/progress')).toHaveLength(1);
  await closePanel();
  await page.evaluate(() => dispatchEvent(new Event('scroll')));
  await f.flush();
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'automatic/progress').length)
    .toBe(2);
});
test('resetting reading defaults updates preferences instead of withdrawing consent', async ({
  page,
}) => {
  const f = await fixture(page, { resumePreferences: true, stub: true });
  await expect(page.locator('.pagenest-article')).toHaveAttribute('data-reader-size', '20');
  await page.locator('[data-reader-reset]').click();
  await f.flush();
  await expect
    .poll(() => f.calls.filter((call) => call.action === 'preferences/update').length)
    .toBe(1);
  expect(f.calls.find((call) => call.action === 'preferences/update').body.values).toEqual({
    size: 'default',
    width: 'default',
    palette: 'default',
  });
  expect(f.state.preferences.enabled).toBe(true);
  expect(f.calls.some((call) => call.action === 'preferences/withdraw')).toBe(false);
});
