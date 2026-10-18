const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
test('share preview treats overrides as text and the picker clears safely', async ({ page }) => {
  await page.goto('/standalone.html');
  await page.setContent(
    '<div data-pn-seo-editor><input data-pn-seo-title placeholder="Article title"><textarea data-pn-seo-description placeholder="Site description"></textarea><input data-pn-seo-image type="hidden" value="0"><button data-pn-seo-select>Choose image</button><button data-pn-seo-clear>Use default</button><section class="pn-seo-preview"><img data-pn-seo-preview-image data-pn-seo-default-image="http://127.0.0.1:18780/default.png" hidden alt=""><strong data-pn-seo-preview-title></strong><p data-pn-seo-preview-description></p></section></div>',
  );
  await page.evaluate(() => {
    window.PageNestSEOEditor = { choose: 'Choose image' };
    const frame = {
      on(name, callback) {
        this.selected = callback;
      },
      open() {
        this.selected();
      },
      state() {
        return {
          get() {
            return {
              first() {
                return {
                  toJSON() {
                    return { id: 9, type: 'image', url: location.origin + '/image.png' };
                  },
                };
              },
            };
          },
        };
      },
    };
    window.wp = { media: () => frame };
  });
  await page.addScriptTag({
    content: fs.readFileSync(path.resolve(__dirname, '../assets/seo-editor.js'), 'utf8'),
  });
  await expect(page.locator('[data-pn-seo-preview-title]')).toHaveText('Article title');
  await page.locator('[data-pn-seo-title]').fill('<img src=x onerror=alert(1)>');
  await expect(page.locator('[data-pn-seo-preview-title]')).toHaveText(
    '<img src=x onerror=alert(1)>',
  );
  await expect(page.locator('[data-pn-seo-preview-title] img')).toHaveCount(0);
  await page.getByRole('button', { name: 'Choose image' }).click();
  await expect(page.locator('[data-pn-seo-image]')).toHaveValue('9');
  await page.getByRole('button', { name: 'Use default' }).click();
  await expect(page.locator('[data-pn-seo-image]')).toHaveValue('0');
  await expect(page.locator('[data-pn-seo-preview-image]')).toBeVisible();
  await expect(page.locator('[data-pn-seo-preview-image]')).toHaveAttribute(
    'src',
    'http://127.0.0.1:18780/default.png',
  );
});
