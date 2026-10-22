const assert = require('node:assert/strict');
const http = require('node:http');
const { chromium } = require('@playwright/test');
const { makePolicy } = require('./policy.cjs');
const { runMeasurements } = require('./measure.cjs');
const { job } = require('./test.cjs');

async function main() {
  const seen = [];
  const server = http.createServer((req, res) => {
    seen.push(req.url);
    res.writeHead(200, { 'content-type': 'text/html', 'cache-control': 'no-store' });
    if (req.url === '/slow-body/') {
      res.write('<h1>Response headers arrived; load remains pending</h1>');
      return;
    }
    res.end('<!doctype html><h1>Lifecycle fixture</h1>');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  let injected = false;
  let failLive = false;
  let pendingRejected = false;
  let browserCloseCalls = 0;
  const instrumentedChromium = {
    launchServer: (...args) => chromium.launchServer(...args),
    async connect(...args) {
      const browser = await chromium.connect(...args);
      const close = browser.close.bind(browser);
      browser.close = (...closeArgs) => {
        browserCloseCalls++;
        return close(...closeArgs);
      };
      const newContext = browser.newContext.bind(browser);
      browser.newContext = async (...contextArgs) => {
        const context = await newContext(...contextArgs);
        const newCDPSession = context.newCDPSession.bind(context);
        context.newCDPSession = async (page) => {
          const session = await newCDPSession(page);
          const send = session.send.bind(session);
          session.send = (method, params) => {
            if (!injected && method === 'Fetch.continueResponse') {
              injected = true;
              if (failLive) return Promise.reject(new Error('synthetic live protocol failure'));
              // Hold a real response at the Fetch boundary until the timed-out page
              // closes. This deterministically models an in-flight CDP teardown error.
              return new Promise((resolve, reject) => {
                page.once('close', () => {
                  pendingRejected = true;
                  reject(new Error('synthetic command interrupted by page close'));
                });
              });
            }
            return send(method, params);
          };
          return session;
        };
        return context;
      };
      return browser;
    },
  };
  try {
    const fixture = job();
    fixture.origin = origin;
    fixture.profile.cache_mode = 'paired';
    fixture.pages[0].url = origin + '/held/';
    fixture.pages.push({
      ...fixture.pages[0],
      key: 'post:2',
      post_id: 2,
      url: origin + '/healthy/',
    });
    const outcome = await runMeasurements(fixture, makePolicy(origin, [], true), {
      chromium: instrumentedChromium,
      deadline: Date.now() + 15000,
      sampleTimeoutMs: 4000,
    });
    assert.equal(injected, true);
    assert.equal(pendingRejected, true);
    assert.equal(outcome.results.length, 4);
    assert.equal(outcome.results[0].error, 'navigation_timeout');
    assert.equal(outcome.results[1].error, 'warmup_failed');
    assert.equal(outcome.results[2].status, 'ok');
    assert.equal(outcome.results[3].status, 'ok');
    assert.equal(browserCloseCalls, 1, 'only final browser cleanup closes the browser');
    assert.deepEqual(seen, ['/held/', '/healthy/', '/healthy/']);

    const slow = job();
    slow.origin = origin;
    slow.pages[0].url = origin + '/slow-body/';
    const failedLoad = await runMeasurements(slow, makePolicy(origin, [], true), {
      chromium,
      deadline: Date.now() + 10000,
      sampleTimeoutMs: 3000,
    });
    assert.equal(failedLoad.results[0].error, 'navigation_timeout');
    assert.equal(failedLoad.results[0].status, 'failed');
    assert.equal(
      failedLoad.results[0].http_status,
      200,
      'HTTP response does not imply load success',
    );
    injected = false;
    failLive = true;
    const liveRows = [];
    const offset = seen.length;
    await assert.rejects(
      runMeasurements(fixture, makePolicy(origin, [], true), {
        chromium: instrumentedChromium,
        deadline: Date.now() + 10000,
        onResult: (row) => liveRows.push(row),
      }),
      { message: 'guard_failed' },
    );
    assert.equal(liveRows.length, 1, 'live guard failure stops all subsequent samples');
    assert.equal(liveRows[0].status, 'failed');
    assert.deepEqual(seen.slice(offset), ['/held/']);
    console.log(
      'Browser CDP teardown recovery, live fail-closed errors and partial HTTP status: passed',
    );
  } finally {
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
