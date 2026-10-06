const { test, expect } = require('@playwright/test');

async function readingGeometry(page) {
  return page.evaluate(() => {
    const rect = (selector) => {
      const box = document.querySelector(selector).getBoundingClientRect();
      return { left: box.left, width: box.width, top: box.top, height: box.height };
    };
    const range = document.createRange();
    range.selectNodeContents(document.querySelector('.pagenest-entry-title'));
    return {
      body: rect('.pagenest-article-body'),
      column: rect('.pagenest-article-column'),
      title: rect('.pagenest-entry-title'),
      titleLines: [...range.getClientRects()].map((box) => ({ top: box.top, width: box.width })),
      anchor: rect('#section-0'),
      columns: getComputedStyle(document.querySelector('.pagenest-reading-grid'))
        .gridTemplateColumns,
    };
  });
}

async function panelGeometry(page) {
  return page.evaluate(() => {
    const rail = document.querySelector('.pagenest-reading-rail');
    const panel = document.querySelector('.pagenest-paragraph-panel').getBoundingClientRect();
    const column = rail.parentElement.getBoundingClientRect();
    const close = document.querySelector('.pagenest-paragraph-close').getBoundingClientRect();
    return {
      width: panel.width,
      left: panel.left,
      right: panel.right,
      expectedWidth: Math.max(
        column.width,
        Math.min(350, document.documentElement.clientWidth - column.left - 20),
      ),
      columnWidth: column.width,
      closeFits: close.left >= panel.left && close.right <= panel.right,
      overflowing: document.documentElement.scrollWidth > innerWidth,
      viewport: innerWidth,
    };
  });
}

async function openFixture(page, width, height = 900) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setViewportSize({ width, height });
  await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === 'http://127.0.0.1:18780'
      ? route.continue()
      : route.abort(),
  );
  let reads = 0;
  await page.route('**/api/comments*', async (route) => {
    expect(route.request().method()).toBe('GET');
    reads++;
    await route.fulfill({ json: [] });
  });
  await page.goto('/reading.html');
  await expect(page.locator('.pagenest-toc')).toBeVisible();
  return { errors, reads: () => reads };
}

for (const width of [1280, 1335, 1440, 1920]) {
  test(`reading geometry stays fixed when comments open and close at ${width}px`, async ({
    page,
  }) => {
    const runtime = await openFixture(page, width);
    const realTheme = await page.evaluate(() => window.fixtureRealTheme);
    if (realTheme) {
      await page.getByRole('button', { name: '展开全部', exact: true }).click();
      await page.locator('.pagenest-toc-toggle').nth(1).click();
    }
    const collapse = await page
      .locator('.pagenest-toc-toggle')
      .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-expanded')));
    const rail = page.locator('.pagenest-reading-rail');
    const originalScroll = await rail.evaluate((node) => {
      node.scrollTop = 180;
      return node.scrollTop;
    });
    expect(originalScroll).toBeGreaterThan(0);
    const before = await readingGeometry(page);
    await page.locator('.pagenest-paragraph-bubble').click();
    await expect(rail).toHaveClass(/pagenest-panel-active/);
    await expect(page.locator('.pagenest-paragraph-input')).toBeVisible();
    expect(await readingGeometry(page)).toEqual(before);
    const geometry = await panelGeometry(page);
    expect(geometry.columnWidth).toBe(240);
    expect(geometry.width).toBeCloseTo(geometry.expectedWidth, 1);
    expect(geometry.left).toBeGreaterThanOrEqual(before.column.left + before.column.width);
    expect(geometry.right).toBeLessThanOrEqual(width);
    expect(geometry.closeFits).toBe(true);
    expect(geometry.overflowing).toBe(false);
    if (width === 1920) expect(geometry.width).toBe(350);
    if (width === 1280) expect(geometry.width).toBeLessThan(350);
    // Repeated placement must use the stable grid column, not the widened rail's right edge.
    await page.evaluate(() => {
      for (let index = 0; index < 8; index++) {
        dispatchEvent(new Event('resize'));
        document.dispatchEvent(new Event('pagenest-reading-layout'));
      }
    });
    expect((await panelGeometry(page)).width).toBe(geometry.width);
    expect(await readingGeometry(page)).toEqual(before);
    await page.locator('.pagenest-paragraph-input').fill('Unsent neutral reading draft');
    await page.locator('.pagenest-paragraph-close').click();
    await expect(rail).not.toHaveClass(/pagenest-panel-active/);
    expect(await readingGeometry(page)).toEqual(before);
    expect(await rail.evaluate((node) => node.scrollTop)).toBe(originalScroll);
    expect(
      await rail.evaluate((node) => node.style.getPropertyValue('--pagenest-pc-docked-width')),
    ).toBe('');
    expect(
      await page
        .locator('.pagenest-toc-toggle')
        .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('aria-expanded'))),
    ).toEqual(collapse);
    await page.locator('.pagenest-paragraph-bubble').click();
    await expect(page.locator('.pagenest-paragraph-input')).toHaveValue(
      'Unsent neutral reading draft',
    );
    expect(runtime.reads()).toBe(1);
    expect(runtime.errors).toEqual([]);
  });
}

test('reading drawer preserves drafts and selection through desktop, mobile and short-window layouts', async ({
  page,
}) => {
  const runtime = await openFixture(page, 1440);
  await page.locator('.pagenest-paragraph-bubble').click();
  const input = page.locator('.pagenest-paragraph-input');
  await input.fill('Neutral draft retained across reading layouts');
  await input.evaluate((node) => {
    node.focus();
    node.setSelectionRange(3, 12, 'backward');
  });
  for (const viewport of [
    { width: 1335, height: 900 },
    { width: 390, height: 900 },
    { width: 1440, height: 900 },
    { width: 1440, height: 480 },
    { width: 1440, height: 900 },
    { width: 1279, height: 900 },
    { width: 1280, height: 900 },
  ]) {
    await page.setViewportSize(viewport);
    const docked = viewport.width >= 1280 && viewport.height >= 900;
    await expect
      .poll(() =>
        page
          .locator('.pagenest-reading-rail')
          .evaluate((node) => node.classList.contains('pagenest-panel-active')),
      )
      .toBe(docked);
    await expect(page.getByRole('dialog')).toBeVisible({ visible: !docked });
    await expect(input).toHaveValue('Neutral draft retained across reading layouts');
    await expect
      .poll(
        () =>
          input.evaluate(
            (node, mobile) => [
              document.activeElement === node ||
                (mobile && document.activeElement.matches('.pagenest-paragraph-close')),
              node.selectionStart,
              node.selectionEnd,
              node.selectionDirection,
            ],
            viewport.width === 390,
          ),
        { message: JSON.stringify(viewport) },
      )
      .toEqual([true, 3, 12, 'backward']);
    // Mobile CSS may hide the old slot before placement and give the drawer close button focus.
    await input.focus();
    if (docked) {
      const geometry = await panelGeometry(page);
      expect(geometry.width).toBeCloseTo(geometry.expectedWidth, 1);
      expect(geometry.closeFits).toBe(true);
    } else {
      expect(
        await page
          .locator('.pagenest-reading-rail')
          .evaluate((node) => node.style.getPropertyValue('--pagenest-pc-docked-width')),
      ).toBe('');
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(
      false,
    );
  }
  await page.locator('.pagenest-paragraph-close').click();
  expect(runtime.reads()).toBe(1);
  expect(runtime.errors).toEqual([]);
});

for (const viewport of [
  { width: 390, height: 900 },
  { width: 1440, height: 480 },
]) {
  test(`drawer open and close preserve reading geometry at ${viewport.width}x${viewport.height}`, async ({
    page,
  }) => {
    const runtime = await openFixture(page, viewport.width, viewport.height);
    const before = await readingGeometry(page);
    await page.locator('.pagenest-paragraph-bubble').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(await readingGeometry(page)).toEqual(before);
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(
      false,
    );
    await page.locator('.pagenest-paragraph-close').click();
    await expect(page.getByRole('dialog')).toBeHidden();
    expect(await readingGeometry(page)).toEqual(before);
    await expect(page.locator('.pagenest-paragraph-bubble')).toBeFocused();
    expect(runtime.errors).toEqual([]);
  });
}
