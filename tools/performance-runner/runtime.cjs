'use strict';
const fs = require('node:fs');
const path = require('node:path');
const MAX_MEMORY = 768 * 1024 * 1024;
const MIN_MEMORY = 64 * 1024 * 1024;
const MAX_TMP = 256 * 1024 * 1024;
const TMPFS = 0x1021994;
function fail(code) {
  throw new Error(code);
}
function canonical(value) {
  if (
    typeof value !== 'string' ||
    !value.startsWith('/') ||
    value.includes('\0') ||
    value.includes('\\') ||
    value.split('/').some((v) => v === '.' || v === '..') ||
    path.posix.normalize(value) !== value
  )
    fail('runtime_unavailable');
  return value;
}
function decodeMount(value) {
  return value.replace(/\\([0-7]{3})/g, (_, digits) => String.fromCharCode(parseInt(digits, 8)));
}
/** Pure parsing; never a fixture override for assertRuntime. */
function cgroupPaths(cgroup, mountinfo) {
  if (typeof cgroup !== 'string' || typeof mountinfo !== 'string') fail('runtime_unavailable');
  const entries = cgroup
    .trim()
    .split('\n')
    .filter((line) => line.startsWith('0::'));
  if (entries.length !== 1) fail('runtime_unavailable');
  const self = canonical(entries[0].slice(3));
  const matches = [];
  for (const line of mountinfo.trim().split('\n')) {
    const halves = line.split(' - ');
    if (halves.length !== 2 || halves[1].split(' ')[0] !== 'cgroup2') continue;
    const fields = halves[0].split(' ');
    if (fields.length < 6) fail('runtime_unavailable');
    const root = canonical(decodeMount(fields[3]));
    const mount = canonical(decodeMount(fields[4]));
    const visible = self === root || self.startsWith(root === '/' ? '/' : root + '/');
    // A cgroup namespace can expose '/' despite a host mount root such as '/slice/service'.
    const suffix = visible && root !== '/' ? self.slice(root.length) : self;
    const resolved = path.posix.join(mount, suffix).replace(/\/+$/, '') || '/';
    if (resolved !== mount && !resolved.startsWith(mount + '/')) fail('runtime_unavailable');
    matches.push({ path: canonical(resolved), mount, root, visible });
  }
  if (!matches.length) fail('runtime_unavailable');
  matches.sort((a, b) => Number(b.visible) - Number(a.visible) || b.root.length - a.root.length);
  return matches;
}
function positive(raw) {
  if (typeof raw !== 'string' || !/^[0-9]+$/.test(raw.trim())) fail('runtime_unrestricted');
  const value = Number(raw.trim());
  if (!Number.isSafeInteger(value) || value <= 0) fail('runtime_unrestricted');
  return value;
}
function limits(values) {
  if (!values || typeof values !== 'object' || typeof values.cpu !== 'string')
    fail('runtime_unrestricted');
  const cpu = values.cpu.trim().split(/\s+/);
  if (cpu.length !== 2) fail('runtime_unrestricted');
  const quota = positive(cpu[0]);
  const period = positive(cpu[1]);
  const memory = positive(values.memory);
  const pids = positive(values.pids);
  if (
    quota > period ||
    memory < MIN_MEMORY ||
    memory > MAX_MEMORY ||
    pids > 128 ||
    typeof values.swap !== 'string' ||
    !/^0\s*$/.test(values.swap)
  )
    fail('runtime_unrestricted');
  return {
    cpu_quota: quota,
    cpu_period: period,
    cpu_millis: (quota / period) * 1000,
    memory_bytes: memory,
    pids,
    swap_bytes: 0,
  };
}
function identity(platform, uid, status) {
  if (platform !== 'linux' || !Number.isInteger(uid) || uid < 0) fail('runtime_unavailable');
  if (uid === 0) fail('runtime_wrong_user');
  if (typeof status !== 'string' || !/^NoNewPrivs:\s+1\s*$/m.test(status))
    fail('runtime_unrestricted');
}
function tmpInfo({ dir, uid, owner, mode, type, blocks, bsize }) {
  canonical(dir);
  // Deployment examples use a private child of /tmp; web content directories are forbidden.
  if (
    /^\/(?:www|var\/www|srv\/(?:www|http)|usr\/share\/nginx\/html)(?:\/|$)/.test(dir) ||
    /(?:^|\/)(?:public_html|htdocs|wwwroot)(?:\/|$)/.test(dir) ||
    owner !== uid ||
    (mode & 0o077) !== 0
  )
    fail('runtime_not_private');
  let size;
  try {
    size = BigInt(blocks) * BigInt(bsize);
  } catch {
    fail('runtime_unrestricted');
  }
  if (Number(type) !== TMPFS || size <= 0n || size > BigInt(MAX_TMP)) fail('runtime_unrestricted');
  return { tmp_bytes: Number(size) };
}
function privatePath(location, code = 'runtime_unavailable') {
  try {
    canonical(location);
    if (fs.realpathSync(location) !== location) fail(code);
    for (let cursor = location; cursor !== '/'; cursor = path.dirname(cursor)) {
      if (fs.lstatSync(cursor).isSymbolicLink()) fail(code);
    }
  } catch {
    fail(code);
  }
}
function read(location) {
  privatePath(location);
  return fs.readFileSync(location, 'utf8');
}
function constraintValue(raw) {
  if (typeof raw !== 'string') fail('runtime_unavailable');
  const value = raw.trim();
  if (value === 'max') return 'max';
  if (!/^[0-9]+$/.test(value)) fail('runtime_unavailable');
  return BigInt(value).toString();
}
/** Pure normalized constraints; ancestor limits may be unlimited or stricter than the leaf. */
function constraints(values) {
  if (!values || typeof values.cpu !== 'string') fail('runtime_unavailable');
  const cpu = values.cpu.trim().split(/\s+/);
  if (cpu.length !== 2) fail('runtime_unavailable');
  const quota = constraintValue(cpu[0]);
  const period = constraintValue(cpu[1]);
  if (period === 'max' || period === '0' || quota === '0') fail('runtime_unavailable');
  return {
    cpu_max: quota + ' ' + period,
    memory_max: constraintValue(values.memory),
    memory_swap_max: constraintValue(values.swap),
    pids_max: constraintValue(values.pids),
  };
}
function hierarchy(group, mount) {
  const chain = [];
  for (let current = group; ; current = path.dirname(current)) {
    if (
      chain.length >= 64 ||
      (current !== mount.path && !current.startsWith(mount.path === '/' ? '/' : mount.path + '/'))
    )
      fail('runtime_unavailable');
    privatePath(current);
    const names = ['cpu.max', 'memory.max', 'memory.swap.max', 'pids.max'];
    const raw = names.map((name) => {
      const location = path.join(current, name);
      try {
        return read(location);
      } catch (error) {
        // Only a genuinely absent control file qualifies; unreadable files never count as unlimited.
        try {
          fs.lstatSync(location);
        } catch (cause) {
          if (cause.code === 'ENOENT') return null;
        }
        throw error;
      }
    });
    if (raw.every((v) => v === null)) {
      if (current !== mount.path || mount.root !== '/') fail('runtime_unavailable');
      try {
        fs.lstatSync(path.join(current, 'cgroup.type'));
        fail('runtime_unavailable');
      } catch (error) {
        if (error.code !== 'ENOENT') throw error;
      }
      // Kernel cgroup-v2 root has controllers but no cgroup.type or per-cgroup resource maxima.
      read(path.join(current, 'cgroup.controllers'));
      chain.push({ kind: 'cgroup-root-unlimited' });
    } else {
      if (raw.some((v) => v === null)) fail('runtime_unavailable');
      chain.push(constraints({ cpu: raw[0], memory: raw[1], swap: raw[2], pids: raw[3] }));
    }
    if (current === mount.path) break;
  }
  return chain;
}
function actualResources() {
  const uid = typeof process.getuid === 'function' ? process.getuid() : -1;
  identity(process.platform, uid, fs.readFileSync('/proc/self/status', 'utf8'));
  const candidates = cgroupPaths(
    fs.readFileSync('/proc/self/cgroup', 'utf8'),
    fs.readFileSync('/proc/self/mountinfo', 'utf8'),
  );
  let group;
  let matchedMount;
  for (const candidate of candidates) {
    try {
      privatePath(candidate.path);
      const members = read(path.join(candidate.path, 'cgroup.procs')).trim().split(/\s+/);
      if (members.includes(String(process.pid))) {
        group = candidate.path;
        matchedMount = { path: candidate.mount, root: candidate.root };
        break;
      }
    } catch {
      /* Another mount may expose the same cgroup under a valid namespace path. */
    }
  }
  if (!group) fail('runtime_unavailable');
  const measured = limits({
    cpu: read(path.join(group, 'cpu.max')),
    memory: read(path.join(group, 'memory.max')),
    swap: read(path.join(group, 'memory.swap.max')),
    pids: read(path.join(group, 'pids.max')),
  });
  measured.hierarchy = hierarchy(group, matchedMount);
  return { uid, measured };
}
/** Trusted production entry: no arguments, environment bypasses or caller-provided paths. */
function assertRuntime() {
  if (arguments.length) fail('runtime_unavailable');
  try {
    const { uid, measured } = actualResources();
    const dir = process.env.TMPDIR;
    if (!dir) fail('runtime_not_private');
    privatePath(dir, 'runtime_not_private');
    const stat = fs.lstatSync(dir);
    if (!stat.isDirectory()) fail('runtime_not_private');
    const disk = fs.statfsSync(dir, { bigint: true });
    return {
      ...measured,
      ...tmpInfo({
        dir,
        uid,
        owner: stat.uid,
        mode: stat.mode,
        type: disk.type,
        blocks: disk.blocks,
        bsize: disk.bsize,
      }),
    };
  } catch (error) {
    const known = [
      'runtime_unrestricted',
      'runtime_not_private',
      'runtime_wrong_user',
      'runtime_unavailable',
    ];
    fail(known.includes(error?.message) ? error.message : 'runtime_unavailable');
  }
}
/** Deployment-only preparation in the same process and mount namespace as the runner. */
function prepareRuntimeTemporaryDirectory() {
  if (arguments.length) fail('runtime_unavailable');
  try {
    const { uid } = actualResources();
    const dir = process.env.TMPDIR;
    if (!dir) fail('runtime_not_private');
    canonical(dir);
    const parent = path.dirname(dir);
    if (parent === dir) fail('runtime_not_private');
    privatePath(parent, 'runtime_not_private');
    if (!fs.lstatSync(parent).isDirectory()) fail('runtime_not_private');
    const disk = fs.statfsSync(parent, { bigint: true });
    // Validate the filesystem and destination before making any changes. Never create in normal /tmp.
    tmpInfo({
      dir,
      uid,
      owner: uid,
      mode: 0o700,
      type: disk.type,
      blocks: disk.blocks,
      bsize: disk.bsize,
    });
    try {
      fs.mkdirSync(dir, { mode: 0o700 });
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
    }
    return assertRuntime();
  } catch (error) {
    const known = [
      'runtime_unrestricted',
      'runtime_not_private',
      'runtime_wrong_user',
      'runtime_unavailable',
    ];
    fail(known.includes(error?.message) ? error.message : 'runtime_unavailable');
  }
}
module.exports = {
  assertRuntime,
  prepareRuntimeTemporaryDirectory,
  cgroupPaths,
  limits,
  constraints,
  identity,
  tmpInfo,
  privatePath,
};
