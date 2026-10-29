(() => {
  'use strict';
  const config = window.PageNestNotifications;
  if (!config) return;
  const t = config.text;
  const openButtons = Array.from(document.querySelectorAll('[data-pn-open-notifications]'));
  const labels = new Map(
    openButtons.map((button) => [
      button,
      button.getAttribute('aria-label') || button.textContent.trim(),
    ]),
  );
  let countGeneration = 0,
    countController,
    countRequested = false;
  const cancelCount = () => {
    countGeneration++;
    countController?.abort();
    countController = null;
  };
  const clearCount = () => {
    cancelCount();
    openButtons.forEach((button) => {
      const badge = button.querySelector('[data-pn-notifications-badge]');
      if (badge) {
        badge.hidden = true;
        badge.textContent = '';
      }
      button.setAttribute('aria-label', labels.get(button));
    });
  };
  const displayCount = (count) => {
    clearCount();
    if (!config.countApi) return;
    if (!Number.isInteger(count) || count <= 0 || count > 100 || document.hidden) return;
    openButtons.forEach((button) => {
      const badge = button.querySelector('[data-pn-notifications-badge]');
      if (badge) {
        badge.textContent = String(count);
        badge.hidden = false;
      }
      button.setAttribute('aria-label', `${labels.get(button)} — ${t.recent} ${count}`);
    });
  };
  const loadCount = async () => {
    if (
      countRequested ||
      !config.countApi ||
      !config.nonce ||
      !openButtons.length ||
      document.hidden
    )
      return;
    countRequested = true;
    const generation = ++countGeneration;
    countController = new AbortController();
    try {
      const response = await fetch(config.countApi, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-WP-Nonce': config.nonce },
        signal: countController.signal,
      });
      if (!response.ok) throw new Error('count');
      const data = await response.json();
      if (generation === countGeneration && !document.hidden) displayCount(data.recentUnread);
    } catch {
      if (generation === countGeneration) clearCount();
    }
  };
  const states = [];
  const invalidateOthers = (root) =>
    states.forEach((state) => {
      if (state.root !== root) state.purge();
    });
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
    const actions = make('div');
    actions.className = 'pn-notifications-actions';
    load.before(actions);
    actions.append(load, status);
    let generation = 0;
    let controller;
    let filter = 'all';
    let settingsOpen = false;
    const rememberSettings = () => {
      const current = result.querySelector('details');
      if (current) settingsOpen = current.open;
    };
    const loginPrompt = () => {
      const login = make('a', t.login, status);
      login.href = config.login;
    };
    const purge = (preserveCount = false) => {
      if (preserveCount) cancelCount();
      else clearCount();
      generation++;
      controller?.abort();
      controller = null;
      result.replaceChildren();
      settingsOpen = false;
      status.replaceChildren();
      root.removeAttribute('aria-busy');
      load.disabled = false;
      load.textContent = t.load;
      if (!config.nonce) loginPrompt();
    };
    const request = async (suffix = '', body) => {
      controller?.abort();
      controller = new AbortController();
      let endpoint =
        suffix === '/read-page'
          ? config.batchApi
          : suffix === '/read'
            ? config.readApi
            : suffix === '/preference'
              ? config.preferenceApi
              : config.api;
      const url = new URL(endpoint, location.href);
      if (suffix.startsWith('?page=')) {
        url.searchParams.set('page', suffix.slice(6));
        url.searchParams.set('filter', filter);
      }
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
    const sessionExpired = () => {
      purge();
      status.textContent = t.expired;
      loginPrompt();
    };
    const fail = (error) => {
      clearCount();
      if (error.status === 401 || error.code === 'rest_cookie_invalid_nonce') {
        states.forEach((state) => state.sessionExpired());
        return;
      }
      if (error.status === 403) invalidateOthers(root);
      result.replaceChildren();
      status.textContent = error.status === 403 ? t.off : t.error;
    };
    const run = async (operation) => {
      clearCount();
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
      rememberSettings();
      result.replaceChildren();
      load.textContent = t.refresh;
      status.textContent = `${t.recent} ${data.recentUnread}`;
      displayCount(data.recentUnread);
      const settings = make('details', '', result);
      settings.open = settingsOpen;
      settings.addEventListener('toggle', () => {
        if (settings.isConnected) settingsOpen = settings.open;
      });
      make('summary', t.settings, settings);
      make('p', t.window, result);
      const label = make('label', '', settings);
      const choice = make('input', '', label);
      choice.type = 'checkbox';
      choice.checked = data.enabled;
      label.append(document.createTextNode(t.preference));
      make('p', t.independent, settings);
      choice.addEventListener('change', () => {
        const enabled = choice.checked;
        choice.disabled = true;
        invalidateOthers(root);
        run(async (active) => {
          await request('/preference', { enabled });
          if (!active()) return;
          invalidateOthers(root);
          data.enabled = enabled;
          render(data);
          result.querySelector('details input')?.focus();
          status.textContent = t.saved;
        });
      });
      const controls = make('div', '', result);
      controls.className = 'pn-notifications-filters';
      controls.setAttribute('role', 'group');
      controls.setAttribute('aria-label', t.filter || 'Show notifications');
      [
        ['all', t.all || 'All'],
        ['unread', t.unread],
      ].forEach(([value, label]) => {
        const option = button(label, controls, () => {
          filter = value;
          fetchPage(1, value);
        });
        option.dataset.pnFilter = value;
        option.setAttribute('aria-pressed', String(filter === value));
      });
      const unread = data.items.filter((item) => !item.read);
      const markItems = (items, focusKey) => {
        invalidateOthers(root);
        run(async (active) => {
          let failure;
          try {
            await request(
              items.length === 1 && focusKey ? '/read' : '/read-page',
              items.length === 1 && focusKey ? items[0] : { items },
            );
          } catch (error) {
            if (
              error.status === 401 ||
              error.status === 403 ||
              error.code === 'rest_cookie_invalid_nonce' ||
              error.name === 'AbortError'
            )
              throw error;
            failure = error;
          }
          if (!active()) return;
          invalidateOthers(root);
          rememberSettings();
          result.replaceChildren();
          const latest = await request(`?page=${data.page}`);
          if (!active()) return;
          render(latest);
          if (failure) status.textContent = t.partial || t.error;
          const focusTarget = focusKey
            ? result.querySelector(`[data-notification-key="${focusKey}"] a`) || load
            : result.querySelector('[data-pn-mark-page]:not(:disabled)') ||
              result.querySelector('[aria-pressed="true"]') ||
              load;
          if (focusTarget === load) load.disabled = false;
          focusTarget.focus();
        });
      };
      const batch = button(t.batch || 'Mark this page as read', controls, () =>
        markItems(unread.map((item) => ({ kind: item.kind || 'comment_reply', id: item.id }))),
      );
      batch.dataset.pnMarkPage = '';
      batch.disabled = !unread.length;
      if (!data.items.length) make('p', t.empty, result);
      const list = make('ul', '', result);
      data.items.forEach((item) => {
        const row = make('li', '', list);
        row.dataset.read = String(item.read);
        row.dataset.notificationId = String(item.id);
        const kind = item.kind || 'comment_reply';
        const key = `${kind}:${item.id}`;
        row.dataset.notificationKey = key;
        const content = make('div', '', row);
        const link = make('a', `${item.author} — ${item.title}`, content);
        link.href = safeURL(item.url) || '#';
        const typeLabel = kind === 'paragraph_comment' ? t.paragraph : t.reply;
        const meta = make(
          'p',
          `${typeLabel ? typeLabel + ' · ' : ''}${item.read ? t.read : t.unread}`,
          content,
        );
        const time = make('time', '', meta);
        time.dateTime = item.created;
        const date = new Date(item.created);
        time.textContent = Number.isNaN(date.getTime())
          ? ''
          : ` · ${date.toLocaleString(document.documentElement.lang || undefined, { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`;
        if (!item.read) {
          const mark = button(t.mark, row, () => {
            mark.disabled = true;
            markItems([{ kind, id: item.id }], key);
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
    const fetchPage = (page = 1, focusFilter = '') =>
      run(async (active) => {
        rememberSettings();
        result.replaceChildren();
        const data = await request(`?page=${page}`);
        if (active()) {
          render(data);
          if (focusFilter) result.querySelector(`[data-pn-filter="${focusFilter}"]`)?.focus();
        }
      });
    load.hidden = false;
    if (!config.nonce) {
      load.hidden = true;
      loginPrompt();
    } else load.addEventListener('click', () => fetchPage());
    states.push({ root, purge, fetchPage, sessionExpired });
  });
  const dialog = document.querySelector('.pn-notifications-dialog');
  if (config.nonce && dialog && typeof dialog.showModal === 'function') {
    const state = states.find((entry) => dialog.contains(entry.root));
    const toolbar = dialog.querySelector('.pn-notifications-toolbar');
    const scrollGap = () => {
      dialog.style.scrollPaddingBlockStart = `${toolbar.getBoundingClientRect().height + 12}px`;
    };
    if (typeof ResizeObserver === 'function') new ResizeObserver(scrollGap).observe(toolbar);
    window.addEventListener('resize', scrollGap);
    document.querySelectorAll('[data-pn-open-notifications]').forEach((open) => {
      open.hidden = false;
      open.addEventListener('click', () => {
        if (!dialog.open) dialog.showModal();
        scrollGap();
        state?.fetchPage();
      });
    });
    dialog
      .querySelector('[data-pn-close-notifications]')
      .addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      if (!dialog.open) state?.purge(true);
    });
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      countRequested = false;
      clearCount();
      states.forEach((state) => state.purge());
    } else loadCount();
  });
  window.addEventListener('pagehide', () => {
    countRequested = false;
    clearCount();
    states.forEach((state) => state.purge());
  });
  loadCount();
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      countRequested = false;
      clearCount();
      loadCount();
    }
  });
  document.addEventListener('pagenest-pc-auth-change', () => {
    clearCount();
    states.forEach((state) => state.sessionExpired());
  });
})();
