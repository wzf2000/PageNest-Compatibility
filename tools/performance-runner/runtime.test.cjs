'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const runtime = require('./runtime.cjs');
let checks = 0;
function check(fn) {
  fn();
  checks++;
}
function rejects(fn, code) {
  check(() => assert.throws(fn, { message: code }));
}
const { createHash } = require('node:crypto');
const digest = (value) => createHash('sha256').update(JSON.stringify(value)).digest('hex');
const leafRaw = { cpu: '50000 100000', memory: '805306368', swap: '0', pids: '96' };
const ancestorUnlimited = { cpu: 'max 100000', memory: 'max', swap: 'max', pids: 'max' };
const ancestorRestricted = { cpu: '25000 100000', memory: '268435456', swap: '0', pids: '64' };
check(() =>
  assert.notEqual(
    digest([runtime.constraints(leafRaw), runtime.constraints(ancestorUnlimited)]),
    digest([runtime.constraints(leafRaw), runtime.constraints(ancestorRestricted)]),
  ),
);
check(() =>
  assert.deepEqual(
    runtime.constraints({
      cpu: '  050000  0100000\n',
      memory: '0805306368\n',
      swap: '0',
      pids: '096',
    }),
    runtime.constraints(leafRaw),
  ),
);
check(() =>
  assert.equal(
    runtime.constraints({ ...ancestorUnlimited, memory: '9223372036854771712' }).memory_max,
    '9223372036854771712',
  ),
);
for (const field of ['cpu', 'memory', 'swap', 'pids']) {
  const missing = { ...ancestorUnlimited };
  delete missing[field];
  rejects(() => runtime.constraints(missing), 'runtime_unavailable');
}
for (const cpu of ['max max', '0 100000', 'max 0', 'max 100000 trailing'])
  rejects(() => runtime.constraints({ ...ancestorUnlimited, cpu }), 'runtime_unavailable');
const good = { cpu: '50000 100000\n', memory: String(768 * 1024 * 1024), swap: '0\n', pids: '96' };
check(() =>
  assert.deepEqual(runtime.limits(good), {
    cpu_quota: 50000,
    cpu_period: 100000,
    cpu_millis: 500,
    memory_bytes: 768 * 1024 * 1024,
    swap_bytes: 0,
    pids: 96,
  }),
);
for (const [field, value] of [
  ['cpu', 'max 100000'],
  ['cpu', '100001 100000'],
  ['cpu', '0 100000'],
  ['cpu', '1 0'],
  ['cpu', '100000'],
  ['memory', 'max'],
  ['memory', String(768 * 1024 * 1024 + 1)],
  ['memory', String(64 * 1024 * 1024 - 1)],
  ['memory', '-1'],
  ['memory', '1e8'],
  ['memory', '99999999999999999999'],
  ['swap', 'max'],
  ['swap', '1'],
  ['pids', 'max'],
  ['pids', '129'],
  ['pids', '0'],
]) {
  rejects(() => runtime.limits({ ...good, [field]: value }), 'runtime_unrestricted');
}
check(() =>
  assert.equal(
    runtime.limits({ ...good, cpu: '100000 100000', memory: String(64 * 1024 * 1024), pids: '128' })
      .cpu_millis,
    1000,
  ),
);
rejects(() => runtime.identity('linux', 0, 'NoNewPrivs:\t1\n'), 'runtime_wrong_user');
rejects(() => runtime.identity('linux', 1000, 'NoNewPrivs:\t0\n'), 'runtime_unrestricted');
rejects(() => runtime.identity('linux', 1000, 'Name:\ttest\n'), 'runtime_unrestricted');
rejects(() => runtime.identity('darwin', 1000, 'NoNewPrivs:\t1\n'), 'runtime_unavailable');
check(() => runtime.identity('linux', 1000, 'Name:\ttest\nNoNewPrivs:\t1\n'));
const mount = (root = '/', location = '/sys/fs/cgroup') =>
  `19 18 0:30 ${root} ${location} rw,nosuid,nodev,noexec - cgroup2 cgroup rw\n`;
check(() =>
  assert.equal(
    runtime.cgroupPaths('0::/slice/runner\n', mount())[0].path,
    '/sys/fs/cgroup/slice/runner',
  ),
);
check(() =>
  assert.equal(
    runtime.cgroupPaths('0::/slice/runner\n', mount('/slice'))[0].path,
    '/sys/fs/cgroup/runner',
  ),
);
check(() =>
  assert.equal(runtime.cgroupPaths('0::/\n', mount('/slice/runner'))[0].path, '/sys/fs/cgroup'),
);
check(() =>
  assert.equal(
    runtime.cgroupPaths('0::/child\n', mount('/host/runner'))[0].path,
    '/sys/fs/cgroup/child',
  ),
);
check(() =>
  assert.equal(
    runtime.cgroupPaths('0::/slice/runner\n', mount('/slice/runner'))[0].path,
    '/sys/fs/cgroup',
  ),
);
check(() =>
  assert.equal(
    runtime.cgroupPaths('0::/\n', mount('/', '/sys/cgroup\\040mount'))[0].path,
    '/sys/cgroup mount',
  ),
);
for (const value of [
  '0::/../escape',
  '0::/slice/../../escape',
  '0::relative',
  '0::/slice//child',
  '0::/ok\n0::/different',
])
  rejects(() => runtime.cgroupPaths(value, mount()), 'runtime_unavailable');
rejects(() => runtime.cgroupPaths('0::/safe', mount('/../../host')), 'runtime_unavailable');
rejects(() => runtime.cgroupPaths('0::/safe', ''), 'runtime_unavailable');
const tmp = {
  dir: '/tmp/private-runner',
  uid: 1000,
  owner: 1000,
  mode: 0o40700,
  type: 0x1021994,
  blocks: 65536n,
  bsize: 4096n,
};
check(() => assert.deepEqual(runtime.tmpInfo(tmp), { tmp_bytes: 256 * 1024 * 1024 }));
for (const change of [
  { owner: 1001 },
  { mode: 0o40750 },
  { mode: 0o40707 },
  { dir: '/var/www/private' },
  { dir: '/www/private' },
  { dir: '/home/user/public_html/private' },
  { dir: '/srv/http/private' },
])
  rejects(() => runtime.tmpInfo({ ...tmp, ...change }), 'runtime_not_private');
for (const change of [
  { type: 0xef53 },
  { blocks: 65537n },
  { blocks: 0n },
  { bsize: 'not-a-number' },
])
  rejects(() => runtime.tmpInfo({ ...tmp, ...change }), 'runtime_unrestricted');
rejects(() => runtime.assertRuntime({ fixture: true, dir: '/tmp' }), 'runtime_unavailable');
// A fresh child cannot acquire privileges, cgroup limits or temp mounts through environment switches.
const child = spawnSync(
  process.execPath,
  [
    '-e',
    `const r=require(${JSON.stringify(path.join(__dirname, 'runtime.cjs'))});try {r.assertRuntime(); process.exitCode=2;}catch(e){if(!['runtime_unrestricted','runtime_not_private','runtime_wrong_user','runtime_unavailable'].includes(e.message))process.exitCode=3;else process.stdout.write(e.message);}`,
  ],
  { encoding: 'utf8', env: { ...process.env, PAGENEST_RUNTIME_FIXTURE: '1', TMPDIR: '/tmp' } },
);
check(() => {
  assert.equal(child.error, undefined);
  assert.equal(child.status, 0);
  assert.match(child.stdout, /^runtime_(?:unrestricted|not_private|wrong_user|unavailable)$/);
  assert.equal(child.stderr, '');
});
const realCheck = 'unrestricted child rejected (' + child.stdout + ')';
const absent = path.join(__dirname, '.must-not-create-' + process.pid);
const preparation = spawnSync(
  process.execPath,
  [
    '-e',
    `const r=require(${JSON.stringify(path.join(__dirname, 'runtime.cjs'))});try {r.prepareRuntimeTemporaryDirectory(); process.exitCode=2;}catch(e){process.stdout.write(e.message);}`,
  ],
  { encoding: 'utf8', env: { ...process.env, TMPDIR: absent } },
);
check(() => {
  assert.equal(preparation.error, undefined);
  assert.equal(preparation.status, 0);
  assert.match(preparation.stdout, /^runtime_(?:unrestricted|wrong_user|unavailable)$/);
  assert.equal(fs.existsSync(absent), false);
});
rejects(() => runtime.prepareRuntimeTemporaryDirectory({ fixture: true }), 'runtime_unavailable');
const entry = spawnSync(process.execPath, [path.join(__dirname, 'entry.cjs')], {
  encoding: 'utf8',
  env: { ...process.env, TMPDIR: absent },
});
check(() => {
  assert.equal(entry.error, undefined);
  assert.equal(entry.status, 1);
  assert.match(entry.stderr, /^runtime_(?:unrestricted|wrong_user|unavailable)\n$/);
  assert.equal(entry.stdout, '');
  assert.equal(fs.existsSync(absent), false);
});

const fixture = fs.mkdtempSync(path.join(__dirname, '.runtime-test-'));
try {
  fs.mkdirSync(path.join(fixture, 'private'), { mode: 0o700 });
  fs.symlinkSync(path.join(fixture, 'private'), path.join(fixture, 'link'));
  check(() => runtime.privatePath(path.join(fixture, 'private'), 'runtime_not_private'));
  rejects(
    () => runtime.privatePath(path.join(fixture, 'link'), 'runtime_not_private'),
    'runtime_not_private',
  );
  fs.mkdirSync(path.join(fixture, 'private/child'), { mode: 0o700 });
  rejects(
    () => runtime.privatePath(path.join(fixture, 'link/child'), 'runtime_not_private'),
    'runtime_not_private',
  );
} finally {
  fs.rmSync(fixture, { recursive: true, force: true });
}

const deployment = fs.readFileSync(
  path.join(__dirname, 'deployment/pagenest-performance.service.example'),
  'utf8',
);
check(() => {
  assert.match(deployment, /^CPUQuota=50%$/m);
  assert.match(deployment, /^MemoryMax=768M$/m);
  assert.match(deployment, /^MemorySwapMax=0$/m);
  assert.match(deployment, /^TasksMax=96$/m);
  assert.match(deployment, /^NoNewPrivileges=yes$/m);
  assert.match(deployment, /^KillMode=control-group$/m);
  assert.doesNotMatch(
    deployment,
    /^\[(?:Install|Timer)\]|^Restart=|^RestrictNamespaces=|^ExecStartPre=/m,
  );
  assert.match(deployment, /^ExecStart=.*entry\.cjs /m);
});
const lock = JSON.parse(fs.readFileSync(path.join(__dirname, 'runtime/package-lock.json'), 'utf8'));
check(() => {
  assert.equal(lock.packages['node_modules/playwright-core'].version, '1.55.1');
  assert.equal(Object.keys(lock.packages).length, 2);
  assert.equal(lock.packages['node_modules/playwright-core'].dev, undefined);
  assert.match(lock.packages['node_modules/playwright-core'].integrity, /^sha512-/);
});
console.log(`Runtime contracts: ${checks} checks; ${realCheck}.`);
