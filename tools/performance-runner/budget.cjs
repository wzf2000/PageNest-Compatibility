const { createHash } = require('node:crypto');

const LIMITS = Object.freeze({
  requests_per_navigation: 256,
  bytes_per_navigation: 16 * 1024 * 1024,
  proxy_bytes_per_job: 128 * 1024 * 1024,
  connections: 16,
  page_timeout_ms: 30000,
  job_timeout_ms: 720000,
});

function budgets(policy, fixtureLimits = {}) {
  if (!policy.fixture && Object.keys(fixtureLimits).length) throw new Error('resource_limit');
  const out = { ...LIMITS, ...fixtureLimits };
  for (const key of Object.keys(out)) {
    if (
      !Object.hasOwn(LIMITS, key) ||
      !Number.isSafeInteger(out[key]) ||
      out[key] < 1 ||
      out[key] > LIMITS[key]
    )
      throw new Error('resource_limit');
  }
  return Object.freeze(out);
}

function limitsHash(limits, runtime, sandbox) {
  return createHash('sha256')
    .update(
      JSON.stringify({
        application: limits,
        kernel: runtime || 'synthetic-fixture',
        chromium_sandbox: sandbox,
      }),
    )
    .digest('hex');
}

module.exports = { LIMITS, budgets, limitsHash };
