// Opt-in Linux/systemd isolation test; uses disposable units, never installed services.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const runtime = require('./runtime.cjs');

function groupPath() {
  return runtime
    .cgroupPaths(
      fs.readFileSync('/proc/self/cgroup', 'utf8'),
      fs.readFileSync('/proc/self/mountinfo', 'utf8'),
    )
    .find((candidate) =>
      fs
        .readFileSync(path.join(candidate.path, 'cgroup.procs'), 'utf8')
        .trim()
        .split(/\s+/)
        .includes(String(process.pid)),
    ).path;
}
function counter(group, file, key) {
  return Number(
    fs
      .readFileSync(path.join(group, file), 'utf8')
      .split('\n')
      .find((line) => line.startsWith(key + ' '))
      .split(' ')[1],
  );
}
function exit(child) {
  return new Promise((resolve) => {
    if (child.exitCode !== null || child.signalCode !== null)
      resolve([child.exitCode, child.signalCode]);
    else child.once('exit', (code, signal) => resolve([code, signal]));
  });
}
async function worker(mode) {
  const limits = runtime.prepareRuntimeTemporaryDirectory();
  assert.equal(limits.cpu_millis, 250);
  assert.equal(limits.memory_bytes, 128 * 1024 * 1024);
  assert.equal(limits.pids, 32);
  assert.equal(limits.swap_bytes, 0);
  assert.equal(limits.tmp_bytes, 32 * 1024 * 1024);
  if (mode === 'termination') {
    const child = spawn('/bin/sleep', ['30']);
    await new Promise((resolve, reject) => {
      child.once('spawn', resolve);
      child.once('error', reject);
    });
    process.stdout.write(JSON.stringify({ parent: process.pid, child: child.pid }) + '\n');
    await exit(child);
    return;
  }
  const group = groupPath();
  const throttled = counter(group, 'cpu.stat', 'nr_throttled');
  const started = Date.now();
  while (Date.now() - started < 1200) Math.sqrt(Date.now());
  assert.ok(counter(group, 'cpu.stat', 'nr_throttled') > throttled, 'kernel throttles CPU');
  const children = [];
  let rejected = false;
  try {
    for (let i = 0; i < 40; i++) {
      const child = spawn('/bin/sleep', ['10']);
      const result = await new Promise((resolve) => {
        child.once('spawn', () => resolve(null));
        child.once('error', resolve);
      });
      if (result) {
        assert.equal(result.code, 'EAGAIN');
        rejected = true;
        break;
      }
      children.push(child);
    }
    assert.equal(rejected, true, 'kernel prevents exceeding TasksMax');
    assert.ok(counter(group, 'pids.events', 'max') > 0);
  } finally {
    for (const child of children) child.kill('SIGKILL');
    await Promise.all(children.map(exit));
  }
  const kills = counter(group, 'memory.events', 'oom_kill');
  // Bounded allocation only under the verified 128 MiB cgroup. Parent reports kernel evidence.
  const memory = spawn(
    process.execPath,
    [
      '-e',
      'const a=[];for(let i=0;i<5;i++)a.push(Buffer.alloc(32*1024*1024,1));setTimeout(()=>{},100);',
    ],
    { stdio: 'ignore' },
  );
  const [code, signal] = await exit(memory);
  assert.equal(code, null);
  assert.equal(signal, 'SIGKILL');
  assert.ok(counter(group, 'memory.events', 'oom_kill') > kills);
  process.stdout.write('Kernel CPU throttle, task ceiling and memory OOM: passed\n');
}
async function unit(mode) {
  const name = `pagenest-speed-fixture-${process.pid}-${mode}.service`;
  const account = execFileSync('/usr/bin/id', ['-un'], { encoding: 'utf8' }).trim();
  const group = execFileSync('/usr/bin/id', ['-gn'], { encoding: 'utf8' }).trim();
  const args = [
    '-n',
    '/usr/bin/systemd-run',
    '--wait',
    '--pipe',
    '--collect',
    '--quiet',
    '--unit=' + name,
  ];
  for (const property of [
    `User=${account}`,
    `Group=${group}`,
    'CPUQuota=25%',
    'CPUQuotaPeriodSec=100ms',
    'MemoryMax=128M',
    'MemorySwapMax=0',
    'TasksMax=32',
    'NoNewPrivileges=yes',
    'RuntimeMaxSec=20',
    'KillMode=control-group',
    'TimeoutStopSec=2',
    'OOMPolicy=continue',
    'TemporaryFileSystem=/tmp:size=32M,mode=1777',
  ])
    args.push('-p', property);
  args.push(
    '--setenv=TMPDIR=/tmp/pagenest-performance',
    process.execPath,
    __filename,
    '--worker',
    mode,
  );
  const processUnit = spawn('/usr/bin/sudo', args, { stdio: ['ignore', 'pipe', 'pipe'] });
  let output = '',
    errors = '',
    identities;
  let stop;
  processUnit.stdout.on('data', (chunk) => {
    output += chunk;
    if (mode === 'termination' && !identities && output.includes('\n')) {
      identities = JSON.parse(output.split('\n')[0]);
      stop = new Promise((resolve, reject) => {
        const stopping = spawn('/usr/bin/sudo', ['-n', '/usr/bin/systemctl', 'stop', name], {
          stdio: 'ignore',
        });
        stopping.once('error', reject);
        stopping.once('exit', (code) =>
          code === 0 ? resolve() : reject(new Error('fixture_stop_failed')),
        );
      });
    }
  });
  processUnit.stderr.on('data', (chunk) => {
    errors += chunk;
  });
  try {
    const [code] = await exit(processUnit);
    if (mode === 'termination') {
      assert.ok(identities && stop);
      await stop;
      assert.throws(() => process.kill(identities.parent, 0), { code: 'ESRCH' });
      assert.throws(() => process.kill(identities.child, 0), { code: 'ESRCH' });
      console.log('Transient service termination kills the entire process group: passed');
    } else {
      assert.equal(code, 0, errors);
      assert.match(output, /^Kernel CPU throttle, task ceiling and memory OOM: passed\n$/);
      process.stdout.write(output);
    }
  } finally {
    // Only this test's exact ephemeral unit is addressed, even if an assertion fails.
    const cleanup = spawn('/usr/bin/sudo', ['-n', '/usr/bin/systemctl', 'stop', name], {
      stdio: 'ignore',
    });
    await exit(cleanup);
  }
}
async function main() {
  if (process.argv[2] === '--worker') await worker(process.argv[3]);
  else {
    assert.ok(typeof process.getuid === 'function' && process.getuid() !== 0);
    await unit('caps');
    await unit('termination');
  }
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
