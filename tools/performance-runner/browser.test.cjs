const assert = require('node:assert/strict');
const http = require('node:http');
const https = require('node:https');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createHash, X509Certificate } = require('node:crypto');
const { chromium } = require('@playwright/test');
const { makePolicy, UA_TOKEN } = require('./policy.cjs');
const { runMeasurements } = require('./measure.cjs');
const { job } = require('./test.cjs');

async function main() {
  const requests = [];
  const certificates = fs.mkdtempSync(path.join(os.tmpdir(), 'pagenest-speed-tls-'));
  execFileSync(
    'openssl',
    [
      'req',
      '-x509',
      '-newkey',
      'rsa:2048',
      '-nodes',
      '-keyout',
      path.join(certificates, 'key.pem'),
      '-out',
      path.join(certificates, 'cert.pem'),
      '-days',
      '1',
      '-subj',
      '/CN=localhost',
    ],
    { stdio: 'ignore' },
  );
  const handler = (request, response) => {
    requests.push({
      path: request.url,
      method: request.method,
      cookie: request.headers.cookie,
      cacheControl: request.headers['cache-control'],
      validator: request.headers['if-none-match'],
      pragma: request.headers.pragma,
      ua: request.headers['user-agent'],
    });
    if (request.url === '/redirect/') {
      response.writeHead(302, { location: '/wp-login.php' });
      response.end();
      return;
    }
    if (request.url === '/redirect.js') {
      response.writeHead(302, { location: '/wp-json/synthetic-write' });
      response.end();
      return;
    }
    if (request.url === '/late.js') {
      response.writeHead(200, { 'content-type': 'text/javascript' });
      response.end('window.syntheticLate = true;');
      return;
    }
    if (request.url === '/hang/') {
      response.writeHead(200, { 'content-type': 'text/html' });
      response.end('<h1>Hang fixture</h1><script>setTimeout(()=>{while(true){}},100)</script>');
      return;
    }
    if (request.url === '/client-redirect/') {
      response.writeHead(200, { 'content-type': 'text/html' });
      response.end(
        '<h1>Navigation fixture</h1><script>setTimeout(()=>location.replace("/other-public/"),100)</script>',
      );
      return;
    }
    if (request.url === '/asset.css') {
      response.writeHead(200, {
        'content-type': 'text/css',
        'cache-control': 'public,max-age=3600',
      });
      response.end('h1 { color: #125; font-size: 48px; }');
      return;
    }
    if (request.url === '/cached.css') {
      response.writeHead(200, {
        'content-type': 'text/css',
        'cache-control': 'public,max-age=3600',
      });
      response.end('h1 { color: #125; font-size: 48px; }' + '/* cache fixture */'.repeat(1000));
      return;
    }
    if (request.url === '/revalidate.css') {
      response.writeHead(request.headers['if-none-match'] === '"fixture-v1"' ? 304 : 200, {
        'content-type': 'text/css',
        'cache-control': 'public,max-age=0,must-revalidate',
        etag: '"fixture-v1"',
      });
      response.end(request.headers['if-none-match'] ? '' : 'h1 { margin: 24px; }');
      return;
    }
    if (request.url === '/cache-html/') {
      response.writeHead(200, {
        'content-type': 'text/html',
        'cache-control': 'public,max-age=3600',
        'x-wzf-blog-cache': 'MISS',
      });
      response.end(
        '<!doctype html><h1>Cached HTML fixture</h1><link rel="stylesheet" href="/cached.css">',
      );
      return;
    }
    if (request.url === '/cache-pair/') {
      response.writeHead(200, {
        'content-type': 'text/html',
        'cache-control': 'private,no-store',
        'x-wzf-blog-cache': 'HIT',
        'set-cookie': 'synthetic=fixture; Path=/',
      });
      response.end(`<!doctype html><html><head><link rel="stylesheet" href="/cached.css"><link rel="stylesheet" href="/revalidate.css"></head><body><h1>Cache pair</h1><script>
        if (localStorage.getItem('synthetic')) location.replace('/other-public/');
        localStorage.setItem('synthetic', 'fixture');
        document.cookie = 'late=fixture';
        fetch('/submit/', {method:'POST', body:'synthetic'}).catch(()=>{});
        navigator.sendBeacon('/beacon/', 'synthetic');
        window.open('/wp-admin/');
      </script></body></html>`);
      return;
    }
    response.writeHead(200, {
      'content-type': 'text/html',
      'x-wzf-blog-cache': 'HIT',
      'set-cookie': 'synthetic=fixture; Path=/',
    });
    response.end(`<!doctype html><html><head><link rel="stylesheet" href="/asset.css"></head><body><h1>Synthetic representative page</h1><p>Browser rendering fixture.</p><iframe src="/wp-login.php"></iframe><img src="http://169.254.169.254/latest/meta-data/"><script src="/redirect.js"></script><script>
      fetch('/submit/', {method:'POST', body:'synthetic'}).catch(()=>{});
      fetch('/read-api/').catch(()=>{});
      navigator.sendBeacon('/beacon/', 'synthetic');
      document.cookie = 'late=fixture';
      const late = document.createElement('script'); late.src = '/late.js'; document.head.appendChild(late);
      window.open('/wp-admin/');
      const worker = new Worker('/late.js');
    </script></body></html>`);
  };
  const servers = [
    http.createServer(handler),
    https.createServer(
      {
        key: fs.readFileSync(path.join(certificates, 'key.pem')),
        cert: fs.readFileSync(path.join(certificates, 'cert.pem')),
      },
      handler,
    ),
  ];
  for (const server of servers)
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  // Generic TLS-error bypass can prevent Chromium from caching responses. Restrict
  // this test exception to our ephemeral fixture public key; CLI has no such option.
  const spki = new X509Certificate(
    fs.readFileSync(path.join(certificates, 'cert.pem')),
  ).publicKey.export({ type: 'spki', format: 'der' });
  const fixtureChromium = {
    launchServer: (options) =>
      chromium.launchServer({
        ...options,
        args: [
          ...options.args,
          '--ignore-certificate-errors-spki-list=' +
            createHash('sha256').update(spki).digest('base64'),
        ],
      }),
    connect: (...args) => chromium.connect(...args),
  };
  try {
    for (let i = 0; i < servers.length; i++) {
      const origin = `${i ? 'https' : 'http'}://127.0.0.1:${servers[i].address().port}`;
      const policy = makePolicy(origin, [], true);
      const testChromium = i ? fixtureChromium : chromium;
      for (const device of ['desktop', 'mobile']) {
        const fixture = job();
        fixture.origin = origin;
        fixture.profile.device = device;
        fixture.pages[0].url = origin + '/';
        fixture.pages.push({
          key: 'post:2',
          post_id: 2,
          url: origin + '/redirect/',
          title: 'Redirect safety',
          reason: 'fixture',
        });
        const outcome = await runMeasurements(fixture, policy, {
          chromium: testChromium,
          deadline: Date.now() + 60000,
        });
        assert.equal(outcome.results.length, 2);
        const success = outcome.results[0];
        assert.equal(success.status, 'ok');
        assert.equal(success.http_status, 200);
        assert.equal(success.cache_header, 'HIT');
        assert.ok(success.ttfb_ms >= 0);
        assert.ok(success.fcp_ms > 0);
        assert.ok(success.lcp_ms > 0);
        assert.ok(success.transfer_bytes > 0);
        assert.ok(success.blocked_requests >= 4);
        assert.equal(outcome.results[1].status, 'failed');
        assert.equal(outcome.environment.cache_mode, 'fresh-context');
        assert.equal(outcome.environment.runner, 'playwright-navigation-v3');
        assert.equal(success.cache_phase, 'fresh');
        assert.equal(outcome.environment.sample_window_ms, 1500);
        assert.match(outcome.environment.policy_hash, /^[a-f0-9]{64}$/);
        assert.match(outcome.environment.limits_hash, /^[a-f0-9]{64}$/);
      }
      const paired = job();
      paired.origin = origin;
      paired.profile.device = i ? 'desktop' : 'mobile';
      paired.profile.cache_mode = 'paired';
      paired.profile.repeats = i ? 3 : 1;
      paired.pages[0].url = origin + '/cache-pair/';
      const offset = requests.length;
      const pairs = await runMeasurements(paired, policy, {
        chromium: testChromium,
        deadline: Date.now() + 60000,
      });
      assert.equal(pairs.environment.cache_mode, 'paired');
      assert.equal(pairs.results.length, paired.profile.repeats * 2);
      for (let run = 1; run <= paired.profile.repeats; run++) {
        const first = pairs.results.find((row) => row.run === run && row.cache_phase === 'fresh');
        const warm = pairs.results.find((row) => row.run === run && row.cache_phase === 'warm');
        assert.equal(first.status, 'ok');
        assert.equal(warm.status, 'ok', 'warm page must not retain localStorage login/state');
        assert.equal(first.browser_cache_requests, 0);
        assert.ok(warm.browser_cache_requests >= 1, 'warm CSS served from browser HTTP cache');
        assert.ok(warm.browser_cache_requests <= warm.request_count);
        assert.ok(
          first.transfer_bytes > warm.transfer_bytes,
          'cached response bytes excluded from transfer',
        );
        assert.equal(first.cache_header, 'HIT');
        assert.equal(
          warm.cache_header,
          'HIT',
          'origin cache header is distinct from browser cache',
        );
      }
      const pairRequests = requests.slice(offset);
      assert.equal(
        pairRequests.filter((entry) => entry.path === '/cache-pair/').length,
        paired.profile.repeats * 2,
      );
      assert.equal(
        pairRequests.filter((entry) => entry.path === '/cached.css').length,
        paired.profile.repeats,
        'one CSS network request per fresh context, none for warm navigation',
      );
      assert.equal(
        pairRequests.filter(
          (entry) => entry.path === '/revalidate.css' && entry.validator === '"fixture-v1"',
        ).length,
        paired.profile.repeats,
        'warm revalidation reaches server with ETag and accepts 304',
      );
      const htmlPair = job();
      htmlPair.origin = origin;
      htmlPair.profile.cache_mode = 'paired';
      htmlPair.pages[0].url = origin + '/cache-html/';
      const htmlOffset = requests.length;
      const htmlResults = await runMeasurements(htmlPair, policy, {
        chromium: testChromium,
        deadline: Date.now() + 15000,
      });
      assert.equal(htmlResults.results[0].cache_header, 'MISS');
      assert.equal(htmlResults.results[1].status, 'ok');
      assert.equal(
        htmlResults.results[1].cache_header,
        'unknown',
        'cached HTML must not reuse a stale origin cache header',
      );
      assert.ok(htmlResults.results[1].browser_cache_requests >= 2);
      assert.equal(
        requests.slice(htmlOffset).filter((entry) => entry.path === '/cache-html/').length,
        1,
      );
      const failedPair = job();
      failedPair.origin = origin;
      failedPair.profile.cache_mode = 'paired';
      failedPair.pages[0].url = origin + '/redirect/';
      const failedOffset = requests.length;
      const failed = await runMeasurements(failedPair, policy, {
        chromium: testChromium,
        deadline: Date.now() + 15000,
      });
      assert.equal(failed.results[0].status, 'failed');
      assert.equal(failed.results[1].error, 'warmup_failed');
      assert.equal(
        requests.slice(failedOffset).filter((entry) => entry.path === '/redirect/').length,
        1,
        'failed fresh navigation never primes a warm request',
      );
      const cancelledPair = job();
      cancelledPair.origin = origin;
      cancelledPair.profile.cache_mode = 'paired';
      cancelledPair.pages[0].url = origin + '/cache-pair/';
      let cancel = false;
      const cancelled = await runMeasurements(cancelledPair, policy, {
        chromium: testChromium,
        deadline: Date.now() + 15000,
        cancelled: () => cancel,
        onResult: () => {
          cancel = true;
        },
      });
      assert.equal(cancelled.cancelled, true);
      assert.equal(
        cancelled.results.length,
        1,
        'cancellation between pair phases prevents warm navigation',
      );
      const hanging = job();
      hanging.origin = origin;
      hanging.pages[0].url = origin + '/hang/';
      const started = Date.now();
      const timeout = await runMeasurements(hanging, policy, {
        chromium: testChromium,
        deadline: Date.now() + 15000,
        sampleTimeoutMs: 2500,
      });
      assert.equal(timeout.results[0].error, 'navigation_timeout');
      assert.ok(Date.now() - started < 12000, 'infinite renderer must stop before job deadline');
      const redirect = job();
      redirect.origin = origin;
      redirect.pages[0].url = origin + '/client-redirect/';
      const stopped = await runMeasurements(redirect, policy, {
        chromium: testChromium,
        deadline: Date.now() + 15000,
      });
      assert.equal(stopped.results[0].status, 'failed');
    }
    assert.ok(requests.some((entry) => entry.path === '/asset.css'));
    assert.ok(requests.some((entry) => entry.path === '/redirect.js'));
    assert.ok(
      requests.every((entry) =>
        [
          '/',
          '/asset.css',
          '/redirect/',
          '/redirect.js',
          '/late.js',
          '/hang/',
          '/client-redirect/',
          '/cache-pair/',
          '/cached.css',
          '/revalidate.css',
          '/cache-html/',
        ].includes(entry.path),
      ),
      JSON.stringify(requests.map(({ path, method }) => ({ path, method }))),
    );
    assert.ok(
      requests.every(
        (entry) =>
          entry.method === 'GET' &&
          entry.cookie === undefined &&
          !entry.cacheControl &&
          !entry.pragma &&
          entry.ua.includes(UA_TOKEN),
      ),
      JSON.stringify(requests),
    );
    console.log(
      'Performance browser fresh/warm HTTP+TLS rendering, anonymous/cache isolation, cancellation and infinite-script timeout: passed (loopback fixtures only)',
    );
  } finally {
    for (const server of servers) await new Promise((resolve) => server.close(resolve));
    fs.rmSync(certificates, { recursive: true, force: true });
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
