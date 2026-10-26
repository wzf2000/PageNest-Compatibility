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
    resolving = 0;
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
    return persist();
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
    if (event.key !== key) return;
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
        if ([401, 403].includes(response.status) || data?.code === 'comments_disabled') {
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
  document.querySelectorAll('.pn-reading').forEach((root, index) => {
    const deviceView = root.querySelector('[data-pn-device]'),
      records = root.querySelector('[data-pn-records]');
    if (!deviceView || !records) return;
    root.querySelector(':scope > hr')?.remove();
    const tabs = node('div', undefined, 'pn-reading-tabs');
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', t.reading);
    const entries = [
      ['recent', t.recent],
      ['later', t.later],
      ['records', t.notes],
    ];
    const select = (selected, focus = false, initial = false) => {
      if (!initial && root.dataset.pnView === selected) return;
      if (root.dataset.pnView === 'records' && selected !== 'records') {
        centers.filter((center) => root.contains(center.view)).forEach((center) => center.clear());
      }
      root.dataset.pnView = selected;
      deviceView.hidden = selected === 'records';
      records.hidden = selected !== 'records';
      [...tabs.children].forEach((tab) => {
        const active = tab.dataset.pnTab === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (active) {
          (selected === 'records' ? records : deviceView).setAttribute('aria-labelledby', tab.id);
          if (focus) tab.focus();
        }
      });
      if (selected !== 'records' && !initial) renderDevice();
    };
    for (const [value, label] of entries) {
      const tab = button(label, () => select(value));
      tab.id = `pn-reading-${index}-tab-${value}`;
      tab.dataset.pnTab = value;
      tab.setAttribute('role', 'tab');
      const panel = value === 'records' ? records : deviceView;
      panel.id ||= `pn-reading-${index}-${value === 'records' ? 'records' : 'device'}`;
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
      updateReadingScrollPadding();
      renderDevice();
    });
  });
  document
    .querySelector('[data-pn-close-reading]')
    ?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('close', () => {
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
