'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { performance } = require('node:perf_hooks');
const TIMEOUT_MS = 12000;
const CLOSE_MS = 1000;
const KILL_MS = 1000;
const SETTLEMENT_GRACE_MS = 500;
const fail = () => {
  throw new Error('sandbox_unverified');
};

/** Browser and all path ancestors must be root-owned and unavailable for service writes. */
function immutableBrowser(executablePath) {
  try {
    if (
      typeof executablePath !== 'string' ||
      !path.isAbsolute(executablePath) ||
      executablePath.includes('\0') ||
      path.normalize(executablePath) !== executablePath ||
      fs.realpathSync(executablePath) !== executablePath
    )
      fail();
    for (let current = executablePath; ; current = path.dirname(current)) {
      const stat = fs.lstatSync(current);
      if (
        stat.isSymbolicLink() ||
        stat.uid !== 0 ||
        (stat.mode & 0o022) !== 0 ||
        (current === executablePath ? !stat.isFile() || !(stat.mode & 0o111) : !stat.isDirectory())
      )
        fail();
      if (current === '/') break;
    }
    return executablePath;
  } catch {
    fail();
  }
}

function statusFields(raw) {
  if (typeof raw !== 'string' || raw.length > 65536) fail();
  const fields = {};
  for (const name of [
    'Uid',
    'NoNewPrivs',
    'CapPrm',
    'CapEff',
    'CapAmb',
    'Seccomp',
    'Seccomp_filters',
    'NSpid',
  ]) {
    const matches = [...raw.matchAll(new RegExp('^' + name + ':\\s*([^\\n]+)$', 'gm'))];
    if (matches.length !== 1) fail();
    fields[name] = matches[0][1].trim();
  }
  const numbers = (text) => {
    if (!/^\d+(?:\s+\d+)*$/.test(text)) fail();
    const values = text.split(/\s+/).map(Number);
    if (!values.every(Number.isSafeInteger)) fail();
    return values;
  };
  const uid = numbers(fields.Uid);
  const nspid = numbers(fields.NSpid);
  if (
    uid.length !== 4 ||
    uid.some((v) => v === 0) ||
    fields.NoNewPrivs !== '1' ||
    !/^0+$/.test(fields.CapPrm) ||
    !/^0+$/.test(fields.CapEff) ||
    !/^0+$/.test(fields.CapAmb) ||
    !/^[012]$/.test(fields.Seccomp) ||
    !nspid.length ||
    nspid.some((v) => v <= 0)
  )
    fail();
  const filters = numbers(fields.Seccomp_filters);
  if (filters.length !== 1 || filters[0] <= 0) fail();
  return { uid, nspid, seccomp: Number(fields.Seccomp), filters: filters[0] };
}
function uidMap(raw) {
  if (typeof raw !== 'string' || raw.length > 65536 || !raw.trim()) fail();
  const rows = raw
    .trim()
    .split('\n')
    .map((line) => {
      if (!/^\s*\d+\s+\d+\s+\d+\s*$/.test(line)) fail();
      const row = line.trim().split(/\s+/).map(Number);
      if (!row.every(Number.isSafeInteger) || row[2] <= 0) fail();
      return row;
    });
  return JSON.stringify(rows);
}
function processEvidence(pid) {
  if (!Number.isSafeInteger(pid) || pid <= 0) fail();
  try {
    return {
      status: fs.readFileSync(`/proc/${pid}/status`, 'utf8'),
      uidMap: fs.readFileSync(`/proc/${pid}/uid_map`, 'utf8'),
      cgroup: fs.readFileSync(`/proc/${pid}/cgroup`, 'utf8'),
    };
  } catch {
    fail();
  }
}
/** Pure proof verification, including fail-closed handling of missing /proc evidence. */
function verifyEvidence({ parent, browser, renderers, browserPid }) {
  const parentStatus = statusFields(parent.status);
  const browserStatus = statusFields(browser.status);
  const parentMap = uidMap(parent.uidMap);
  if (
    typeof parent.cgroup !== 'string' ||
    !/^0::\/[^\n]*\n?$/.test(parent.cgroup) ||
    browser.cgroup !== parent.cgroup
  )
    fail();
  if (
    uidMap(browser.uidMap) !== parentMap ||
    browserStatus.nspid[0] !== browserPid ||
    browserStatus.nspid.length !== parentStatus.nspid.length ||
    browserStatus.uid.some((v, i) => v !== parentStatus.uid[i]) ||
    !Array.isArray(renderers) ||
    !renderers.length
  )
    fail();
  for (const renderer of renderers) {
    const status = statusFields(renderer.status);
    if (
      status.seccomp !== 2 ||
      status.filters <= Math.max(browserStatus.filters, parentStatus.filters) ||
      renderer.cgroup !== parent.cgroup ||
      status.nspid[0] !== renderer.pid ||
      status.nspid.length <= parentStatus.nspid.length ||
      status.uid.some((v, i) => v !== parentStatus.uid[i]) ||
      uidMap(renderer.uidMap) === parentMap
    )
      fail();
  }
  return {
    non_root: true,
    no_new_privileges: true,
    capabilities_zero: true,
    renderer_seccomp: 2,
    renderer_user_namespace: true,
    renderer_pid_namespace: true,
    renderer_count: renderers.length,
    renderer_seccomp_additional_filter: true,
  };
}

function bounded(operation, ms) {
  let timer;
  return Promise.race([
    Promise.resolve().then(operation),
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error('sandbox_unverified')), ms);
    }),
  ]).finally(() => clearTimeout(timer));
}
async function terminate(server, browser) {
  let closed = false;
  if (browser) {
    try {
      await bounded(() => browser.close(), CLOSE_MS);
      closed = true;
    } catch {
      /* Force below. */
    }
  }
  if (server) {
    try {
      await bounded(() => server.kill(), KILL_MS);
    } catch {
      // An unconfirmed kill is a failed preflight. The restricted service's KillMode
      // terminates the whole control group; do not reconstruct process groups from mutable PIDs.
      fail();
    }
    closed = true;
  }
  if (!closed && browser) fail();
}

/** Caller must pass a trusted Chromium implementation after real runtime checks, before queue claim.
 * The second argument is a unit-test seam only; the executable and CLI expose no bypass switches.
 */
async function assertChromiumSandbox({ chromium, executablePath, signal } = {}, dependencies = {}) {
  const readEvidence = dependencies.readEvidence || processEvidence;
  const checkBrowser = dependencies.checkBrowser || immutableBrowser;
  checkBrowser(executablePath);
  if (!chromium || typeof chromium.launchServer !== 'function' || signal?.aborted) fail();
  let server, browser, timer, abortListener;
  let stopped = false;
  let finalized = false;
  const deadline = performance.now() + TIMEOUT_MS;
  const remaining = () => {
    const ms = Math.floor(deadline - performance.now());
    if (stopped || ms <= 0) fail();
    return ms;
  };
  const cleanedServers = new Set();
  const cleanedBrowsers = new Set();
  let cleanup = Promise.resolve();
  function cleanupResources(currentServer, currentBrowser) {
    const cleanServer = currentServer && !cleanedServers.has(currentServer) ? currentServer : null;
    const cleanBrowser =
      currentBrowser && !cleanedBrowsers.has(currentBrowser) ? currentBrowser : null;
    if (cleanServer) cleanedServers.add(cleanServer);
    if (cleanBrowser) cleanedBrowsers.add(cleanBrowser);
    if (!cleanServer && !cleanBrowser) return cleanup;
    cleanup = cleanup.catch(() => {}).then(() => terminate(cleanServer, cleanBrowser));
    return cleanup;
  }
  let stop;
  const stopping = new Promise((_, reject) => {
    stop = () => {
      stopped = true;
      reject(new Error('sandbox_unverified'));
    };
    timer = setTimeout(stop, TIMEOUT_MS);
    if (signal) {
      abortListener = stop;
      signal.addEventListener('abort', abortListener, { once: true });
    }
  });
  const work = (async () => {
    const launchedServer = await chromium.launchServer({
      executablePath,
      headless: true,
      chromiumSandbox: true,
      host: '127.0.0.1',
      timeout: remaining(),
      args: ['--disable-background-networking', '--disable-sync', '--disable-quic'],
    });
    if (finalized) {
      // A dependency that ignores its launch timeout cannot establish successful cleanup evidence.
      // Fail closed; clean late handles serially, with the service control-group kill as final fallback.
      cleanupResources(launchedServer).catch(() => {});
      fail();
    }
    server = launchedServer;
    const connectedBrowser = await chromium.connect(server.wsEndpoint(), { timeout: remaining() });
    if (finalized) {
      cleanupResources(server, connectedBrowser).catch(() => {});
      fail();
    }
    browser = connectedBrowser;
    remaining();
    const context = await browser.newContext({
      serviceWorkers: 'block',
      acceptDownloads: false,
      permissions: [],
    });
    remaining();
    await context.route('**/*', (route) => route.abort());
    remaining();
    const page = await context.newPage();
    remaining();
    await page.setContent(
      '<!doctype html><title>Sandbox preflight</title><p>local sandbox proof</p>',
      { timeout: remaining() },
    );
    if ((await page.textContent('p', { timeout: remaining() })) !== 'local sandbox proof') fail();
    remaining();
    const cdp = await browser.newBrowserCDPSession();
    remaining();
    const info = await cdp.send('SystemInfo.getProcessInfo');
    remaining();
    const processes = info?.processInfo;
    if (!Array.isArray(processes)) fail();
    const browserProcesses = processes.filter((p) => p.type === 'browser');
    const rendererProcesses = processes.filter((p) => p.type === 'renderer');
    const browserPid = server.process()?.pid;
    if (
      browserProcesses.length !== 1 ||
      browserProcesses[0].id !== browserPid ||
      !Number.isSafeInteger(browserPid) ||
      !rendererProcesses.length ||
      new Set(rendererProcesses.map((p) => p.id)).size !== rendererProcesses.length
    )
      fail();
    const proof = verifyEvidence({
      parent: readEvidence(process.pid),
      browser: readEvidence(browserPid),
      browserPid,
      renderers: rendererProcesses.map((p) => ({ ...readEvidence(p.id), pid: p.id })),
    });
    const version = browser.version();
    if (typeof version !== 'string' || !/^\d+(?:\.\d+){1,3}$/.test(version)) fail();
    remaining();
    return { ...proof, chromium_version: version, local_content: true };
  })();
  try {
    return await Promise.race([work, stopping]);
  } catch {
    fail();
  } finally {
    stopped = true;
    let settled = false;
    try {
      // Abort does not cancel Playwright's in-flight launch/connect promises. Wait for their
      // bounded settlement before taking the final handle snapshot, never race two cleanups.
      await bounded(
        () => work.catch(() => {}),
        Math.max(1, deadline + SETTLEMENT_GRACE_MS - performance.now()),
      );
      settled = true;
    } finally {
      finalized = true;
      clearTimeout(timer);
      if (signal && abortListener) signal.removeEventListener('abort', abortListener);
      await cleanupResources(server, browser);
    }
    // A signal can arrive after proof collection while bounded close/kill is still running.
    // Never hand a successful proof back to a caller that can claim queued work after cancellation.
    if (!settled || signal?.aborted) fail();
  }
}
module.exports = { immutableBrowser, verifyEvidence, assertChromiumSandbox, TIMEOUT_MS };
