const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { LIMITS, budgets, limitsHash } = require('./budget.cjs');
const { makePolicy } = require('./policy.cjs');
const { atomicWrite, readJson, acquire, validJob } = require('./queue.cjs');
const { processOnce } = require('./cli.cjs');
const { job, environment, sample } = require('./test.cjs');

async function main() {
  const policy = makePolicy('https://example.test');
  assert.deepEqual(budgets(policy), LIMITS);
  assert.throws(() => budgets(policy, { connections: 1 }), /resource_limit/);
  for (const override of [
    { connections: 17 },
    { extra: 1 },
    { connections: 0 },
    { connections: 1.5 },
  ])
    assert.throws(() => budgets({ fixture: true }, override), /resource_limit/);
  assert.notEqual(limitsHash(LIMITS, { pids: 96 }, true), limitsHash(LIMITS, { pids: 80 }, true));
  assert.notEqual(limitsHash(LIMITS, { pids: 96 }, true), limitsHash(LIMITS, { pids: 96 }, false));
  const v3 = { ...job(), environment: environment() };
  delete v3.environment.limits_hash;
  assert.equal(validJob(v3, policy), false);
  v3.environment.runner = 'playwright-navigation-v2';
  assert.equal(validJob(v3, policy), true, 'previous isolated reports remain readable');

  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pagenest-speed-resources-'));
  const filename = path.join(dir, `job-${job().id}.json`);
  try {
    // Direct CLI cannot claim even a valid queued job in an unrestricted process.
    atomicWrite(dir, path.basename(filename), job());
    const config = path.join(dir, 'runner.json');
    fs.writeFileSync(
      config,
      JSON.stringify({ schema_version: 1, queue_dir: dir, origin: policy.origin, asset_hosts: [] }),
      { mode: 0o600 },
    );
    const cli = spawnSync(
      process.execPath,
      [path.join(__dirname, 'cli.cjs'), '--once', '--config', config],
      { encoding: 'utf8' },
    );
    assert.equal(cli.error, undefined);
    assert.equal(cli.status, 1);
    assert.match(cli.stderr, /^runtime_(?:unrestricted|wrong_user|not_private|unavailable)\n$/);
    assert.equal(cli.stdout, '');
    assert.equal(readJson(filename).status, 'queued');
    assert.equal(fs.existsSync(path.join(dir, 'worker.lock')), false);
    fs.unlinkSync(config);
    for (const mode of ['marker', 'signal']) {
      const controller = new AbortController();
      atomicWrite(dir, path.basename(filename), job());
      const output = await processOnce(
        { dir, policy },
        async (entry, _, options) => {
          await options.onResult(sample(), environment());
          if (mode === 'marker')
            atomicWrite(dir, `cancel-${entry.id}`, { schema_version: 1, id: entry.id });
          else controller.abort();
          throw new Error('browser context closed during setup');
        },
        { signal: controller.signal },
      );
      assert.equal(output.status, 'cancelled', `${mode} survives a browser setup rejection`);
      const report = readJson(filename);
      assert.equal(report.status, 'cancelled');
      assert.equal(report.error, null);
      assert.equal(report.results.length, 1);
      assert.equal(validJob(report, policy), true);
      acquire(dir, 'worker.lock')();
      if (mode === 'marker') fs.unlinkSync(path.join(dir, `cancel-${report.id}`));
    }
    for (const code of ['resource_limit', 'job_timeout']) {
      atomicWrite(dir, path.basename(filename), job());
      await assert.rejects(
        processOnce({ dir, policy }, async (entry, _, options) => {
          const row = {
            ...sample(),
            status: 'failed',
            error: code === 'resource_limit' ? code : 'navigation_timeout',
          };
          await options.onResult(row, environment());
          throw new Error(code);
        }),
        { message: code },
      );
      const report = readJson(filename);
      assert.equal(report.status, 'failed');
      assert.equal(report.error, code);
      assert.equal(validJob(report, policy), true);
      acquire(dir, 'worker.lock')();
    }
    const expiring = job();
    expiring.expires_at = new Date(Date.now() + 1200).toISOString();
    atomicWrite(dir, path.basename(filename), expiring);
    let releaseQueue, timer;
    try {
      await assert.rejects(
        processOnce({ dir, policy }, async (entry, _, options) => {
          await options.onResult(sample(), environment());
          const release = acquire(dir, 'queue.lock');
          releaseQueue = release;
          timer = setTimeout(
            () => {
              releaseQueue = null;
              release();
            },
            Math.max(1, Date.parse(entry.expires_at) - Date.now() + 150),
          );
          return { results: [sample()], environment: environment(), cancelled: false };
        }),
        { message: 'job_timeout' },
      );
      assert.equal(
        readJson(filename).error,
        'job_timeout',
        'final lock wait cannot turn an expired job into completed',
      );
    } finally {
      clearTimeout(timer);
      releaseQueue?.();
    }
    const interrupted = job();
    interrupted.status = 'running';
    atomicWrite(dir, path.basename(filename), interrupted);
    assert.deepEqual(
      await processOnce({ dir, policy }, () => {
        throw new Error('must not relaunch orphan');
      }),
      { status: 'idle' },
    );
    assert.equal(readJson(filename).error, 'worker_expired');
    console.log(
      'Resource budgets, preflight before claim, interrupted recovery and cancellation: passed (synthetic only)',
    );
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
