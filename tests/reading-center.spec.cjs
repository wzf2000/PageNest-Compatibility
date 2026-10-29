const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const readingAssets = process.env.PAGENEST_TEST_READING_ASSETS || path.join(root, 'assets');
test('no-script header controls stay hidden while original account and search remain available', async ({
  browser,
}) => {
  test.skip(!process.env.PAGENEST_TEST_THEME_ROOT, 'requires theme header styles');
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.route('**/*', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }),
  );
  await page.goto('http://controls.fixture.test/');
  const styles = [
    path.join(root, 'assets/notifications.css'),
    path.join(root, 'assets/reading-center.css'),
    path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/presentation.css'),
  ]
    .map((asset) => fs.readFileSync(asset, 'utf8'))
    .join('\n');
  await page.setContent(
    `<style>${styles}</style><body class="pagenest-site"><header><div class="pagenest-header-actions"><button class="pn-header-notifications" data-pn-open-notifications hidden>Notifications</button><button class="pagenest-cycle-mode" data-pagenest-cycle-mode hidden>Appearance</button></div><details class="pagenest-account-menu" open><summary>My account</summary><a href="/login">Log in</a><button data-pn-open-reading hidden>My reading</button></details><details class="pagenest-header-search"><summary>Search</summary><form><input type="search"></form></details></header></body>`,
  );
  await expect(page.locator('[data-pn-open-notifications]')).toBeHidden();
  await expect(page.locator('[data-pn-open-reading]')).toBeHidden();
  await expect(page.locator('[data-pagenest-cycle-mode]')).toBeHidden();
  await expect(page.getByRole('link', { name: 'Log in', exact: true })).toBeVisible();
  await page.locator('.pagenest-header-search > summary').click();
  await expect(page.getByRole('searchbox')).toBeVisible();
  await context.close();
});
test.beforeEach(async ({ page }) => {
  await page.route('**/*', (route) => {
    const host = new URL(route.request().url()).hostname;
    return ['127.0.0.1', 'localhost'].includes(host) ? route.continue() : route.abort();
  });
});
const text = {
  reading: 'My reading',
  notes: 'Comments and notes',
  continue: 'Continue reading',
  refresh: 'Refresh records',
  devicePrompt: 'Enable the option above to keep reading here.',
  deviceLoadError: 'Could not load the device reading list. Try again.',
  retry: 'Retry',
  manage: 'Manage device records',
  deviceEnabled: 'Device memory enabled.',
  deviceDisabled: 'Device memory disabled and records cleared.',
  deviceCleared: 'Device records cleared.',
  filter: 'Filter records',
  backup: 'Backup and import',
  privateIntro: 'Your records load only when you choose to view them.',
  device: 'On this device',
  consent: 'Remember my reading on this device',
  deviceHelp: 'Optional browser storage; article IDs are checked with the site.',
  deviceSummary: 'Stored on this device only. No device sync.',
  help: 'How this works',
  unavailable: 'Storage unavailable',
  clear: 'Clear device records',
  recent: 'Recent reading',
  later: 'Read later',
  remove: 'Remove',
  empty: 'No records to show.',
  records: 'My comments and private notes',
  privateHelp: 'Private text stays in page memory.',
  login: 'Sign in',
  load: 'Load my records',
  more: 'Load more',
  all: 'All records',
  public: 'Public comments',
  author_only: 'Private notes',
  needs_review: 'Reference needs review',
  q: 'Search my text and references',
  search: 'Search records',
  loading: 'Loading…',
  error: 'Could not load records.',
  edit: 'Edit text',
  save: 'Save text',
  cancel: 'Cancel',
  delete: 'Delete',
  confirmDelete: 'Delete?',
  conflict: 'The record changed. Reload and compare before saving.',
  reviewHelp: 'The original paragraph changed. Review the preserved reference.',
  relink: 'Choose a replacement paragraph',
  replace: 'Replace saved reference',
  choose: 'Choose a paragraph',
  export: 'Export my records as JSON',
  import: 'Import a JSON backup',
  importHelp: 'Imported records remain private.',
  imported: 'Import processed.',
  savedLater: 'Saved for later.',
  enableFirst: 'Enable device memory first.',
};
async function fixture(page, width, extra = '') {
  await page.setViewportSize({ width, height: 900 });
  await page.route('**/reading-center-origin', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }),
  );
  await page.goto('/reading-center-origin');
  await page.setContent(
    `<style>body{font:16px system-ui;margin:24px auto;padding:0 16px;max-width:800px}${fs.readFileSync(path.join(readingAssets, 'reading-center.css'), 'utf8')}</style><article class="entry-content"><h1>Reading and learning</h1><h2 id="stable">A stable author heading</h2><button data-pn-save-later hidden>Read later on this device</button><span data-pn-later-status></span></article><section class="pn-reading" data-pn-reading><h2>My reading</h2><div data-pn-device></div><hr><div data-pn-records></div></section>${extra}`,
  );
  await page.evaluate((text) => {
    window.PageNestReading = {
      text,
      api: location.origin + '/records/',
      resolve: location.origin + '/resolve',
      nonce: 'synthetic',
      login: location.origin + '/login',
      current: { id: 1, headings: ['stable'] },
    };
  }, text);
  await page.route('**/resolve', (route) =>
    route.fulfill({
      json: {
        items: [
          { id: 1, title: 'Public article', url: locationUrl('/article/1'), headings: ['stable'] },
        ],
      },
    }),
  );
  await page.addScriptTag({
    content: fs.readFileSync(path.join(readingAssets, 'reading-center.js'), 'utf8'),
  });
}
function locationUrl(value) {
  return (process.env.PAGENEST_TEST_ORIGIN || 'http://127.0.0.1:18780') + value;
}
const record = {
  id: 11,
  post_id: 1,
  post_title: 'Learning safely',
  location_url: locationUrl('/article/1#block'),
  post_url: locationUrl('/article/1'),
  text: 'Private synthetic note',
  quote: 'Saved reference',
  visibility: 'author_only',
  association: 'needs_review',
  version: 'original',
};
for (const width of [1440, 390]) {
  test(`device opt-in, paging and version conflict at ${width}px`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    let edited;
    await page.route('**/records/my-records?*', (route) => {
      const next = new URL(route.request().url()).searchParams.get('cursor');
      return route.fulfill({
        json: { items: next ? [record] : [], next_cursor: next ? null : 70 },
      });
    });
    await page.route('**/records/notes/11', (route) => {
      edited = route.request().postDataJSON();
      return route.fulfill({ status: 409, json: { code: 'version_conflict' } });
    });
    await fixture(page, width);
    expect(await page.evaluate(() => localStorage.getItem('pagenest.reading.v1'))).toBeNull();
    await page.getByLabel(text.consent).check();
    await page.getByRole('button', { name: 'Read later on this device', exact: true }).click();
    await expect(page.locator('[data-pn-device]')).toContainText('Public article');
    const stored = await page.evaluate(() =>
      JSON.parse(localStorage.getItem('pagenest.reading.v1')),
    );
    expect(stored.items[0]).toEqual({
      id: 1,
      time: expect.any(Number),
      later: true,
      anchor: 'stable',
    });
    await page.getByRole('tab', { name: text.notes, exact: true }).click();
    await page.getByRole('button', { name: text.load, exact: true }).click();
    await expect(page.getByRole('button', { name: text.more, exact: true })).toBeVisible();
    await page.getByRole('button', { name: text.more, exact: true }).click();
    await expect(page.locator('.pn-reading-note-text')).toHaveText(record.text);
    await page.getByRole('button', { name: text.edit, exact: true }).click();
    await page.getByLabel(text.edit, { exact: true }).fill('Unsaved private edit');
    await page.getByRole('button', { name: text.save, exact: true }).click();
    await expect(page.getByLabel(text.edit, { exact: true })).toHaveValue('Unsaved private edit');
    expect(edited).toEqual({ text: 'Unsaved private edit', version: 'original' });
    await page.screenshot({ path: `.runtime/reading-center-${width}.png`, fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(
      false,
    );
    expect(await page.evaluate(() => localStorage.getItem('pagenest.reading.v1'))).not.toContain(
      'Private',
    );
    await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
    await expect(page.locator('.pn-reading-note-text')).toHaveCount(0);
    await expect(page.locator('textarea')).toHaveCount(0);
    expect(errors).toEqual([]);
  });
}
test('late private response cannot restore a page after leaving', async ({ page }) => {
  let release;
  const wait = new Promise((r) => {
    release = r;
  });
  await page.route('**/records/my-records?*', async (route) => {
    await wait;
    await route.fulfill({ json: { items: [record], next_cursor: null } }).catch(() => {});
  });
  await fixture(page, 390);
  await page.getByRole('tab', { name: text.notes, exact: true }).click();
  await page.getByRole('button', { name: text.load, exact: true }).click();
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  release();
  await page.waitForTimeout(100);
  await expect(page.locator('.pn-reading-note-text')).toHaveCount(0);
});

test('plain permalink REST routes keep query arguments separate', async ({ page }) => {
  await fixture(page, 390);
  await page.evaluate(() => {
    window.PageNestReading.api = location.origin + '/?rest_route=/records/';
  });
  let path, search;
  await page.route('**/?rest_route=*', (route) => {
    const url = new URL(route.request().url());
    path = url.searchParams.get('rest_route');
    search = url.searchParams.get('q');
    return route.fulfill({ json: { items: [record], next_cursor: null } });
  });
  await page.getByRole('tab', { name: text.notes, exact: true }).click();
  await page.locator('.pn-reading-record-filters > summary').click();
  await page.getByLabel(text.q).fill('query with spaces');
  await page.getByRole('button', { name: text.search, exact: true }).click();
  await expect(page.locator('.pn-reading-note-text')).toHaveText(record.text);
  expect(path).toBe('/records/my-records');
  expect(search).toBe('query with spaces');
});

test('authorization loss clears sensitive search and every center', async ({ page }) => {
  await page.route('**/records/my-records?*', (route) =>
    route.fulfill({ json: { items: [record], next_cursor: null } }),
  );
  await page.route('**/records/my-records/validate', (route) =>
    route.fulfill({ status: 403, json: { code: 'denied' } }),
  );
  await fixture(
    page,
    390,
    '<section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section>',
  );
  for (const tab of await page.getByRole('tab', { name: text.notes, exact: true }).all())
    await tab.click();
  for (const summary of await page.locator('.pn-reading-record-filters > summary').all())
    await summary.click();
  const searches = page.getByLabel(text.q);
  await searches.nth(0).fill('Sensitive medical search');
  await searches.nth(1).fill('Another private search');
  for (const root of await page.locator('.pn-reading').all()) {
    await root.getByRole('button', { name: text.load, exact: true }).click();
  }
  await expect(page.locator('.pn-reading-note-text')).toHaveCount(2);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('.pn-reading-note-text')).toHaveCount(0);
  await expect(searches.nth(0)).toHaveValue('');
  await expect(searches.nth(1)).toHaveValue('');
});
test('short reading dialog keeps keyboard focus below its fixed heading', async ({
  page,
}, testInfo) => {
  await page.route('**/records/my-records/validate', (route) =>
    route.fulfill({ json: { valid: true } }),
  );
  await page.route('**/records/my-records?*', (route) =>
    route.fulfill({
      json: {
        items: Array.from({ length: 12 }, (_, index) => ({
          ...record,
          id: index + 11,
          text:
            `Synthetic private record ${index + 1}. ` +
            'Long reading notes for keyboard navigation. '.repeat(4),
        })),
        next_cursor: null,
      },
    }),
  );
  await fixture(
    page,
    390,
    '<button data-pn-open-reading hidden>Open My reading</button><dialog class="pn-reading-dialog" aria-labelledby="pn-reading-dialog-title"><div class="pn-reading-dialog-toolbar"><h2 id="pn-reading-dialog-title">My reading</h2><button data-pn-close-reading>Close</button></div><section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section></dialog>',
  );
  await page.setViewportSize({ width: 390, height: 520 });
  if (process.env.PAGENEST_TEST_THEME_ROOT) {
    await page.addStyleTag({
      path: path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/presentation.css'),
    });
    await page.evaluate(() => (document.documentElement.dataset.pagenestColorScheme = 'dark'));
  }
  await page.getByRole('button', { name: 'Open My reading', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('tab', { name: text.notes, exact: true }).click();
  await dialog.getByRole('button', { name: text.load, exact: true }).click();
  await expect(dialog.locator('.pn-reading-record')).toHaveCount(12);
  const lastAction = dialog.locator('.pn-reading-record').last().locator('button:visible').last();
  await lastAction.focus();
  await expect(lastAction).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
  await expect(dialog.locator('.pn-reading-record')).toHaveCount(12);
  expect(
    await dialog
      .locator('.pn-reading-record')
      .last()
      .evaluate((node) => node.contains(document.activeElement)),
  ).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('reading-short-keyboard.png') });
  for (let index = 0; index < 12; index++) {
    await page.keyboard.press('Shift+Tab');
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
    const position = await dialog.evaluate((node) => {
      const focused = document.activeElement;
      if (
        focused === node ||
        !node.contains(focused) ||
        focused.closest('.pn-reading-dialog-toolbar')
      )
        return null;
      const box = focused.getBoundingClientRect();
      return {
        top: box.top,
        bottom: box.bottom,
        header: node.querySelector('.pn-reading-dialog-toolbar').getBoundingClientRect().bottom,
        limit: node.getBoundingClientRect().bottom,
      };
    });
    if (position) {
      expect(position.top, `focus at reverse step ${index}`).toBeGreaterThanOrEqual(
        position.header - 1,
      );
      if (position.bottom - position.top <= position.limit - position.header) {
        expect(position.bottom).toBeLessThanOrEqual(position.limit + 1);
      }
    }
  }
});

for (const width of [1440, 390]) {
  test(`account reading returns to a visible header entry after outside menu closure at ${width}px`, async ({
    page,
  }) => {
    test.skip(!process.env.PAGENEST_TEST_THEME_ROOT, 'requires the companion theme navigation');
    await fixture(
      page,
      width,
      '<header><button class="pagenest-menu-toggle" aria-expanded="false">Menu</button><nav id="pagenest-nav"><details class="pagenest-account-menu pagenest-dropdown"><summary>My account</summary><div><button data-pn-open-reading hidden>Open My reading</button></div></details></nav></header><dialog class="pn-reading-dialog"><div class="pn-reading-dialog-toolbar"><h2>My reading</h2><button data-pn-close-reading>Close</button></div><section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section></dialog>',
    );
    await page.addStyleTag({
      content:
        '@media(max-width:1023px){#pagenest-nav{display:none}#pagenest-nav.pagenest-nav-open{display:block}}@media(min-width:1024px){.pagenest-menu-toggle{display:none}}',
    });
    await page.addScriptTag({
      path: path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/navigation.js'),
    });
    const menu = page.locator('.pagenest-menu-toggle');
    if (width < 1024) await menu.click();
    const account = page.locator('.pagenest-account-menu');
    await account.locator('summary').click();
    await page.getByRole('button', { name: 'Open My reading', exact: true }).click();
    await page.getByRole('dialog').getByRole('tab').first().click();
    await page.keyboard.press('Escape');
    const target = width < 1024 ? menu : account.locator('summary');
    await expect(target).toBeVisible();
    await expect(target).toBeFocused();
  });
}

test('mobile dialog keeps close reachable and returns focus', async ({ page }) => {
  await fixture(
    page,
    390,
    '<button data-pn-open-reading hidden>Open My reading</button><dialog class="pn-reading-dialog" aria-labelledby="pn-reading-dialog-title"><div class="pn-reading-dialog-toolbar"><h2 id="pn-reading-dialog-title">My reading</h2><button data-pn-close-reading>Close</button></div><section class="pn-reading"><div data-pn-device></div><div data-pn-records></div><div style="height:1800px"></div></section></dialog>',
  );
  const opener = page.getByRole('button', { name: 'Open My reading', exact: true });
  await opener.click();
  const dialog = page.getByRole('dialog', { name: 'My reading', exact: true });
  await expect(dialog).toBeVisible();
  await dialog.evaluate((node) => {
    node.scrollTop = node.scrollHeight;
  });
  const box = await page.getByRole('button', { name: 'Close', exact: true }).boundingBox();
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.y + box.height).toBeLessThanOrEqual(900);
  await page.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(opener).toBeFocused();
  await opener.click();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(opener).toBeFocused();
});

test('disabling the record service clears sensitive search in all centers', async ({ page }) => {
  await page.route('**/records/my-records?*', (route) =>
    route.fulfill({ status: 503, json: { code: 'comments_disabled' } }),
  );
  await fixture(
    page,
    390,
    '<section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section>',
  );
  for (const tab of await page.getByRole('tab', { name: text.notes, exact: true }).all())
    await tab.click();
  for (const summary of await page.locator('.pn-reading-record-filters > summary').all())
    await summary.click();
  const searches = page.getByLabel(text.q);
  await searches.nth(0).fill('Sensitive search');
  await searches.nth(1).fill('Another sensitive search');
  await page.getByRole('button', { name: text.search, exact: true }).nth(0).click();
  await expect(searches.nth(0)).toHaveValue('');
  await expect(searches.nth(1)).toHaveValue('');
});

test('reading explanations stay collapsed beside concise privacy hints', async ({ page }) => {
  await fixture(page, 390);
  await expect(page.getByText(text.deviceSummary, { exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: text.records, exact: true })).not.toBeVisible();
  await expect(page.getByText(text.deviceHelp, { exact: true })).not.toBeVisible();
  await expect(page.getByText(text.privateHelp, { exact: true })).not.toBeVisible();
  const help = page.locator('.pn-reading-help');
  await expect(help).toHaveCount(2);
  await help.first().locator('summary').click();
  await expect(page.getByText(text.deviceHelp, { exact: true })).toBeVisible();
  await expect(page.getByLabel(text.consent)).not.toBeChecked();
  expect(await page.evaluate(() => localStorage.getItem('pagenest.reading.v1'))).toBeNull();
});

test('tabs request private records only explicitly and discard responses after switching away', async ({
  page,
}) => {
  let requests = 0,
    release;
  const pending = new Promise((resolve) => {
    release = resolve;
  });
  await page.route('**/records/my-records?*', async (route) => {
    requests++;
    await pending;
    await route.fulfill({ json: { items: [record], next_cursor: null } }).catch(() => {});
  });
  await fixture(page, 390);
  const recent = page.getByRole('tab', { name: text.recent, exact: true });
  await recent.focus();
  await page.keyboard.press('End');
  await expect(page.getByRole('tab', { name: text.notes })).toBeFocused();
  await expect(page.getByRole('button', { name: text.load, exact: true })).toBeVisible();
  expect(requests).toBe(0);
  await page.getByRole('button', { name: text.load, exact: true }).click();
  await expect.poll(() => requests).toBe(1);
  await recent.click();
  release();
  await expect(page.locator('.pn-reading-note-text')).toHaveCount(0);
  await page.getByRole('tab', { name: text.notes }).click();
  await expect(page.getByRole('button', { name: text.load, exact: true })).toBeVisible();
  expect(requests).toBe(1);
});

test('device controls retain focus and provide consent and removal feedback', async ({ page }) => {
  await fixture(page, 390);
  await page.getByLabel(text.consent).check();
  await expect(page.getByLabel(text.consent)).toBeFocused();
  await expect(page.getByRole('status').filter({ hasText: text.deviceEnabled })).toBeVisible();
  await expect(page.locator('.pn-reading-device-items a')).toHaveCount(1);
  await page.getByRole('button', { name: 'Remove: Public article', exact: true }).click();
  await expect(page.getByLabel(text.consent)).toBeFocused();
  await expect(page.locator('.pn-reading-device-items a')).toHaveCount(0);
  await page.getByLabel(text.consent).uncheck();
  await expect(page.getByLabel(text.consent)).toBeFocused();
  await expect(page.getByRole('status').filter({ hasText: text.deviceDisabled })).toBeVisible();
  expect(
    await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1'))),
  ).toEqual({ enabled: false, items: [] });
});

test('damaged dates are removed and multiple centers initialize with one public resolve', async ({
  page,
}) => {
  await page.addInitScript(() =>
    localStorage.setItem(
      'pagenest.reading.v1',
      JSON.stringify({ enabled: true, items: [{ id: 99, time: 1e100, later: true, anchor: '' }] }),
    ),
  );
  let requests = 0;
  page.on('request', (request) => {
    if (request.url().endsWith('/resolve')) requests++;
  });
  await fixture(
    page,
    390,
    '<section class="pn-reading"><div data-pn-device></div><div data-pn-records></div></section>',
  );
  await expect(page.locator('.pn-reading-device-items a')).toHaveCount(2);
  expect(requests).toBe(1);
  const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1')));
  expect(stored.items.map((item) => item.id)).toEqual([1]);
  const ids = await page
    .locator('[role=tab], [role=tabpanel]')
    .evaluateAll((nodes) => nodes.map((node) => node.id));
  expect(new Set(ids).size).toBe(ids.length);
});

test('card-local failures preserve edits and restore the matching action on cancel', async ({
  page,
}) => {
  await page.route('**/records/my-records?*', (route) =>
    route.fulfill({
      json: {
        items: Array.from({ length: 10 }, (_, index) => ({ ...record, id: index + 1 })),
        next_cursor: null,
      },
    }),
  );
  await page.route('**/records/notes/10', (route) =>
    route.fulfill({ status: 409, json: { code: 'version_conflict' } }),
  );
  await page.route('**/records/blocks?*', (route) =>
    route.fulfill({ status: 500, json: { code: 'unavailable' } }),
  );
  await fixture(page, 390);
  await page.getByRole('tab', { name: text.notes, exact: true }).click();
  await page.getByRole('button', { name: text.load, exact: true }).click();
  const card = page.locator('.pn-reading-record').last();
  await card.getByRole('button', { name: text.edit, exact: true }).click();
  await card.getByRole('textbox').fill('Keep this private edit');
  await card.getByRole('button', { name: text.save, exact: true }).click();
  const message = card.getByRole('status').filter({ hasText: text.conflict });
  await expect(message).toBeInViewport();
  await expect(card.getByRole('textbox')).toHaveValue('Keep this private edit');
  await expect(card.getByRole('textbox')).toBeFocused();
  await card.getByRole('button', { name: text.cancel, exact: true }).click();
  await expect(card.getByRole('button', { name: text.edit, exact: true })).toBeFocused();
  await card.getByRole('button', { name: text.relink, exact: true }).click();
  await expect(card.getByRole('status').filter({ hasText: text.error })).toBeVisible();
  await card.getByRole('button', { name: text.cancel, exact: true }).click();
  await expect(card.getByRole('button', { name: text.relink, exact: true })).toBeFocused();
  page.once('dialog', (dialog) => dialog.accept());
  await card.getByRole('button', { name: text.delete, exact: true }).click();
  await expect(card.getByRole('status').filter({ hasText: text.conflict })).toBeInViewport();
  await expect(card.getByRole('button', { name: text.delete, exact: true })).toBeFocused();
});

for (const scenario of ['cancel', 'leave', 'move focus']) {
  test(`pending private edit cannot undo ${scenario}`, async ({ page }) => {
    let release;
    let started = false;
    let reads = 0;
    const pending = new Promise((resolve) => {
      release = resolve;
    });
    await page.route('**/records/my-records?*', (route) => {
      reads++;
      return route.fulfill({ json: { items: [record], next_cursor: null } });
    });
    await page.route('**/records/notes/11', async (route) => {
      started = true;
      await pending;
      await route
        .fulfill({
          status: scenario === 'cancel' ? 200 : 409,
          json: scenario === 'cancel' ? {} : { code: 'version_conflict' },
        })
        .catch(() => {});
    });
    await fixture(page, 390);
    await page.getByRole('tab', { name: text.notes, exact: true }).click();
    await page.getByRole('button', { name: text.load, exact: true }).click();
    const card = page.locator('.pn-reading-record');
    await card.getByRole('button', { name: text.edit, exact: true }).click();
    await card.getByRole('textbox').fill('Pending private edit');
    await card.getByRole('button', { name: text.save, exact: true }).click();
    await expect.poll(() => started).toBe(true);
    if (scenario === 'cancel')
      await card.getByRole('button', { name: text.cancel, exact: true }).click();
    else if (scenario === 'leave')
      await page.getByRole('tab', { name: text.recent, exact: true }).click();
    else await page.getByRole('button', { name: text.refresh, exact: true }).focus();
    release();
    if (scenario === 'move focus') {
      await expect(card.getByRole('status').filter({ hasText: text.conflict })).toBeVisible();
      await expect(page.getByRole('button', { name: text.refresh, exact: true })).toBeFocused();
      await expect(card.getByRole('textbox')).toHaveValue('Pending private edit');
    } else {
      await page.waitForTimeout(100);
      await expect(page.getByRole('textbox', { name: text.edit, exact: true })).toHaveCount(0);
      expect(reads).toBe(1);
      if (scenario === 'cancel')
        await expect(card.getByRole('button', { name: text.edit, exact: true })).toBeFocused();
      else {
        await expect(card).toHaveCount(0);
        await expect(page.getByRole('tab', { name: text.recent, exact: true })).toBeFocused();
      }
    }
  });
}

test('a delayed delete failure leaves a newly focused editor alone', async ({ page }) => {
  let release;
  let started = false;
  const pending = new Promise((resolve) => {
    release = resolve;
  });
  await page.route('**/records/my-records?*', (route) =>
    route.fulfill({ json: { items: [record], next_cursor: null } }),
  );
  await page.route('**/records/notes/11', async (route) => {
    started = true;
    await pending;
    await route.fulfill({ status: 409, json: { code: 'version_conflict' } });
  });
  await fixture(page, 390);
  await page.getByRole('tab', { name: text.notes, exact: true }).click();
  await page.getByRole('button', { name: text.load, exact: true }).click();
  const card = page.locator('.pn-reading-record');
  page.once('dialog', (dialog) => dialog.accept());
  await card.getByRole('button', { name: text.delete, exact: true }).click();
  await expect.poll(() => started).toBe(true);
  await card.getByRole('button', { name: text.edit, exact: true }).click();
  release();
  await expect(card.getByRole('status').filter({ hasText: text.conflict })).toBeVisible();
  await expect(card.getByRole('textbox')).toBeFocused();
});

test('device list retries public IDs without requesting private records or losing keyboard focus', async ({
  page,
}) => {
  let requests = 0;
  let privateRequests = 0;
  const ids = [];
  await fixture(page, 390);
  await page.route('**/records/**', (route) => {
    privateRequests++;
    return route.fulfill({ json: { items: [], next_cursor: null } });
  });
  await page.route('**/resolve', (route) => {
    requests++;
    ids.push(route.request().postDataJSON().ids);
    return requests < 3
      ? route.fulfill({ status: 503, json: {} })
      : route.fulfill({
          json: {
            items: [
              {
                id: 1,
                title: 'Public article',
                url: locationUrl('/article/1'),
                headings: ['stable'],
              },
            ],
          },
        });
  });
  await page.getByLabel(text.consent).check();
  const view = page.locator('[data-pn-device]');
  await expect(view.getByRole('status').filter({ hasText: text.deviceLoadError })).toBeVisible();
  expect(
    await page.evaluate(() =>
      JSON.parse(localStorage.getItem('pagenest.reading.v1')).items.map((item) => item.id),
    ),
  ).toEqual([1]);
  await view.getByRole('button', { name: text.retry, exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => requests).toBe(2);
  await expect(view.getByRole('button', { name: text.retry, exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(view.getByRole('link', { name: 'Public article', exact: true })).toBeFocused();
  expect(ids).toEqual([[1], [1], [1]]);
  expect(privateRequests).toBe(0);
});

for (const action of ['disable', 'move focus']) {
  test(`a pending device retry respects ${action}`, async ({ page }) => {
    let requests = 0;
    let release;
    const pending = new Promise((resolve) => {
      release = resolve;
    });
    await fixture(page, 390);
    await page.route('**/resolve', async (route) => {
      if (++requests === 1) return route.fulfill({ status: 503, json: {} });
      await pending;
      await route.fulfill({
        json: {
          items: [
            {
              id: 1,
              title: 'Public article',
              url: locationUrl('/article/1'),
              headings: ['stable'],
            },
          ],
        },
      });
    });
    await page.getByLabel(text.consent).check();
    await page.getByRole('button', { name: text.retry, exact: true }).click();
    await expect.poll(() => requests).toBe(2);
    if (action === 'disable') await page.getByLabel(text.consent).uncheck();
    else await page.getByRole('tab', { name: text.recent, exact: true }).focus();
    release();
    if (action === 'disable') {
      await page.waitForTimeout(100);
      await expect(page.getByLabel(text.consent)).not.toBeChecked();
      await expect(page.getByRole('link', { name: 'Public article', exact: true })).toHaveCount(0);
      expect(
        await page.evaluate(() => JSON.parse(localStorage.getItem('pagenest.reading.v1'))),
      ).toEqual({ enabled: false, items: [] });
    } else {
      await expect(page.getByRole('link', { name: 'Public article', exact: true })).toBeVisible();
      await expect(page.getByRole('tab', { name: text.recent, exact: true })).toBeFocused();
    }
  });
}
test('reading shortcode keeps dialog list spacing and muted hints under real article CSS', async ({
  page,
}) => {
  test.skip(!process.env.PAGENEST_TEST_THEME_ROOT, 'requires the actual theme content stylesheet');
  await page.route('**/reading-style-origin', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body></body></html>' }),
  );
  await page.goto('/reading-style-origin');
  const panel =
    '<section class="pn-reading" data-pn-reading><p class="pn-reading-hint">Manual sync and recent records</p><ul class="pn-reading-device-items"><li><div class="pn-reading-item-body"><a href="/article/1">Public article</a><time>Oct 29</time></div></li></ul><details class="pn-reading-help" open><summary>Help</summary><p>Reading remains optional.</p></details></section>';
  await page.setContent(
    `<style>body{font:16px system-ui}${fs.readFileSync(path.join(readingAssets, 'reading-center.css'), 'utf8')}</style><article class="pagenest-article-body" style="--pagenest-reading-size:16px;--pagenest-reading-line-height:1.6">${panel}</article><dialog class="pn-reading-dialog" open>${panel}</dialog>`,
  );
  await page.addStyleTag({
    path: path.join(process.env.PAGENEST_TEST_THEME_ROOT, 'assets/content.css'),
  });
  const measure = (scope) =>
    scope.evaluate((root) => {
      const ul = getComputedStyle(root.querySelector('ul'));
      const li = getComputedStyle(root.querySelector('li'));
      const hint = getComputedStyle(root.querySelector('.pn-reading-hint'));
      const help = getComputedStyle(root.querySelector('.pn-reading-help p'));
      return {
        indent: ul.paddingInlineStart,
        listMargin: ul.marginBottom,
        rowMargin: li.marginTop,
        rowHeight: li.lineHeight,
        hintSize: hint.fontSize,
        hintSpace: hint.marginBottom,
        hintColor: hint.color,
        helpSpace: help.marginBottom,
      };
    });
  const actual = await measure(page.locator('article .pn-reading'));
  expect(actual.indent).toBe('0px');
  expect(actual.hintSize).toBe('14px');
  expect(actual.hintSpace).toBe('12px');
  expect(actual).toEqual(await measure(page.locator('dialog .pn-reading')));
});
