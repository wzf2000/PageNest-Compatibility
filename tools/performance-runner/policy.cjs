const http = require('node:http');
const net = require('node:net');
const dns = require('node:dns').promises;
const { LIMITS } = require('./budget.cjs');

const UA_TOKEN = 'PageNestPerformance/1.0';
const SENSITIVE =
  /(?:^|\/)(?:wp-admin|wp-login\.php|wp-signup\.php|wp-activate\.php|wp-cron\.php|wp-comments-post\.php|wp-json|xmlrpc\.php|oauth|callback|logout|login|register|preview)(?:\/|$)/i;
const ASSET_TYPES = new Set(['stylesheet', 'script', 'image', 'font', 'media']);
const ASSET_EXTENSION =
  /\.(?:css|js|mjs|png|jpe?g|svg|gif|webp|avif|ico|woff2?|ttf|otf|mp[34]|ogg|wav|pdf|wasm)$/i;
const blocked = new net.BlockList();
for (const [address, prefix] of [
  ['0.0.0.0', 8],
  ['10.0.0.0', 8],
  ['100.64.0.0', 10],
  ['127.0.0.0', 8],
  ['169.254.0.0', 16],
  ['172.16.0.0', 12],
  ['192.0.0.0', 24],
  ['192.0.2.0', 24],
  ['192.88.99.0', 24],
  ['192.168.0.0', 16],
  ['198.18.0.0', 15],
  ['198.51.100.0', 24],
  ['203.0.113.0', 24],
  ['224.0.0.0', 3],
])
  blocked.addSubnet(address, prefix, 'ipv4');
const globalV6 = new net.BlockList();
globalV6.addSubnet('2000::', 3, 'ipv6');
for (const [address, prefix] of [
  ['2001::', 23],
  ['2001:db8::', 32],
  ['2002::', 16],
  ['3fff::', 20],
])
  blocked.addSubnet(address, prefix, 'ipv6');

function publicAddress(address) {
  const family = net.isIP(address);
  return family === 4
    ? !blocked.check(address, 'ipv4')
    : family === 6 && globalV6.check(address, 'ipv6') && !blocked.check(address, 'ipv6');
}

function normalizeOrigin(value, fixture = false) {
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error('invalid_origin');
  }
  if (
    url.username ||
    url.password ||
    url.search ||
    url.hash ||
    url.pathname !== '/' ||
    (!fixture && (url.protocol !== 'https:' || url.port)) ||
    (fixture && !['https:', 'http:'].includes(url.protocol))
  )
    throw new Error('invalid_origin');
  if (net.isIP(url.hostname.replace(/^\[|\]$/g, '')) && !fixture) throw new Error('invalid_origin');
  return url.origin;
}

function makePolicy(origin, assetHosts = [], fixture = false) {
  const normalized = normalizeOrigin(origin, fixture);
  const home = new URL(normalized);
  if (
    !Array.isArray(assetHosts) ||
    assetHosts.length > 16 ||
    assetHosts.some(
      (host) =>
        typeof host !== 'string' ||
        !/^(?=.{1,253}$)[a-z0-9]+(?:[.-][a-z0-9]+)*$/.test(host) ||
        net.isIP(host),
    )
  )
    throw new Error('invalid_asset_hosts');
  const hosts = new Set([home.hostname, ...assetHosts]);
  function allowedUrl(value, document = false) {
    try {
      const url = new URL(value);
      const decoded = decodeURIComponent(url.pathname);
      if (
        url.username ||
        url.password ||
        url.hash ||
        SENSITIVE.test(decoded) ||
        /[%\\\x00-\x20]/.test(decoded)
      )
        return false;
      if (fixture && url.origin === normalized) return !document || !url.search;
      if (url.protocol !== 'https:' || url.port || !hosts.has(url.hostname)) return false;
      return !document || (url.origin === normalized && !url.search);
    } catch {
      return false;
    }
  }
  return {
    origin: normalized,
    hosts,
    fixture,
    allowedUrl,
    allowedRequest(request) {
      if (!['GET', 'HEAD'].includes(request.method())) return false;
      if (request.isNavigationRequest()) {
        return (
          request.frame() === request.frame().page().mainFrame() && allowedUrl(request.url(), true)
        );
      }
      return (
        ASSET_TYPES.has(request.resourceType()) &&
        allowedUrl(request.url()) &&
        ASSET_EXTENSION.test(new URL(request.url()).pathname)
      );
    },
    async resolve(host, lookup = dns.lookup) {
      if (!hosts.has(host)) throw new Error('host_blocked');
      if (fixture && host === home.hostname && ['127.0.0.1', 'localhost'].includes(host))
        return { address: '127.0.0.1', family: 4 };
      const addresses = await lookup(host, { all: true, verbatim: true });
      if (!addresses.length || addresses.some(({ address }) => !publicAddress(address)))
        throw new Error('address_blocked');
      return addresses[0];
    },
  };
}

// Chromium can only CONNECT to allowlisted TLS hosts. The socket uses the verified IP,
// so a second browser DNS lookup cannot rebind it to a private service.
async function startProxy(
  policy,
  {
    maxConnections = LIMITS.connections,
    maxBytes = LIMITS.proxy_bytes_per_job,
    onLimit = () => {},
  } = {},
) {
  const sockets = new Set();
  const clients = new Set();
  let bytes = 0;
  let exhausted = false;
  function limit() {
    if (exhausted) return;
    exhausted = true;
    for (const socket of sockets) socket.destroy();
    onLimit();
  }
  function received(chunk) {
    bytes += chunk.length;
    if (bytes > maxBytes) limit();
  }
  const server = http.createServer(async (req, res) => {
    // Plain HTTP is exclusively for the synthetic loopback fixture; CLI never enables it.
    if (
      exhausted ||
      !policy.fixture ||
      !['GET', 'HEAD'].includes(req.method) ||
      !policy.allowedUrl(req.url)
    ) {
      res.writeHead(403);
      res.end();
      return;
    }
    try {
      const url = new URL(req.url);
      if (url.origin !== policy.origin || url.protocol !== 'http:') throw new Error('blocked');
      const address = await policy.resolve(url.hostname);
      const upstream = http.request(
        {
          hostname: address.address,
          port: url.port || 80,
          path: url.pathname + url.search,
          method: req.method,
          agent: false,
          headers: { ...req.headers, host: url.host },
          timeout: 15000,
        },
        (incoming) => {
          incoming.on('data', received);
          res.writeHead(incoming.statusCode, incoming.headers);
          incoming.pipe(res);
        },
      );
      sockets.add(upstream);
      upstream.on('close', () => sockets.delete(upstream));
      upstream.on('error', () => {
        if (!res.headersSent) res.writeHead(502);
        res.end();
      });
      upstream.on('timeout', () => upstream.destroy());
      req.pipe(upstream);
    } catch {
      res.writeHead(403);
      res.end();
    }
  });
  server.on('connection', (socket) => {
    if (exhausted || clients.size >= maxConnections) {
      socket.destroy();
      limit();
      return;
    }
    clients.add(socket);
    sockets.add(socket);
    socket.on('data', received);
    socket.on('close', () => {
      clients.delete(socket);
      sockets.delete(socket);
    });
  });
  server.on('connect', async (req, client, head) => {
    try {
      if (exhausted) throw new Error('resource_limit');
      const fixtureOrigin = new URL(policy.origin);
      const fixtureConnect =
        policy.fixture && fixtureOrigin.protocol === 'https:' && req.url === fixtureOrigin.host;
      if (!fixtureConnect && !/^[a-z0-9.-]+:443$/.test(req.url)) throw new Error('blocked');
      const host = fixtureConnect ? fixtureOrigin.hostname : req.url.slice(0, -4);
      const address = await policy.resolve(host);
      if (exhausted || client.destroyed) return;
      const upstream = net.connect({
        host: address.address,
        family: address.family,
        port: fixtureConnect ? Number(fixtureOrigin.port || 443) : 443,
      });
      sockets.add(upstream);
      upstream.on('data', received);
      upstream.on('close', () => sockets.delete(upstream));
      upstream.setTimeout(30000, () => upstream.destroy());
      upstream.on('error', () => client.destroy());
      client.on('error', () => upstream.destroy());
      client.on('close', () => upstream.destroy());
      upstream.on('connect', () => {
        client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
        if (head.length) upstream.write(head);
        client.pipe(upstream);
        upstream.pipe(client);
      });
    } catch {
      client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
    }
  });
  await new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolve);
  });
  return {
    url: `http://127.0.0.1:${server.address().port}`,
    async close() {
      for (const socket of sockets) socket.destroy();
      await new Promise((resolve) => server.close(resolve));
    },
  };
}

module.exports = { UA_TOKEN, publicAddress, normalizeOrigin, makePolicy, startProxy };
