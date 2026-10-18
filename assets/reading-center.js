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
          .filter((item) => Number.isInteger(item.id) && item.id > 0 && Number.isFinite(item.time))
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
  async function renderDevice() {
    const generation = ++resolving;
    deviceViews.forEach((view) => {
      view.replaceChildren(node('h3', t.device));
      const label = node('label'),
        toggle = node('input');
      toggle.type = 'checkbox';
      toggle.checked = device.enabled;
      toggle.disabled = !storageOK;
      label.append(toggle, document.createTextNode(' ' + t.consent));
      toggle.addEventListener('change', () => {
        device = { enabled: toggle.checked, items: toggle.checked ? device.items : [] };
        if (persist()) remember();
        renderDevice();
      });
      view.append(label, node('p', storageOK ? t.deviceHelp : t.unavailable));
      if (device.enabled)
        view.append(
          button(t.clear, () => {
            device.items = [];
            persist();
            renderDevice();
          }),
        );
    });
    if (!device.enabled || !device.items.length) return;
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
      persist();
      deviceViews.forEach((view) => {
        for (const later of [false, true]) {
          view.append(node('h4', later ? t.later : t.recent));
          const list = node('ul');
          for (const item of device.items.filter((item) => !later || item.later)) {
            const current = live.get(item.id),
              li = node('li'),
              link = node('a', current.title);
            link.href = safeUrl(
              current.url +
                (current.headings.includes(item.anchor)
                  ? '#' + encodeURIComponent(item.anchor)
                  : ''),
            );
            li.append(
              link,
              button(t.remove, () => {
                device.items = device.items.filter((saved) => saved.id !== item.id);
                persist();
                renderDevice();
              }),
            );
            list.append(li);
          }
          view.append(list.childElementCount ? list : node('p', t.empty));
        }
      });
    } catch {
      if (generation === resolving) deviceViews.forEach((view) => view.append(node('p', t.error)));
    }
  }
  renderDevice();
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
      actions.append(
        button(t.edit, () => {
          if (article.querySelector('textarea')) return;
          const editor = node('textarea');
          editor.value = item.text;
          editor.maxLength = 10000;
          editor.setAttribute('aria-label', t.edit);
          const editBox = node('div');
          const save = button(t.save, async () => {
            save.disabled = true;
            try {
              await api(`notes/${item.id}`, 'PATCH', { text: editor.value, version: item.version });
              await load();
            } catch (error) {
              if (!error.stale) status.textContent = error.message || t.error;
            } finally {
              save.disabled = false;
            }
          });
          editBox.append(
            editor,
            save,
            button(t.cancel, () => editBox.remove()),
          );
          article.append(editBox);
          editor.focus();
        }),
        button(t.delete, async () => {
          if (!confirm(t.confirmDelete)) return;
          try {
            await api(`notes/${item.id}`, 'DELETE', { version: item.version });
            await load();
          } catch (error) {
            if (!error.stale) status.textContent = error.message || t.error;
          }
        }),
        button(t.relink, async () => {
          if (article.querySelector('[data-pn-relink]')) return;
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
          const loadBlocks = button(t.more, async () => {
            loadBlocks.disabled = true;
            try {
              const page = await api(`blocks?post=${item.post_id}&cursor=${blockCursor}`);
              for (const block of page.items) {
                paragraphs.set(block.id, block.text);
                const option = node('option', block.text);
                option.value = block.id;
                select.append(option);
              }
              blockCursor = page.next_cursor;
              loadBlocks.hidden = blockCursor === null;
            } catch (error) {
              if (!error.stale) status.textContent = t.error;
            } finally {
              loadBlocks.disabled = false;
            }
          });
          box.append(
            select,
            preview,
            loadBlocks,
            button(t.replace, async () => {
              if (!select.value) return;
              try {
                await api(`notes/${item.id}`, 'PATCH', {
                  block_id: select.value,
                  version: item.version,
                });
                await load();
              } catch (error) {
                if (!error.stale) status.textContent = error.message || t.error;
              }
            }),
            button(t.cancel, () => box.remove()),
          );
          loadBlocks.click();
        }),
      );
      article.append(actions);
      return article;
    }
    async function load(append = false) {
      if (pending) return;
      if (!append) clearPrivate();
      const turn = generation;
      pending = true;
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
        status.textContent = !results.childElementCount ? t.empty : '';
      } catch (error) {
        if (!error.stale) {
          clearPrivate(t.error);
        }
      } finally {
        if (turn === generation) pending = false;
      }
    }
    controls.addEventListener('submit', (event) => {
      event.preventDefault();
      load();
    });
    view.append(node('h3', t.records), node('p', t.privateHelp));
    if (!cfg.nonce) {
      const link = node('a', t.login);
      link.href = safeUrl(cfg.login);
      view.append(link);
      return;
    }
    view.append(
      controls,
      button(t.load, () => load()),
      status,
      results,
      more,
    );
    const backup = node('details');
    backup.append(node('summary', t.import));
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
    view.append(backup);
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
      clear: (message = '') => {
        clearPrivate(message);
        query.value = '';
        kind.value = 'all';
        file.value = '';
        backup.open = false;
      },
      validate,
    });
  });
  const dialog = document.querySelector('.pn-reading-dialog');
  let dialogOpener;
  document.querySelectorAll('[data-pn-open-reading]').forEach((opener) => {
    if (dialog) opener.hidden = false;
    opener.addEventListener('click', () => {
      dialogOpener = opener;
      dialog?.showModal();
      renderDevice();
    });
  });
  document
    .querySelector('[data-pn-close-reading]')
    ?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('close', () => {
    centers.forEach((center) => center.clear());
    if (dialogOpener?.isConnected) dialogOpener.focus({ preventScroll: true });
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
