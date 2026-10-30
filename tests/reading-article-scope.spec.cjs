const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const fixtures = Object.fromEntries(
  ['post', 'page'].map((type) => [
    type,
    JSON.parse(
      execFileSync('php', [path.join(__dirname, 'reading-article-scope.php'), '--fixture', type]),
    ),
  ]),
);
const themeRoot = process.env.PAGENEST_TEST_THEME_ROOT;
const css = [
  ...(themeRoot
    ? ['appearance.css', 'layout.css', 'content.css', 'presentation.css'].map((file) =>
        fs.readFileSync(path.join(themeRoot, 'assets', file), 'utf8'),
      )
    : []),
  fs.readFileSync(path.join(root, 'assets/reading-center.css'), 'utf8'),
].join('\n');
for (const width of [1440, 390]) {
  test(`only articles collect device reading at ${width}px`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
    await page.route('**/*', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/reading/resolve')) {
        return route.fulfill({
          json: {
            items: [
              { id: 1, title: '公开文章', url: url.origin + '/scope/post', headings: ['stable'] },
              { id: 2, title: '旧页面记录', url: url.origin + '/scope/page', headings: ['stable'] },
            ],
          },
        });
      }
      const type = url.pathname.endsWith('/page') ? 'page' : 'post';
      const fixture = fixtures[type];
      return route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><body class="pagenest-site"><header class="pagenest-header"><div class="pagenest-nav-inner">合成阅读验收 ${fixture.global.slice(0, fixture.global.indexOf('<dialog'))}</div></header><main class="pagenest-main"><article class="pagenest-article"><h1>${type === 'page' ? '工具总览' : '公开文章'}</h1><div class="pagenest-article-body entry-content">${fixture.html}<p style="min-height:1000px">${type === 'page' ? '选择工具并完成操作。' : '文章阅读正文。'}</p></div></article></main>${fixture.global.slice(fixture.global.indexOf('<dialog'))}<script>window.PageNestReading=${JSON.stringify(fixture.config)}</script><script>${fs.readFileSync(path.join(root, 'assets/reading-center.js'), 'utf8')}</script></body></html>`,
      });
    });
    await page.goto('/scope/page');
    await page.evaluate(() =>
      localStorage.setItem(
        'pagenest.reading.v1',
        JSON.stringify({
          enabled: true,
          items: [{ id: 2, time: 1, later: true, anchor: 'stable' }],
        }),
      ),
    );
    await page.reload();
    const saved = await page.evaluate(() => localStorage.getItem('pagenest.reading.v1'));
    await expect(page.locator('[data-pn-save-later]')).toHaveCount(0);
    expect(await page.evaluate(() => PageNestReading.current)).toBeNull();
    await page.evaluate(() => window.scrollTo(0, 700));
    await page.waitForTimeout(1400);
    expect(await page.evaluate(() => localStorage.getItem('pagenest.reading.v1'))).toBe(saved);
    await page.locator('[data-pn-open-reading]').click();
    await expect(page.locator('.pn-reading-dialog')).toBeVisible();
    await expect(page.getByRole('link', { name: '旧页面记录', exact: true })).toBeVisible();
    await page.locator('[data-pn-close-reading]').click();
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    const evidence = process.env.PAGENEST_SCOPE_EVIDENCE;
    if (evidence) await page.screenshot({ path: path.join(evidence, `tools-${width}.png`) });
    await page.goto('/scope/post');
    await expect(page.locator('[data-pn-save-later]')).toBeVisible();
    await page.locator('[data-pn-save-later]').click();
    await expect(page.locator('[data-pn-later-status]')).toContainText('Saved for later');
    const records = await page.evaluate(
      () => JSON.parse(localStorage.getItem('pagenest.reading.v1')).items,
    );
    expect(records.find((item) => item.id === 1)?.later).toBe(true);
    expect(records.find((item) => item.id === 2)?.time).toBe(1);
    await page.locator('[data-pn-open-reading]').click();
    await expect(page.getByRole('link', { name: '公开文章', exact: true })).toBeVisible();
    if (evidence) await page.screenshot({ path: path.join(evidence, `reading-${width}.png`) });
    await page.getByRole('button', { name: 'Remove: 旧页面记录', exact: true }).click();
    await expect(page.getByRole('link', { name: '旧页面记录', exact: true })).toHaveCount(0);
    expect(
      await page.evaluate(() =>
        JSON.parse(localStorage.getItem('pagenest.reading.v1')).items.some((item) => item.id === 2),
      ),
    ).toBe(false);
    expect(errors).toEqual([]);
  });
}
