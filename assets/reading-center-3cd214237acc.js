(() => {
  'use strict';
  const cfg = window.PageNestReading;
  if (!cfg) return;
  const t = cfg.text;
  const node = (tag, text, cls) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (cls) element.className = cls;
    return element;
  };
  const button = (text, run) => {
    const element = node('button', text);
    element.type = 'button';
    element.addEventListener('click', run);
    return element;
  };
  const safeUrl = (value) => {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol))
      throw Error();
    return url.href;
  };
  const help = (text) => {
    const details = node('details', undefined, 'pn-reading-help');
    details.append(node('summary', t.help), node('p', text));
    return details;
  };
  const key = 'pagenest.reading.v1';
  let device = { enabled: false, items: [] },
    storageOK = true,
    resolving = 0,
    deviceGeneration = 0,
    sync = null;
  function readDevice() {
    try {
      const saved = JSON.parse(localStorage.getItem(key) || 'null');
      device = { enabled: saved?.enabled === true, items: [] };
      if (device.enabled && Array.isArray(saved.items)) {
        device.items = saved.items
          .slice(0, 100)
          .filter(
            (item) =>
              Number.isInteger(item.id) &&
              item.id > 0 &&
              Number.isFinite(item.time) &&
              Number.isFinite(new Date(item.time).getTime()),
          )
          .map((item) => ({
            id: item.id,
            time: item.time,
            later: item.later === true,
            anchor: typeof item.anchor === 'string' ? item.anchor.slice(0, 200) : '',
          }));
      }
    } catch {
      device = { enabled: false, items: [] };
      storageOK = false;
    }
  }
  function persist() {
    try {
      localStorage.setItem(key, JSON.stringify(device));
      return true;
    } catch {
      device = { enabled: false, items: [] };
      storageOK = false;
      return false;
    }
  }
  readDevice();
  function remember(later = false) {
    if (!device.enabled || !cfg.current || !storageOK) return false;
    deviceGeneration++;
    const old = device.items.find((item) => item.id === cfg.current.id);
    const body = document.querySelector('.pagenest-article-body, .entry-content');
    let anchor = '';
    for (const id of cfg.current.headings) {
      const heading = document.getElementById(id);
      if (
        heading &&
        body?.contains(heading) &&
        heading.getBoundingClientRect().top < innerHeight * 0.45
      )
        anchor = id;
    }
    device.items = [
      { id: cfg.current.id, time: Date.now(), later: later || old?.later === true, anchor },
      ...device.items.filter((item) => item.id !== cfg.current.id),
    ].slice(0, 100);
    const saved = persist();
    if (saved) sync?.record();
    return saved;
  }
  if (device.enabled) remember();
  let scrollTimer;
  addEventListener(
    'scroll',
    () => {
      clearTimeout(scrollTimer);
      if (device.enabled) scrollTimer = setTimeout(() => remember(), 1200);
    },
    { passive: true },
  );
  const deviceViews = [...document.querySelectorAll('[data-pn-device]')];
  let deviceMessage = '';
  async function renderDevice() {
    const generation = ++resolving;
    const restore = new Map();
    deviceViews.forEach((view) => {
      const focused = view.contains(document.activeElement) ? document.activeElement : null;
      const focusKey = focused?.dataset.pnFocus;
      const managementOpen = view.querySelector('.pn-reading-management')?.open;
      if (focusKey) restore.set(view, focusKey);
      view.replaceChildren();
      const label = node('label', undefined, 'pn-reading-consent'),
        toggle = node('input');
      toggle.type = 'checkbox';
      toggle.dataset.pnFocus = 'consent';
      toggle.checked = device.enabled;
      toggle.disabled = !storageOK;
      label.append(toggle, document.createTextNode(' ' + t.consent));
      toggle.addEventListener('change', () => {
        deviceGeneration++;
        device = { enabled: toggle.checked, items: toggle.checked ? device.items : [] };
        if (persist()) {
          remember();
          deviceMessage = !storageOK
            ? t.unavailable
            : device.enabled
              ? t.deviceEnabled
              : t.deviceDisabled;
        } else deviceMessage = t.unavailable;
        renderDevice();
      });
      const feedback = node('p', deviceMessage, 'pn-reading-hint');
      feedback.setAttribute('role', 'status');
      view.append(
        label,
        node('p', storageOK ? t.deviceSummary : t.unavailable, 'pn-reading-hint'),
        feedback,
      );
      const content = node('div', undefined, 'pn-reading-device-list');
      content.dataset.pnDeviceList = '';
      view.append(content);
      if (device.enabled) {
        const management = node('details', undefined, 'pn-reading-management');
        management.open = Boolean(managementOpen);
        const clearButton = button(t.clear, () => {
          deviceGeneration++;
          device.items = [];
          persist();
          deviceMessage = storageOK ? t.deviceCleared : t.unavailable;
          renderDevice();
        });
        clearButton.dataset.pnFocus = 'clear';
        management.append(node('summary', t.manage), clearButton, node('p', t.deviceHelp));
        view.append(management);
      } else {
        content.append(node('p', storageOK ? t.devicePrompt : t.unavailable, 'pn-reading-empty'));
        view.append(help(t.deviceHelp));
      }
      if (device.enabled && !device.items.length)
        content.append(node('p', t.empty, 'pn-reading-empty'));
      if (focusKey) {
        const replacement = view.querySelector(`[data-pn-focus="${focusKey}"]`);
        if (replacement) replacement.focus({ preventScroll: true });
        else if (!device.items.length) toggle.focus({ preventScroll: true });
      }
    });
    if (!device.enabled || !device.items.length) return;
    deviceViews.forEach((view) => {
      const message = node('p', t.loading);
      message.setAttribute('role', 'status');
      view.querySelector('[data-pn-device-list]').replaceChildren(message);
    });
    try {
      const response = await fetch(safeUrl(cfg.resolve), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: device.items.map((item) => item.id) }),
        credentials: 'omit',
        cache: 'no-store',
      });
      if (!response.ok) throw Error();
      const data = await response.json();
      if (generation !== resolving || !device.enabled) return;
      const live = new Map(data.items.map((item) => [item.id, item]));
      // Invalid or newly restricted articles are removed without retaining titles or URLs.
      device.items = device.items.filter((item) => live.has(item.id));
      if (!persist()) {
        renderDevice();
        return;
      }
      deviceViews.forEach((view) => {
        const content = view.querySelector('[data-pn-device-list]');
        content.replaceChildren();
        const later = view.closest('.pn-reading')?.dataset.pnView === 'later';
        const items = device.items.filter((item) => !later || item.later);
        const list = node('ul', undefined, 'pn-reading-device-items');
        items.forEach((item, index) => {
          const current = live.get(item.id),
            li = node('li'),
            body = node('div', undefined, 'pn-reading-item-body'),
            link = node('a', current.title);
          link.dataset.pnFocus = 'article-' + item.id;
          link.href = safeUrl(
            current.url +
              (current.headings.includes(item.anchor) ? '#' + encodeURIComponent(item.anchor) : ''),
          );
          if (!later && index === 0) {
            li.classList.add('pn-reading-continue');
            body.append(node('span', t.continue, 'pn-reading-kicker'));
          }
          const date = node(
            'time',
            new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
              month: 'short',
              day: 'numeric',
            }).format(item.time),
          );
          date.dateTime = new Date(item.time).toISOString();
          body.append(link, date);
          const remove = button(t.remove, () => {
            deviceGeneration++;
            device.items = device.items.filter((saved) => saved.id !== item.id);
            persist();
            renderDevice();
          });
          remove.dataset.pnFocus = 'remove-' + item.id;
          remove.setAttribute('aria-label', t.remove + ': ' + current.title);
          li.append(body, remove);
          list.append(li);
        });
        content.append(items.length ? list : node('p', t.empty, 'pn-reading-empty'));
        if (restore.has(view) && document.activeElement === document.body && !view.hidden) {
          (content.querySelector('a') || view.querySelector('input'))?.focus({
            preventScroll: true,
          });
        }
      });
    } catch {
      if (generation === resolving)
        deviceViews.forEach((view) => {
          const message = node('p', t.deviceLoadError);
          message.setAttribute('role', 'status');
          const retry = button(t.retry, () => renderDevice());
          retry.dataset.pnFocus = 'retry';
          view.querySelector('[data-pn-device-list]').replaceChildren(message, retry);
          if (restore.has(view) && document.activeElement === document.body && !view.hidden)
            retry.focus({ preventScroll: true });
        });
    }
  }
  addEventListener('storage', (event) => {
    if (event.key !== key && event.key !== null) return;
    deviceGeneration++;
    readDevice();
    renderDevice();
  });
  document.querySelectorAll('[data-pn-save-later]').forEach((control) => {
    control.hidden = false;
    control.addEventListener('click', () => {
      const saved = remember(true);
      document.querySelectorAll('[data-pn-later-status]').forEach((status) => {
        status.textContent = saved ? t.savedLater : storageOK ? t.enableFirst : t.unavailable;
      });
      renderDevice();
    });
  });
  const centers = [];
  document.querySelectorAll('[data-pn-records]').forEach((view) => {
    let generation = 0,
      cursor = null,
      loaded = false,
      ids = [],
      pending = false;
    const controllers = new Set();
    const status = node('p');
    status.setAttribute('role', 'status');
    const results = node('div', undefined, 'pn-reading-records');
    const query = node('input');
    query.type = 'search';
    query.autocomplete = 'off';
    query.maxLength = 100;
    const qLabel = node('label', t.q);
    qLabel.append(query);
    const kind = node('select');
    kind.setAttribute('aria-label', t.all);
    for (const value of ['all', 'public', 'author_only', 'needs_review']) {
      const option = node('option', t[value]);
      option.value = value;
      kind.append(option);
    }
    const controls = node('form', undefined, 'pn-reading-filters');
    const submit = node('button', t.search);
    submit.type = 'submit';
    controls.append(qLabel, kind, submit);
    const intro = node('p', t.privateIntro, 'pn-reading-hint');
    const loadButton = button(t.load, () => load());
    const more = button(t.more, () => load(true));
    more.hidden = true;
    function clearPrivate(message = '') {
      generation++;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      results.replaceChildren();
      more.hidden = true;
      cursor = null;
      loaded = false;
      pending = false;
      ids = [];
      status.textContent = message;
      intro.hidden = false;
      loadButton.textContent = t.load;
      loadButton.disabled = false;
    }
    async function api(path, method = 'GET', body) {
      const turn = generation,
        controller = new AbortController();
      controllers.add(controller);
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const [route, query = ''] = path.split('?');
        let url = new URL(cfg.api, location.href);
        if (url.searchParams.has('rest_route'))
          url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
        else url = new URL(route, url);
        new URLSearchParams(query).forEach((value, name) => url.searchParams.set(name, value));
        const response = await fetch(safeUrl(url.href), {
          method,
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
        const data = await response.json();
        if (turn !== generation) throw Object.assign(Error(), { stale: true });
        if (response.status === 401 || data?.code === 'rest_cookie_invalid_nonce') {
          document.dispatchEvent(new Event('pagenest-pc-auth-change'));
          centers.forEach((center) => center.clear(t.error));
          accountCenters.forEach((center) => center.clear(cfg.accountText.loginRequired));
          throw Object.assign(Error(), { stale: true });
        }
        if (response.status === 403 || data?.code === 'comments_disabled') {
          centers.forEach((center) => center.clear(t.error));
          throw Object.assign(Error(), { stale: true });
        }
        if (!response.ok)
          throw Object.assign(Error(response.status === 409 ? t.conflict : t.error), {
            conflict: response.status === 409,
          });
        return data;
      } catch (error) {
        if (turn !== generation) error.stale = true;
        throw error;
      } finally {
        clearTimeout(timeout);
        controllers.delete(controller);
      }
    }
    function card(item) {
      const article = node('article', undefined, 'pn-reading-record');
      const feedback = () => {
        const message = node('p');
        message.setAttribute('role', 'status');
        message.hidden = true;
        return message;
      };
      async function act(box, control, focusTarget, message, task, focusScope = box) {
        const current = () => box.isConnected && article.isConnected;
        let moved = !focusScope.contains(document.activeElement);
        const trackFocus = (event) => {
          if (!focusScope.contains(event.target)) moved = true;
        };
        document.addEventListener('focusin', trackFocus);
        document.addEventListener('pointerdown', trackFocus);
        message.hidden = true;
        message.textContent = '';
        control.disabled = true;
        try {
          await task(current);
        } catch (error) {
          if (!error.stale && current()) {
            message.textContent = error.message || t.error;
            message.hidden = false;
            if (!moved) {
              control.disabled = false;
              focusTarget.focus({ preventScroll: true });
              message.scrollIntoView({ block: 'nearest' });
            }
          }
        } finally {
          control.disabled = false;
          document.removeEventListener('focusin', trackFocus);
          document.removeEventListener('pointerdown', trackFocus);
        }
      }
      const title = node('a', item.post_title);
      title.href = safeUrl(item.location_url);
      article.append(title, node('p', item.visibility === 'public' ? t.public : t.author_only));
      const quote = node('blockquote', item.quote);
      article.append(quote);
      if (item.association === 'needs_review')
        article.append(node('p', t.reviewHelp, 'pn-reading-warning'));
      const text = node('p', item.text, 'pn-reading-note-text');
      article.append(text);
      const actions = node('div', undefined, 'pn-reading-actions');
      const deleteMessage = feedback();
      actions.append(
        button(t.edit, (event) => {
          if (article.querySelector('textarea')) return;
          const opener = event.currentTarget;
          const editor = node('textarea');
          editor.value = item.text;
          editor.maxLength = 10000;
          editor.setAttribute('aria-label', t.edit);
          const editBox = node('div');
          const message = feedback();
          const save = button(t.save, () =>
            act(editBox, save, editor, message, async (current) => {
              await api(`notes/${item.id}`, 'PATCH', { text: editor.value, version: item.version });
              if (current()) await load();
            }),
          );
          editBox.append(
            editor,
            save,
            button(t.cancel, () => {
              editBox.remove();
              opener.focus({ preventScroll: true });
            }),
            message,
          );
          article.append(editBox);
          editor.focus();
        }),
        button(t.delete, (event) => {
          if (!confirm(t.confirmDelete)) return;
          const remove = event.currentTarget;
          return act(
            article,
            remove,
            remove,
            deleteMessage,
            async (current) => {
              await api(`notes/${item.id}`, 'DELETE', { version: item.version });
              if (current()) await load();
            },
            remove,
          );
        }),
        button(t.relink, (event) => {
          if (article.querySelector('[data-pn-relink]')) return;
          const opener = event.currentTarget;
          const box = node('div');
          box.dataset.pnRelink = '';
          article.append(box);
          const select = node('select');
          select.setAttribute('aria-label', t.choose);
          const placeholder = node('option', t.choose);
          placeholder.value = '';
          select.append(placeholder);
          const preview = node('blockquote');
          const paragraphs = new Map();
          select.addEventListener('change', () => {
            preview.textContent = paragraphs.get(select.value) || '';
          });
          let blockCursor = 0;
          const message = feedback();
          const loadBlocks = button(t.more, () =>
            act(box, loadBlocks, select, message, async (current) => {
              const page = await api(`blocks?post=${item.post_id}&cursor=${blockCursor}`);
              if (!current()) return;
              for (const block of page.items) {
                paragraphs.set(block.id, block.text);
                const option = node('option', block.text);
                option.value = block.id;
                select.append(option);
              }
              blockCursor = page.next_cursor;
              loadBlocks.hidden = blockCursor === null;
            }),
          );
          const replace = button(t.replace, () => {
            if (!select.value) return;
            return act(box, replace, select, message, async (current) => {
              await api(`notes/${item.id}`, 'PATCH', {
                block_id: select.value,
                version: item.version,
              });
              if (current()) await load();
            });
          });
          box.append(
            select,
            preview,
            loadBlocks,
            replace,
            button(t.cancel, () => {
              box.remove();
              opener.focus({ preventScroll: true });
            }),
            message,
          );
          loadBlocks.click();
        }),
      );
      article.append(actions, deleteMessage);
      return article;
    }
    async function load(append = false) {
      if (pending) return;
      if (!append) clearPrivate();
      const turn = generation;
      pending = true;
      loadButton.disabled = true;
      status.textContent = t.loading;
      try {
        const page = await api(
          `my-records?q=${encodeURIComponent(query.value)}&kind=${encodeURIComponent(kind.value)}${append && cursor ? `&cursor=${cursor}` : ''}`,
        );
        if (turn !== generation) return;
        if (!append) results.replaceChildren();
        page.items.forEach((item) => {
          results.append(card(item));
          ids.push(item.id);
        });
        cursor = page.next_cursor;
        more.hidden = cursor === null;
        loaded = true;
        intro.hidden = true;
        loadButton.textContent = t.refresh;
        status.textContent = !results.childElementCount ? t.empty : '';
      } catch (error) {
        if (!error.stale) {
          clearPrivate(t.error);
        }
      } finally {
        if (turn === generation) {
          pending = false;
          loadButton.disabled = false;
        }
      }
    }
    controls.addEventListener('submit', (event) => {
      event.preventDefault();
      load();
    });
    view.append(node('h3', t.records));
    if (!cfg.nonce) {
      const link = node('a', t.login);
      link.href = safeUrl(cfg.login);
      view.append(link, help(t.privateHelp));
      return;
    }
    const filters = node('details', undefined, 'pn-reading-record-filters');
    filters.append(node('summary', t.filter), controls);
    view.append(intro, loadButton, filters, status, results, more);
    const backup = node('details');
    backup.append(node('summary', t.backup));
    const file = node('input');
    file.type = 'file';
    file.accept = 'application/json,.json';
    file.setAttribute('aria-label', t.import);
    file.addEventListener('change', async () => {
      const selected = file.files[0],
        turn = generation;
      if (!selected || selected.size > 5000000) {
        status.textContent = t.error;
        return;
      }
      try {
        const body = JSON.parse(await selected.text());
        if (turn !== generation) return;
        const data = await api('import', 'POST', body);
        if (turn !== generation) return;
        status.textContent = t.imported;
        data.forEach((item) => {
          if (item.error) status.append(node('p', String(item.error)));
        });
      } catch (error) {
        if (!error.stale) status.textContent = error.message || t.error;
      } finally {
        file.value = '';
      }
    });
    backup.append(
      node('p', t.importHelp),
      file,
      button(t.export, async () => {
        const turn = generation;
        try {
          const data = await api('export');
          if (turn !== generation) return;
          const url = URL.createObjectURL(
            new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }),
          );
          const link = node('a');
          link.href = url;
          link.download = 'reading-records.json';
          link.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
          if (!error.stale) status.textContent = t.error;
        }
      }),
    );
    view.append(backup, help(t.privateHelp));
    const validate = async () => {
      if (!loaded || !ids.length || document.hidden) return;
      try {
        for (let index = 0; index < ids.length; index += 200) {
          const data = await api('my-records/validate', 'POST', {
            ids: ids.slice(index, index + 200),
          });
          if (!data.valid) {
            centers.forEach((center) => center.clear(t.error));
            break;
          }
        }
      } catch (error) {
        if (!error.stale) clearPrivate(t.error);
      }
    };
    centers.push({
      view,
      clear: (message = '') => {
        clearPrivate(message);
        query.value = '';
        kind.value = 'all';
        file.value = '';
        backup.open = false;
        filters.open = false;
      },
      validate,
    });
  });
  // Account state stays inside each panel; the device store remains independently opt-in.
  const accountCenters = [];
  const accountViewOpen = () =>
    [...document.querySelectorAll('.pn-reading')].some(
      (panel) =>
        panel.dataset.pnView === 'account' &&
        panel.getClientRects().length &&
        (!panel.closest('dialog') || panel.closest('dialog').open),
    );
  const updateSyncPanelState = () => sync?.panel(accountViewOpen());
  function createReadingSync() {
    const config = cfg.sync;
    if (!config?.actor || !cfg.nonce) return null;
    const text = config.text;
    const names = ['automatic', 'preferences'];
    const allowed = {
      size: ['default', '18', '20', '22'],
      width: ['default', 'compact', 'wide'],
      palette: ['default', 'paper', 'dark'],
    };
    const storageKey = (name) => 'pagenest.reading.' + name + '.v1';
    let state = null,
      generation = 0,
      serial = Promise.resolve(),
      busy = false,
      authLost = false;
    let timer,
      delta = null,
      patch = {},
      sentPatch = {},
      working = false,
      workingKind = null,
      themeReady = false,
      applying = '',
      consentingPreferences = false;
    let suspended = document.hidden,
      panelOpen = false;
    const controllers = new Set(),
      views = [],
      markers = {},
      messages = { automatic: '', preferences: '' };
    function readMarker(name) {
      try {
        const value = JSON.parse(localStorage.getItem(storageKey(name)) || 'null');
        if (
          value &&
          value.version === 1 &&
          value.actor === config.actor &&
          /^[a-f0-9]{32}$/.test(value.epoch)
        )
          return value;
      } catch {
        messages[name] = text.storage;
      }
      return null;
    }
    names.forEach((name) => {
      markers[name] = readMarker(name);
    });
    const live = (name) =>
      !suspended &&
      !authLost &&
      state?.available &&
      state[name]?.enabled &&
      markers[name]?.actor === config.actor &&
      markers[name]?.epoch === state[name]?.epoch;
    function serialize(task) {
      const result = serial.then(task, task);
      serial = result.catch(() => {});
      return result;
    }
    function restoreLocal() {
      applying = 'local-' + generation;
      document.dispatchEvent(
        new CustomEvent('pagenest-reader-preferences-apply', {
          detail: {
            version: 1,
            source: 'local',
            changed: [],
            preferences: {},
            requestId: applying,
          },
        }),
      );
    }
    function applyPreferences() {
      if (!live('preferences')) {
        restoreLocal();
        return;
      }
      if (!themeReady) {
        messages.preferences = text.noTheme;
        render();
        return;
      }
      const values = { ...state.preferences.values, ...sentPatch, ...patch };
      applying = 'remote-' + generation + '-' + state.preferences.revision;
      document.dispatchEvent(
        new CustomEvent('pagenest-reader-preferences-apply', {
          detail: {
            version: 1,
            source: 'remote',
            preferences: { ...values },
            changed: Object.keys(values),
            requestId: applying,
          },
        }),
      );
    }
    function forget(name, message = '') {
      const previous = markers[name];
      markers[name] = null;
      messages[name] = message;
      try {
        const current = JSON.parse(localStorage.getItem(storageKey(name)) || 'null');
        // A stale tab may pause itself, but cannot erase a newer explicit device binding.
        if (previous && current?.actor === previous.actor && current?.epoch === previous.epoch)
          localStorage.removeItem(storageKey(name));
      } catch {
        messages[name] = message || text.storage;
      }
      if (name === 'automatic') delta = null;
      else {
        patch = {};
        sentPatch = {};
        restoreLocal();
      }
    }
    function bind(name) {
      const value = { version: 1, actor: config.actor, epoch: state[name].epoch };
      try {
        localStorage.setItem(storageKey(name), JSON.stringify(value));
        markers[name] = value;
        return true;
      } catch {
        forget(name, text.savedStorage);
        return false;
      }
    }
    async function request(path = '', body) {
      const turn = generation;
      return serialize(async () => {
        if (turn !== generation || suspended || authLost)
          throw Object.assign(Error(), { stale: true });
        if (path === 'automatic/progress' || path === 'preferences/update') {
          const name = path.startsWith('automatic') ? 'automatic' : 'preferences';
          if (name === 'automatic') {
            readDevice();
            if (!storageOK || !device.enabled) throw Error(text.localRequired);
          }
          const currentMarker = readMarker(name);
          if (!currentMarker || currentMarker.epoch !== state?.[name]?.epoch)
            throw Error(messages[name] === text.storage ? text.storage : text.reconfirm);
        }
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
          let url = new URL(cfg.accountApi, location.href);
          const suffix = path || 'sync';
          if (url.searchParams.has('rest_route'))
            url.searchParams.set('rest_route', url.searchParams.get('rest_route') + '/' + suffix);
          else url.pathname = url.pathname.replace(/\/$/, '') + '/' + suffix;
          const response = await fetch(safeUrl(url.href), {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
          });
          const data = await response.json();
          if (turn !== generation || suspended || authLost)
            throw Object.assign(Error(), { stale: true });
          if (response.status === 401 || data?.code === 'rest_cookie_invalid_nonce') {
            document.dispatchEvent(new Event('pagenest-pc-auth-change'));
            throw Object.assign(Error(), { stale: true });
          }
          if (!response.ok)
            throw Object.assign(Error(response.status === 409 ? text.conflict : text.failed), {
              status: response.status,
            });
          return data;
        } catch (error) {
          if (turn !== generation) error.stale = true;
          throw error;
        } finally {
          clearTimeout(timeout);
          controllers.delete(controller);
        }
      });
    }
    function validateMarkers() {
      names.forEach((name) => {
        if (
          markers[name] &&
          (!state.available || !state[name].enabled || markers[name].epoch !== state[name].epoch)
        )
          forget(name, text.reconfirm);
      });
    }
    async function load(explicit = false) {
      if (busy || authLost || suspended || (!explicit && !names.some((name) => markers[name])))
        return;
      const turn = generation;
      busy = true;
      render();
      try {
        const value = await request();
        if (turn !== generation) return;
        state = value;
        validateMarkers();
        applyPreferences();
        panelOpen = accountViewOpen();
        recordCurrent();
      } catch (error) {
        if (!error.stale) names.forEach((name) => forget(name, error.message));
      } finally {
        if (turn === generation) {
          busy = false;
          render();
          if (delta || Object.keys(patch).length) schedule();
        }
      }
    }
    function fail(name, error) {
      if (!error.stale) {
        forget(name, error.message || text.failed);
        render();
      }
    }
    async function choose(name, enabled) {
      if (
        !state ||
        busy ||
        suspended ||
        authLost ||
        (name === 'preferences' && workingKind === 'preferences')
      ) {
        render();
        return;
      }
      if (name === 'automatic' && enabled) {
        readDevice();
        if (!storageOK || !device.enabled || !persist()) {
          messages.automatic = text.localRequired;
          render();
          return;
        }
      }
      const turn = generation;
      const opener = document.activeElement;
      const focusName = opener?.dataset.pnSyncFocus;
      let focusMoved = false;
      const moved = (event) => {
        if (
          (event.type === 'pointerdown' || event.target !== document.body) &&
          event.target !== opener
        )
          focusMoved = true;
      };
      document.addEventListener('focusin', moved);
      document.addEventListener('pointerdown', moved);
      busy = true;
      consentingPreferences = name === 'preferences' && enabled;
      // Stop before revocation starts. A lost response cannot resume this device.
      if (!enabled) forget(name);
      render();
      try {
        const body =
          name === 'automatic'
            ? enabled
              ? { enabled, epoch: state.automatic.epoch }
              : { epoch: state.automatic.epoch }
            : { revision: state.preferences.revision };
        const value = await request(name + (enabled ? '/consent' : '/withdraw'), body);
        if (turn !== generation) return;
        state.available = value.available !== false;
        delete value.available;
        state[name] = value;
        if (!state.available) {
          names.forEach((kind) => forget(kind, text.savedPaused));
          return;
        }
        messages[name] = enabled
          ? text.enabled
          : name === 'preferences'
            ? text.withdrawnPreferences
            : text.stoppedAutomatic;
        if (enabled && bind(name)) {
          if (name === 'preferences') applyPreferences();
          else recordCurrent();
        }
      } catch (error) {
        fail(name, error);
      } finally {
        document.removeEventListener('focusin', moved);
        document.removeEventListener('pointerdown', moved);
        if (turn === generation) {
          busy = false;
          consentingPreferences = false;
          render();
          if (focusName && !focusMoved && document.activeElement === document.body)
            views
              .flatMap((view) => [...view.querySelectorAll('[data-pn-sync-focus]')])
              .find(
                (control) =>
                  control.dataset.pnSyncFocus === focusName && control.getClientRects().length,
              )
              ?.focus({ preventScroll: true });
          if (Object.keys(patch).length) schedule();
        }
      }
    }
    function recordCurrent() {
      if (!cfg.current || !live('automatic') || panelOpen) return;
      readDevice();
      if (!storageOK || !device.enabled) {
        forget('automatic', text.localRequired);
        render();
        return;
      }
      const current = device.items.find((item) => item.id === cfg.current.id);
      if (!current) return;
      delta = { id: current.id, later: current.later, anchor: current.anchor };
      schedule();
    }
    function schedule() {
      if (suspended || authLost) return;
      clearTimeout(timer);
      timer = setTimeout(flush, 800);
    }
    async function flush() {
      if (working || busy || suspended || authLost) return;
      const turn = generation;
      const kind =
        live('preferences') && Object.keys(patch).length
          ? 'preferences'
          : live('automatic') && !panelOpen && delta
            ? 'automatic'
            : null;
      if (!kind) return;
      working = true;
      workingKind = kind;
      render();
      const value = kind === 'preferences' ? patch : delta;
      if (kind === 'preferences') {
        sentPatch = value;
        patch = {};
      } else delta = null;
      try {
        const body =
          kind === 'preferences'
            ? {
                revision: state.preferences.revision,
                epoch: state.preferences.epoch,
                values: value,
              }
            : { epoch: state.automatic.epoch, item: value };
        const data = await request(kind + (kind === 'preferences' ? '/update' : '/progress'), body);
        if (turn !== generation) return;
        state.available = data.available !== false;
        delete data.available;
        if (!state.available) {
          names.forEach((name) => forget(name, text.savedPaused));
          render();
          return;
        }
        if (kind === 'preferences') {
          state.preferences = data;
          sentPatch = {};
        } else accountCenters.forEach((center) => center.clear());
        messages[kind] = text.saved;
        render();
      } catch (error) {
        fail(kind, error);
      } finally {
        if (turn === generation) {
          working = false;
          workingKind = null;
          render();
          if (delta || Object.keys(patch).length) schedule();
        }
      }
    }
    function render() {
      views.forEach((view) => {
        const focused = view.contains(document.activeElement)
          ? document.activeElement?.dataset.pnSyncFocus
          : null;
        const open = view.querySelector('details')?.open;
        view.replaceChildren();
        const settings = node('details', undefined, 'pn-reading-management');
        settings.open = Boolean(open);
        settings.append(node('summary', text.title));
        const refresh = button(text.load, () => load(true));
        refresh.dataset.pnSyncFocus = 'load';
        refresh.disabled = busy || authLost;
        settings.append(refresh);
        if (state)
          names.forEach((name) => {
            const box = node('div');
            const label = node('label', undefined, 'pn-reading-consent');
            const input = node('input');
            input.type = 'checkbox';
            input.dataset.pnSyncFocus = name;
            input.dataset.pnSyncConsent = name;
            input.checked = Boolean(live(name));
            input.disabled =
              busy ||
              authLost ||
              (name === 'preferences' && workingKind === 'preferences') ||
              !state.available ||
              (name === 'automatic' && !state.readingEnabled);
            label.append(input, document.createTextNode(' ' + text[name]));
            input.addEventListener('change', () => choose(name, input.checked));
            const feedback = node(
              'p',
              (name === 'preferences' && workingKind === 'preferences' ? text.saving : '') ||
                messages[name] ||
                (!state.available
                  ? text.paused
                  : name === 'automatic' && !state.readingEnabled
                    ? text.accountFirst
                    : state[name].enabled && !live(name)
                      ? text.reconfirm
                      : ''),
              'pn-reading-hint',
            );
            feedback.dataset.pnSyncStatus = name;
            feedback.setAttribute('role', 'status');
            box.append(label, feedback);
            if (state[name].enabled) {
              const withdraw = button(
                name === 'preferences' ? text.withdrawPreferences : text.stopAutomatic,
                () => choose(name, false),
              );
              withdraw.disabled =
                busy || authLost || (name === 'preferences' && workingKind === 'preferences');
              withdraw.dataset.pnSyncWithdraw = name;
              withdraw.dataset.pnSyncFocus = 'withdraw-' + name;
              box.append(withdraw);
            }
            settings.append(box);
          });
        settings.append(node('p', text.help, 'pn-reading-hint'));
        view.append(settings);
        if (focused && document.activeElement === document.body)
          view.querySelector(`[data-pn-sync-focus="${focused}"]`)?.focus({ preventScroll: true });
      });
    }
    function suspend(authentication = false) {
      generation++;
      suspended = true;
      clearTimeout(timer);
      delta = null;
      patch = {};
      sentPatch = {};
      consentingPreferences = false;
      busy = false;
      working = false;
      workingKind = null;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      state = null;
      restoreLocal();
      if (authentication) {
        authLost = true;
        names.forEach((name) => forget(name, text.login));
      }
      render();
    }
    document.addEventListener('pagenest-reader-preferences-ready', (event) => {
      if (event.detail?.version !== 1) return;
      themeReady = true;
      if (live('preferences')) applyPreferences();
    });
    document.addEventListener('pagenest-reader-preferences-applied', (event) => {
      if (event.detail?.version !== 1 || event.detail.requestId !== applying) return;
      if (event.detail.source === 'remote' && live('preferences')) {
        messages.preferences = text.applied;
        render();
      }
    });
    document.addEventListener('pagenest-reader-preferences-change', (event) => {
      const value = event.detail;
      if (
        (!live('preferences') && !consentingPreferences && !markers.preferences) ||
        suspended ||
        authLost ||
        !themeReady ||
        value?.version !== 1 ||
        value.source !== 'user' ||
        !Array.isArray(value.changed) ||
        !value.changed.length ||
        value.changed.length > 3 ||
        new Set(value.changed).size !== value.changed.length ||
        !value.preferences ||
        value.changed.some(
          (name) =>
            !Object.hasOwn(allowed, name) || !allowed[name].includes(value.preferences[name]),
        )
      )
        return;
      value.changed.forEach((name) => {
        patch[name] = value.preferences[name];
      });
      schedule();
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) suspend();
      else if (!authLost) {
        suspended = false;
        load();
      }
    });
    addEventListener('pagehide', () => suspend());
    addEventListener('pageshow', (event) => {
      if (event.persisted && !authLost) {
        suspended = false;
        load();
      }
    });
    document.addEventListener('pagenest-pc-auth-change', () => suspend(true));
    addEventListener('storage', (event) => {
      if (!names.some((name) => event.key === storageKey(name)) && event.key !== null) return;
      names.forEach((name) => {
        const current = readMarker(name);
        if (!current || current.epoch !== markers[name]?.epoch) forget(name, text.reconfirm);
      });
      render();
    });
    document.dispatchEvent(
      new CustomEvent('pagenest-reader-preferences-request', {
        detail: { version: 1, requestId: 'reading-sync' },
      }),
    );
    load();
    return {
      attach(view) {
        views.push(view);
        render();
      },
      serialize,
      record: recordCurrent,
      panel(open) {
        panelOpen = open;
        if (open) delta = null;
      },
      beforeManual(action) {
        if (['remove', 'clear', 'withdraw'].includes(action)) {
          forget('automatic', text.reconfirm);
          render();
        }
      },
      afterManual(action) {
        if (state && ['consent', 'remove', 'clear', 'withdraw'].includes(action)) load(true);
      },
    };
  }
  sync = createReadingSync();
  function accountPanel(view) {
    const a = cfg.accountText;
    let state = null,
      generation = 0,
      pending = false;
    const controllers = new Set();
    const content = node('div');
    const status = node('p', '', 'pn-reading-hint');
    status.setAttribute('role', 'status');
    const loadButton = button(a.load, () => load());
    loadButton.dataset.pnAccountLoad = '';
    const intro = node('p', a.intro, 'pn-reading-hint');
    view.append(node('h3', a.title), intro);
    if (!cfg.nonce) {
      const login = node('a', t.login);
      login.href = safeUrl(cfg.login);
      view.append(login);
      return { view, clear() {} };
    }
    view.append(loadButton, status, content);
    if (sync) {
      const settings = node('div');
      settings.dataset.pnSyncSettings = '';
      view.append(settings);
      sync.attach(settings);
    }
    function clear(message = '') {
      generation++;
      controllers.forEach((controller) => controller.abort());
      controllers.clear();
      state = null;
      pending = false;
      content.replaceChildren();
      status.textContent = message;
      loadButton.disabled = false;
      loadButton.textContent = a.load;
      intro.hidden = false;
    }
    async function api(action = '', body) {
      const turn = generation,
        controller = new AbortController();
      controllers.add(controller);
      try {
        let url = new URL(cfg.accountApi, location.href);
        if (action) {
          if (url.searchParams.has('rest_route'))
            url.searchParams.set('rest_route', url.searchParams.get('rest_route') + '/' + action);
          else url.pathname = url.pathname.replace(/\/$/, '') + '/' + action;
        }
        const send = async () => {
          if (turn !== generation || controller.signal.aborted)
            throw Object.assign(Error(), { stale: true });
          const timeout = setTimeout(() => controller.abort(), 15000);
          try {
            const response = await fetch(safeUrl(url.href), {
              method: body === undefined ? 'GET' : 'POST',
              credentials: 'same-origin',
              cache: 'no-store',
              signal: controller.signal,
              headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
              ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            const data = await response.json();
            return { response, data };
          } finally {
            clearTimeout(timeout);
          }
        };
        const { response, data } = sync ? await sync.serialize(send) : await send();
        if (turn !== generation) throw Object.assign(Error(), { stale: true });
        if (response.status === 401 || data?.code === 'rest_cookie_invalid_nonce') {
          document.dispatchEvent(new Event('pagenest-pc-auth-change'));
          accountCenters.forEach((center) => center.clear(a.loginRequired));
          throw Object.assign(Error(), { stale: true });
        }
        if (response.status === 403) {
          accountCenters.forEach((center) =>
            center.clear(
              data?.code === 'account_reading_consent' ? a.needConsent : a.featureUnavailable,
            ),
          );
          throw Object.assign(Error(), { stale: true });
        }
        if (!response.ok)
          throw Object.assign(
            Error(
              response.status === 409
                ? a.conflict
                : data?.code === 'account_reading_readback'
                  ? a.savedReadback
                  : a.error,
            ),
            { conflict: response.status === 409 },
          );
        return data;
      } catch (error) {
        if (turn !== generation) error.stale = true;
        throw error;
      } finally {
        controllers.delete(controller);
      }
    }
    function render() {
      const managementOpen = content.querySelector('[data-pn-account-manage]')?.open;
      const helpOpen = content.querySelector('.pn-reading-help')?.open;
      content.replaceChildren();
      if (!state) return;
      intro.hidden = true;
      if (state.available === false) {
        content.append(node('p', a.paused, 'pn-reading-hint'));
        if (state.enabled) {
          const withdraw = button(a.withdraw, () => {
            if (confirm(a.confirmWithdraw)) mutate('withdraw');
          });
          withdraw.dataset.pnAccountWithdraw = '';
          content.append(withdraw);
        }
        return;
      }
      if (!state.enabled) {
        const label = node('label', undefined, 'pn-reading-consent');
        const consent = node('input');
        consent.type = 'checkbox';
        consent.dataset.pnAccountConsent = '';
        label.append(consent, document.createTextNode(' ' + a.consent));
        const enable = button(a.enable, () => {
          if (consent.checked) mutate('consent', { enabled: true });
        });
        enable.dataset.pnAccountEnable = '';
        enable.disabled = true;
        consent.addEventListener('change', () => {
          enable.disabled = !consent.checked;
        });
        content.append(node('p', a.off), label, enable);
        return;
      }
      const merge = button(a.merge, () => {
        readDevice();
        if (!storageOK || !device.enabled || !persist()) {
          status.textContent = a.localRequired;
          return;
        }
        const snapshot = { state: JSON.stringify(device), generation: deviceGeneration };
        mutate(
          'merge',
          { items: device.items.map((item) => ({ ...item, time: Math.trunc(item.time) })) },
          snapshot,
        );
      });
      merge.dataset.pnAccountMerge = '';
      const mergeHelp = help(a.mergeHelp);
      mergeHelp.open = Boolean(helpOpen);
      content.append(merge, node('p', a.mergeSummary, 'pn-reading-hint'), mergeHelp);
      const list = node('ul', undefined, 'pn-reading-device-items');
      const management = node('details', undefined, 'pn-reading-management');
      management.dataset.pnAccountManage = '';
      management.open = Boolean(managementOpen);
      management.append(node('summary', a.manage), node('p', a.deleteHelp));
      state.items.forEach((item) => {
        const li = node('li'),
          body = node('div', undefined, 'pn-reading-item-body'),
          link = node('a', item.title);
        link.href = safeUrl(item.url + (item.anchor ? '#' + encodeURIComponent(item.anchor) : ''));
        const date = node(
          'time',
          new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
            month: 'short',
            day: 'numeric',
          }).format(item.time),
        );
        date.dateTime = new Date(item.time).toISOString();
        body.append(link, date);
        if (item.later) body.append(node('span', t.later, 'pn-reading-kicker'));
        li.append(body);
        list.append(li);
        const remove = button(a.remove + ': ' + item.title, () => {
          if (confirm(a.confirmRemove)) mutate('remove', { id: item.id });
        });
        remove.dataset.pnAccountRemove = String(item.id);
        management.append(remove);
      });
      const clearButton = button(a.clear, () => {
        if (confirm(a.confirmClear)) mutate('clear');
      });
      clearButton.dataset.pnAccountClear = '';
      const withdraw = button(a.withdraw, () => {
        if (confirm(a.confirmWithdraw)) mutate('withdraw');
      });
      withdraw.dataset.pnAccountWithdraw = '';
      management.append(clearButton, withdraw);
      content.append(
        state.items.length ? list : node('p', t.empty, 'pn-reading-empty'),
        management,
      );
    }
    async function load() {
      if (pending) return;
      clear();
      const turn = generation;
      pending = true;
      loadButton.disabled = true;
      status.textContent = t.loading;
      try {
        const data = await api();
        if (turn !== generation) return;
        state = data;
        render();
        loadButton.textContent = a.refresh;
        status.textContent = '';
      } catch (error) {
        if (!error.stale) clear(a.error);
      } finally {
        if (turn === generation) {
          pending = false;
          loadButton.disabled = false;
        }
      }
    }
    async function mutate(action, input = {}, deviceSnapshot = null) {
      if (pending || !state) return;
      const turn = generation;
      sync?.beforeManual(action);
      const manageAction = action === 'remove' || action === 'clear';
      const removedIndex =
        action === 'remove' ? state.items.findIndex((item) => item.id === input.id) : -1;
      const neighborIds =
        removedIndex < 0
          ? []
          : [
              ...state.items.slice(removedIndex + 1).map((item) => item.id),
              ...state.items
                .slice(0, removedIndex)
                .reverse()
                .map((item) => item.id),
            ];
      const focusOrigin = document.activeElement;
      const focusWasInside = view.contains(focusOrigin);
      let focusMoved = false;
      const moved = (event) => {
        if (manageAction) {
          if (
            (event.type === 'pointerdown' || event.target !== document.body) &&
            event.target !== focusOrigin
          )
            focusMoved = true;
        } else if (!view.contains(event.target)) focusMoved = true;
      };
      document.addEventListener('focusin', moved);
      document.addEventListener('pointerdown', moved);
      pending = true;
      loadButton.disabled = true;
      content.querySelectorAll('button, input').forEach((control) => {
        control.disabled = true;
      });
      status.textContent = t.loading;
      try {
        const data = await api(action, { ...input, revision: state.revision });
        if (turn !== generation) return;
        accountCenters
          .filter((center) => center.view !== view)
          .forEach((center) => center.clear(a.changed));
        state = data;
        sync?.afterManual(action);
        let message = action === 'merge' ? a.merged : action === 'consent' ? a.enabled : a.saved;
        if (action === 'merge') {
          if (data.available === false) message = a.serverPaused;
          else {
            readDevice();
            if (
              !storageOK ||
              !device.enabled ||
              JSON.stringify(device) !== deviceSnapshot.state ||
              deviceGeneration !== deviceSnapshot.generation
            )
              message = a.serverOnly;
            else {
              device.items = data.items.map(({ id, time, later, anchor }) => ({
                id,
                time,
                later,
                anchor,
              }));
              message = persist() ? a.merged : a.serverOnly;
              renderDevice();
            }
          }
        }
        const redrawFocus =
          manageAction && content.contains(document.activeElement) ? document.activeElement : null;
        const redrawHref = redrawFocus?.matches('a[href]') ? redrawFocus.href : null;
        const redrawSummary = redrawFocus?.matches('summary')
          ? redrawFocus.closest('[data-pn-account-manage]')
            ? '[data-pn-account-manage] > summary'
            : redrawFocus.closest('.pn-reading-help')
              ? '.pn-reading-help > summary'
              : null
          : null;
        render();
        if (redrawFocus && !redrawFocus.isConnected && document.activeElement === document.body) {
          const replacement = redrawHref
            ? [...content.querySelectorAll('a[href]')].find((link) => link.href === redrawHref)
            : redrawSummary
              ? content.querySelector(redrawSummary)
              : null;
          replacement?.focus({ preventScroll: true });
        }
        status.textContent = message;
        loadButton.textContent = a.refresh;
        loadButton.disabled = false;
        if (focusWasInside && !focusMoved) {
          let focusTarget = loadButton;
          const management = content.querySelector('[data-pn-account-manage]');
          if (manageAction && management?.open) {
            focusTarget =
              action === 'clear'
                ? management.querySelector('[data-pn-account-withdraw]')
                : neighborIds
                    .map((id) => management.querySelector(`[data-pn-account-remove="${id}"]`))
                    .find(Boolean) || management.querySelector('[data-pn-account-clear]');
          }
          (focusTarget || loadButton).focus({ preventScroll: true });
        }
      } catch (error) {
        if (!error.stale) {
          clear(error.message || a.error);
          if (focusWasInside && !focusMoved) loadButton.focus({ preventScroll: true });
        }
      } finally {
        document.removeEventListener('focusin', moved);
        document.removeEventListener('pointerdown', moved);
        if (turn === generation) {
          pending = false;
          loadButton.disabled = false;
        }
      }
    }
    return { view, clear };
  }
  document.querySelectorAll('.pn-reading').forEach((root, index) => {
    const deviceView = root.querySelector('[data-pn-device]'),
      records = root.querySelector('[data-pn-records]');
    if (!deviceView || !records) return;
    root.querySelector(':scope > hr')?.remove();
    const tabs = node('div', undefined, 'pn-reading-tabs');
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', t.reading);
    const account = cfg.accountApi ? node('div') : null;
    if (account) {
      account.dataset.pnAccount = '';
      root.append(account);
      accountCenters.push(accountPanel(account));
    }
    const entries = [
      ['recent', t.recent],
      ['later', t.later],
      ['records', t.notes],
      ...(account ? [['account', cfg.accountText.title]] : []),
    ];
    const select = (selected, focus = false, initial = false) => {
      if (!initial && root.dataset.pnView === selected) return;
      if (root.dataset.pnView === 'records' && selected !== 'records') {
        centers.filter((center) => root.contains(center.view)).forEach((center) => center.clear());
      }
      if (root.dataset.pnView === 'account' && selected !== 'account')
        accountCenters
          .filter((center) => root.contains(center.view))
          .forEach((center) => center.clear());
      root.dataset.pnView = selected;
      deviceView.hidden = ['records', 'account'].includes(selected);
      if (account) account.hidden = selected !== 'account';
      records.hidden = selected !== 'records';
      updateSyncPanelState();
      [...tabs.children].forEach((tab) => {
        const active = tab.dataset.pnTab === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (active) {
          (selected === 'account'
            ? account
            : selected === 'records'
              ? records
              : deviceView
          ).setAttribute('aria-labelledby', tab.id);
          if (focus) tab.focus();
        }
      });
      if (['recent', 'later'].includes(selected) && !initial) renderDevice();
    };
    for (const [value, label] of entries) {
      const tab = button(label, () => select(value));
      tab.id = `pn-reading-${index}-tab-${value}`;
      tab.dataset.pnTab = value;
      tab.setAttribute('role', 'tab');
      const panel = value === 'account' ? account : value === 'records' ? records : deviceView;
      panel.id ||= `pn-reading-${index}-${value === 'account' ? 'account' : value === 'records' ? 'records' : 'device'}`;
      panel.setAttribute('role', 'tabpanel');
      panel.tabIndex = 0;
      tab.setAttribute('aria-controls', panel.id);
      tabs.append(tab);
    }
    tabs.addEventListener('keydown', (event) => {
      const direction = getComputedStyle(tabs).direction === 'rtl' ? -1 : 1;
      let next = entries.findIndex(([value]) => value === root.dataset.pnView);
      if (event.key === 'ArrowRight') next = (next + direction + entries.length) % entries.length;
      else if (event.key === 'ArrowLeft')
        next = (next - direction + entries.length) % entries.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = entries.length - 1;
      else return;
      event.preventDefault();
      select(entries[next][0], true);
    });
    root.prepend(tabs);
    select('recent', false, true);
  });
  renderDevice();
  const dialog = document.querySelector('.pn-reading-dialog');
  const readingToolbar = dialog?.querySelector('.pn-reading-dialog-toolbar');
  const updateReadingScrollPadding = () => {
    if (readingToolbar) {
      dialog.style.scrollPaddingBlockStart = `${readingToolbar.getBoundingClientRect().height + 12}px`;
    }
  };
  if (readingToolbar && typeof ResizeObserver === 'function') {
    new ResizeObserver(updateReadingScrollPadding).observe(readingToolbar);
  }
  window.addEventListener('resize', updateReadingScrollPadding);
  dialog?.addEventListener('focusin', () => {
    requestAnimationFrame(() => {
      const focused = document.activeElement;
      if (
        !dialog.open ||
        focused === dialog ||
        !dialog.contains(focused) ||
        readingToolbar?.contains(focused)
      )
        return;
      const bounds = dialog.getBoundingClientRect();
      const target = focused.getBoundingClientRect();
      const top = (readingToolbar?.getBoundingClientRect().bottom || bounds.top) + 12;
      const bottom = bounds.bottom - 12;
      if (target.top < top) dialog.scrollTop += target.top - top;
      else if (target.height <= bottom - top && target.bottom > bottom)
        dialog.scrollTop += target.bottom - bottom;
    });
  });
  let dialogOpener;
  document.querySelectorAll('[data-pn-open-reading]').forEach((opener) => {
    if (dialog) opener.hidden = false;
    opener.addEventListener('click', () => {
      dialogOpener = opener;
      dialog?.showModal();
      updateSyncPanelState();
      updateReadingScrollPadding();
      renderDevice();
    });
  });
  document
    .querySelector('[data-pn-close-reading]')
    ?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('close', () => {
    if (dialog.open) return;
    updateSyncPanelState();
    accountCenters.forEach((center) => center.clear());
    centers.forEach((center) => center.clear());
    const candidates = [
      dialogOpener,
      dialogOpener?.closest('details')?.querySelector('summary'),
      document.querySelector('.pagenest-menu-toggle'),
    ];
    const target = candidates.find((element) => {
      if (
        !element?.isConnected ||
        !element.getClientRects().length ||
        getComputedStyle(element).visibility === 'hidden'
      )
        return false;
      for (let parent = element.parentElement; parent; parent = parent.parentElement) {
        if (
          parent.matches('details:not([open])') &&
          !parent.querySelector(':scope > summary')?.contains(element)
        )
          return false;
      }
      return true;
    });
    target?.focus({ preventScroll: true });
  });
  const clear = () => {
    accountCenters.forEach((center) => center.clear());
    centers.forEach((center) => center.clear());
    resolving++;
    deviceViews.forEach((view) => view.replaceChildren());
  };
  addEventListener('pagehide', clear);
  document.addEventListener('pagenest-pc-auth-change', clear);
  addEventListener('pageshow', (event) => {
    if (event.persisted) {
      clear();
      renderDevice();
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clear();
    else renderDevice();
  });
  addEventListener('focus', () => centers.forEach((center) => center.validate()));
  setInterval(() => centers.forEach((center) => center.validate()), 30000);
})();
