/** Exercises the owned PDF viewer adaptation with local vendor files and synthetic documents. */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const origin = 'https://pdf-fixture.test';
const viewer = '/assets/vendor/pdfjs-6.4.299/web/viewer.html';
const stylesheet =
  '/assets/' +
  fs
    .readFileSync(path.join(root, 'assets/manifest.php'), 'utf8')
    .match(/'pdf_viewer_css' => '([^']+)'/)[1];
function pdf() {
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 7 0 R >> >> /Contents 4 0 R >>',
    null,
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 7 0 R >> >> /Contents 6 0 R >>',
    null,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  for (const [i, n] of [
    [3, 1],
    [5, 2],
  ]) {
    const s = `BT /F1 24 Tf 60 740 Td (PageNest PDF audit - Page ${n}) Tj 0 -50 Td /F1 14 Tf (Synthetic public material. No private content.) Tj ET`;
    objs[i] = `<< /Length ${s.length} >>\nstream\n${s}\nendstream`;
  }
  let s = '%PDF-1.4\n',
    offsets = [0];
  objs.forEach((o, i) => {
    offsets.push(Buffer.byteLength(s));
    s += `${i + 1} 0 obj\n${o}\nendobj\n`;
  });
  const x = Buffer.byteLength(s);
  s +=
    `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` +
    offsets
      .slice(1)
      .map((o) => `${String(o).padStart(10, '0')} 00000 n \n`)
      .join('') +
    `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF`;
  return Buffer.from(s);
}

async function fixture(page, width, extra = '') {
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin !== origin)
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Other origin</title>',
      });
    if (url.pathname === '/sample.pdf')
      return route.fulfill({ contentType: 'application/pdf', body: pdf() });
    if (url.pathname === '/fixture')
      return route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:12px}iframe{display:block;width:${width}px;max-width:100%;height:700px;border:0}</style><div class="pagenest-media-pdf"><iframe class="pagenest-media-pdf-frame" data-pagenest-pdf-viewer title="Owned PDF" src="${viewer}?file=/sample.pdf"></iframe>${extra}</div><script>window.PageNestMedia=${JSON.stringify({ pdfViewerUrl: origin + viewer, pdfViewerStyles: origin + stylesheet })}</script><script src="/assets/media-compatibility.js"></script>`,
      });
    if (url.pathname === '/foreign-viewer.html')
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Unrelated same-origin viewer</title>',
      });
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname));
    if (!file.startsWith(root + '/assets/') || !fs.existsSync(file)) return route.abort();
    const types = {
      '.js': 'text/javascript',
      '.css': 'text/css',
      '.html': 'text/html',
      '.json': 'application/json',
      '.ftl': 'text/plain',
      '.svg': 'image/svg+xml',
      '.wasm': 'application/wasm',
      '.ttf': 'font/ttf',
    };
    return route.fulfill({
      contentType: types[path.extname(file)] || 'application/octet-stream',
      body: fs.readFileSync(file),
    });
  });
  await page.goto(origin + '/fixture');
  const frame = await page
    .getByTitle('Owned PDF', { exact: true })
    .elementHandle()
    .then((element) => element.contentFrame());
  await frame.waitForFunction(
    () =>
      window.PDFViewerApplication?.pagesCount === 2 &&
      document.querySelector('link[data-pagenest-pdf-style]')?.sheet,
  );
  return frame;
}
for (const width of [900, 348, 238, 220]) {
  test(`PDF controls, pages, zoom and download remain available in a ${width}px frame`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: Math.max(390, width + 24), height: 844 });
    const frame = await fixture(page, width);
    const geometry = await frame.locator('#toolbarViewer button').evaluateAll((buttons) =>
      buttons
        .filter((button) => button.getClientRects().length)
        .map((button) => {
          const box = button.getBoundingClientRect();
          return { id: button.id, left: box.left, right: box.right, bottom: box.bottom };
        }),
    );
    const start = await frame
      .locator('#viewerContainer')
      .evaluate((node) => node.getBoundingClientRect().top);
    for (const control of geometry) {
      expect(control.left, control.id).toBeGreaterThanOrEqual(-1);
      expect(control.right, control.id).toBeLessThanOrEqual(width + 1);
      expect(control.bottom, control.id).toBeLessThanOrEqual(start);
    }
    await frame.locator('#pageNumber').fill('2');
    await frame.locator('#pageNumber').press('Enter');
    await expect.poll(() => frame.evaluate(() => PDFViewerApplication.page)).toBe(2);
    const scale = await frame.evaluate(() => PDFViewerApplication.pdfViewer.currentScale);
    await frame.locator('#zoomInButton').click();
    await expect
      .poll(() => frame.evaluate(() => PDFViewerApplication.pdfViewer.currentScale))
      .toBeGreaterThan(scale);
    await frame.locator('#secondaryToolbarToggleButton').click();
    const save = (await frame.locator('#secondaryDownload').isVisible())
      ? frame.locator('#secondaryDownload')
      : frame.locator('#downloadButton');
    await expect(save).toBeVisible();
    const menu = await frame
      .locator('#secondaryToolbar')
      .evaluate((node) => node.getBoundingClientRect().toJSON());
    expect(menu.x).toBeGreaterThanOrEqual(-1);
    expect(menu.x + menu.width).toBeLessThanOrEqual(width + 1);
    const event = page.waitForEvent('download');
    await save.click();
    const download = await event;
    const location = await download.path();
    expect(fs.readFileSync(location)).toEqual(pdf());
    if (width === 238) {
      await frame.evaluate(() => (document.documentElement.dataset.toolbarDensity = 'touch'));
      const more = await frame
        .locator('#secondaryToolbarToggleButton')
        .evaluate((node) => node.getBoundingClientRect().toJSON());
      const bodyStart = await frame
        .locator('#viewerContainer')
        .evaluate((node) => node.getBoundingClientRect().top);
      expect(more.x + more.width).toBeLessThanOrEqual(width + 1);
      expect(more.y + more.height).toBeLessThanOrEqual(bodyStart);
    }
  });
}
test('PDF adaptation leaves unowned, unrelated and cross-origin frames alone', async ({ page }) => {
  await fixture(
    page,
    348,
    `<iframe class="pagenest-media-pdf-frame" title="Unowned PDF" src="${viewer}?file=/sample.pdf"></iframe><iframe class="pagenest-media-pdf-frame" data-pagenest-pdf-viewer title="Other path" src="/foreign-viewer.html"></iframe><iframe class="pagenest-media-pdf-frame" data-pagenest-pdf-viewer title="Other origin" src="https://other-fixture.test${viewer}"></iframe>`,
  );
  for (const title of ['Unowned PDF', 'Other path', 'Other origin']) {
    const frame = await page
      .getByTitle(title, { exact: true })
      .elementHandle()
      .then((element) => element.contentFrame());
    await expect(frame.locator('link[data-pagenest-pdf-style]')).toHaveCount(0);
  }
});
