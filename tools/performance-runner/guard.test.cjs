const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { startGuard } = require('./guard.cjs');

const flush = () => new Promise(setImmediate);
const policy = { allowedUrl: () => true, allowedRequest: () => true };
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}
function value(targetId = 'main') {
  return {
    targetId,
    frameId: 'frame',
    url: 'https://example.invalid/',
    row: { blocked_requests: 0 },
  };
}
function paused() {
  return {
    requestId: 'request',
    frameId: 'frame',
    resourceType: 'Document',
    request: { method: 'GET', url: 'https://example.invalid/', headers: {} },
  };
}
async function fixture() {
  let closeCalls = 0;
  const guard = await startGuard({ close: async () => closeCalls++ }, policy);
  const session = new EventEmitter();
  const calls = [];
  session.send = async (method, params) => {
    calls.push({ method, params });
    return {};
  };
  const binding = value();
  await guard.bind(session, binding);
  return { guard, session, calls, binding, closeCalls: () => closeCalls };
}
async function main() {
  // A live child loses its attachment; the target might still run unguarded.
  const child = await fixture();
  child.session.emit('Target.attachedToTarget', {
    sessionId: 'child',
    targetInfo: { type: 'iframe', targetId: 'child' },
  });
  child.session.emit('Target.detachedFromTarget', { sessionId: 'child' });
  await flush();
  assert.equal(child.guard.healthy(), false, 'live child detach must remain fail closed');
  assert.equal(child.closeCalls(), 1);
  await child.guard.close();

  // Explicit retirement also disposes pending child setup before our page close.
  const retiringChild = await fixture();
  retiringChild.session.emit('Target.attachedToTarget', {
    sessionId: 'child',
    targetInfo: { type: 'iframe', targetId: 'child' },
  });
  retiringChild.guard.retire();
  retiringChild.session.emit('Target.detachedFromTarget', { sessionId: 'child' });
  await flush();
  assert.equal(retiringChild.guard.healthy(), true);
  assert.equal(retiringChild.closeCalls(), 0);
  await retiringChild.guard.close();

  // The page is intentionally closed with an anonymous-request command still in flight.
  for (const settle of ['reject', 'resolve']) {
    const retired = await fixture();
    const pending = deferred();
    const send = retired.session.send;
    retired.session.send = (method, params) =>
      method === 'Network.clearBrowserCookies' ? pending.promise : send(method, params);
    retired.session.emit('Fetch.requestPaused', paused());
    retired.guard.retire();
    const next = new EventEmitter();
    next.send = async () => ({});
    const nextBinding = value('next');
    await retired.guard.bind(next, nextBinding);
    pending[settle](new Error('synthetic closed target'));
    retired.session.emit('Fetch.requestPaused', {
      ...paused(),
      request: { ...paused().request, method: 'POST' },
    });
    await flush();
    assert.equal(retired.guard.healthy(), true);
    assert.equal(retired.closeCalls(), 0);
    assert.equal(retired.binding.row.blocked_requests, 0);
    assert.equal(nextBinding.row.blocked_requests, 0, 'old target must not mutate the new row');
    assert.equal(
      retired.calls.filter(({ method }) => method === 'Fetch.continueRequest').length,
      0,
    );
    assert.equal(retired.session.listenerCount('Fetch.requestPaused'), 0);
    await retired.guard.close();
  }

  // The same failure while the binding is live remains fatal. Never classify by error text.
  const live = await fixture();
  live.session.send = async () => {
    throw new Error('Target page, context or browser has been closed');
  };
  live.session.emit('Fetch.requestPaused', paused());
  await flush();
  assert.equal(live.guard.healthy(), false);
  assert.equal(live.closeCalls(), 1);
  assert.throws(() => live.guard.assertHealthy(), { message: 'guard_failed' });
  await live.guard.close();

  const failedChild = await fixture();
  failedChild.session.emit('Target.attachedToTarget', {
    sessionId: 'child',
    targetInfo: { type: 'iframe', targetId: 'child' },
  });
  failedChild.session.emit('Target.receivedMessageFromTarget', {
    sessionId: 'child',
    message: JSON.stringify({ id: 1, error: { code: -32000, message: 'synthetic failure' } }),
  });
  await flush();
  assert.equal(failedChild.closeCalls(), 1, 'live child initialization failure stays fail closed');
  await failedChild.guard.close();

  const setup = await fixture();
  setup.guard.retire();
  const broken = new EventEmitter();
  broken.send = async () => {
    throw new Error('synthetic setup failure');
  };
  await assert.rejects(setup.guard.bind(broken, value()), /synthetic setup failure/);
  assert.equal(setup.closeCalls(), 1, 'main guard initialization failure stays fail closed');
  await setup.guard.close();
  console.log('Guard retirement, child detach, late events and live fail-closed errors: passed');
}
main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
