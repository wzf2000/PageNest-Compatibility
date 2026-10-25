(() => {
  'use strict';
  const config = window.PageNestNotifications;
  if (!config) return;
  const t = config.text;
  const states = [];
  const make = (tag, text, parent) => {
    const element = document.createElement(tag);
    if (text) element.textContent = text;
    if (parent) parent.append(element);
    return element;
  };
  const button = (text, parent, action) => {
    const element = make('button', text, parent);
    element.type = 'button';
    element.addEventListener('click', action);
    return element;
  };
  const safeURL = (value) => {
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && ['http:', 'https:'].includes(url.protocol)
        ? url.href
        : '';
    } catch {
      return '';
    }
  };
  document.querySelectorAll('[data-pn-notifications]').forEach((root) => {
    const status = root.querySelector('[data-pn-notifications-status]');
    const result = root.querySelector('[data-pn-notifications-result]');
    const load = root.querySelector('[data-pn-notifications-load]');
    let generation = 0;
    let controller;
    const purge = () => {
      generation++;
      controller?.abort();
      controller = null;
      result.replaceChildren();
      status.replaceChildren();
      root.removeAttribute('aria-busy');
      load.disabled = false;
      load.textContent = t.load;
    };
    const request = async (suffix = '', body) => {
      controller?.abort();
      controller = new AbortController();
      let endpoint =
        suffix === '/read'
          ? config.readApi
          : suffix === '/preference'
            ? config.preferenceApi
            : config.api;
      const url = new URL(endpoint, location.href);
      if (suffix.startsWith('?page=')) url.searchParams.set('page', suffix.slice(6));
      const response = await fetch(url.href, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
      if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw Object.assign(new Error(), { status: response.status, code: payload.code });
      }
      return response.json();
    };
    const fail = (error) => {
      result.replaceChildren();
      status.textContent =
        error.status === 401 || error.code === 'rest_cookie_invalid_nonce'
          ? t.expired
          : error.status === 403
            ? t.off
            : t.error;
      if (error.status === 401 || error.code === 'rest_cookie_invalid_nonce') {
        const link = make('a', t.login, status);
        link.href = config.login;
      }
    };
    const run = async (operation) => {
      const token = ++generation;
      root.setAttribute('aria-busy', 'true');
      load.disabled = true;
      result.querySelectorAll('button, input').forEach((element) => {
        element.disabled = true;
      });
      status.textContent = t.loading;
      try {
        await operation(() => token === generation && !document.hidden);
      } catch (error) {
        if (token === generation && error.name !== 'AbortError') fail(error);
      } finally {
        if (token === generation) {
          root.removeAttribute('aria-busy');
          load.disabled = false;
        }
      }
    };
    const render = (data) => {
      result.replaceChildren();
      load.textContent = t.refresh;
      status.textContent = `${t.recent} ${data.recentUnread}`;
      const settings = make('details', '', result);
      make('summary', t.settings, settings);
      make('p', t.window, settings);
      const label = make('label', '', settings);
      const choice = make('input', '', label);
      choice.type = 'checkbox';
      choice.checked = data.enabled;
      label.append(document.createTextNode(t.preference));
      make('p', t.independent, settings);
      choice.addEventListener('change', () => {
        const enabled = choice.checked;
        choice.disabled = true;
        run(async (active) => {
          await request('/preference', { enabled });
          if (!active()) return;
          data.enabled = enabled;
          result.querySelectorAll('button, input').forEach((element) => {
            element.disabled = false;
          });
          const navigation = result.querySelectorAll('nav button');
          if (navigation[0]) navigation[0].disabled = data.page <= 1;
          if (navigation[1]) navigation[1].disabled = data.page >= data.pages;
          status.textContent = t.saved;
        });
      });
      if (!data.items.length) make('p', t.empty, result);
      const list = make('ul', '', result);
      data.items.forEach((item) => {
        const row = make('li', '', list);
        row.dataset.read = String(item.read);
        row.dataset.notificationId = String(item.id);
        const content = make('div', '', row);
        const link = make('a', `${item.author} — ${item.title}`, content);
        link.href = safeURL(item.url) || '#';
        const meta = make('p', item.read ? t.read : t.unread, content);
        const time = make('time', '', meta);
        time.dateTime = item.created;
        const date = new Date(item.created);
        time.textContent = Number.isNaN(date.getTime())
          ? ''
          : ` · ${date.toLocaleString(document.documentElement.lang || undefined, { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`;
        if (!item.read) {
          const mark = button(t.mark, row, () => {
            mark.disabled = true;
            run(async (active) => {
              await request('/read', { id: item.id });
              if (!active()) return;
              item.read = true;
              data.recentUnread = Math.max(0, data.recentUnread - 1);
              render(data);
              result.querySelector(`[data-notification-id="${item.id}"] a`)?.focus();
            });
          });
        }
      });
      const nav = make('nav', '', result);
      nav.setAttribute('aria-label', t.load);
      const previous = button(t.previous, nav, () => fetchPage(data.page - 1));
      previous.disabled = data.page <= 1;
      make('span', `${data.page} / ${data.pages}`, nav).dir = 'ltr';
      const next = button(t.next, nav, () => fetchPage(data.page + 1));
      next.disabled = data.page >= data.pages;
    };
    const fetchPage = (page = 1) =>
      run(async (active) => {
        result.replaceChildren();
        const data = await request(`?page=${page}`);
        if (active()) render(data);
      });
    load.hidden = false;
    if (!config.nonce) {
      load.hidden = true;
      const login = make('a', t.login, status);
      login.href = config.login;
    } else load.addEventListener('click', () => fetchPage());
    states.push({ root, purge, fetchPage });
  });
  const dialog = document.querySelector('.pn-notifications-dialog');
  if (dialog && typeof dialog.showModal === 'function') {
    const state = states.find((entry) => dialog.contains(entry.root));
    document.querySelectorAll('[data-pn-open-notifications]').forEach((open) => {
      open.hidden = false;
      open.addEventListener('click', () => {
        if (!dialog.open) dialog.showModal();
        state?.fetchPage();
      });
    });
    dialog
      .querySelector('[data-pn-close-notifications]')
      .addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => state?.purge());
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) states.forEach((state) => state.purge());
  });
  window.addEventListener('pagehide', () => states.forEach((state) => state.purge()));
})();
