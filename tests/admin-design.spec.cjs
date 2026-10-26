const { test, expect } = require('@playwright/test');
const path = require('node:path');
const script = path.join(__dirname, '../assets/admin-design.js');
test('settings feedback follows edits, reversions, reset and a media picker hidden field', async ({
  page,
}) => {
  await page.setContent(
    `<main class="pn-admin"><form method="post"><input name="nonce" type="hidden" value="fixture"><input id="image" type="hidden" value="0"><input id="toggle" type="checkbox" checked><textarea id="pagenest-description" maxlength="160">阅读笔记</textarea><span data-pn-description-count></span><select id="audience"><option value="all" selected>All</option><option value="guest">Guest</option></select><button type="reset">Reset</button></form></main>`,
  );
  await page.addScriptTag({ path: script });
  const status = page.locator('[role=status]');
  await expect(status).toBeHidden();
  await page.locator('#toggle').uncheck();
  await expect(status).toBeVisible();
  await page.locator('#toggle').check();
  await expect(status).toBeHidden();
  await page.locator('#audience').selectOption('guest');
  await expect(status).toBeVisible();
  await page.getByRole('button', { name: 'Reset' }).click();
  await expect(status).toBeHidden();
  await page.locator('#pagenest-description').fill('新的阅读笔记');
  await expect(page.locator('[data-pn-description-count]')).toHaveText('6 / 160');
  await expect(status).toBeVisible();
  await page.locator('#pagenest-description').fill('阅读笔记');
  await expect(status).toBeHidden();
  await page.locator('#image').evaluate((field) => {
    field.value = '42';
    field.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await expect(status).toBeVisible();
});
test('feedback stays scoped to its form and leaves unrelated screens alone', async ({ page }) => {
  await page.setContent(
    '<form id="other"><input value="outside"></form><main class="pn-admin"><form method="post"><input id="first" value="one"></form><form method="post"><input id="second" value="two"></form></main>',
  );
  await page.addScriptTag({ path: script });
  await expect(page.locator('#other [role=status]')).toHaveCount(0);
  await page.locator('#second').fill('changed');
  await expect(page.locator('.pn-admin form').first().locator('[role=status]')).toBeHidden();
  await expect(page.locator('.pn-admin form').last().locator('[role=status]')).toBeVisible();
});

test('read-only filters and the guide keep their own feedback', async ({ page }) => {
  await page.setContent(
    '<main class="pn-admin"><form method="get"><input id="filter"></form><form method="post" data-pn-guide-form><input id="guide"></form><form method="post"><input id="setting"><p class="submit"><button type="submit">Save</button></p></form></main>',
  );
  await page.addScriptTag({ path: script });
  await page.locator('#filter').fill('Search modules');
  await page.locator('#guide').fill('Owned guide state');
  await expect(page.locator('form[method=get] [role=status]')).toHaveCount(0);
  await expect(page.locator('[data-pn-guide-form] [role=status]')).toHaveCount(0);
  await page.locator('#setting').fill('Changed');
  await expect(page.locator('.pn-admin-savebar [role=status]')).toBeVisible();
});

// Relevant WordPress 7.1 forms.css rules, including its higher-specificity mobile select.
const nativeFormStyles = `
  body, input, select, textarea { font: 14px Arial, sans-serif; }
  input[type=text], input[type=search], select, textarea { border: 1px solid #949494; border-radius: 2px; }
  input[type=text], input[type=search] { padding: 0 12px; min-height: 40px; }
  .wp-core-ui select { font-size: 14px; line-height: 2.71428571; padding: 0 24px 0 12px; min-height: 40px; }
  input[type=search] { -webkit-appearance: textfield; }
  @media screen and (max-width: 782px) {
    input[type=text], input[type=search], textarea { font-size: 16px; }
    .wp-core-ui select { font-size: 16px; line-height: 2.5; }
  }
`;
for (const width of [1440, 390]) {
  test(`single-line admin controls align against native WordPress styles at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.setContent(`<style>${nativeFormStyles}</style><body class="wp-core-ui">
      <input id="outside" type="search"><select id="outside-select"><option>Native select</option></select>
      <main class="pn-admin"><div class="pn-guide-query-actions">
        <input type="search" aria-label="Search guide"><button class="button">Search</button>
      </div><input type="text" aria-label="Group title"><select aria-label="Target group"><option>Reading</option></select>
      <form class="pn-diagnostics-filters"><input type="search" aria-label="Filter modules"><select aria-label="Module status"><option>All</option></select><button class="button">Filter</button></form>
      <select multiple size="6" aria-label="Multiple options"><option>One</option><option>Two</option></select>
      <textarea aria-label="Description">Multiline content</textarea><input type="checkbox" aria-label="Enable">
      </main></body>`);
    const metrics = (node) => ({
      height: node.getBoundingClientRect().height,
      fontSize: getComputedStyle(node).fontSize,
      radius: getComputedStyle(node).borderRadius,
    });
    const outside = await page.locator('#outside, #outside-select').evaluateAll((nodes) =>
      nodes.map((node) => ({
        height: node.getBoundingClientRect().height,
        fontSize: getComputedStyle(node).fontSize,
        radius: getComputedStyle(node).borderRadius,
      })),
    );
    await page.addStyleTag({ path: path.join(__dirname, '../assets/admin-design.css') });
    for (const control of await page
      .locator(
        '.pn-admin input:not([type=checkbox]), .pn-admin select:not([multiple]), .pn-admin .button',
      )
      .all()) {
      const measured = await control.evaluate(metrics);
      expect(measured.height).toBe(44);
      expect(measured.radius).toBe('7px');
      if (width === 390 && !(await control.evaluate((node) => node.matches('button'))))
        expect(measured.fontSize).toBe('16px');
    }
    const after = await page.locator('#outside, #outside-select').evaluateAll((nodes) =>
      nodes.map((node) => ({
        height: node.getBoundingClientRect().height,
        fontSize: getComputedStyle(node).fontSize,
        radius: getComputedStyle(node).borderRadius,
      })),
    );
    expect(after).toEqual(outside);
    expect((await page.getByRole('listbox').evaluate(metrics)).height).toBeGreaterThan(44);
    expect(
      (await page.getByRole('textbox', { name: 'Description' }).evaluate(metrics)).height,
    ).toBeGreaterThan(44);
    expect((await page.getByRole('checkbox').evaluate(metrics)).height).toBeLessThan(44);
  });
}

test('guide target follows the selected group through edits, moves, additions and removals', async ({
  page,
}) => {
  const group = (name) =>
    `<fieldset data-pn-guide-group><legend>Group</legend><label>Title<input data-pn-group-title value="${name}"></label><div data-pn-group-actions></div><ol data-pn-guide-items></ol></fieldset>`;
  await page.route('https://guide.test/**', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<body></body>' }),
  );
  await page.goto('https://guide.test/');
  await page.setContent(
    `<form data-pn-guide-form><div data-pn-guide-groups>${['A', 'B', 'C'].map(group).join('')}</div><select data-pn-guide-target></select><p data-pn-guide-status></p><p data-pn-guide-dirty></p><ul data-pn-guide-catalog></ul><button type="button" data-pn-guide-more>More</button><button type="button" data-pn-add-group>Add group</button><input data-pn-guide-query><button type="button" data-pn-guide-search>Search</button></form>`,
  );
  await page.evaluate(() => {
    window.PageNestGuide = {
      api: 'https://guide.test/api',
      nonce: 'synthetic',
      text: {
        group: 'Group',
        title: 'Title',
        up: 'Up',
        down: 'Down',
        remove: 'Remove',
        add: 'Add',
        preview: 'Preview',
        emptyGroup: 'Empty',
        addGroupFirst: 'Add first',
        unsaved: 'Unsaved',
      },
    };
  });
  await page.route('https://guide.test/api*', (route) =>
    route.fulfill({
      json: {
        items: [{ id: 71, title: 'Article', url: 'https://guide.test/article' }],
        next_page: null,
      },
    }),
  );
  await page.addScriptTag({ path: path.join(__dirname, '../assets/guide-admin.js') });
  const target = page.locator('[data-pn-guide-target]');
  const groups = page.locator('[data-pn-guide-group]');
  const selected = () => target.locator('option:checked');
  await target.selectOption({ label: 'B' });
  await groups.nth(0).getByRole('button', { name: 'Down', exact: true }).click();
  await expect(selected()).toHaveText('B');
  await groups.nth(0).locator('input').fill('B renamed');
  await expect(selected()).toHaveText('B renamed');
  await page.getByRole('button', { name: 'Add group', exact: true }).click();
  await expect(selected()).toHaveText('B renamed');
  await groups.nth(1).getByRole('button', { name: 'Remove', exact: true }).click();
  await expect(selected()).toHaveText('B renamed');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(groups.nth(0).locator('[data-pn-guide-items] input')).toHaveValue('71');
  await groups.nth(0).getByRole('button', { name: 'Remove', exact: true }).first().click();
  await expect(selected()).toHaveText('C');
  while (await groups.count())
    await groups.nth(0).getByRole('button', { name: 'Remove', exact: true }).first().click();
  await expect(target).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Add', exact: true })).toBeDisabled();
});

for (const direction of ['ltr', 'rtl']) {
  test(`current settings tab stays visible after page restoration and resize in ${direction}`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const labels = [
      '阅读互动',
      '搜索与分享',
      '通知',
      '我的阅读',
      '内容导览',
      '模块诊断',
      '媒体与文件夹',
      '社交登录',
    ];
    await page.setContent(
      `<html dir="${direction}"><style>body{margin:16px;background:#f0f0f1;font:16px system-ui}</style><main class="pn-admin"><h1>社交登录</h1><nav class="pn-admin-nav">${labels.map((label, i) => `<a href="#page-${i}"${i === 7 ? ' aria-current="page"' : ''}>${label}</a>`).join('')}</nav><p>选择登录方式并独立保存。</p></main></html>`,
    );
    await page.addStyleTag({ path: path.join(__dirname, '../assets/admin-design.css') });
    await page.evaluate(() => {
      Object.defineProperty(document.fonts, 'ready', {
        value: new Promise((resolve) => (window.resolveFonts = resolve)),
      });
    });
    await page.addScriptTag({ path: script });
    const visible = () =>
      page.locator('.pn-admin-nav').evaluate((nav) => {
        const bounds = nav.getBoundingClientRect();
        const selected = nav.querySelector('[aria-current]').getBoundingClientRect();
        return selected.left >= bounds.left && selected.right <= bounds.right;
      });
    await expect.poll(visible).toBe(true);
    await page.locator('.pn-admin-nav').evaluate((nav) => {
      nav.scrollLeft = 0;
    });
    await page.evaluate(() => window.dispatchEvent(new Event('pageshow')));
    await expect.poll(visible).toBe(true);
    const first = page.locator('.pn-admin-nav a').first();
    const firstVisible = () =>
      first.evaluate((node) => {
        const bounds = node.parentElement.getBoundingClientRect(),
          item = node.getBoundingClientRect();
        return item.left >= bounds.left && item.right <= bounds.right;
      });
    await first.focus();
    await expect.poll(firstVisible).toBe(true);
    await page.evaluate(() => window.resolveFonts());
    await page.evaluate(
      () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    await expect.poll(firstVisible).toBe(true);
    await expect(first).toBeFocused();
    await page.locator('.pn-admin').evaluate((root) => (root.style.fontSize = '24px'));
    await page.setViewportSize({ width: 320, height: 844 });
    await expect.poll(firstVisible).toBe(true);
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
    expect(await page.locator('.pn-admin-nav a').count()).toBe(8);
    expect(
      await page
        .locator('.pn-admin-nav-wrap')
        .evaluate(
          (node) =>
            node.hasAttribute('data-overflow-left') || node.hasAttribute('data-overflow-right'),
        ),
    ).toBe(true);
    await page.screenshot({ path: testInfo.outputPath(`settings-navigation-${direction}.png`) });
  });
}
