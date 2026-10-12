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
  }
  send(method, params = {}) {
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
    for (const pending of this.pending.values()) {
      clearTimeout(pending.timer);
      pending.reject(new Error('protocol_closed'));
    }
    this.pending.clear();
    this.removeAllListeners();
  }
}

async function startGuard(browser, policy) {
  const all = new Set();
  let active = null;
  let fault = false;
  const types = {
    Stylesheet: 'stylesheet',
    Script: 'script',
    Image: 'image',
    Font: 'font',
    Media: 'media',
  };
  function intercept(session, targetInfo) {
    session.on('Fetch.requestPaused', (event) => {
      (async () => {
        const current = active;
        const document = event.resourceType === 'Document';
        const permittedTarget =
          current && targetInfo.type === 'page' && targetInfo.targetId === current.targetId;
        const request = {
          url: () => event.request.url,
          method: () => event.request.method,
          resourceType: () => types[event.resourceType] || 'other',
          isNavigationRequest: () => false,
        };
        const allowed =
          permittedTarget &&
          ['GET', 'HEAD'].includes(event.request.method) &&
          (document
            ? event.frameId === current.frameId &&
              event.request.url === current.url &&
              policy.allowedUrl(event.request.url, true)
            : policy.allowedRequest(request));
        const redirect = event.responseStatusCode >= 300 && event.responseStatusCode < 400;
        if (!allowed || redirect) {
          if (current) current.row.blocked_requests++;
          await session.send('Fetch.failRequest', {
            requestId: event.requestId,
            errorReason: 'BlockedByClient',
          });
        } else if (event.responseStatusCode !== undefined) {
          const headers = (event.responseHeaders || []).filter(
            ({ name }) => !['set-cookie', 'set-cookie2'].includes(name.toLowerCase()),
          );
          await session.send('Fetch.continueResponse', {
            requestId: event.requestId,
            responseCode: event.responseStatusCode,
            responsePhrase: event.responseStatusText || '',
            responseHeaders: headers,
          });
        } else {
          await session.send('Network.clearBrowserCookies');
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
          await session.send('Fetch.continueRequest', { requestId: event.requestId, headers });
        }
      })().catch(() => {
        fault = true;
        browser.close().catch(() => {});
      });
    });
  }
  async function enable(session) {
    await session.send('Network.enable');
    await session.send('Emulation.setDocumentCookieDisabled', { disabled: true }).catch(() => {});
    await session.send('Network.setCacheDisabled', { cacheDisabled: false });
    await session.send('Fetch.enable', {
      patterns: [
        { urlPattern: '*', requestStage: 'Request' },
        { urlPattern: '*', requestStage: 'Response' },
      ],
    });
    await session.send('Target.setAutoAttach', {
      autoAttach: true,
      waitForDebuggerOnStart: true,
      flatten: false,
    });
  }
  function install(parent) {
    const children = new Map();
    parent.on('Target.receivedMessageFromTarget', ({ sessionId, message }) =>
      children.get(sessionId)?.receive(message),
    );
    parent.on('Target.detachedFromTarget', ({ sessionId }) => {
      const child = children.get(sessionId);
      child?.dispose();
      children.delete(sessionId);
      all.delete(child);
    });
    parent.on('Target.attachedToTarget', ({ sessionId, targetInfo }) => {
      const session = new ChildSession(parent, sessionId);
      children.set(sessionId, session);
      all.add(session);
      install(session);
      intercept(session, targetInfo);
      (async () => {
        await enable(session);
        await session.send('Runtime.runIfWaitingForDebugger');
      })().catch(() => {
        fault = true;
        browser.close().catch(() => {});
      });
    });
  }
  return {
    async bind(session, value) {
      active = value;
      install(session);
      intercept(session, { type: 'page', targetId: value.targetId });
      await enable(session);
    },
    activate(value) {
      active = value;
    },
    healthy() {
      return !fault;
    },
    async close() {
      active = null;
      for (const session of all) session.dispose();
    },
  };
}

module.exports = { startGuard };
