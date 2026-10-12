const { performance } = require('node:perf_hooks');
const { createHash } = require('node:crypto');
const { UA_TOKEN, startProxy } = require('./policy.cjs');
const { startGuard } = require('./guard.cjs');
const { budgets, limitsHash } = require('./budget.cjs');

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

function sampleRow(target, run, phase) {
  return {
    page_key: target.key,
    run,
    cache_phase: phase,
    status: 'failed',
    http_status: null,
    ...Object.fromEntries(METRICS.map((key) => [key, null])),
    request_count: 0,
    transfer_bytes: 0,
    failed_requests: 0,
    blocked_requests: 0,
    browser_cache_requests: 0,
    cache_header: 'unknown',
    duration_ms: 0,
    error: null,
  };
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
    runtimeLimits,
    fixtureLimits = {},
  } = {},
) {
  if (!policy.fixture && !runtimeLimits) throw new Error('runtime_unavailable');
  const limits = budgets(policy, fixtureLimits);
  sampleTimeoutMs = Math.min(sampleTimeoutMs, limits.page_timeout_ms);
  deadline = Math.min(deadline, Date.now() + limits.job_timeout_ms);
  let browser, server, guard, watchdog, sampleTimer, cancellationTimer, activeContext;
  let stopped = null;
  function stop(reason) {
    if (stopped) return;
    stopped = reason;
    if (activeContext) closeBounded(activeContext).catch(() => server?.kill().catch(() => {}));
    else if (server) server.kill().catch(() => {});
  }
  const proxy = await startProxy(policy, {
    maxConnections: limits.connections,
    maxBytes: limits.proxy_bytes_per_job,
    onLimit: () => stop('resource_limit'),
  });
  const results = [];
  async function closeBounded(object) {
    let timer;
    try {
      await Promise.race([
        object.close().catch(() => server?.kill().catch(() => {})),
        new Promise((resolve) => {
          timer = setTimeout(() => server.kill().then(resolve, resolve), 1500);
        }),
      ]);
    } finally {
      clearTimeout(timer);
    }
  }
  try {
    server = await chromium.launchServer({
      headless: true,
      chromiumSandbox: !policy.fixture,
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
        stop('job_timeout');
        server.kill().catch(() => {});
      },
      Math.max(1, deadline - Date.now()),
    );
    browser = await chromium.connect(server.wsEndpoint());
    const browserSession = await browser.newBrowserCDPSession();
    const browserInfo = await browserSession.send('Browser.getVersion');
    guard = await startGuard(browser, policy);
    cancellationTimer = setInterval(() => {
      try {
        if (cancelled()) stop('cancelled');
      } catch {
        stop('runner_failed');
      }
    }, 200);
    const environment = {
      runner: 'playwright-navigation-v3',
      node_version: process.version,
      chromium_version: browser.version(),
      network: 'server-local',
      cache_mode: job.profile.cache_mode,
      sample_window_ms: 1500,
      limits_hash: limitsHash(limits, runtimeLimits, !policy.fixture),
      policy_hash: createHash('sha256')
        .update(
          JSON.stringify({
            guard: 'cdp-anonymous-v2',
            origin: policy.origin,
            hosts: [...policy.hosts].sort(),
            fixture: policy.fixture,
          }),
        )
        .digest('hex'),
    };
    const phases = job.profile.cache_mode === 'paired' ? ['fresh', 'warm'] : ['fresh'];
    for (const target of job.pages) {
      for (let run = 1; run <= job.profile.repeats; run++) {
        if (cancelled()) return { results, environment, cancelled: true };
        if (stopped) throw new Error(stopped);
        if (Date.now() >= deadline) throw new Error('job_timeout');
        if (!policy.allowedUrl(target.url, true)) {
          for (const phase of phases) {
            const row = sampleRow(target, run, phase);
            row.error = phase === 'fresh' ? 'target_blocked' : 'warmup_failed';
            results.push(row);
            await onResult(row, environment);
          }
          continue;
        }
        const canonicalUrl = new URL(target.url).href;
        const mobile = job.profile.device === 'mobile';
        // A pair shares only its anonymous HTTP cache. Every repeated pair starts
        // in a new context; warm navigation never consumes another page's cache.
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
        activeContext = context;
        let currentRow = null;
        let freshSucceeded = false;
        try {
          await context.routeWebSocket('**/*', (socket) => {
            if (currentRow) currentRow.blocked_requests++;
            socket.close();
          });
          await context.addInitScript(collect);
          for (const phase of phases) {
            if (cancelled()) return { results, environment, cancelled: true };
            if (stopped) throw new Error(stopped);
            if (Date.now() >= deadline) throw new Error('job_timeout');
            const started = performance.now();
            const row = sampleRow(target, run, phase);
            currentRow = row;
            if (phase === 'warm' && !freshSucceeded) {
              row.error = 'warmup_failed';
              results.push(row);
              await onResult(row, environment);
              continue;
            }
            // Use a new document/page for warm navigation, retaining the context's
            // HTTP cache, but no DOM or sessionStorage from the first navigation.
            const page = await context.newPage();
            let timedOut = false;
            try {
              const cdp = await context.newCDPSession(page);
              await cdp.send('Network.enable');
              if (phase === 'warm') {
                await cdp.send('Storage.clearDataForOrigin', {
                  origin: new URL(canonicalUrl).origin,
                  // Origin storage clearing is separate from Network.clearBrowserCache.
                  storageTypes: 'all',
                });
              }
              const targetId = (await cdp.send('Target.getTargetInfo')).targetInfo.targetId;
              const frameId = (await cdp.send('Page.getFrameTree')).frameTree.frame.id;
              await guard.bind(cdp, {
                targetId,
                frameId,
                url: canonicalUrl,
                row,
                permitted: () => !stopped,
              });
              sampleTimer = setTimeout(
                () => {
                  timedOut = true;
                  closeBounded(context).catch(() => server.kill().catch(() => {}));
                },
                Math.max(1, Math.min(sampleTimeoutMs, deadline - Date.now())),
              );
              const cached = new Set();
              let mainRequestId = null;
              let mainRevalidated = false;
              cdp.on('Network.requestWillBeSent', (event) => {
                if (event.type === 'Document' && event.request.url === canonicalUrl)
                  mainRequestId = event.requestId;
              });
              cdp.on('Network.responseReceivedExtraInfo', (event) => {
                if (event.requestId === mainRequestId && event.statusCode === 304)
                  mainRevalidated = true;
              });
              const cacheHit = (id) => {
                cached.add(id);
                row.browser_cache_requests = cached.size;
              };
              cdp.on('Network.requestServedFromCache', (event) => cacheHit(event.requestId));
              cdp.on('Network.responseReceived', (event) => {
                if (event.response.fromDiskCache || event.response.fromPrefetchCache)
                  cacheHit(event.requestId);
              });
              cdp.on('Network.loadingFinished', (event) => {
                row.transfer_bytes += Math.max(0, event.encodedDataLength || 0);
              });
              let bodyBytes = 0;
              cdp.on('Network.dataReceived', (event) => {
                bodyBytes += Math.max(event.dataLength || 0, event.encodedDataLength || 0);
                if (bodyBytes > limits.bytes_per_navigation) stop('resource_limit');
              });
              page.on('request', () => {
                row.request_count++;
                if (row.request_count > limits.requests_per_navigation) stop('resource_limit');
              });
              page.on('requestfailed', () => {
                row.failed_requests++;
              });
              page.on('popup', (popup) => popup.close().catch(() => {}));
              page.on('download', (download) => download.cancel().catch(() => {}));
              if (Date.now() + 1500 >= deadline) {
                stop('job_timeout');
                throw new Error('job_timeout');
              }
              const timeout = Math.max(
                1,
                Math.min(28500, sampleTimeoutMs - 1500, deadline - Date.now() - 1500),
              );
              const response = await page.goto(canonicalUrl, { waitUntil: 'load', timeout });
              row.http_status = response?.status() ?? null;
              const header = response ? (await response.allHeaders())['x-wzf-blog-cache'] : null;
              row.cache_header =
                cached.has(mainRequestId) || mainRevalidated
                  ? 'unknown'
                  : ['HIT', 'MISS', 'BYPASS'].includes(header)
                    ? header
                    : 'unknown';
              if (Date.now() + 1500 >= deadline) {
                stop('job_timeout');
                throw new Error('job_timeout');
              }
              await page.waitForTimeout(1500);
              const sample = await page.evaluate(() => window.__pagenestPerformanceRead());
              if (stopped) throw new Error(stopped);
              if (cached.has(mainRequestId) || mainRevalidated) row.cache_header = 'unknown';
              if (page.url() !== canonicalUrl || !guard.healthy())
                throw new Error('navigation_changed');
              for (const key of METRICS) row[key] = finite(sample[key]);
              if (row.http_status >= 200 && row.http_status < 300) row.status = 'ok';
              else row.error = 'http_error';
            } catch (error) {
              if (error.name === 'TimeoutError' && Date.now() + 1500 >= deadline)
                stop('job_timeout');
              row.error =
                stopped === 'resource_limit' || stopped === 'cancelled'
                  ? stopped
                  : error.message === 'job_timeout' || stopped === 'job_timeout'
                    ? 'navigation_timeout'
                    : timedOut || error.name === 'TimeoutError'
                      ? 'navigation_timeout'
                      : 'navigation_failed';
            } finally {
              clearTimeout(sampleTimer);
              sampleTimer = null;
              guard.activate(null);
              await closeBounded(page);
            }
            row.duration_ms = finite(performance.now() - started);
            results.push(row);
            await onResult(row, environment);
            if (stopped === 'cancelled' || cancelled())
              return { results, environment, cancelled: true };
            if (stopped) throw new Error(stopped);
            if (Date.now() >= deadline) throw new Error('job_timeout');
            if (phase === 'fresh') freshSucceeded = row.status === 'ok';
          }
        } finally {
          activeContext = null;
          currentRow = null;
          guard.activate(null);
          await closeBounded(context);
        }
      }
    }
    if (stopped === 'cancelled' || cancelled()) return { results, environment, cancelled: true };
    if (stopped) throw new Error(stopped);
    if (Date.now() >= deadline) throw new Error('job_timeout');
    return { results, environment, cancelled: false };
  } finally {
    clearInterval(cancellationTimer);
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
