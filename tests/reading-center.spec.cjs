const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const text = {
  device: 'On this device',
  consent: 'Remember public articles and reading positions on this device',
  deviceHelp: 'Optional browser storage; article IDs are checked with the site.',
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
async function fixture(page, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto('/standalone.html');
  await page.setContent(
    `<style>body{font:16px system-ui;margin:24px auto;padding:0 16px;max-width:800px}${fs.readFileSync(path.join(root, 'assets/reading-center.css'), 'utf8')}</style><article class="entry-content"><h1>Reading and learning</h1><h2 id="stable">A stable author heading</h2><button data-pn-save-later hidden>Read later on this device</button><span data-pn-later-status></span></article><section class="pn-reading" data-pn-reading><h2>My reading</h2><div data-pn-device></div><hr><div data-pn-records></div></section>`,
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
    content: fs.readFileSync(path.join(root, 'assets/reading-center.js'), 'utf8'),
  });
}
function locationUrl(value) {
  return 'http://127.0.0.1:18780' + value;
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
  await page.getByLabel(text.q).fill('query with spaces');
  await page.getByRole('button', { name: text.search, exact: true }).click();
  await expect(page.locator('.pn-reading-note-text')).toHaveText(record.text);
  expect(path).toBe('/records/my-records');
  expect(search).toBe('query with spaces');
});
