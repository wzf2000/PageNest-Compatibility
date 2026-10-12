'use strict';
const { prepareRuntimeTemporaryDirectory, assertRuntime } = require('./runtime.cjs');
const { immutableBrowser, assertChromiumSandbox } = require('./sandbox.cjs');
function parseArguments(args) {
  if (
    !Array.isArray(args) ||
    args.length !== 2 ||
    args[0] !== '--browser' ||
    typeof args[1] !== 'string' ||
    !args[1].startsWith('/') ||
    args[1].includes('\0') ||
    args[1].includes('\\')
  )
    throw new Error('preflight_arguments');
  return args[1];
}
async function main(args = process.argv.slice(2), dependencies = {}) {
  process.umask(0o077);
  const executablePath = parseArguments(args);
  const controller = new AbortController();
  let receivedSignal;
  const stop = (signal) => {
    receivedSignal = signal;
    controller.abort();
  };
  const interrupt = () => stop('SIGINT');
  const terminate = () => stop('SIGTERM');
  process.once('SIGINT', interrupt);
  process.once('SIGTERM', terminate);
  try {
    (dependencies.prepare || prepareRuntimeTemporaryDirectory)();
    (dependencies.assertRuntime || assertRuntime)();
    (dependencies.immutableBrowser || immutableBrowser)(executablePath);
    // No configuration, queue or browser dependency is loaded before both real runtime checks.
    const { chromium } = (dependencies.loadChromium || (() => require('playwright-core')))();
    const proof = await (dependencies.checkSandbox || assertChromiumSandbox)({
      chromium,
      executablePath,
      signal: controller.signal,
    });
    if (receivedSignal) throw new Error('sandbox_unverified');
    return { schema_version: 1, ok: true, ...proof };
  } finally {
    process.removeListener('SIGINT', interrupt);
    process.removeListener('SIGTERM', terminate);
    if (receivedSignal) process.exitCode = receivedSignal === 'SIGINT' ? 130 : 143;
  }
}
/** Executable-only terminal wrapper. Bounded helper cleanup can reject while an uncooperative
 * Playwright launch still holds Node handles. Exit after flushing the safe failure record so
 * systemd observes MainPID exit and KillMode=control-group terminates remaining children.
 * The exported main() never calls this wrapper or process.exit().
 */
function reportFailure(error, terminal = process) {
  const allowed = [
    'sandbox_unverified',
    'runtime_unrestricted',
    'runtime_not_private',
    'runtime_wrong_user',
    'runtime_unavailable',
    'preflight_arguments',
  ];
  const code = allowed.includes(error?.message) ? error.message : 'sandbox_unverified';
  const exitCode = [130, 143].includes(terminal.exitCode) ? terminal.exitCode : 1;
  terminal.exitCode = exitCode;
  terminal.stdout.write(
    JSON.stringify({ schema_version: 1, ok: false, error: code }) + '\n',
    () => {
      terminal.exit(exitCode);
    },
  );
}
if (require.main === module) {
  main().then((proof) => process.stdout.write(JSON.stringify(proof) + '\n'), reportFailure);
}
module.exports = { main, parseArguments, reportFailure };
