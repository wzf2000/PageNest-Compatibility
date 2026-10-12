const path = require('node:path');
const fs = require('node:fs');
const { config, readJson, atomicWrite, acquire, locked, reports } = require('./queue.cjs');
const { runMeasurements } = require('./measure.cjs');

async function processOnce(settings, engine) {
  const { dir, policy, executablePath } = settings;
  const release = acquire(dir, 'worker.lock');
  let job;
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
      selected.status = 'running';
      selected.updated_at = new Date().toISOString();
      atomicWrite(dir, `job-${selected.id}.json`, selected);
      return selected;
    });
    if (!job) return { status: 'idle' };
    const cancelled = () => {
      const filename = path.join(dir, `cancel-${job.id}`);
      if (!fs.existsSync(filename)) return false;
      const marker = readJson(filename);
      if (marker.schema_version !== 1 || marker.id !== job.id) throw new Error('invalid_cancel');
      return true;
    };
    const deadline = Math.min(Date.now() + 720000, Date.parse(job.expires_at));
    const outcome = await engine(job, policy, {
      executablePath,
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
    await locked(dir, () => {
      job.status = outcome.cancelled || cancelled() ? 'cancelled' : 'completed';
      job.error = null;
      job.updated_at = new Date().toISOString();
      atomicWrite(dir, `job-${job.id}.json`, job);
    });
    return { status: job.status, id: job.id, samples: job.results.length };
  } catch (error) {
    if (job) {
      job.status = 'failed';
      job.error = error.message === 'job_timeout' ? 'job_timeout' : 'runner_failed';
      job.updated_at = new Date().toISOString();
      await locked(dir, () => atomicWrite(dir, `job-${job.id}.json`, job));
    }
    throw error;
  } finally {
    release();
  }
}

async function main() {
  if (process.argv.length !== 5 || process.argv[2] !== '--once' || process.argv[3] !== '--config')
    throw new Error('usage_once_config');
  const settings = config(process.argv[4]);
  // This development runner has no daemon, scheduler, FPM child command or auto-install.
  const { chromium } = require('@playwright/test');
  const output = await processOnce(settings, (job, policy, options) =>
    runMeasurements(job, policy, { ...options, chromium }),
  );
  process.stdout.write(JSON.stringify(output) + '\n');
}

if (require.main === module)
  main().catch((error) => {
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
    ];
    process.stderr.write(
      (allowed.includes(error.message) ? error.message : 'runner_failed') + '\n',
    );
    process.exitCode = 1;
  });

module.exports = { processOnce };
