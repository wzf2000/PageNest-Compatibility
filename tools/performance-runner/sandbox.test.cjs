'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { immutableBrowser, verifyEvidence, assertChromiumSandbox } = require('./sandbox.cjs');
const { main, parseArguments, reportFailure } = require('./preflight.cjs');
const status = (
  pid,
  { filters = 4, nspid = [pid], uid = 1000, nnp = 1, seccomp = 2, cap = '0000000000000000' } = {},
) =>
  `Uid:\t${uid}\t${uid}\t${uid}\t${uid}\nNoNewPrivs:\t${nnp}\nCapPrm:\t${cap}\nCapEff:\t${cap}\nCapAmb:\t${cap}\nSeccomp:\t${seccomp}\nSeccomp_filters:\t${filters}\nNSpid:\t${nspid.join('\t')}\n`;
const parent = { status: status(process.pid), uidMap: '0 0 4294967295\n', cgroup: '0::/runner\n' };
const browser = { ...parent, status: status(42) };
const renderer = {
  status: status(43, { filters: 5, nspid: [43, 2, 2] }),
  uidMap: '1000 1000 1\n',
  cgroup: parent.cgroup,
  pid: 43,
};
const evidence = () => ({ parent, browser, browserPid: 42, renderers: [renderer] });
let checks = 0;
function check(fn) {
  fn();
  checks++;
}
async function rejects(fn) {
  await assert.rejects(fn, { message: 'sandbox_unverified' });
  checks++;
}
function invalid(value) {
  check(() => assert.throws(() => verifyEvidence(value), { message: 'sandbox_unverified' }));
}

async function run() {
  check(() => assert.equal(verifyEvidence(evidence()).renderer_count, 1));
  for (const change of [
    { status: status(43, { uid: 0, filters: 5, nspid: [43, 2] }) },
    { status: status(43, { nnp: 0, filters: 5, nspid: [43, 2] }) },
    { status: status(43, { cap: '0000000000000001', filters: 5, nspid: [43, 2] }) },
    { status: status(43, { seccomp: 1, filters: 5, nspid: [43, 2] }) },
    { status: status(43, { filters: 4, nspid: [43, 2] }) },
    { status: status(43, { filters: 5, nspid: [43] }) },
    { status: status(43, { filters: 5, nspid: [99, 2] }) },
    { uidMap: parent.uidMap },
    { uidMap: '' },
    { cgroup: '0::/elsewhere\n' },
    { status: renderer.status.replace(/^CapAmb:.*\n/m, '') },
    { status: renderer.status + 'NoNewPrivs:\t1\n' },
  ])
    invalid({ ...evidence(), renderers: [{ ...renderer, ...change }] });
  invalid({ ...evidence(), renderers: [] });
  invalid({ ...evidence(), browserPid: 99 });
  invalid({ ...evidence(), browser: { ...browser, cgroup: '0::/elsewhere\n' } });
  check(() =>
    assert.equal(parseArguments(['--browser', '/opt/browser/chrome']), '/opt/browser/chrome'),
  );
  for (const args of [
    [],
    ['--browser'],
    ['--browser', '/opt/chrome', '--no-sandbox'],
    ['--fixture', '/opt/chrome'],
    ['--browser', 'relative'],
    ['--browser', '/opt/\\chrome'],
    ['--browser=/opt/chrome'],
  ])
    check(() => assert.throws(() => parseArguments(args), { message: 'preflight_arguments' }));
  for (const exe of [
    '/opt/../chrome',
    '/nonexistent/chrome',
    '/tmp',
    '/tmp/link',
    'relative',
    null,
  ])
    check(() => assert.throws(() => immutableBrowser(exe), { message: 'sandbox_unverified' }));

  function mock({
    failure,
    hangClose = false,
    hangLaunch = false,
    launchGate,
    connectGate,
    closeGate,
    killGate,
  } = {}) {
    const calls = [];
    const fail = (at) => {
      calls.push(at);
      if (failure === at) throw new Error('secret /private/path raw exception');
    };
    const instance = {
      close: async () => {
        fail('close');
        if (closeGate) {
          await closeGate;
          calls.push('close-done');
        }
        if (hangClose) await new Promise(() => {});
      },
      version: () => '140.0.7339.186',
      newContext: async (options) => {
        fail('context');
        assert.equal(options.serviceWorkers, 'block');
        return {
          route: async (glob, callback) => {
            fail('route');
            assert.equal(glob, '**/*');
            await callback({ abort: async () => calls.push('abort-route') });
          },
          newPage: async () => ({
            setContent: async (html) => {
              fail('content');
              assert.doesNotMatch(html, /https?:/);
            },
            textContent: async () => {
              fail('text');
              return 'local sandbox proof';
            },
          }),
        };
      },
      newBrowserCDPSession: async () => ({
        send: async (command) => {
          fail('cdp');
          assert.equal(command, 'SystemInfo.getProcessInfo');
          return {
            processInfo: [
              { type: 'browser', id: 42 },
              { type: 'renderer', id: 43 },
            ],
          };
        },
      }),
    };
    const server = {
      process: () => {
        fail('process');
        return { pid: 42, exitCode: 0, signalCode: null };
      },
      wsEndpoint: () => 'ws://127.0.0.1/mock',
      kill: async () => {
        fail('kill');
        if (killGate) {
          await killGate;
          calls.push('kill-done');
        }
      },
    };
    const chromium = {
      launchServer: async (options) => {
        fail('launch');
        assert.equal(options.chromiumSandbox, true);
        assert.equal(options.headless, true);
        assert.equal(options.host, '127.0.0.1');
        assert.ok(options.timeout <= 15000);
        assert.ok(options.args.every((arg) => !arg.includes('no-sandbox')));
        if (launchGate) await launchGate;
        if (hangLaunch) await new Promise(() => {});
        return server;
      },
      connect: async () => {
        fail('connect');
        if (connectGate) await connectGate;
        return instance;
      },
    };
    const dependencies = {
      checkBrowser: () => {},
      readEvidence: (pid) => {
        fail('evidence');
        return pid === process.pid ? parent : pid === 42 ? browser : renderer;
      },
    };
    return { calls, options: { chromium, executablePath: '/opt/chrome' }, dependencies };
  }
  const good = mock();
  const proof = await assertChromiumSandbox(good.options, good.dependencies);
  check(() => {
    assert.equal(proof.renderer_seccomp_additional_filter, true);
    assert.equal(proof.chromium_version, '140.0.7339.186');
    assert.deepEqual(good.calls.slice(-2), ['close', 'kill']);
    assert.doesNotMatch(JSON.stringify(proof), /"(?:path|pid)"|\/|local sandbox proof/i);
  });
  for (const failure of [
    'launch',
    'connect',
    'context',
    'route',
    'content',
    'text',
    'cdp',
    'evidence',
    'process',
    'kill',
  ]) {
    const bad = mock({ failure });
    await rejects(() => assertChromiumSandbox(bad.options, bad.dependencies));
    if (failure !== 'launch') check(() => assert.ok(bad.calls.includes('kill')));
  }
  const close = mock({ hangClose: true });
  await assertChromiumSandbox(close.options, close.dependencies);
  check(() => assert.equal(close.calls.at(-1), 'kill'));
  // An abort must await delayed handle delivery before one final cleanup and settlement.
  for (const phase of ['launch', 'connect']) {
    let resolveGate;
    const gate = new Promise((resolve) => {
      resolveGate = resolve;
    });
    const delayed = mock({ [phase + 'Gate']: gate });
    const controller = new AbortController();
    let settled = false;
    const result = assertChromiumSandbox(
      { ...delayed.options, signal: controller.signal },
      delayed.dependencies,
    );
    result.then(
      () => {
        settled = true;
      },
      () => {
        settled = true;
      },
    );
    for (let attempt = 0; attempt < 10 && !delayed.calls.includes(phase); attempt++)
      await Promise.resolve();
    check(() => assert.ok(delayed.calls.includes(phase)));
    controller.abort();
    await Promise.resolve();
    await Promise.resolve();
    check(() => {
      assert.equal(settled, false);
      assert.equal(delayed.calls.includes('kill'), false);
    });
    resolveGate();
    await rejects(() => result);
    const callsAtSettlement = [...delayed.calls];
    await Promise.resolve();
    await Promise.resolve();
    check(() => {
      assert.deepEqual(delayed.calls, callsAtSettlement);
      assert.equal(delayed.calls.filter((call) => call === 'kill').length, 1);
      assert.equal(
        delayed.calls.filter((call) => call === 'close').length,
        phase === 'connect' ? 1 : 0,
      );
      if (phase === 'launch') assert.equal(delayed.calls.includes('connect'), false);
    });
  }
  // A signal during successful proof cleanup must reject before any caller can claim a job.
  for (const phase of ['close', 'kill']) {
    let resolveGate;
    const gate = new Promise((resolve) => {
      resolveGate = resolve;
    });
    const delayed = mock({ [phase + 'Gate']: gate });
    const controller = new AbortController();
    let settled = false;
    const result = assertChromiumSandbox(
      { ...delayed.options, signal: controller.signal },
      delayed.dependencies,
    );
    result.then(
      () => {
        settled = true;
      },
      () => {
        settled = true;
      },
    );
    for (let attempt = 0; attempt < 100 && !delayed.calls.includes(phase); attempt++)
      await Promise.resolve();
    check(() => assert.ok(delayed.calls.includes(phase)));
    controller.abort();
    await Promise.resolve();
    await Promise.resolve();
    check(() => {
      assert.equal(settled, false);
      if (phase === 'close') assert.equal(delayed.calls.includes('kill'), false);
    });
    resolveGate();
    await rejects(() => result);
    const callsAtSettlement = [...delayed.calls];
    await Promise.resolve();
    await Promise.resolve();
    check(() => {
      assert.deepEqual(delayed.calls, callsAtSettlement);
      assert.equal(delayed.calls.filter((call) => call === 'close').length, 1);
      assert.equal(delayed.calls.filter((call) => call === 'kill').length, 1);
      assert.ok(delayed.calls.indexOf('close') < delayed.calls.indexOf('kill'));
      if (phase === 'close')
        assert.ok(delayed.calls.indexOf('close-done') < delayed.calls.indexOf('kill'));
      if (phase === 'kill') assert.equal(delayed.calls.at(-1), 'kill-done');
    });
  }
  const originalTimeout = global.setTimeout;
  global.setTimeout = (fn, ms, ...rest) => originalTimeout(fn, ms > 1000 ? 20 : ms, ...rest);
  try {
    const cancelled = mock({ hangLaunch: true });
    const controller = new AbortController();
    const result = assertChromiumSandbox(
      { ...cancelled.options, signal: controller.signal },
      cancelled.dependencies,
    );
    controller.abort();
    await rejects(() => result);
    const timeout = mock({ hangLaunch: true });
    await rejects(() => assertChromiumSandbox(timeout.options, timeout.dependencies));
  } finally {
    global.setTimeout = originalTimeout;
  }
  const aborted = new AbortController();
  aborted.abort();
  const early = mock();
  await rejects(() =>
    assertChromiumSandbox({ ...early.options, signal: aborted.signal }, early.dependencies),
  );
  check(() => assert.equal(early.calls.length, 0));

  // The executable must flush the fixed failure JSON, then exit despite unknown live handles.
  // main() remains reusable without terminal exits; only the require.main wrapper invokes this.
  for (const previousCode of [undefined, 0, 2, 130, 143]) {
    const calls = [];
    let flush;
    const terminal = {
      exitCode: previousCode,
      stdout: {
        write: (text, callback) => {
          calls.push(['write', text]);
          flush = callback;
        },
      },
      exit: (code) => calls.push(['exit', code]),
    };
    reportFailure(new Error('secret /private/path raw launch error'), terminal);
    check(() => {
      assert.deepEqual(calls, [
        ['write', '{"schema_version":1,"ok":false,"error":"sandbox_unverified"}\n'],
      ]);
      assert.equal(terminal.exitCode, [130, 143].includes(previousCode) ? previousCode : 1);
    });
    flush();
    check(() => assert.deepEqual(calls.at(-1), ['exit', terminal.exitCode]));
  }
  check(() => {
    const calls = [];
    reportFailure(new Error('runtime_unrestricted'), {
      stdout: {
        write: (text, callback) => {
          calls.push(JSON.parse(text));
          callback(new Error('write failure'));
        },
      },
      exit: (code) => calls.push(code),
    });
    assert.deepEqual(calls, [{ schema_version: 1, ok: false, error: 'runtime_unrestricted' }, 1]);
  });
  const sequence = [];
  const summary = await main(['--browser', '/opt/chrome'], {
    prepare: () => sequence.push('prepare'),
    assertRuntime: () => sequence.push('runtime'),
    immutableBrowser: () => sequence.push('immutable'),
    loadChromium: () => {
      sequence.push('load');
      return { chromium: {} };
    },
    checkSandbox: async () => {
      sequence.push('sandbox');
      return proof;
    },
  });
  check(() => assert.deepEqual(sequence, ['prepare', 'runtime', 'immutable', 'load', 'sandbox']));
  check(() => assert.equal(summary.ok, true));
  let loaded = false;
  await assert.rejects(
    () =>
      main(['--browser', '/opt/chrome'], {
        prepare: () => {
          throw new Error('runtime_unrestricted');
        },
        loadChromium: () => {
          loaded = true;
        },
      }),
    { message: 'runtime_unrestricted' },
  );
  check(() => assert.equal(loaded, false));
  check(() => {
    const source = fs.readFileSync(path.join(__dirname, 'preflight.cjs'), 'utf8');
    assert.doesNotMatch(source, /require\(['"]\.\/(?:queue|cli|measure)/);
  });
  console.log(`sandbox preflight: ${checks} checks passed (mock browser; no browser launched)`);
}
run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
