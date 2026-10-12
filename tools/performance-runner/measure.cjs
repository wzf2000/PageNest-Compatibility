const { performance } = require('node:perf_hooks');
const { UA_TOKEN, startProxy } = require('./policy.cjs');
const { startGuard } = require('./guard.cjs');

const METRICS = ['ttfb_ms', 'fcp_ms', 'lcp_ms', 'cls', 'long_task_blocking_ms'];
function finite(value) {
  return Number.isFinite(value) && value >= 0 ? Math.round(value * 1000) / 1000 : null;
}

function collect() {
  // The runner has no interactions. Prevent script-created popups and form/anchor
  // submissions before a second page could navigate outside the guarded main target.
  Object.defineProperty(window, 'open', {
    value: () => null,
    writable: false,
    configurable: false,
  });
  for (const name of ['submit', 'requestSubmit'])
    Object.defineProperty(HTMLFormElement.prototype, name, {
      value: () => {},
      writable: false,
      configurable: false,
    });
  for (const name of ['click', 'submit'])
    window.addEventListener(
      name,
      (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
      },
      true,
    );
  const observed = { lcp: null, cls: 0, blocking: 0, support: {} };
  const observe = (type, callback) => {
    try {
      new PerformanceObserver((entries) => entries.getEntries().forEach(callback)).observe({
        type,
        buffered: true,
      });
      observed.support[type] = true;
    } catch {
      observed.support[type] = false;
    }
  };
  observe('largest-contentful-paint', (entry) => {
    observed.lcp = entry.startTime;
  });
  // CLS uses maximum session window: gap <1s, window <=5s. This is a bounded lab sample.
  let windowStart = 0,
    lastShift = 0,
    windowValue = 0;
  observe('layout-shift', (entry) => {
    if (entry.hadRecentInput) return;
    if (
      !lastShift ||
      entry.startTime - lastShift >= 1000 ||
      entry.startTime - windowStart >= 5000
    ) {
      windowStart = entry.startTime;
      windowValue = 0;
    }
    lastShift = entry.startTime;
    windowValue += entry.value;
    observed.cls = Math.max(observed.cls, windowValue);
  });
  observe('longtask', (entry) => {
    observed.blocking += Math.max(0, entry.duration - 50);
  });
  Object.defineProperty(window, '__pagenestPerformanceRead', {
    configurable: false,
    value: () => {
      const nav = performance.getEntriesByType('navigation')[0];
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      return {
        ttfb_ms: nav ? nav.responseStart - nav.startTime : null,
        fcp_ms: fcp?.startTime ?? null,
        lcp_ms: observed.lcp,
        cls: observed.support['layout-shift'] ? observed.cls : null,
        long_task_blocking_ms: observed.support.longtask ? observed.blocking : null,
      };
    },
  });
}

async function runMeasurements(
  job,
  policy,
  {
    chromium,
    executablePath,
    cancelled = () => false,
    onResult = () => {},
    deadline = Date.now() + 720000,
    sampleTimeoutMs = 30000,
  } = {},
) {
  const proxy = await startProxy(policy);
  let browser, server, guard, watchdog, sampleTimer;
  const results = [];
  try {
    server = await chromium.launchServer({
      headless: true,
      executablePath,
      host: '127.0.0.1',
      timeout: Math.max(1, Math.min(30000, deadline - Date.now())),
      ignoreDefaultArgs: ['--disable-popup-blocking'],
      proxy: { server: proxy.url, bypass: '<-loopback>' },
      args: [
        '--disable-quic',
        '--disable-background-networking',
        '--disable-sync',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
      ],
    });
    watchdog = setTimeout(
      () => {
        server.kill().catch(() => {});
      },
      Math.max(1, deadline - Date.now()),
    );
    browser = await chromium.connect(server.wsEndpoint());
    const browserSession = await browser.newBrowserCDPSession();
    const browserInfo = await browserSession.send('Browser.getVersion');
    guard = await startGuard(browser, policy);
    const environment = {
      runner: 'playwright-navigation-v1',
      node_version: process.version,
      chromium_version: browser.version(),
      network: 'server-local',
      cache_mode: 'fresh-context',
    };
    for (const target of job.pages) {
      for (let run = 1; run <= job.profile.repeats; run++) {
        if (cancelled()) return { results, environment, cancelled: true };
        if (Date.now() >= deadline) throw new Error('job_timeout');
        const started = performance.now();
        const row = {
          page_key: target.key,
          run,
          status: 'failed',
          http_status: null,
          ...Object.fromEntries(METRICS.map((key) => [key, null])),
          request_count: 0,
          transfer_bytes: 0,
          failed_requests: 0,
          blocked_requests: 0,
          cache_header: 'unknown',
          duration_ms: 0,
          error: null,
        };
        if (!policy.allowedUrl(target.url, true)) {
          row.error = 'target_blocked';
          results.push(row);
          await onResult(row, environment);
          continue;
        }
        const canonicalUrl = new URL(target.url).href;
        const mobile = job.profile.device === 'mobile';
        const context = await browser.newContext({
          viewport: mobile ? { width: 390, height: 844 } : { width: 1365, height: 768 },
          deviceScaleFactor: mobile ? 2 : 1,
          isMobile: mobile,
          hasTouch: mobile,
          serviceWorkers: 'block',
          acceptDownloads: false,
          permissions: [],
          ignoreHTTPSErrors: policy.fixture,
          userAgent: `${mobile ? `Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${browser.version()} Mobile Safari/537.36` : browserInfo.userAgent} ${UA_TOKEN}`,
        });
        await context.routeWebSocket('**/*', (socket) => {
          row.blocked_requests++;
          socket.close();
        });
        await context.addInitScript(collect);
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        const targetId = (await cdp.send('Target.getTargetInfo')).targetInfo.targetId;
        const frameId = (await cdp.send('Page.getFrameTree')).frameTree.frame.id;
        await guard.bind(cdp, { targetId, frameId, url: canonicalUrl, row });
        let timedOut = false;
        sampleTimer = setTimeout(
          () => {
            timedOut = true;
            context.close().catch(() => {});
          },
          Math.max(1, Math.min(sampleTimeoutMs, deadline - Date.now())),
        );
        cdp.on('Network.loadingFinished', (event) => {
          row.transfer_bytes += Math.max(0, event.encodedDataLength || 0);
        });
        page.on('request', () => {
          row.request_count++;
        });
        page.on('requestfailed', () => {
          row.failed_requests++;
        });
        page.on('popup', (popup) => popup.close().catch(() => {}));
        page.on('download', (download) => download.cancel().catch(() => {}));
        try {
          const timeout = Math.max(1, Math.min(28500, deadline - Date.now() - 1500));
          const response = await page.goto(canonicalUrl, { waitUntil: 'load', timeout });
          row.http_status = response?.status() ?? null;
          const header = response ? (await response.allHeaders())['x-wzf-blog-cache'] : null;
          row.cache_header = ['HIT', 'MISS', 'BYPASS'].includes(header) ? header : 'unknown';
          await page.waitForTimeout(Math.max(0, Math.min(1500, deadline - Date.now())));
          const sample = await page.evaluate(() => window.__pagenestPerformanceRead());
          if (page.url() !== canonicalUrl || !guard.healthy())
            throw new Error('navigation_changed');
          for (const key of METRICS) row[key] = finite(sample[key]);
          if (row.http_status >= 200 && row.http_status < 300) row.status = 'ok';
          else row.error = 'http_error';
        } catch (error) {
          row.error =
            timedOut || error.name === 'TimeoutError' ? 'navigation_timeout' : 'navigation_failed';
        } finally {
          clearTimeout(sampleTimer);
          sampleTimer = null;
          guard.activate(null);
          let closing;
          try {
            await Promise.race([
              context.close(),
              new Promise((resolve) => {
                closing = setTimeout(() => server.kill().then(resolve, resolve), 1500);
              }),
            ]);
          } finally {
            clearTimeout(closing);
          }
        }
        row.duration_ms = finite(performance.now() - started);
        results.push(row);
        await onResult(row, environment);
      }
    }
    return { results, environment, cancelled: false };
  } finally {
    clearTimeout(sampleTimer);
    try {
      if (guard) await guard.close();
    } finally {
      try {
        if (server) await server.kill();
      } finally {
        try {
          if (browser) await browser.close();
        } finally {
          try {
            await proxy.close();
          } finally {
            clearTimeout(watchdog);
          }
        }
      }
    }
  }
}

module.exports = { METRICS, finite, runMeasurements };
