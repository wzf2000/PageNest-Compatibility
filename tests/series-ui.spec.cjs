const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
async function fixture(post = '') {
  if (process.env.PAGENEST_SERIES_FIXTURE_URL) {
    const response = await fetch(process.env.PAGENEST_SERIES_FIXTURE_URL, {
      method: post ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: post || undefined,
    });
    if (!response.ok) throw new Error(`Fixture failed: ${response.status}`);
    return response.text();
  }
  return execFileSync('php', [path.join(__dirname, 'series-ui-fixture.php')], {
    encoding: 'utf8',
    env: { ...process.env, PAGENEST_SERIES_FIXTURE_POST: post },
  });
}
async function routeFixture(page) {
  await page.route('**/*', async (route) => {
    if (new URL(route.request().url()).hostname !== 'fixture.example') return route.abort();
    return route.fulfill({
      contentType: 'text/html',
      body: await fixture(route.request().postData() || ''),
    });
  });
  await page.goto('https://fixture.example/wp-admin/options-general.php?page=pagenest-companion');
}
for (const width of [1440, 390]) {
  test(`directory selection and keyboard chapter order at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await routeFixture(page);
    await expect(page.locator('.pn-admin-nav a')).toHaveCount(8);
    expect(await page.locator('form form').count()).toBe(0);
    const section = page.locator('[data-pn-series-directories]');
    await expect(section.getByLabel('系列名称')).toHaveCount(3);
    await expect(section.getByLabel('目录页 ID').nth(1)).toHaveValue('99');
    await expect(section).toContainText('原目录当前不可用');
    await expect(section).toContainText('服务器系列');
    await expect(section).toContainText('1 个已有文档');
    await section.getByLabel('按标题选择目录').last().selectOption('2');
    await expect(section.getByLabel('目录页 ID').last()).toHaveValue('2');
    await section.getByLabel('目录页 ID').last().fill('88');
    await expect(section.getByLabel('按标题选择目录').last()).toHaveValue('');
    const chapters = page.locator('[data-pn-series-maintenance]');
    await expect(chapters).toContainText('顺序重复');
    await expect(chapters).toContainText('未填写顺序');
    const move = chapters
      .locator('[data-pn-series-chapter]')
      .filter({ hasText: '第一章' })
      .locator('[data-pn-series-move="1"]');
    await move.focus();
    await page.keyboard.press('Enter');
    await expect(chapters.locator('[data-pn-series-chapter]').first()).toContainText('第二章');
    await expect(move).toBeFocused();
    await chapters.getByRole('button', { name: '预览章节顺序' }).click();
    await expect(chapters.locator('[data-pn-series-result]')).toBeFocused();
    await expect(chapters.locator('[data-pn-series-chapter]').first()).toContainText('第二章');
    await expect(chapters.getByRole('button', { name: '保存章节顺序' })).toBeVisible();
    await chapters.getByLabel('章节顺序').first().fill('10');
    await expect(chapters.getByRole('button', { name: '保存章节顺序' })).toBeHidden();
    await expect(chapters.locator('[data-pn-savebar]')).toHaveCSS('position', 'static');
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
    ).toBeTruthy();
    await page.screenshot({
      path: path.join(
        __dirname,
        '../.runtime',
        `series-admin-${test.info().project.name}-${width}.png`,
      ),
      fullPage: true,
    });
  });
}
test('native no-JavaScript proposal POST retains drafts and previews', async ({ browser }) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 390, height: 844 },
  });
  const page = await context.newPage();
  await routeFixture(page);
  const chapters = page.locator('[data-pn-series-maintenance]');
  await expect(chapters.locator('[data-pn-series-move="1"]').first()).toBeHidden();
  for (const [index, value] of ['3', '1', '2'].entries())
    await chapters.getByLabel('章节顺序').nth(index).fill(value);
  await chapters.getByRole('button', { name: '预览章节顺序' }).click();
  await expect(chapters).toContainText('预览已生成');
  await expect(chapters.locator('[data-pn-series-chapter]').first()).toContainText('第二章');
  await expect(chapters.getByRole('button', { name: '保存章节顺序' })).toBeVisible();
  await chapters.getByLabel('章节顺序').first().fill('');
  await chapters.getByRole('button', { name: '预览章节顺序' }).click();
  await expect(chapters).toContainText('请为每篇章节填写');
  await expect(chapters.getByLabel('章节顺序').first()).toHaveValue('3');
  await expect(chapters.getByLabel('章节顺序').nth(1)).toHaveValue('');
  await expect(chapters.getByRole('button', { name: '保存章节顺序' })).toHaveCount(0);
  await context.close();
});
