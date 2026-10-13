const { test, expect } = require('@playwright/test');
const path = require('node:path');
const script = path.join(__dirname, '../assets/admin-design.js');
test('settings feedback follows edits, reversions, reset and a media picker hidden field', async ({
  page,
}) => {
  await page.setContent(
    `<main class="pn-admin"><form><input name="nonce" type="hidden" value="fixture"><input id="image" type="hidden" value="0"><input id="toggle" type="checkbox" checked><textarea id="pagenest-description" maxlength="160">阅读笔记</textarea><span data-pn-description-count></span><select id="audience"><option value="all" selected>All</option><option value="guest">Guest</option></select><button type="reset">Reset</button></form></main>`,
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
    '<form id="other"><input value="outside"></form><main class="pn-admin"><form><input id="first" value="one"></form><form><input id="second" value="two"></form></main>',
  );
  await page.addScriptTag({ path: script });
  await expect(page.locator('#other [role=status]')).toHaveCount(0);
  await page.locator('#second').fill('changed');
  await expect(page.locator('.pn-admin form').first().locator('[role=status]')).toBeHidden();
  await expect(page.locator('.pn-admin form').last().locator('[role=status]')).toBeVisible();
});
