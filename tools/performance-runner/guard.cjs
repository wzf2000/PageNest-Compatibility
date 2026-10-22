const { EventEmitter } = require('node:events');

// A separate CDP attachment owns Fetch interception. Playwright's route handler skips
// redirected requests, so it must not be the security boundary for this runner.
class ChildSession extends EventEmitter {
  constructor(parent, id) {
    super();
    this.parent = parent;
    this.id = id;
    this.serial = 0;
    this.pending = new Map();
    this.closed = false;
  }
  send(method, params = {}) {
    if (this.closed) return Promise.reject(new Error('protocol_closed'));
    const id = ++this.serial;
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error('protocol_timeout'));
      }, 5000);
      this.pending.set(id, { resolve, reject, timer });
      this.parent
        .send('Target.sendMessageToTarget', {
          sessionId: this.id,
          message: JSON.stringify({ id, method, params }),
        })
        .catch(() => {
          clearTimeout(timer);
          this.pending.delete(id);
          reject(new Error('protocol_failed'));
        });
    });
  }
  receive(raw) {
    if (this.closed) return;
    const message = JSON.parse(raw);
    if (message.id) {
      const pending = this.pending.get(message.id);
      if (!pending) return;
      clearTimeout(pending.timer);
      this.pending.delete(message.id);
      if (message.error) pending.reject(new Error('protocol_failed'));
      else pending.resolve(message.result);
    } else if (message.method) this.emit(message.method, message.params);
  }
  dispose() {
    if (this.closed) return;
    this.closed = true;
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(new Error('protocol_closed'));
    }
    this.pending.clear();
    this.removeAllListeners();
  }
}

async function startGuard(browser, policy) {
  let active = null;
  let fault = false;
  const scopes = new Set();
  const types = {
    Stylesheet: 'stylesheet',
    Script: 'script',
    Image: 'image',
    Font: 'font',
    Media: 'media',
  };
  function fail(entry) {
    // Only explicit retirement before our own page/context close permits ignoring
    // in-flight teardown errors. A live child losing its CDP attachment is fatal.
    if (entry.closed || entry.scope.retired || fault) return;
    fault = true;
    browser.close().catch(() => {});
  }
  function listen(entry, name, callback) {
    entry.session.on(name, callback);
    entry.listeners.push(() => entry.session.off(name, callback));
  }
  function dispose(entry) {
    if (entry.closed) return;
    entry.closed = true;
    for (const child of entry.children.values()) dispose(child);
    entry.children.clear();
    for (const remove of entry.listeners) remove();
    entry.listeners.length = 0;
    if (entry.session instanceof ChildSession) entry.session.dispose();
  }
  function retire() {
    if (!active) return;
    const scope = active;
    active = null;
    scope.retired = true;
    dispose(scope.root);
    scopes.delete(scope);
  }
  function send(entry, method, params) {
    if (entry.closed || entry.scope.retired) return Promise.reject(new Error('protocol_closed'));
    return entry.session.send(method, params);
  }
  function intercept(entry) {
    listen(entry, 'Fetch.requestPaused', (event) => {
      if (entry.closed || entry.scope.retired) return;
      (async () => {
        const current = entry.scope.value;
        const targetInfo = entry.targetInfo;
        const document = event.resourceType === 'Document';
        const permittedTarget =
          active === entry.scope &&
          targetInfo.type === 'page' &&
          targetInfo.targetId === current.targetId;
        const request = {
          url: () => event.request.url,
          method: () => event.request.method,
          resourceType: () => types[event.resourceType] || 'other',
          isNavigationRequest: () => false,
        };
        const allowed =
          permittedTarget &&
          current.permitted?.() !== false &&
          ['GET', 'HEAD'].includes(event.request.method) &&
          (document
            ? event.frameId === current.frameId &&
              event.request.url === current.url &&
              policy.allowedUrl(event.request.url, true)
            : policy.allowedRequest(request));
        // 304 revalidates the same HTTP cache entry; it is not a navigation redirect.
        const redirect =
          event.responseStatusCode >= 300 &&
          event.responseStatusCode < 400 &&
          event.responseStatusCode !== 304;
        if (!allowed || redirect) {
          current.row.blocked_requests++;
          await send(entry, 'Fetch.failRequest', {
            requestId: event.requestId,
            errorReason: 'BlockedByClient',
          });
        } else if (event.responseStatusCode !== undefined) {
          const headers = (event.responseHeaders || []).filter(
            ({ name }) => !['set-cookie', 'set-cookie2'].includes(name.toLowerCase()),
          );
          await send(entry, 'Fetch.continueResponse', {
            requestId: event.requestId,
            responseCode: event.responseStatusCode,
            // HTTP/2 has no reason phrase. Omit the optional field so Chromium
            // supplies its standard phrase instead of rejecting an empty value.
            ...(event.responseStatusText ? { responsePhrase: event.responseStatusText } : {}),
            responseHeaders: headers,
          });
        } else {
          await send(entry, 'Network.clearBrowserCookies');
          if (entry.closed || entry.scope.retired) return;
          const headers = Object.entries(event.request.headers)
            .filter(
              ([name]) =>
                ![
                  'cookie',
                  'authorization',
                  'proxy-authorization',
                  'cache-control',
                  'pragma',
                ].includes(name.toLowerCase()),
            )
            .map(([name, value]) => ({ name, value: String(value) }));
          if (current.permitted?.() === false) {
            current.row.blocked_requests++;
            await send(entry, 'Fetch.failRequest', {
              requestId: event.requestId,
              errorReason: 'BlockedByClient',
            });
          } else {
            await send(entry, 'Fetch.continueRequest', { requestId: event.requestId, headers });
          }
        }
      })().catch(() => fail(entry));
    });
  }
  async function enable(entry) {
    await send(entry, 'Network.enable');
    await send(entry, 'Emulation.setDocumentCookieDisabled', { disabled: true }).catch(() => {});
    await send(entry, 'Network.setCacheDisabled', { cacheDisabled: false });
    await send(entry, 'Fetch.enable', {
      patterns: [
        { urlPattern: '*', requestStage: 'Request' },
        { urlPattern: '*', requestStage: 'Response' },
      ],
    });
    await send(entry, 'Target.setAutoAttach', {
      autoAttach: true,
      waitForDebuggerOnStart: true,
      flatten: false,
    });
  }
  function install(entry) {
    listen(entry, 'Target.receivedMessageFromTarget', ({ sessionId, message }) => {
      try {
        entry.children.get(sessionId)?.session.receive(message);
      } catch {
        fail(entry);
      }
    });
    listen(entry, 'Target.detachedFromTarget', ({ sessionId }) => {
      const child = entry.children.get(sessionId);
      if (child) {
        dispose(child);
        entry.children.delete(sessionId);
        // Detach does not prove the target was destroyed; interception may be lost.
        fail(entry);
      }
    });
    listen(entry, 'Target.attachedToTarget', ({ sessionId, targetInfo }) => {
      if (entry.closed || entry.scope.retired) return;
      const child = {
        session: new ChildSession(entry.session, sessionId),
        targetInfo,
        scope: entry.scope,
        closed: false,
        children: new Map(),
        listeners: [],
      };
      entry.children.set(sessionId, child);
      install(child);
      intercept(child);
      (async () => {
        await enable(child);
        await send(child, 'Runtime.runIfWaitingForDebugger');
      })().catch(() => fail(child));
    });
  }
  return {
    async bind(session, value) {
      if (active || fault) throw new Error('guard_failed');
      const scope = { value, retired: false };
      const root = {
        session,
        targetInfo: { type: 'page', targetId: value.targetId },
        scope,
        closed: false,
        children: new Map(),
        listeners: [],
      };
      scope.root = root;
      scopes.add(scope);
      active = scope;
      install(root);
      intercept(root);
      try {
        await enable(root);
      } catch (error) {
        fail(root);
        throw error;
      }
    },
    // Call synchronously before closing the bound page or its containing context.
    // Removing listeners does not disable Fetch or release paused requests.
    retire,
    healthy() {
      return !fault;
    },
    assertHealthy() {
      if (fault) throw new Error('guard_failed');
    },
    async close() {
      retire();
      for (const scope of scopes) {
        scope.retired = true;
        dispose(scope.root);
      }
      scopes.clear();
    },
  };
}

module.exports = { startGuard };
