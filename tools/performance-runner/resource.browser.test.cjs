const assert = require('node:assert/strict');
const http = require('node:http');
const https = require('node:https');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { gzipSync } = require('node:zlib');
const { chromium } = require('@playwright/test');
const { makePolicy, startProxy } = require('./policy.cjs');
const { runMeasurements } = require('./measure.cjs');
const { job } = require('./test.cjs');

async function connect(port) {
  return new Promise((resolve, reject) => {
    const socket = net.connect({ port, host: '127.0.0.1' });
    socket.once('connect', () => resolve(socket));
    socket.once('error', reject);
  });
}
async function main() {
  let cancel = false;
  let cancelTimer;
  const seen = [];
  const handler = (req, res) => {
    seen.push(req.url);
    if (req.url === '/deadline/') {
      res.writeHead(200, { 'content-type': 'text/html' });
      res.write('<h1>Slow fixture</h1>');
      return;
    }
    if (req.url === '/cancel/')
      cancelTimer = setTimeout(() => {
        cancel = true;
      }, 200);
    if (req.url.endsWith('.js')) {
      res.writeHead(200, { 'content-type': 'text/javascript', 'content-encoding': 'gzip' });
      res.end(gzipSync('/*' + 'x'.repeat(1024 * 1024) + '*/'));
      return;
    }
    res.writeHead(200, { 'content-type': 'text/html', 'cache-control': 'no-store' });
    res.end(
      req.url === '/requests/'
        ? '<h1>Budget fixture</h1>' +
            Array.from({ length: 12 }, (_, i) => `<img src="/${i}.png">`).join('')
        : req.url === '/cancel/'
          ? '<h1>Cancellation fixture</h1><script>setTimeout(()=>{while(true){}},50)</script>'
          : '<h1>Budget fixture</h1><script src="/large.js"></script>',
    );
  };
  const certificates = fs.mkdtempSync(path.join(os.tmpdir(), 'pagenest-budget-tls-'));
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
  const server = http.createServer(handler);
  const tls = https.createServer(
    {
      key: fs.readFileSync(path.join(certificates, 'key.pem')),
      cert: fs.readFileSync(path.join(certificates, 'cert.pem')),
    },
    handler,
  );
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  await new Promise((resolve) => tls.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const policy = makePolicy(origin, [], true);
  const processes = [];
  const ports = [];
  const fixtureChromium = {
    async launchServer(options) {
      ports.push(Number(new URL(options.proxy.server).port));
      const instance = await chromium.launchServer(options);
      processes.push(instance.process().pid);
      ports.push(Number(new URL(instance.wsEndpoint()).port));
      return instance;
    },
    connect: (...args) => chromium.connect(...args),
  };
  function target(url) {
    const fixture = job();
    fixture.origin = origin;
    fixture.pages[0].url = origin + url;
    fixture.profile.cache_mode = 'paired';
    return fixture;
  }
  try {
    for (const [url, limits] of [
      ['/requests/', { requests_per_navigation: 3 }],
      ['/bytes/', { bytes_per_navigation: 65536 }],
      ['/wire/', { proxy_bytes_per_job: 512 }],
    ]) {
      const rows = [];
      const started = Date.now();
      await assert.rejects(
        runMeasurements(target(url), policy, {
          chromium: fixtureChromium,
          deadline: Date.now() + 10000,
          fixtureLimits: limits,
          onResult: (row) => rows.push(row),
        }),
        { message: 'resource_limit' },
      );
      assert.ok(Date.now() - started < 7000, 'resource overrun stops promptly');
      assert.equal(rows.length, 1, 'resource stop never begins warm navigation');
      assert.equal(rows[0].status, 'failed');
      assert.equal(rows[0].error, 'resource_limit');
    }
    const tlsOrigin = `https://127.0.0.1:${tls.address().port}`;
    const tlsTarget = target('/tls-wire/');
    tlsTarget.origin = tlsOrigin;
    tlsTarget.pages[0].url = tlsOrigin + '/tls-wire/';
    const tlsRows = [];
    await assert.rejects(
      runMeasurements(tlsTarget, makePolicy(tlsOrigin, [], true), {
        chromium: fixtureChromium,
        deadline: Date.now() + 10000,
        fixtureLimits: { proxy_bytes_per_job: 512 },
        onResult: (row) => tlsRows.push(row),
      }),
      { message: 'resource_limit' },
    );
    assert.equal(
      tlsRows[0].error,
      'resource_limit',
      'CONNECT encrypted bytes obey whole-job budget',
    );
    const started = Date.now();
    const cancelled = await runMeasurements(target('/cancel/'), policy, {
      chromium: fixtureChromium,
      deadline: Date.now() + 10000,
      cancelled: () => cancel,
    });
    assert.equal(cancelled.cancelled, true);
    assert.equal(cancelled.results.length, 1);
    assert.equal(cancelled.results[0].error, 'cancelled');
    assert.ok(Date.now() - started < 7000, 'active cancellation stops an infinite renderer');
    const deadlineRows = [];
    await assert.rejects(
      runMeasurements(target('/deadline/'), policy, {
        chromium: fixtureChromium,
        deadline: Date.now() + 10000,
        fixtureLimits: { job_timeout_ms: 3500 },
        onResult: (row) => deadlineRows.push(row),
      }),
      { message: 'job_timeout' },
    );
    assert.equal(deadlineRows[0].error, 'navigation_timeout');

    let stopped = 0;
    const proxy = await startProxy(policy, { maxConnections: 1, onLimit: () => stopped++ });
    const port = Number(new URL(proxy.url).port);
    let first, second;
    try {
      first = await connect(port);
      const firstClosed = new Promise((resolve) => first.once('close', resolve));
      second = await connect(port);
      await firstClosed;
      assert.equal(stopped, 1, 'second concurrent proxy connection stops all sockets');
    } finally {
      first?.destroy();
      second?.destroy();
      await proxy.close();
    }
    for (const pid of processes) assert.throws(() => process.kill(pid, 0), { code: 'ESRCH' });
    for (const port of ports) await assert.rejects(connect(port), { code: 'ECONNREFUSED' });
    assert.ok(!seen.some((url) => url.startsWith('/wp-')));
    console.log(
      'Browser request/decoded/wire limits, active cancellation, deadline and socket/process cleanup: passed (loopback only)',
    );
  } finally {
    clearTimeout(cancelTimer);
    server.closeAllConnections();
    tls.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
    await new Promise((resolve) => tls.close(resolve));
    fs.rmSync(certificates, { recursive: true, force: true });
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
