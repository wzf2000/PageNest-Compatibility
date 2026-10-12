const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { normalizeOrigin, makePolicy } = require('./policy.cjs');

const FILE_LIMIT = 524288;
const TOTAL_LIMIT = 5 * 1024 * 1024;
const ID = /^[a-f0-9]{32}$/;
const DATE = /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{3})?Z$/;
const STATES = ['queued', 'running', 'completed', 'failed', 'cancelled'];
const FORBIDDEN = new Set(['__proto__', 'prototype', 'constructor']);

function privateDirectory(dir) {
  if (typeof dir !== 'string' || !path.isAbsolute(dir) || fs.realpathSync(dir) !== dir)
    throw new Error('invalid_directory');
  const stat = fs.lstatSync(dir);
  if (!stat.isDirectory() || stat.mode & 0o007) throw new Error('invalid_directory');
  for (let parent = dir; parent !== '/'; parent = path.dirname(parent)) {
    if (fs.lstatSync(parent).isSymbolicLink()) throw new Error('invalid_directory');
  }
  // CLI rejects commonly used Web roots; PHP also checks its exact ABSPATH/DOCUMENT_ROOT.
  if (
    ['/www/wwwroot', '/var/www', '/srv/www'].some(
      (root) => dir === root || dir.startsWith(root + '/'),
    )
  )
    throw new Error('invalid_directory');
  for (const name of fs.readdirSync(dir)) {
    if (fs.lstatSync(path.join(dir, name)).isSymbolicLink()) throw new Error('invalid_file');
  }
  return dir;
}

function readJson(filename) {
  const fd = fs.openSync(filename, fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW);
  try {
    const stat = fs.fstatSync(fd);
    if (!stat.isFile() || stat.mode & 0o007 || stat.size > FILE_LIMIT)
      throw new Error('invalid_file');
    const buffer = Buffer.alloc(FILE_LIMIT + 1);
    const size = fs.readSync(fd, buffer, 0, buffer.length, 0);
    if (size > FILE_LIMIT) throw new Error('invalid_file');
    return JSON.parse(buffer.toString('utf8', 0, size), (key, value) => {
      if (FORBIDDEN.has(key)) throw new Error('invalid_file');
      return value;
    });
  } finally {
    fs.closeSync(fd);
  }
}

function atomicWrite(dir, name, data) {
  if (!/^(?:job-[a-f0-9]{32}\.json|cancel-[a-f0-9]{32})$/.test(name))
    throw new Error('invalid_file');
  const target = path.join(dir, name);
  if (fs.existsSync(target) && fs.lstatSync(target).isSymbolicLink())
    throw new Error('invalid_file');
  const raw = Buffer.from(JSON.stringify(data));
  if (raw.length > FILE_LIMIT) throw new Error('report_too_large');
  const temporary = path.join(dir, '.tmp-' + crypto.randomBytes(16).toString('hex'));
  const fd = fs.openSync(temporary, 'wx', 0o600);
  try {
    let written = 0;
    while (written < raw.length) written += fs.writeSync(fd, raw, written, raw.length - written);
    fs.fsyncSync(fd);
    fs.renameSync(temporary, target);
  } finally {
    fs.closeSync(fd);
    if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
  }
}

// Linux flock operates on the shared open-file description inherited as child fd 3.
// It remains held by this Node process until its fd is closed, including browser lifetime.
function acquire(dir, name) {
  if (!['queue.lock', 'worker.lock'].includes(name)) throw new Error('invalid_file');
  const filename = path.join(dir, name);
  const fd = fs.openSync(
    filename,
    fs.constants.O_CREAT | fs.constants.O_RDWR | fs.constants.O_NOFOLLOW,
    0o600,
  );
  try {
    const stat = fs.fstatSync(fd);
    if (!stat.isFile() || stat.mode & 0o007) throw new Error('invalid_file');
    execFileSync('/usr/bin/flock', ['-n', '-E', '75', '3'], {
      stdio: ['ignore', 'ignore', 'ignore', fd],
    });
    return () => fs.closeSync(fd);
  } catch (error) {
    fs.closeSync(fd);
    throw new Error(error.status === 75 ? 'queue_busy' : 'lock_failed');
  }
}

async function locked(dir, callback) {
  let release;
  for (let retry = 0; !release; retry++) {
    try {
      release = acquire(dir, 'queue.lock');
    } catch (error) {
      if (error.message !== 'queue_busy' || retry >= 20) throw error;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  try {
    return callback();
  } finally {
    release();
  }
}

function validJob(job, policy) {
  if (
    !job ||
    ![1, 2].includes(job.schema_version) ||
    !ID.test(job.id) ||
    !STATES.includes(job.status) ||
    !['created_at', 'updated_at', 'expires_at'].every(
      (key) =>
        typeof job[key] === 'string' &&
        DATE.test(job[key]) &&
        Number.isFinite(Date.parse(job[key])),
    ) ||
    job.origin !== policy.origin ||
    !['fresh-context', 'paired'].includes(job.profile?.cache_mode) ||
    (job.schema_version === 1 && job.profile.cache_mode !== 'fresh-context') ||
    !['desktop', 'mobile'].includes(job.profile?.device) ||
    ![1, 3].includes(job.profile?.repeats) ||
    !Array.isArray(job.pages) ||
    job.pages.length < 1 ||
    job.pages.length > 8 ||
    !Array.isArray(job.results) ||
    job.results.length >
      job.pages.length * job.profile.repeats * (job.profile.cache_mode === 'paired' ? 2 : 1) ||
    !job.versions ||
    !(
      job.error === null ||
      [
        'worker_expired',
        'job_timeout',
        'runner_failed',
        ...(job.schema_version === 2 ? ['resource_limit'] : []),
      ].includes(job.error)
    ) ||
    !['wordpress', 'companion', 'theme'].every(
      (key) => typeof job.versions[key] === 'string' && Buffer.byteLength(job.versions[key]) <= 100,
    )
  )
    return false;
  if (job.environment !== undefined) {
    const env = job.environment;
    if (
      !env ||
      Object.keys(env).some(
        (key) =>
          ![
            'runner',
            'node_version',
            'chromium_version',
            'network',
            'cache_mode',
            ...(job.schema_version === 2 ? ['sample_window_ms', 'policy_hash', 'limits_hash'] : []),
          ].includes(key),
      ) ||
      !['node_version', 'chromium_version'].every(
        (key) => typeof env[key] === 'string' && Buffer.byteLength(env[key]) <= 100,
      ) ||
      env.network !== 'server-local' ||
      env.cache_mode !== job.profile.cache_mode ||
      !(
        job.schema_version === 1
          ? ['playwright-navigation-v1']
          : ['playwright-navigation-v2', 'playwright-navigation-v3']
      ).includes(env.runner) ||
      (job.schema_version === 2 &&
        (env.sample_window_ms !== 1500 ||
          typeof env.policy_hash !== 'string' ||
          !/^[a-f0-9]{64}$/.test(env.policy_hash) ||
          ((env.runner === 'playwright-navigation-v3' || env.limits_hash !== undefined) &&
            (typeof env.limits_hash !== 'string' || !/^[a-f0-9]{64}$/.test(env.limits_hash)))))
    )
      return false;
  }
  const keys = new Set();
  for (const page of job.pages) {
    if (
      !page ||
      !/^(home|blog|post:[1-9][0-9]*)$/.test(page.key) ||
      keys.has(page.key) ||
      !Number.isSafeInteger(page.post_id) ||
      page.post_id < 0 ||
      (page.key.startsWith('post:') && page.post_id !== Number(page.key.slice(5))) ||
      !['url', 'title', 'reason'].every(
        (key) => typeof page[key] === 'string' && Buffer.byteLength(page[key]) <= 4096,
      ) ||
      !policy.allowedUrl(page.url, true)
    )
      return false;
    keys.add(page.key);
  }
  const samples = new Set();
  for (const row of job.results) {
    const phase = job.schema_version === 1 ? 'fresh' : row?.cache_phase;
    if (
      !row ||
      !keys.has(row.page_key) ||
      !Number.isInteger(row.run) ||
      row.run < 1 ||
      row.run > job.profile.repeats ||
      !['fresh', 'warm'].includes(phase) ||
      (job.profile.cache_mode === 'fresh-context' && phase !== 'fresh') ||
      (job.schema_version === 1 && row.cache_phase !== undefined) ||
      (job.schema_version === 2 &&
        (!Number.isSafeInteger(row.browser_cache_requests) ||
          row.browser_cache_requests < 0 ||
          row.browser_cache_requests > row.request_count)) ||
      !['ok', 'failed'].includes(row.status) ||
      !['HIT', 'MISS', 'BYPASS', 'unknown'].includes(row.cache_header) ||
      !(
        row.error === null ||
        [
          'target_blocked',
          'http_error',
          'navigation_timeout',
          'navigation_failed',
          ...(job.schema_version === 2 ? ['resource_limit', 'cancelled'] : []),
        ].includes(row.error) ||
        (phase === 'warm' && row.status === 'failed' && row.error === 'warmup_failed')
      ) ||
      !(
        row.http_status === null ||
        (Number.isInteger(row.http_status) && row.http_status >= 100 && row.http_status <= 599)
      ) ||
      !['ttfb_ms', 'fcp_ms', 'lcp_ms', 'cls', 'long_task_blocking_ms'].every(
        (key) => row[key] === null || (Number.isFinite(row[key]) && row[key] >= 0),
      ) ||
      !['request_count', 'transfer_bytes', 'failed_requests', 'blocked_requests'].every(
        (key) => Number.isSafeInteger(row[key]) && row[key] >= 0,
      ) ||
      !Number.isFinite(row.duration_ms) ||
      row.duration_ms < 0 ||
      samples.has(`${row.page_key}:${row.run}:${phase}`)
    )
      return false;
    samples.add(`${row.page_key}:${row.run}:${phase}`);
  }
  if (job.profile.cache_mode === 'paired') {
    const fresh = new Map(
      job.results
        .filter((row) => row.cache_phase === 'fresh')
        .map((row) => [`${row.page_key}:${row.run}`, row]),
    );
    for (const row of job.results.filter((row) => row.cache_phase === 'warm')) {
      const first = fresh.get(`${row.page_key}:${row.run}`);
      if (
        !first ||
        (first.status === 'failed' && (row.status !== 'failed' || row.error !== 'warmup_failed')) ||
        (first.status === 'ok' && row.error === 'warmup_failed')
      )
        return false;
    }
  }
  return true;
}

function reports(dir, policy) {
  const out = [];
  let bytes = 0;
  for (const name of fs.readdirSync(dir)) {
    const filename = path.join(dir, name);
    const stat = fs.lstatSync(filename);
    if (stat.isSymbolicLink()) throw new Error('invalid_file');
    if (stat.isFile()) bytes += stat.size;
    if (!name.startsWith('job-')) continue;
    const match = /^job-([a-f0-9]{32})\.json$/.exec(name);
    if (!match) throw new Error('invalid_report');
    const job = readJson(filename);
    if (job.id !== match[1] || !validJob(job, policy)) throw new Error('invalid_report');
    out.push(job);
  }
  if (out.length > 10 || bytes > TOTAL_LIMIT) throw new Error('budget_cleanup_required');
  return out.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

function config(filename) {
  if (
    !path.isAbsolute(filename) ||
    fs.realpathSync(filename) !== filename ||
    ['/www/wwwroot', '/var/www', '/srv/www'].some((root) => filename.startsWith(root + '/'))
  )
    throw new Error('invalid_config');
  const data = readJson(filename);
  if (
    !data ||
    data.schema_version !== 1 ||
    Object.keys(data).some(
      (key) =>
        ![
          'schema_version',
          'queue_dir',
          'origin',
          'asset_hosts',
          'browser_executable',
          'cache_header_name',
        ].includes(key),
    ) ||
    (data.asset_hosts !== undefined && !Array.isArray(data.asset_hosts))
  )
    throw new Error('invalid_config');
  const origin = normalizeOrigin(data.origin);
  const dir = privateDirectory(data.queue_dir);
  if (
    typeof data.browser_executable !== 'string' ||
    !path.isAbsolute(data.browser_executable) ||
    fs.realpathSync(data.browser_executable) !== data.browser_executable ||
    !fs.statSync(data.browser_executable).isFile()
  )
    throw new Error('invalid_browser');
  return {
    dir,
    policy: makePolicy(origin, data.asset_hosts || [], false, data.cache_header_name),
    executablePath: data.browser_executable,
  };
}

module.exports = {
  FILE_LIMIT,
  ID,
  privateDirectory,
  readJson,
  atomicWrite,
  acquire,
  locked,
  validJob,
  reports,
  config,
};
