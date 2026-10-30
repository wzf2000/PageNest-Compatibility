const { test, expect } = require('@playwright/test');
for (const width of [390, 1440]) {
  test.describe('synthetic content at ' + width, () => {
    test.use({ viewport: { width, height: 900 } });
    test('resource search, grouping, category and anchors', async ({ page }) => {
      await page.goto('/resources.html');
      await expect(page.locator('.pagenest-resource-card:visible')).toHaveCount(3);
      await expect(page.locator('.pagenest-resource-related')).toBeVisible();
      await expect(page.locator('.pagenest-resource-icon svg')).toHaveCount(4);
      const introduction = page.locator('#facility-introduction');
      await expect(introduction).toBeVisible();
      await expect(page.locator('#hidden-introduction')).toBeHidden();
      await page.getByRole('searchbox').fill('festival');
      await expect(introduction).toBeHidden();
      await expect(page.locator('.pagenest-resource-card:visible')).toHaveCount(1);
      await page.getByRole('button', { name: 'Reset filters' }).click();
      await expect(page.getByRole('searchbox')).toBeFocused();
      await expect(introduction).toBeVisible();
      await expect(page.locator('#hidden-introduction')).toBeHidden();
      await page.getByRole('combobox').selectOption('2');
      await expect(introduction).toBeHidden();
      await page.getByRole('combobox').selectOption('0');
      await expect(introduction).toBeVisible();
      await page.getByRole('combobox').selectOption('2');
      await expect(page.locator('.pagenest-resource-card:visible')).toHaveCount(1);
      await page.goto('/resources.html#results');
      await expect(page.locator('#results')).toBeVisible();
      await expect(page.locator('[data-resource-slug="result"]')).toBeVisible();
      await expect(page.getByRole('button', { name: 'Collapse related entries' })).toHaveAttribute(
        'aria-expanded',
        'true',
      );
      await page.getByRole('searchbox').fill('no matching input');
      await expect(page.locator('.pagenest-resource-empty')).toBeVisible();
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      ).toBe(true);
    });
    test('generic guide lists, keyboard and preserved empty anchors', async ({ page }) => {
      await page.goto('/guide.html');
      await expect(page.locator('details')).toHaveCount(3);
      await expect(page.locator('details[open]')).toHaveCount(2);
      await expect(page.locator('#arrival')).toHaveCount(1);
      await expect(page.locator('#venue-existing')).toHaveCount(1);
      await page.getByRole('button', { name: 'Section overview' }).click();
      await expect(page.locator('details[open]')).toHaveCount(0);
      const summary = page.locator('details summary').first();
      await summary.focus();
      await page.keyboard.press('Enter');
      await expect(page.locator('details[open]')).toHaveCount(1);
      await page.getByRole('button', { name: 'Section overview' }).click();
      await page.evaluate(() => {
        location.hash = '#arrival';
      });
      await expect(page.locator('details[open]')).toHaveCount(1);
      await expect(page.locator('details').first()).toHaveAttribute('open', '');
      await page.getByRole('button', { name: 'Expand reading lists' }).click();
      await expect(page.locator('details[open]')).toHaveCount(3);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      ).toBe(true);
    });
    test('native reading links and chapter display preserve identities and TeX', async ({
      page,
    }) => {
      await page.goto('/reading.html');
      await expect(page.getByRole('navigation', { name: 'Reading links' })).toBeVisible();
      await expect(page.getByRole('link', { name: 'Findings' })).toHaveAttribute(
        'href',
        '#existing-findings',
      );
      await page.goto('/chapter.html');
      await expect(page.locator('#spacing')).toHaveText('中文 API 与 HTTP 连接中文API');
      await expect(page.locator('#spacing')).toHaveAttribute('title', '中文API');
      await expect(page.locator('#spacing')).toHaveAttribute('data-pagenest-block', 'b-spacing');
      await expect(page.locator('#code')).toHaveText('中文API');
      await expect(page.locator('#tex')).toHaveText('中文 $中A$ 与 \\(中B\\) \\[中C\\]');
      await expect(page.locator('#split-tex')).toHaveText('中文 $中A$');
      await expect(page.locator('#existing-id')).toHaveCount(1);
      await expect(page.locator('#repeat-2')).toHaveCount(1);
      await expect(page.locator('#old-repeat')).toHaveCount(1);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      ).toBe(true);
    });
    test('no JavaScript keeps complete native guide and public resources', async ({ browser }) => {
      const context = await browser.newContext({
        javaScriptEnabled: false,
        viewport: { width, height: 900 },
      });
      const page = await context.newPage();
      await page.goto('http://127.0.0.1:18783/resources.html');
      await expect(page.locator('li')).toHaveCount(4);
      await expect(page.locator('#facility-introduction')).toBeVisible();
      await expect(page.getByRole('link', { name: 'Result', exact: true })).toBeVisible();
      await expect(page.getByRole('link', { name: 'Private', exact: true })).toHaveCount(0);
      await page.goto('http://127.0.0.1:18783/guide.html#arrival');
      await expect(page.getByText('Hall stop', { exact: true })).toBeVisible();
      await expect(page.getByText('Venue detail', { exact: true })).toBeVisible();
      await expect(page.getByText('Exit detail', { exact: true })).toBeVisible();
      await expect(page.getByText('Native introduction.')).toBeVisible();
      await expect(page.locator('details')).toHaveCount(0);
      await page.goto('http://127.0.0.1:18783/reading.html');
      await expect(page.getByRole('link', { name: 'Observations' })).toHaveAttribute(
        'href',
        '#observations',
      );
      await context.close();
    });
  });
}
