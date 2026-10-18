const { test, expect } = require('@playwright/test');
for (const width of [1440, 390]) {
  test(`code note keeps code height and remains operable at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/paragraph.html', async (route) => {
      const response = await route.fetch();
      const code = Array.from({ length: 32 }, (_, i) => `    value_${i} = ${i}`).join('\n');
      await route.fulfill({
        response,
        body: (await response.text()).replace(
          '</main>',
          `<pre data-pagenest-block="b-code" style="padding:20px;overflow:auto"><code style="display:block">${code}</code></pre></main>`,
        ),
      });
    });
    await page.route('**/api/comments-page?post=10', (route) =>
      route.fulfill({ json: { items: [], next_cursor: null } }),
    );
    await page.goto('/paragraph.html');
    const code = page.locator('pre[data-pagenest-block="b-code"]');
    const bubble = code.locator(':scope > .pagenest-paragraph-bubble');
    await expect(bubble).toHaveCSS('position', 'absolute');
    const heights = await code.evaluate((pre) => {
      const note = pre.querySelector('.pagenest-paragraph-bubble');
      const before = pre.getBoundingClientRect().height;
      note.remove();
      const after = pre.getBoundingClientRect().height;
      pre.append(note);
      return { before, after };
    });
    expect(heights.before).toBe(heights.after);
    await code.hover();
    await bubble.click();
    await expect(page.getByRole('dialog', { name: '段落评论' })).toBeVisible();
    await page.getByRole('button', { name: '关闭评论', exact: true }).click();
    await expect(bubble).toBeFocused();
  });
}
for (const width of [1440, 390]) {
  test(`standalone paragraph drawer at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    let reads = 0;
    await page.route('**/api/comments-page?post=10', async (route) => {
      reads++;
      await route.fulfill({
        json: {
          items: [
            {
              id: 9,
              post_id: 10,
              block_id: 'b-neutral',
              quote: 'A stable synthetic paragraph.',
              text: 'Public synthetic comment',
              author_name: 'Synthetic reader',
              author_avatar_url: '',
              can_edit: false,
              association: 'attached',
              created_at: '2026-01-01T00:00:00Z',
            },
          ],
          next_cursor: null,
        },
      });
    });
    await page.goto('/paragraph.html');
    await expect(page.locator('.pagenest-paragraph-bubble')).toBeVisible();
    await page.locator('.pagenest-paragraph-bubble').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.locator('.pagenest-paragraph-text')).toHaveText('Public synthetic comment');
    await expect(page.locator('.pagenest-paragraph-login')).toBeVisible();
    await expect(page.locator('.pagenest-paragraph-edit')).toHaveCount(0);
    await expect(page.locator('.pagenest-paragraph-input')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(
      false,
    );
    await page.getByRole('button', { name: '关闭评论' }).click();
    await page.locator('.pagenest-paragraph-bubble').click();
    expect(reads).toBe(1);
    await page.evaluate(() => {
      const real = Date.now;
      Date.now = () => real() + 31000;
      window.dispatchEvent(new Event('focus'));
    });
    await expect.poll(() => reads).toBe(2);
    expect(errors).toEqual([]);
  });
}
test('explicit publish and auth loss clear drafts', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'PageNestComments', {
      configurable: true,
      set(value) {
        Object.defineProperty(window, 'PageNestComments', {
          value: { ...value, nonce: 'synthetic-nonce', user: 1, displayName: 'Synthetic reader' },
          writable: true,
          configurable: true,
        });
      },
    });
  });
  let created;
  await page.route('**/api/comments*', async (route) => {
    if (route.request().method() === 'GET')
      return route.fulfill({ json: { items: [], next_cursor: null } });
    created = route.request().postDataJSON();
    await route.fulfill({
      json: {
        id: 9,
        block_id: 'b-neutral',
        quote: 'A stable synthetic paragraph.',
        text: created.text,
        author_name: 'Synthetic reader',
        can_edit: true,
        version: 'opaque',
        association: 'attached',
        created_at: '2026-01-01T00:00:00Z',
      },
    });
  });
  await page.goto('/paragraph.html');
  await page.locator('.pagenest-paragraph-bubble').click();
  await page.locator('.pagenest-paragraph-input').fill('A deliberate public comment');
  expect(created).toBeUndefined();
  await page.getByRole('button', { name: '发布公开评论' }).click();
  await expect(page.locator('.pagenest-paragraph-text')).toHaveText('A deliberate public comment');
  expect(created.public_confirmed).toBe(true);
  expect(created.post_id).toBe(10);
  await page.locator('.pagenest-paragraph-input').fill('Unsent draft');
  await page.evaluate(() => document.dispatchEvent(new Event('pagenest-pc-auth-change')));
  await expect(page.locator('.pagenest-paragraph-input')).toHaveValue('');
  await expect(page.locator('.pagenest-paragraph-text')).toHaveCount(0);
});

for (const width of [1440, 390]) {
  test(`return to a teaching paragraph reveals it before focus at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route('**/api/comments*', (route) =>
      route.fulfill({ json: { items: [], next_cursor: null } }),
    );
    await page.goto('/paragraph.html');
    await page.locator('.pagenest-paragraph-bubble').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.evaluate(() => {
      const node = document.querySelector('[data-pagenest-block="b-neutral"]');
      const stage = document.createElement('div');
      stage.id = 'synthetic-hidden-stage';
      node.before(stage);
      stage.append(node);
      stage.hidden = true;
      window.MarkBridgeTeaching = {
        revealTarget(target) {
          window.fixtureRevealed = target === node;
          stage.hidden = false;
        },
      };
    });
    await page.getByRole('button', { name: '回到原文' }).click();
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(page.locator('#synthetic-hidden-stage')).toBeVisible();
    await expect(page.locator('.pagenest-paragraph-bubble')).toBeFocused();
    expect(await page.evaluate(() => window.fixtureRevealed)).toBe(true);
    await page.screenshot({ path: `.runtime/teaching-paragraph-${width}.png` });
  });
}
