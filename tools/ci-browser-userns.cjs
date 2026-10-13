/* GitHub hosted runner only. This exception permits Chromium's own namespace sandbox;
 * it is not a full AppArmor file-access profile and never changes global sysctls.
 * https://chromium.googlesource.com/chromium/src/+/main/docs/security/apparmor-userns-restrictions.md
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const profileName = 'pagenest-companion-ci-shell';

function validateBrowser(expected, canonical) {
  assert.equal(path.resolve(expected), expected, 'Browser path must be absolute and normalized');
  assert.equal(canonical, expected, 'Pinned shell path must not contain symbolic links');
  assert.match(canonical, /^\/[A-Za-z0-9_./-]+$/, 'Browser path contains AppArmor metacharacters');
  assert.equal(
    path.basename(canonical),
    'headless_shell',
    'Only the pinned headless shell is allowed',
  );
  return canonical;
}
function resolveBrowser() {
  const manifest = require('../package.json');
  const packagePath = require.resolve('playwright-core/package.json');
  const installed = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
  assert.equal(
    installed.version,
    manifest.devDependencies['@playwright/test'],
    'Playwright version must match the package pin',
  );
  const { registry } = require(
    path.join(path.dirname(packagePath), 'lib/server/registry/index.js'),
  );
  const expected = registry.findExecutable('chromium-headless-shell').executablePath();
  const canonical = validateBrowser(expected, fs.realpathSync(expected));
  assert.ok(fs.statSync(canonical).isFile(), 'Pinned shell must be a regular file');
  fs.accessSync(canonical, fs.constants.X_OK);
  return canonical;
}
function selectAbi(available) {
  const abi = ['4.0', '5.0'].find((candidate) => available.includes(candidate));
  assert.ok(abi, 'An installed AppArmor ABI 4.0 or 5.0 is required');
  return abi;
}
function buildProfile(browser, abi) {
  validateBrowser(browser, browser);
  assert.ok(['4.0', '5.0'].includes(abi), 'Unsupported AppArmor ABI');
  return `# Ephemeral CI only: exact pinned Playwright shell, no wildcard attachment.\nabi <abi/${abi}>,\ninclude <tunables/global>\n\nprofile ${profileName} "${browser}" flags=(unconfined) {\n  userns,\n}\n`;
}
function assertHostedRunner(environment) {
  assert.equal(
    environment.GITHUB_ACTIONS,
    'true',
    'Profile installation is restricted to GitHub Actions',
  );
  assert.equal(
    environment.RUNNER_ENVIRONMENT,
    'github-hosted',
    'A GitHub hosted ephemeral runner is required',
  );
}
function readSetting(file) {
  try {
    return fs.readFileSync(file, 'utf8').trim();
  } catch (error) {
    if (error.code === 'ENOENT') return undefined;
    throw error;
  }
}
function main(args) {
  assert.ok(
    args.length === 0 || (args.length === 1 && args[0] === '--print-profile'),
    'Only --print-profile is supported',
  );
  const printOnly = args[0] === '--print-profile';
  if (!printOnly) assertHostedRunner(process.env);
  const browser = resolveBrowser();
  if (
    !printOnly &&
    (process.platform !== 'linux' ||
      readSetting('/sys/module/apparmor/parameters/enabled') !== 'Y' ||
      readSetting('/proc/sys/kernel/apparmor_restrict_unprivileged_userns') !== '1')
  ) {
    console.log('AppArmor user namespace restriction is not enabled; no profile installed.');
    return;
  }
  const abi = selectAbi(fs.readdirSync('/etc/apparmor.d/abi'));
  const profile = buildProfile(browser, abi);
  if (printOnly) {
    process.stdout.write(profile);
    return;
  }
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'pagenest-ci-userns-'));
  try {
    const source = path.join(directory, profileName);
    const target = '/etc/apparmor.d/' + profileName;
    fs.writeFileSync(source, profile, { mode: 0o600 });
    execFileSync(
      'sudo',
      ['-n', 'install', '-o', 'root', '-g', 'root', '-m', '0644', source, target],
      { stdio: 'inherit' },
    );
    execFileSync('sudo', ['-n', 'apparmor_parser', '--replace', target], { stdio: 'inherit' });
    console.log(
      `Loaded exact pinned headless-shell userns exception with ABI ${abi}; Chromium sandbox remains enabled.`,
    );
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
}
module.exports = { validateBrowser, selectAbi, buildProfile, assertHostedRunner };
if (require.main === module) main(process.argv.slice(2));
