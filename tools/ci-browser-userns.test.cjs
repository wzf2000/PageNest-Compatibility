/* Pure CI preparation contracts: no sudo, profile loading or browser launch. */
const assert = require('node:assert/strict');
const {
  validateBrowser,
  selectAbi,
  buildProfile,
  assertHostedRunner,
} = require('./ci-browser-userns.cjs');
const browser =
  '/home/runner/.cache/ms-playwright/chromium_headless_shell-1193/chrome-linux/headless_shell';
assert.equal(validateBrowser(browser, browser), browser);
for (const invalid of [
  'relative/headless_shell',
  '/tmp/**/headless_shell',
  '/tmp/{other}/headless_shell',
  '/tmp/a"/headless_shell',
  '/tmp/chrome',
  '/tmp/a/../headless_shell',
]) {
  assert.throws(() => validateBrowser(invalid, invalid));
}
assert.throws(() => validateBrowser(browser, '/opt/replaced/headless_shell'));
assert.equal(selectAbi(['3.0', '4.0', '5.0']), '4.0');
assert.equal(selectAbi(['5.0']), '5.0');
assert.throws(() => selectAbi(['3.0']));
for (const abi of ['4.0', '5.0']) {
  const profile = buildProfile(browser, abi);
  assert.ok(profile.includes(`abi <abi/${abi}>,`));
  assert.ok(profile.includes(`"${browser}" flags=(unconfined)`));
  assert.equal((profile.match(/userns,/g) || []).length, 1);
  assert.ok(!profile.includes('**') && !profile.includes('sysctl'));
}
assert.throws(() => buildProfile(browser, '3.0'));
assert.throws(() => assertHostedRunner({}));
assert.throws(() =>
  assertHostedRunner({ GITHUB_ACTIONS: 'true', RUNNER_ENVIRONMENT: 'self-hosted' }),
);
assertHostedRunner({ GITHUB_ACTIONS: 'true', RUNNER_ENVIRONMENT: 'github-hosted' });
console.log('CI exact-browser path, ABI and hosted-runner guard contracts passed.');
