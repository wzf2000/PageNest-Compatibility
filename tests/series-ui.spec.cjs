const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
for (const width of [1440, 390]) {
  test(`series relationships settings at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route('**/*', (route) => route.abort());
    const html = execFileSync('php', [path.join(__dirname, 'series-ui-fixture.php')], {
      encoding: 'utf8',
    });
    await page.setContent(html);
    const section = page.locator('[data-pn-series-directories]');
    await expect(section.getByLabel('系列名称')).toHaveCount(3);
    await expect(section.getByLabel('目录页 ID').nth(1)).toHaveValue('99');
    await expect(section).toContainText('服务器系列');
    await expect(section).toContainText('1 个已有文档');
    await section.getByLabel('系列名称').last().fill('新的系列');
    await section.getByLabel('目录页 ID').last().fill('2');
    await section.getByLabel('系列名称').last().focus();
    await page.keyboard.press('Tab');
    await expect(section.getByLabel('目录页 ID').last()).toBeFocused();
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: path.join(__dirname, '../.runtime', `series-admin-${width}.png`),
      fullPage: true,
    });
  });
}
