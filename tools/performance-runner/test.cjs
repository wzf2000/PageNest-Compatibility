const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { makePolicy, publicAddress, normalizeOrigin } = require('./policy.cjs');
const {
  privateDirectory,
  readJson,
  atomicWrite,
  acquire,
  validJob,
  config,
} = require('./queue.cjs');
const { processOnce } = require('./cli.cjs');

const origin = 'https://example.test';
function job() {
  return {
    schema_version: 2,
    id: 'a'.repeat(32),
    status: 'queued',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    expires_at: new Date(Date.now() + 1800000).toISOString(),
    profile: { device: 'desktop', cache_mode: 'fresh-context', repeats: 1 },
    origin,
    pages: [{ key: 'home', post_id: 0, url: origin + '/', title: 'Synthetic', reason: 'fixture' }],
    versions: { wordpress: 'synthetic', companion: 'development', theme: 'synthetic' },
    results: [],
    error: null,
  };
}
function environment(cacheMode = 'fresh-context') {
  return {
    runner: 'playwright-navigation-v3',
    node_version: process.version,
    chromium_version: 'synthetic',
    network: 'server-local',
    cache_mode: cacheMode,
    sample_window_ms: 1500,
    policy_hash: 'f'.repeat(64),
    limits_hash: 'e'.repeat(64),
  };
}
function sample(pageKey = 'home', phase = 'fresh') {
  return {
    page_key: pageKey,
    run: 1,
    cache_phase: phase,
    status: 'ok',
    http_status: 200,
    ttfb_ms: 1,
    fcp_ms: 2,
    lcp_ms: 3,
    cls: 0,
    long_task_blocking_ms: 0,
    request_count: 2,
    transfer_bytes: 100,
    failed_requests: 0,
    blocked_requests: 0,
    browser_cache_requests: phase === 'warm' ? 1 : 0,
    cache_header: 'HIT',
    duration_ms: 1500,
    error: null,
  };
}
async function main() {
  for (const ip of [
    '127.0.0.1',
    '10.1.2.3',
    '169.254.169.254',
    '100.64.1.1',
    '192.168.2.2',
    '0.0.0.0',
    '224.0.0.1',
    '::1',
    'fe80::1',
    'fc00::1',
    '::ffff:127.0.0.1',
    '2002:a00:1::',
    '2001:db8::1',
  ])
    assert.equal(publicAddress(ip), false, ip);
  for (const ip of ['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111'])
    assert.equal(publicAddress(ip), true, ip);
  for (const value of [
    'http://example.test',
    'https://a:b@example.test',
    'https://example.test:8080',
    'https://example.test/?secret=1',
    'https://127.0.0.1',
  ])
    assert.throws(() => normalizeOrigin(value));
  const policy = makePolicy(origin, ['cdn.example.test']);
  for (const value of [
    origin + '/wp-admin/',
    origin + '/wp-login.php',
    origin + '/wp-cron.php',
    origin + '/wp%252dadmin/',
    origin + '/wp%2dadmin/',
    origin + '/x?preview=true',
    origin + '/%5cwp-admin/',
    'https://other.test/',
    origin + '/x#secret',
  ])
    assert.equal(policy.allowedUrl(value, true), false, value);
  assert.equal(policy.allowedUrl(origin + '/blog/', true), true);
  assert.equal(policy.allowedUrl('https://cdn.example.test/a.css?v=1'), true);
  await assert.rejects(
    policy.resolve('other.test', async () => [{ address: '8.8.8.8', family: 4 }]),
  );
  await assert.rejects(
    policy.resolve('example.test', async () => [
      { address: '8.8.8.8', family: 4 },
      { address: '127.0.0.1', family: 4 },
    ]),
  );
  assert.equal(
    (await policy.resolve('example.test', async () => [{ address: '8.8.8.8', family: 4 }])).address,
    '8.8.8.8',
  );
  const request = (url, method = 'GET', type = 'script') => ({
    url: () => url,
    method: () => method,
    resourceType: () => type,
    isNavigationRequest: () => false,
  });
  assert.equal(policy.allowedRequest(request(origin + '/a.js')), true);
  assert.equal(policy.allowedRequest(request(origin + '/a.js', 'POST')), false);
  assert.equal(policy.allowedRequest(request(origin + '/a.js', 'GET', 'fetch')), false);
  assert.equal(
    policy.allowedRequest(request(origin + '/index.php?action=write', 'GET', 'image')),
    false,
  );
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pagenest-speed-test-'));
  fs.chmodSync(dir, 0o700);
  try {
    assert.equal(privateDirectory(dir), dir);
    fs.chmodSync(dir, 0o755);
    assert.throws(() => privateDirectory(dir));
    fs.chmodSync(dir, 0o700);
    fs.symlinkSync('/tmp', path.join(dir, 'symlink'));
    assert.throws(() => privateDirectory(dir));
    fs.unlinkSync(path.join(dir, 'symlink'));
    const configFile = path.join(dir, 'config.json');
    fs.writeFileSync(
      configFile,
      JSON.stringify({ schema_version: 1, queue_dir: dir, origin, asset_hosts: [] }),
      { mode: 0o600 },
    );
    assert.equal(config(configFile).dir, dir);
    fs.writeFileSync(
      configFile,
      JSON.stringify({ schema_version: 1, queue_dir: dir, origin, fixture: true }),
    );
    assert.throws(() => config(configFile));
    const release = acquire(dir, 'worker.lock');
    const check = execFileSync(
      'php',
      [
        '-r',
        '$h=fopen($argv[1],"c+");echo flock($h,LOCK_EX|LOCK_NB)?"unlocked":"locked";',
        path.join(dir, 'worker.lock'),
      ],
      { encoding: 'utf8' },
    );
    assert.equal(check, 'locked', 'Node lock remains held after flock subprocess exits');
    assert.throws(() => acquire(dir, 'worker.lock'), /queue_busy/);
    release();
    const again = acquire(dir, 'worker.lock');
    again();
    let j = job();
    assert.equal(validJob(j, policy), true);
    const duplicate = structuredClone(j);
    duplicate.pages.push(duplicate.pages[0]);
    assert.equal(validJob(duplicate, policy), false);
    const paired = job();
    paired.profile.cache_mode = 'paired';
    paired.results = [sample(), sample('home', 'warm')];
    paired.environment = environment('paired');
    assert.equal(validJob(paired, policy), true);
    paired.results.push(sample('home', 'warm'));
    assert.equal(validJob(paired, policy), false, 'duplicate phase rejected');
    paired.results.pop();
    paired.results[1].browser_cache_requests = 3;
    assert.equal(validJob(paired, policy), false, 'cache count cannot exceed request count');
    paired.results[1].browser_cache_requests = 1;
    paired.environment.cache_mode = 'fresh-context';
    assert.equal(validJob(paired, policy), false, 'environment cannot mislabel cache mode');
    paired.environment = environment('paired');
    paired.results[1].error = 'warmup_failed';
    paired.results[1].status = 'failed';
    assert.equal(
      validJob(paired, policy),
      false,
      'successful fresh phase cannot report failed warmup',
    );
    paired.results[0].status = 'failed';
    paired.results[0].error = 'navigation_failed';
    assert.equal(validJob(paired, policy), true);
    paired.results[0].error = 'warmup_failed';
    assert.equal(validJob(paired, policy), false, 'warmup failure only applies to warm phase');
    const legacy = job();
    legacy.schema_version = 1;
    legacy.results = [sample()];
    delete legacy.results[0].cache_phase;
    delete legacy.results[0].browser_cache_requests;
    assert.equal(validJob(legacy, policy), true, 'legacy reports remain readable');
    legacy.profile.cache_mode = 'paired';
    assert.equal(validJob(legacy, policy), false, 'legacy format cannot silently contain pairs');
    atomicWrite(dir, `job-${j.id}.json`, j);
    assert.deepEqual(readJson(path.join(dir, `job-${j.id}.json`)), j);
    const output = await processOnce({ dir, policy }, async (entry, _, options) => {
      assert.equal(entry.status, 'running');
      assert.equal(options.cancelled(), false);
      atomicWrite(dir, `cancel-${entry.id}`, { schema_version: 1, id: entry.id });
      assert.equal(options.cancelled(), true);
      return { results: [], environment: environment(), cancelled: true };
    });
    assert.equal(output.status, 'cancelled');
    assert.equal(readJson(path.join(dir, `job-${j.id}.json`)).status, 'cancelled');
    j = job();
    fs.unlinkSync(path.join(dir, `cancel-${j.id}`));
    atomicWrite(dir, `job-${j.id}.json`, j);
    const lateCancel = await processOnce({ dir, policy }, async (entry) => {
      atomicWrite(dir, `cancel-${entry.id}`, { schema_version: 1, id: entry.id });
      return { results: [], environment: environment(), cancelled: false };
    });
    assert.equal(lateCancel.status, 'cancelled', 'last-sample cancellation wins completion');
    assert.deepEqual(
      await processOnce({ dir, policy }, async () => {
        throw new Error('must_not_run');
      }),
      { status: 'idle' },
    );
    j = job();
    j.status = 'running';
    j.expires_at = new Date(Date.now() - 1000).toISOString();
    atomicWrite(dir, `job-${j.id}.json`, j);
    await processOnce({ dir, policy }, async () => {
      throw new Error('must_not_run');
    });
    assert.equal(readJson(path.join(dir, `job-${j.id}.json`)).error, 'worker_expired');
    fs.unlinkSync(path.join(dir, `cancel-${j.id}`));
    j = job();
    atomicWrite(dir, `job-${j.id}.json`, j);
    await assert.rejects(
      processOnce({ dir, policy }, async () => {
        throw new Error('unsafe raw URL credential');
      }),
    );
    const failed = readJson(path.join(dir, `job-${j.id}.json`));
    assert.equal(failed.status, 'failed');
    assert.equal(failed.error, 'runner_failed');
    // Real PHP producer and validator, using the same in-memory WordPress fixture,
    // verify the shared wire format rather than two independent JS/PHP mocks.
    const fixture = path.resolve(__dirname, '../../tests/performance.php');
    const phpProducer =
      '$queueDir=$argv[1];$fixture=$argv[2];$cacheMode=$argv[3];$argv[1]="disabled";ob_start();require $fixture;ob_end_clean();$queue=new \\PageNest\\Performance\\Queue($queueDir);echo json_encode($queue->start(["post:1"],"desktop",1,$cacheMode));';
    const phpValidator =
      '$report=$argv[1];$fixture=$argv[2];$argv[1]="disabled";ob_start();require $fixture;ob_end_clean();echo \\PageNest\\Performance\\Queue::valid(json_decode(file_get_contents($report),true))?"valid":"invalid";';
    for (const mode of ['fresh-context', 'paired']) {
      const bridge = path.join(dir, 'bridge-' + mode);
      fs.mkdirSync(bridge, { mode: 0o700 });
      const produced = JSON.parse(
        execFileSync('php', ['-r', phpProducer, bridge, fixture, mode], { encoding: 'utf8' }),
      );
      assert.equal(
        validJob(produced, policy),
        true,
        'PHP-generated job meets worker protocol: ' + mode,
      );
      const executionEnvironment = environment(mode);
      await processOnce({ dir: bridge, policy }, async (entry, _, options) => {
        const rows = [
          sample(entry.pages[0].key),
          ...(mode === 'paired' ? [sample(entry.pages[0].key, 'warm')] : []),
        ];
        for (const row of rows) await options.onResult(row, executionEnvironment);
        return { results: rows, environment: executionEnvironment, cancelled: false };
      });
      assert.equal(
        execFileSync(
          'php',
          ['-r', phpValidator, path.join(bridge, `job-${produced.id}.json`), fixture],
          { encoding: 'utf8' },
        ),
        'valid',
        'worker result accepted by PHP admin validator: ' + mode,
      );
    }
    const legacyQueued = job();
    legacyQueued.schema_version = 1;
    atomicWrite(dir, `job-${legacyQueued.id}.json`, legacyQueued);
    await processOnce({ dir, policy }, async (entry) => {
      assert.equal(entry.schema_version, 2, 'queued legacy job upgraded under the claim lock');
      return { results: [sample()], environment: environment(), cancelled: false };
    });
    assert.equal(validJob(readJson(path.join(dir, `job-${legacyQueued.id}.json`)), policy), true);
    const large = path.join(dir, 'large.json');
    fs.writeFileSync(large, 'x'.repeat(524289), { mode: 0o600 });
    assert.throws(() => readJson(large));
    console.log(
      'Performance runner policy, pinned DNS, locks, queue and cancellation: passed (synthetic only)',
    );
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

if (require.main === module)
  main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
  });
module.exports = { job, environment, sample };
