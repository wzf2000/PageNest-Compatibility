const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fixture = (name) => `/.runtime/performance/${name}.html`;
test('disabled and invalid configuration give useful noninteractive states', async ({ page }) => {
  for (const [state, heading] of [
    ['disabled', '尚未启用'],
    ['config-error', '测速暂不可用'],
  ]) {
    await page.goto(fixture(state));
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
    await expect(page.getByRole('button')).toHaveCount(0);
  }
});
test('selection validates the raw 1–8 entries and estimates only an upper bound', async ({
  page,
}) => {
  await page.goto(fixture('empty'));
  const choices = page.locator('.pn-perf-selection input[type=checkbox]');
  for (const choice of await choices.all()) await choice.uncheck();
  const submit = page.getByRole('button', { name: '提交测速任务' });
  const save = page.getByRole('button', { name: '保存页面选择' });
  await expect(submit).toBeDisabled();
  await expect(save).toBeDisabled();
  await choices.first().check();
  await expect(submit).toBeEnabled();
  await page.locator('.pn-perf-selection details').getByText('补充公开文章或页面').click();
  const ids = page.getByLabel('文章 / 页面 ID', { exact: true });
  await ids.fill('2,3,4,5,6,7,8,8');
  await expect(submit).toBeDisabled();
  await expect(page.locator('[data-pn-perf-count]')).toHaveText('已选择 9 / 8 项');
  await ids.fill('0,one');
  await expect(page.locator('[data-pn-perf-validation]')).toContainText('正整数');
  await ids.fill('2');
  await page.getByLabel('访问方式', { exact: true }).selectOption('paired');
  await page.getByLabel('重复轮数').selectOption('3');
  await expect(page.locator('[data-pn-perf-estimate]')).toContainText('最多 12 次导航');
  await expect(submit).toBeEnabled();
  await expect(page.locator('.pn-perf-compare-form [role=status]')).toHaveCount(0);
  await expect(page.getByText('未保存')).toHaveCount(0);
});
test('empty and single-report history omit empty comparison controls', async ({ page }) => {
  for (const state of ['empty', 'completed']) {
    await page.goto(fixture(state));
    await expect(page.locator('.pn-perf-compare-form')).toHaveCount(0);
    await expect(page.getByText('至少有两份终态报告后')).toBeVisible();
  }
});
test('queued and running show actual stored records and manual execution semantics', async ({
  page,
}) => {
  await page.goto(fixture('queued'));
  await expect(page.locator('.pn-perf-active')).toContainText('等待执行');
  await expect(page.locator('.pn-perf-active')).toContainText('已记录 0 / 12 条阶段结果');
  await expect(page.getByRole('button', { name: '提交测速任务' })).toBeEnabled();
  await expect(page.getByRole('button', { name: '取消任务' })).toBeEnabled();
  await page.goto(fixture('running'));
  await expect(page.locator('.pn-perf-active')).toContainText('已记录 3 / 12 条阶段结果');
  await expect(page.locator('.pn-perf-active')).toContainText('不代表实际导航次数');
  await page.goto(fixture('expired-running'));
  await expect(page.getByRole('button', { name: '提交测速任务' })).toBeEnabled();
  await expect(page.getByText('提交时由服务器确认是否可新建任务', { exact: false })).toBeVisible();
  await page.goto(fixture('cancel-notice'));
  await expect(page.getByRole('status').first()).toContainText('刷新确认终态');
});
test('paired metrics keep zero, missing data, per-metric N and failed warmup distinct', async ({
  page,
}) => {
  await page.goto(fixture('completed'));
  const first = page.locator('.pn-perf-page').first();
  await expect(first.locator('.pn-perf-metric').nth(3)).toContainText('同轮有效配对 3/3');
  await expect(first.locator('.pn-perf-metric').nth(3).locator('dd')).toHaveText(['0', '0']);
  await expect(page.locator('#performance-latest .pn-perf-notice-error')).toContainText(
    '失败或跳过',
  );
  const second = page.locator('.pn-perf-page').nth(1);
  await expect(second.locator('summary').first()).toContainText('成功 4 · 失败 1 · 跳过 1');
  await second.locator('summary').first().click();
  const lcp = second.locator('.pn-perf-metric').nth(2);
  await expect(lcp).toContainText('未采集');
  await expect(lcp).toContainText('同轮有效配对 0/3');
  await second.getByText('逐轮记录与完整阶段摘要', { exact: true }).click();
  await expect(second.getByText('第 3 轮 · 再次访问 · 首次失败，未再次导航')).toBeVisible();
  await expect(first.locator('.pn-perf-origin')).toContainText('浏览器 HTTP 缓存请求数独立');
});
test('comparison gives distinct zero-baseline and missing-sample explanations', async ({
  page,
}) => {
  await page.goto(fixture('history'));
  await expect(page.locator('.pn-perf-comparison')).toContainText('基线为 0，不计算百分比');
  await expect(page.locator('.pn-perf-comparison')).toContainText('缺少有效样本，不计算百分比');
  await expect(page.locator('.pn-perf-comparison')).toContainText('差值不证明因果');
  await page.goto(fixture('incomparable'));
  await expect(page.locator('.pn-perf-comparison')).toContainText('旧协议报告缺少可比环境记录');
  await expect(page.locator('.pn-perf-compare-metric')).toHaveCount(0);
  await page.locator('.pn-perf-history > summary').click();
  await expect(page.locator('.pn-perf-history button[value=export]')).toBeVisible();
});
test('failed and budget states preserve report access and recovery guidance', async ({ page }) => {
  await page.goto(fixture('failed'));
  await expect(page.locator('#performance-latest')).toContainText('达到资源预算');
  await page.getByText('报告身份与执行环境', { exact: true }).click();
  await expect(page.locator('#performance-latest')).toContainText('resource_limit');
  await expect(page.getByRole('button', { name: '导出私有 JSON' })).toBeVisible();
  await page.goto(fixture('budget'));
  await expect(page.getByRole('alert').first()).toContainText('归档或清理报告');
  await expect(page.locator('.pn-perf-history')).toHaveCount(9);
});
for (const width of [1440, 390, 320]) {
  test(`layout at ${width}px contains overflow and keeps interactive controls accessible`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(fixture('completed'));
    await expect(page.locator('.pn-performance')).toBeVisible();
    expect(
      await page
        .locator('.pn-perf-number')
        .last()
        .evaluate((el) => getComputedStyle(el).whiteSpace),
    ).toBe('nowrap');
    await expect(page.getByLabel('设备', { exact: true })).toHaveAttribute('name', 'device');
    await page.getByRole('link', { name: '新建任务', exact: true }).focus();
    const outline = await page
      .getByRole('link', { name: '新建任务', exact: true })
      .evaluate((el) => getComputedStyle(el).outlineStyle);
    expect(outline).toBe('solid');
    for (const control of await page
      .locator('.pn-performance button, .pn-performance select, .pn-performance summary')
      .all()) {
      if (await control.isVisible())
        expect((await control.boundingBox()).height).toBeGreaterThanOrEqual(44);
    }
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > window.innerWidth,
    );
    expect(overflow).toBe(false);
    await page.screenshot({
      path: path.resolve(__dirname, `../.runtime/performance/preview-${width}.png`),
      fullPage: true,
    });
  });
}
test('native forms and disclosure work with JavaScript disabled', async ({ browser }) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 390, height: 1000 },
  });
  const page = await context.newPage();
  await page.goto('http://127.0.0.1:18786' + fixture('completed'));
  await expect(page.getByRole('button', { name: '提交测速任务' })).toBeEnabled();
  await page.locator('.pn-perf-selection details > summary').click();
  await expect(page.getByLabel('文章 / 页面 ID', { exact: true })).toBeVisible();
  await page.locator('.pn-perf-page').nth(1).locator('summary').first().click();
  await expect(page.locator('.pn-perf-page').nth(1).getByText('未采集').first()).toBeVisible();
  await expect(page.locator('.pn-perf-selection')).toHaveAttribute('method', 'post');
  await expect(page.locator('.pn-perf-actions input[name=_wpnonce]')).toHaveValue('good');
  await context.close();
});
