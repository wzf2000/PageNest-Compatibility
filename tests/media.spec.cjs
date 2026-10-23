/** Standalone synthetic media tests: no WordPress, server, remote media or plugin state. */
const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '..');
const picture =
  'data:image/svg+xml,' +
  encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60"><rect width="60" height="60" fill="blue"/></svg>',
  );
async function fixture(page, enhance = true, count = 6) {
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>${fs.readFileSync(path.join(root, 'assets/media-compatibility.css'), 'utf8')}</style></head><body>
    <button id="outside">Outside gallery</button>
    <section class="pagenest-media pagenest-media--slider" aria-label="Image gallery"
      data-pagenest-media='{"layout":"slider","columns":3,"autoplay":true,"interval":2000,"lightbox":true}'>
      <div class="pagenest-media-track">${Array.from({ length: count }, (_, index) => `<figure class="pagenest-media-item" data-attachment-id="${index + 1}"><a class="pagenest-media-link" href="${picture}" data-pagenest-lightbox="1" data-caption="Image ${index + 1}"><img class="pagenest-media-image" src="${picture}" alt="Image ${index + 1}"></a></figure>`).join('')}</div>
    </section></body></html>`);
  if (enhance) await page.addScriptTag({ path: path.join(root, 'assets/media-compatibility.js') });
}
async function aligned(page, index) {
  await expect
    .poll(() =>
      page
        .locator('.pagenest-media-item:not([data-media-clone])')
        .nth(index)
        .evaluate((item) => {
          const track = item.parentElement.parentElement;
          return Math.abs(item.getBoundingClientRect().left - track.getBoundingClientRect().left);
        }),
    )
    .toBeLessThan(3);
}
async function columns(page, count) {
  const widths = await page.locator('.pagenest-media-track').evaluate((track) => ({
    track: track.getBoundingClientRect().width,
    item: track.firstElementChild.getBoundingClientRect().width,
    gap: parseFloat(getComputedStyle(track).gap),
  }));
  expect(Math.abs(widths.track - (widths.item * count + widths.gap * (count - 1)))).toBeLessThan(3);
}

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('without enhancement every image remains visible with an original file link', async ({
    page,
  }) => {
    await fixture(page, false);
    await expect(page.locator('.pagenest-media-item')).toHaveCount(6);
    for (const link of await page.locator('.pagenest-media-link').all()) {
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('href', picture);
      await expect(link.locator('img')).toBeVisible();
    }
    await expect(page.locator('.pagenest-media-controls')).toHaveCount(0);
  });
});

test('desktop shows three images and advances three at a time, including wrap', async ({
  page,
}) => {
  await fixture(page);
  await columns(page, 3);
  await aligned(page, 0);
  await page.getByRole('button', { name: 'Next images', exact: true }).click();
  await aligned(page, 3);
  await page.getByRole('button', { name: 'Next images', exact: true }).click();
  await aligned(page, 0);
  await page.getByRole('button', { name: 'Previous images', exact: true }).click();
  await aligned(page, 3);
});

test('mobile below 600px shows and advances one image', async ({ page }) => {
  await page.setViewportSize({ width: 500, height: 900 });
  await fixture(page);
  await columns(page, 1);
  await page.getByRole('button', { name: 'Next images', exact: true }).click();
  await aligned(page, 1);
  await page.getByRole('button', { name: 'Previous images', exact: true }).click();
  await aligned(page, 0);
});

test('lightbox supports keyboard navigation, Escape and focus return', async ({ page }) => {
  await fixture(page);
  const link = page.locator('[data-pagenest-lightbox]').first();
  await link.click();
  const dialog = page.getByRole('dialog', { name: 'Image viewer' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('1 / 6', { exact: true })).toBeVisible();
  await page.keyboard.press('ArrowRight');
  await expect(dialog.getByText('2 / 6', { exact: true })).toBeVisible();
  await expect(dialog.locator('.pagenest-media-lightbox-image')).toHaveAttribute('alt', 'Image 2');
  await page.keyboard.press('ArrowLeft');
  await expect(dialog.getByText('1 / 6', { exact: true })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(link).toBeFocused();
});

test('pause prevents automatic progression, and play restores the two-second interval', async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.clock.install();
  await fixture(page);
  await page.getByRole('button', { name: 'Pause slideshow', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Play slideshow', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.locator('#outside').focus();
  await page.mouse.move(0, 0);
  await page.clock.runFor(2500);
  await aligned(page, 0);
  await page.getByRole('button', { name: 'Play slideshow', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Pause slideshow', exact: true })).toHaveAttribute(
    'aria-pressed',
    'false',
  );
  await page.locator('#outside').focus();
  await page.mouse.move(0, 0);
  await page.clock.runFor(1900);
  await aligned(page, 0);
  await page.clock.runFor(500);
  await aligned(page, 3);
});

test('reduced motion stops autoplay', async ({ page }) => {
  await page.clock.install();
  await fixture(page);
  await page.locator('#outside').focus();
  await page.mouse.move(0, 0);
  await page.clock.runFor(6500);
  await aligned(page, 0);
});

test('normal motion slides forward through the seam and resets without a reverse animation', async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await fixture(page);
  const next = page.getByRole('button', { name: 'Next images', exact: true });
  await next.click();
  await aligned(page, 3);
  const track = page.locator('.pagenest-media-track');
  const x = () =>
    track.evaluate((node) => new DOMMatrixReadOnly(getComputedStyle(node).transform).m41);
  const before = await x();
  await next.click();
  await page.waitForTimeout(160);
  const during = await x();
  expect(during).toBeLessThan(before - 10);
  const transition = await track.evaluate((node) => ({
    duration: getComputedStyle(node).transitionDuration,
    easing: getComputedStyle(node).transitionTimingFunction,
  }));
  expect(transition).toEqual({ duration: '0.5s', easing: 'ease' });
  await aligned(page, 0);
  expect(await x()).toBeGreaterThan(before);
  expect(await track.evaluate((node) => getComputedStyle(node).transitionDuration)).toBe('0s');
  await page.getByRole('button', { name: 'Previous images', exact: true }).click();
  await page.waitForTimeout(160);
  expect(await x()).toBeGreaterThan(before);
  await aligned(page, 3);
});

test('clones do not duplicate ids or keyboard stops; focus remains usable after navigation', async ({
  page,
}) => {
  await fixture(page, false);
  await page
    .locator('.pagenest-media-link')
    .first()
    .evaluate((link) => {
      link.id = 'original-image';
    });
  await page.addScriptTag({ path: path.join(root, 'assets/media-compatibility.js') });
  await expect(page.locator('#original-image')).toHaveCount(1);
  const clones = page.locator('[data-media-clone]');
  await expect(clones).toHaveCount(12);
  for (const clone of await clones.all()) {
    await expect(clone).toHaveAttribute('aria-hidden', 'true');
    await expect(clone.locator('a')).toHaveAttribute('tabindex', '-1');
  }
  const link = page.locator('#original-image');
  await link.focus();
  await page.keyboard.press('ArrowRight');
  await aligned(page, 3);
  await expect(page.getByRole('button', { name: 'Next images', exact: true })).toBeFocused();
  await expect(link.locator('..')).toHaveAttribute('inert', '');
  await page.locator('[data-pagenest-lightbox]').nth(3).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('button', { name: 'Close viewer' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(dialog.getByRole('button', { name: 'Previous image' })).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(dialog.getByRole('button', { name: 'Close viewer' })).toBeFocused();
});

test('mobile swipes advance the slider and the modal without horizontal page overflow', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page);
  const swipe = async (locator) => {
    await locator.dispatchEvent('pointerdown', {
      pointerType: 'touch',
      isPrimary: true,
      clientX: 250,
      clientY: 150,
    });
    await locator.dispatchEvent('pointerup', {
      pointerType: 'touch',
      isPrimary: true,
      clientX: 100,
      clientY: 155,
    });
  };
  await swipe(page.locator('.pagenest-media-viewport'));
  await aligned(page, 1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
  await page.locator('[data-pagenest-lightbox]').nth(1).click();
  await swipe(page.locator('.pagenest-media-lightbox-image'));
  await expect(page.getByRole('dialog').getByText('3 / 6', { exact: true })).toBeVisible();
  const box = await page.getByRole('dialog').boundingBox();
  expect(box.width).toBeLessThanOrEqual(390);
  expect(box.height).toBeLessThanOrEqual(844);
});

test('localized icon controls retain accessible names', async ({ page }) => {
  await fixture(page, false);
  await page.locator('[data-pagenest-media]').evaluate((gallery) => {
    const options = JSON.parse(gallery.dataset.pagenestMedia);
    options.labels = {
      nextImages: '下一组图片',
      imageViewer: '图片查看器',
      closeViewer: '关闭查看器',
    };
    gallery.dataset.pagenestMedia = JSON.stringify(options);
  });
  await page.addScriptTag({ path: path.join(root, 'assets/media-compatibility.js') });
  await expect(page.getByRole('button', { name: '下一组图片' })).toBeVisible();
  await page.locator('[data-pagenest-lightbox]').first().click();
  await expect(
    page.getByRole('dialog', { name: '图片查看器' }).getByRole('button', { name: '关闭查看器' }),
  ).toBeVisible();
});

test('a partial final group keeps every visible image accessible and clones open the right original', async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await fixture(page, true, 7);
  const next = page.getByRole('button', { name: 'Next images', exact: true });
  await next.click();
  await aligned(page, 3);
  await next.click();
  await aligned(page, 6);
  const visibleLinks = page.getByRole('link');
  await expect(visibleLinks).toHaveCount(3);
  const copy = page.locator('[data-media-clone="0"]:not([inert]) a');
  await expect(copy).toHaveAttribute('tabindex', '0');
  await copy.click();
  await expect(page.getByRole('dialog').getByText('1 / 7', { exact: true })).toBeVisible();
  await page.keyboard.press('Escape');
  await aligned(page, 0);
  await expect(page.locator('[data-pagenest-lightbox]').first()).toBeFocused();
});

test('PDF attachment chrome follows host tokens and retains independent fallbacks', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.setContent(
    `<style>${fs.readFileSync(path.join(root, 'assets/media-compatibility.css'), 'utf8')}</style><div class="pagenest-media-pdf"><iframe class="pagenest-media-pdf-frame" title="PDF" srcdoc="<p>Paper</p>"></iframe><p><a href="#file">Download PDF</a></p></div>`,
  );
  await expect(page.locator('.pagenest-media-pdf')).toHaveCSS(
    'background-color',
    'rgb(244, 247, 251)',
  );
  await page.addStyleTag({
    content:
      ':root{--pagenest-soft:#2c3745;--pagenest-border:#465363;--pagenest-text:#dce3ec;--pagenest-content-link:#a5cdf8}',
  });
  await expect(page.locator('.pagenest-media-pdf')).toHaveCSS(
    'background-color',
    'rgb(44, 55, 69)',
  );
  await expect(page.locator('.pagenest-media-pdf > p')).toHaveCSS('color', 'rgb(220, 227, 236)');
  await expect(page.locator('.pagenest-media-pdf a')).toHaveCSS('color', 'rgb(165, 205, 248)');
  await page.addStyleTag({
    content:
      ':root{--pagenest-soft:#eef1eb;--pagenest-text:#293630;--pagenest-content-link:#426354}',
  });
  await expect(page.locator('.pagenest-media-pdf')).toHaveCSS(
    'background-color',
    'rgb(238, 241, 235)',
  );
});
