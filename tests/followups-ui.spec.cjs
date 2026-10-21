const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const origin = 'http://companion.fixture.test';
test.beforeEach(async ({ page }) => {
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin) return route.abort();
    const html = execFileSync(
      'php',
      [
        path.join(__dirname, 'followups-ui-fixture.php'),
        url.search.slice(1),
        request.postData() || '',
      ],
      { encoding: 'utf8' },
    );
    await route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
  });
});
for (const width of [1440, 390]) {
  test(`diagnostics GET filters and keyboard controls at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(origin);
    await page.getByLabel('Search modules').fill('Likes');
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('Likes');
    await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('enabled');
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await expect(page.locator('tbody')).toContainText('No matching modules');
    const input = page.getByLabel('Search modules');
    await input.focus();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('combobox', { name: 'Status', exact: true })).toBeFocused();
    for (const selector of [
      '#pn-module-search',
      '#pn-module-status',
      '.pn-diagnostics-filters button',
    ])
      expect(await page.locator(selector).evaluate((el) => el.getBoundingClientRect().height)).toBe(
        44,
      );
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
  });
}
test('media single save preserves another edited row and explicit page submit reports each result', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(origin + '/?screen=quality');
  await page.locator('#pn-alt-4').fill('First unique alt');
  await page.locator('#pn-alt-5').fill('Second unique alt');
  await page.locator('button[name=pn_save_one][value="4"]').click();
  await expect(page.locator('#pn-alt-5')).toHaveValue('Second unique alt');
  await expect(page.locator('.notice-success')).toHaveCount(1);
  await page.locator('button[name=pn_save_all]').click();
  // This in-memory renderer resets between requests; real persistence/concurrency uses WP tests.
  await expect(page.locator('.notice')).toHaveCount(2);
  await expect(page.locator('#pn-alt-5')).toHaveValue('Second unique alt');
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
  ).toBeTruthy();
});
test('sharing hints respect inherited summaries and keyboard editing', async ({ page }) => {
  await page.goto(origin + '/?screen=seo');
  await page.addScriptTag({
    content: fs.readFileSync(path.join(__dirname, '../assets/seo-editor.js'), 'utf8'),
  });
  await expect(page.locator('[data-pn-seo-inherited-description]')).toBeVisible();
  await expect(page.locator('[data-pn-seo-missing-description]')).toBeHidden();
  await expect(page.locator('[data-pn-seo-missing-image]')).toBeVisible();
  await page.locator('[data-pn-seo-description]').fill('Custom safe summary');
  await expect(page.locator('[data-pn-seo-inherited-description]')).toBeHidden();
  await expect(page.locator('[data-pn-seo-preview-description]')).toHaveText('Custom safe summary');
  await page.locator('[data-pn-seo-description]').fill('   ');
  await expect(page.locator('[data-pn-seo-inherited-description]')).toBeVisible();
  await expect(page.locator('[data-pn-seo-missing-description]')).toBeHidden();
  await expect(page.locator('[data-pn-seo-preview-description]')).toHaveText('Site description');
  await page.locator('[data-pn-seo-title]').focus();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-pn-seo-description]')).toBeFocused();
});
