const assert = require('node:assert/strict');
const http = require('node:http');
const https = require('node:https');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
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
  try {
    for (let i = 0; i < servers.length; i++) {
      const origin = `${i ? 'https' : 'http'}://127.0.0.1:${servers[i].address().port}`;
      const policy = makePolicy(origin, [], true);
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
          chromium,
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
      }
      const hanging = job();
      hanging.origin = origin;
      hanging.pages[0].url = origin + '/hang/';
      const started = Date.now();
      const timeout = await runMeasurements(hanging, policy, {
        chromium,
        deadline: Date.now() + 15000,
        sampleTimeoutMs: 2500,
      });
      assert.equal(timeout.results[0].error, 'navigation_timeout');
      assert.ok(Date.now() - started < 12000, 'infinite renderer must stop before job deadline');
      const redirect = job();
      redirect.origin = origin;
      redirect.pages[0].url = origin + '/client-redirect/';
      const stopped = await runMeasurements(redirect, policy, {
        chromium,
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
      'Performance browser desktop/mobile HTTP+TLS rendering, cookie/redirect/worker isolation and infinite-script timeout: passed (loopback fixtures only)',
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
