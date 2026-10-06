const { test, expect } = require('@playwright/test');
for (const width of [1440, 390]) {
  test(`Prism inline configuration and MathJax ownership at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const errors = [],
      requested = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('request', (r) => requested.push(r.url()));
    await page.route('**/*', (route) =>
      new URL(route.request().url()).origin === 'http://127.0.0.1:18780'
        ? route.continue()
        : route.abort(),
    );
    for (const mode of ['standalone', 'existing']) {
      await page.goto(`/${mode}.html`);
      await expect(page.locator('code .token.keyword').first()).toHaveText('def');
      expect(await page.evaluate(() => Prism.plugins.autoloader.languages_path)).toBe(
        '/prism/components/',
      );
      const fixture = await page.evaluate(() => window.fixture);
      expect(fixture.math_handles).toEqual(
        mode === 'existing' ? ['mbb-math'] : ['pagenest-mathjax'],
      );
      if (mode === 'standalone') {
        expect(fixture.math_sources['pagenest-mathjax']).toContain('/mathjax/2.7.7/');
        expect(fixture.math_inline.before).toHaveLength(1);
      } else expect(fixture.math_inline).toEqual([]);
    }
    expect(requested.some((url) => url.includes('/components/prism-python.min.js'))).toBe(true);
    expect(errors).toEqual([]);
  });
}
