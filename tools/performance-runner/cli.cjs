const path = require('node:path');
const fs = require('node:fs');
const { config, readJson, atomicWrite, acquire, locked, reports } = require('./queue.cjs');
const { runMeasurements } = require('./measure.cjs');
const { LIMITS } = require('./budget.cjs');
const { assertRuntime } = require('./runtime.cjs');

async function processOnce(settings, engine, { signal } = {}) {
  const { dir, policy, executablePath, runtimeLimits } = settings;
  const release = acquire(dir, 'worker.lock');
  let job;
  let cancelled = () => signal?.aborted === true;
  try {
    job = await locked(dir, () => {
      const available = reports(dir, policy);
      for (const entry of available) {
        if (
          ['queued', 'running'].includes(entry.status) &&
          (Date.parse(entry.expires_at) <= Date.now() || entry.status === 'running')
        ) {
          entry.status = 'failed';
          entry.error = 'worker_expired';
          entry.updated_at = new Date().toISOString();
          atomicWrite(dir, `job-${entry.id}.json`, entry);
        }
      }
      const selected = available.find((entry) => entry.status === 'queued');
      if (!selected) return null;
      // The unpublished v1 queue is readable, but all new execution uses v2 rows.
      // A queued legacy job has no browser environment or samples to preserve.
      if (selected.schema_version === 1) {
        if (selected.results.length || selected.environment) throw new Error('invalid_report');
        selected.schema_version = 2;
      }
      selected.status = 'running';
      selected.updated_at = new Date().toISOString();
      atomicWrite(dir, `job-${selected.id}.json`, selected);
      return selected;
    });
    if (!job) return { status: 'idle' };
    cancelled = () => {
      if (signal?.aborted) return true;
      const filename = path.join(dir, `cancel-${job.id}`);
      if (!fs.existsSync(filename)) return false;
      const marker = readJson(filename);
      if (marker.schema_version !== 1 || marker.id !== job.id) throw new Error('invalid_cancel');
      return true;
    };
    const deadline = Math.min(Date.now() + LIMITS.job_timeout_ms, Date.parse(job.expires_at));
    const outcome = await engine(job, policy, {
      executablePath,
      runtimeLimits,
      deadline,
      cancelled,
      async onResult(row, environment) {
        job.results.push(row);
        job.environment = environment;
        job.updated_at = new Date().toISOString();
        await locked(dir, () => atomicWrite(dir, `job-${job.id}.json`, job));
      },
    });
    job.results = outcome.results;
    job.environment = outcome.environment;
    if (!outcome.cancelled && !cancelled() && Date.now() >= deadline)
      throw new Error('job_timeout');
    await locked(dir, () => {
      const wasCancelled = outcome.cancelled || cancelled();
      if (!wasCancelled && Date.now() >= deadline) throw new Error('job_timeout');
      job.status = wasCancelled ? 'cancelled' : 'completed';
      job.error = null;
      job.updated_at = new Date().toISOString();
      atomicWrite(dir, `job-${job.id}.json`, job);
    });
    return { status: job.status, id: job.id, samples: job.results.length };
  } catch (error) {
    if (job) {
      await locked(dir, () => {
        let wasCancelled = false;
        try {
          wasCancelled = cancelled();
        } catch {
          /* Invalid marker is not a cancellation. */
        }
        job.status = wasCancelled ? 'cancelled' : 'failed';
        job.error = wasCancelled
          ? null
          : ['job_timeout', 'resource_limit'].includes(error.message)
            ? error.message
            : 'runner_failed';
        job.updated_at = new Date().toISOString();
        atomicWrite(dir, `job-${job.id}.json`, job);
      });
      if (job.status === 'cancelled')
        return { status: 'cancelled', id: job.id, samples: job.results.length };
    }
    throw error;
  } finally {
    release();
  }
}

async function main() {
  if (process.argv.length !== 5 || process.argv[2] !== '--once' || process.argv[3] !== '--config')
    throw new Error('usage_once_config');
  process.umask(0o077);
  const runtimeLimits = assertRuntime();
  const settings = config(process.argv[4]);
  settings.runtimeLimits = runtimeLimits;
  // This development runner has no daemon, scheduler, FPM child command or auto-install.
  const { chromium } = require('playwright-core');
  const controller = new AbortController();
  let exitCode = 0;
  const terminate = () => {
    exitCode = 143;
    controller.abort();
  };
  const interrupt = () => {
    exitCode = 130;
    controller.abort();
  };
  process.once('SIGTERM', terminate);
  process.once('SIGINT', interrupt);
  try {
    const output = await processOnce(
      settings,
      (job, policy, options) => runMeasurements(job, policy, { ...options, chromium }),
      { signal: controller.signal },
    );
    process.stdout.write(JSON.stringify(output) + '\n');
    if (exitCode) process.exitCode = exitCode;
  } finally {
    process.removeListener('SIGTERM', terminate);
    process.removeListener('SIGINT', interrupt);
  }
}

function reportFailure(error) {
  const allowed = [
    'queue_busy',
    'invalid_directory',
    'invalid_config',
    'invalid_browser',
    'invalid_file',
    'invalid_report',
    'budget_cleanup_required',
    'usage_once_config',
    'job_timeout',
    'resource_limit',
    'runtime_unrestricted',
    'runtime_not_private',
    'runtime_wrong_user',
    'runtime_unavailable',
  ];
  process.stderr.write((allowed.includes(error.message) ? error.message : 'runner_failed') + '\n');
  process.exitCode = 1;
}

if (require.main === module) main().catch(reportFailure);

module.exports = { processOnce, main, reportFailure };
