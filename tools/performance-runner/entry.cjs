'use strict';
const { prepareRuntimeTemporaryDirectory } = require('./runtime.cjs');
let reportFailure;
async function main() {
  process.umask(0o077);
  prepareRuntimeTemporaryDirectory();
  // The runtime guard must pass before loading the CLI or any browser dependencies.
  const cli = require('./cli.cjs');
  reportFailure = cli.reportFailure;
  await cli.main();
}
function failure(error) {
  if (typeof reportFailure === 'function') {
    reportFailure(error);
    // Unknown in-flight launch handles may still keep Node alive after bounded cleanup.
    // End the service's main process so KillMode=control-group collects every child.
    return process.stderr.write('', () => process.exit(process.exitCode || 1));
  }
  const allowed = [
    'runtime_unrestricted',
    'runtime_not_private',
    'runtime_wrong_user',
    'runtime_unavailable',
  ];
  process.stderr.write((allowed.includes(error?.message) ? error.message : 'runner_failed') + '\n');
  process.exitCode = 1;
  process.stderr.write('', () => process.exit(1));
}
if (require.main === module) main().catch(failure);
module.exports = { main };
