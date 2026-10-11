const { test, expect } = require('@playwright/test');
const path = require('node:path');
async function fixture(page) {
  await page.setContent(
    '<body class="upload-php"><div id="wpbody-content"><div class="media-frame mode-grid"><div class="media-frame-content"><ul class="attachments"><li class="attachment">Image</li></ul></div></div></div></body>',
  );
  await page.evaluate(() => {
    const values = { search: 'keep search', type: 'image', order: 'ASC' },
      listeners = new Set();
    window.props = {
      get: (key) => values[key],
      set: (key, value) => {
        values[key] = value;
        listeners.forEach((fn) => fn());
      },
      on: (key, fn) => listeners.add(fn),
      off: (key, fn) => listeners.delete(fn),
    };
    window.wp = {
      media: { frame: { state: () => ({ get: () => ({ props: window.props }) }) } },
      ajax: { send: async () => window.pagenestMediaLibrary.terms },
    };
    window.pagenestMediaLibrary = {
      taxonomy: 'folders',
      nonce: 'fixture',
      terms: [
        { id: 1, parent: 0, name: 'Parent', slug: 'parent' },
        { id: 2, parent: 1, name: 'Child <img>', slug: 'child' },
        { id: 3, parent: 0, name: 'Second', slug: 'second' },
      ],
    };
  });
  await page.addScriptTag({ path: path.resolve(__dirname, '../assets/media-library.js') });
}
test('folder cards navigate hierarchy without changing media filters and escape names', async ({
  page,
}) => {
  await fixture(page);
  await page
    .locator('.pagenest-folder-card')
    .filter({ hasText: /^Parent$/ })
    .click();
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toHaveText('Parent');
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toBeFocused();
  await page.locator('.pagenest-folder-card').filter({ hasText: 'Child <img>' }).click();
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toHaveText('Child <img>');
  await expect(page.locator('.pagenest-media-folders img')).toHaveCount(0);
  expect(
    await page.evaluate(() => ['search', 'type', 'order', 'folders'].map((k) => props.get(k))),
  ).toEqual(['keep search', 'image', 'ASC', 'child']);
  await page.getByRole('button', { name: 'Parent folder', exact: false }).click();
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toHaveText('Parent');
  await page.getByRole('button', { name: 'Media Library', exact: true }).click();
  await expect(page.locator('.pagenest-folder-card')).toHaveCount(2);
});
test('provider changes and grid refresh retain one panel; explicit refresh discovers folders', async ({
  page,
}) => {
  await fixture(page);
  await page.evaluate(() => {
    const item = document.createElement('li');
    item.className = 'attachment';
    document.querySelector('.attachments').prepend(item);
  });
  await expect(page.locator('.attachments > :first-child')).toHaveClass('pagenest-media-folders');
  await page.evaluate(() => props.set('folders', 'parent'));
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toHaveText('Parent');
  await page.evaluate(() => document.querySelector('.attachments').replaceChildren());
  await expect(page.locator('.pagenest-media-folders')).toHaveCount(1);
  await page.evaluate(() =>
    pagenestMediaLibrary.terms.push({ id: 4, parent: 1, name: 'New folder', slug: 'new' }),
  );
  await page.getByRole('button', { name: 'Refresh folders' }).click();
  await expect(
    page.locator('.pagenest-folder-card').filter({ hasText: 'New folder' }),
  ).toBeVisible();
});

test('sidebar expands, searches, and synchronizes filters without discarding search', async ({
  page,
}) => {
  await fixture(page);
  const sidebar = page.locator('.pagenest-folder-sidebar');
  await expect(sidebar).toBeVisible();
  await expect(sidebar.getByRole('button', { name: 'Child <img>', exact: true })).toHaveCount(0);
  await sidebar.locator('[data-toggle="1"]').click();
  await sidebar.getByRole('button', { name: 'Child <img>', exact: true }).click();
  await expect(sidebar.locator('[aria-current]')).toHaveText('Child <img>');
  await expect(sidebar.locator('[aria-current]')).toBeFocused();
  await expect(page.locator('.pagenest-folder-heading [aria-current]')).toHaveText('Child <img>');
  await sidebar.getByRole('searchbox').fill('child');
  await expect(sidebar.getByRole('button', { name: 'Second', exact: true })).toHaveCount(0);
  await expect(sidebar.getByRole('button', { name: 'Child <img>', exact: true })).toBeVisible();
  await sidebar.getByRole('button', { name: 'Unassigned', exact: true }).click();
  expect(await page.evaluate(() => props.get('folders'))).toBe('-1');
  await sidebar.getByRole('button', { name: 'All files', exact: true }).click();
  expect(await page.evaluate(() => [props.get('folders'), props.get('search')])).toEqual([
    '',
    'keep search',
  ]);
  await sidebar.getByRole('searchbox').fill('absent');
  await expect(sidebar.getByRole('status')).toHaveText('No matching folders');
  await page.evaluate(() => props.set('folders', 'child'));
  await sidebar.getByRole('searchbox').fill('');
  await expect(sidebar.locator('[aria-current]')).toHaveText('Child <img>');
  await expect(sidebar.locator('img')).toHaveCount(0);
  await expect(page.locator('.pagenest-folder-sidebar')).toHaveCount(1);
});
