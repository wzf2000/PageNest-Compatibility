(() => {
  'use strict';
  const cfg = window.PageNestComments;
  const chapter = document.querySelector('.pagenest-comments-body');
  if (!cfg || !chapter || !cfg.post || !cfg.api) return;
  const rail = document.querySelector('.pagenest-reading-rail');
  const slot = document.querySelector('[data-pagenest-reading-panel]');
  const wide = matchMedia('(min-width: 1280px)');
  const blocks = new Map();
  chapter.querySelectorAll('[data-pagenest-block]').forEach((node) => {
    const id = node.dataset.pagenestBlock;
    if (id && !blocks.has(id)) blocks.set(id, node);
  });
  const state = {
    comments: [],
    drafts: new Map(),
    active: null,
    edit: null,
    open: false,
    pending: false,
    authorized: !!cfg.nonce && Number(cfg.user) > 0,
    generation: 0,
    sequence: 0,
    refresh: null,
    refreshedAt: null,
    historyEntry: false,
    historyReturning: false,
    pageY: 0,
    tocScroll: 0,
    opener: null,
    openerBlock: null,
    afterClose: null,
    loaded: false,
    status: '',
    tone: 'neutral',
  };
  const el = (tag, name, text) => {
    const node = document.createElement(tag);
    if (name) node.className = name;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const authorAvatar = (name, url, className = '') => {
    const node = el(
      'span',
      `pagenest-paragraph-avatar ${className}`.trim(),
      Array.from(name || '')[0] || '',
    );
    node.setAttribute('aria-hidden', 'true');
    if (typeof url !== 'string' || !url) return node;
    let source;
    try {
      source = new URL(url, location.href);
    } catch {
      return node;
    }
    if (!['http:', 'https:'].includes(source.protocol)) return node;
    const image = el('img', 'pagenest-paragraph-avatar-image');
    image.alt = '';
    image.width = 31;
    image.height = 31;
    image.loading = 'lazy';
    image.decoding = 'async';
    image.style.visibility = 'hidden';
    image.addEventListener('load', () => {
      if (image.parentNode !== node) return;
      image.style.visibility = '';
      node.classList.add('pagenest-paragraph-avatar-loaded');
    });
    image.addEventListener(
      'error',
      () => {
        node.classList.remove('pagenest-paragraph-avatar-loaded');
        image.remove();
      },
      { once: true },
    );
    image.src = source.href;
    node.append(image);
    return node;
  };
  const button = (label, action, name = '') => {
    const node = el('button', name, label);
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  const icon = (kind) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS(svg.namespaceURI, 'path');
    path.setAttribute(
      'd',
      {
        bubble:
          'M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-7l-5 3v-3H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
        close: 'm6 6 12 12M18 6 6 18',
        back: 'm14 5-7 7 7 7',
      }[kind],
    );
    svg.append(path);
    return svg;
  };
  const iconButton = (label, kind, action, name) => {
    const node = button('', action, name);
    node.setAttribute('aria-label', label);
    node.title = label;
    node.append(icon(kind));
    return node;
  };
  const newUuid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
      (
        Number(c) ^
        (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (Number(c) / 4)))
      ).toString(16),
    );
  };
  const key = () =>
    state.edit ? `edit:${state.edit.id}` : state.active ? `block:${state.active}` : null;
  const draft = () => {
    const id = key();
    if (!id) return null;
    if (!state.drafts.has(id)) state.drafts.set(id, { text: '', uuid: newUuid() });
    return state.drafts.get(id);
  };
  const quoteFrom = (node) => {
    if (!node) return '';
    const clone = node.cloneNode(true);
    clone.querySelectorAll('.pagenest-paragraph-bubble').forEach((x) => x.remove());
    return clone.textContent || '';
  };
  const dialog = el('dialog', 'pagenest-pc-dialog pagenest-paragraph-dialog');
  dialog.setAttribute('aria-label', '段落评论');
  const panel = el('section', 'pagenest-pc-panel pagenest-paragraph-panel');
  panel.setAttribute('aria-label', '段落评论');
  const handle = el('div', 'pagenest-paragraph-handle');
  handle.setAttribute('aria-hidden', 'true');
  const context = el('header', 'pagenest-paragraph-context');
  const quote = el('blockquote', 'pagenest-paragraph-quote');
  const closeButton = iconButton('关闭评论', 'close', () => close(), 'pagenest-paragraph-close');
  closeButton.append(el('span', 'pagenest-paragraph-return-navigation', '返回导航'));
  context.append(quote, closeButton);
  const titleRow = el('div', 'pagenest-paragraph-title-row');
  const back = iconButton('查看本章评论', 'back', () => openPanel(null), 'pagenest-paragraph-back');
  const title = el('h2', '', '评论');
  titleRow.append(back, title);
  const content = el('div', 'pagenest-paragraph-content');
  const status = el('div', 'pagenest-paragraph-status');
  status.setAttribute('role', 'status');
  const composer = el('form', 'pagenest-paragraph-composer');
  const avatar = authorAvatar(
    state.authorized ? cfg.displayName || '我' : '',
    state.authorized ? cfg.avatarUrl : '',
    'pagenest-paragraph-self',
  );
  const input = el('textarea', 'pagenest-paragraph-input');
  input.rows = 1;
  input.maxLength = 10000;
  input.placeholder = '写下你的公开评论…';
  input.setAttribute('aria-label', '公开评论');
  const send = el('button', 'pagenest-paragraph-send', '发布');
  send.type = 'submit';
  send.setAttribute('aria-label', '发布公开评论');
  const cancel = button(
    '取消',
    () => {
      if (state.pending) return;
      state.drafts.delete(key());
      state.edit = null;
      renderComposer();
    },
    'pagenest-paragraph-cancel',
  );
  const login = el('a', 'pagenest-paragraph-login', '登录后发表评论');
  login.href = cfg.loginUrl || '/wp-login.php';
  const hint = el('p', 'pagenest-paragraph-composer-hint', '点正文旁的气泡，围绕一段话展开讨论。');
  composer.append(avatar, input, cancel, send, login, hint);
  panel.append(handle, context, titleRow, content, status, composer);
  dialog.append(panel);
  document.body.append(dialog);
  const opener = button('', () => openPanel(null), 'pagenest-pc-open pagenest-paragraph-open');
  opener.append(icon('bubble'), el('span', '', '段落评论'));
  const nav = document.querySelector('[data-pagenest-header-actions]') || document.body;
  nav.append(opener);
  document.dispatchEvent(new Event('pagenest-pc-ready'));

  function setStatus(text = '', tone = 'neutral') {
    state.status = text;
    state.tone = tone;
    status.replaceChildren();
    status.dataset.tone = tone;
    if (text) status.append(el('span', '', text));
    if (tone === 'error' && !state.pending)
      status.append(button('重新加载', () => load(true), 'pagenest-paragraph-retry'));
  }
  function resizeInput() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, Math.max(44, Math.min(112, (window.visualViewport?.height || innerHeight) * 0.23)))}px`;
  }
  function saveDraft() {
    const value = draft();
    if (value && state.authorized) value.text = input.value;
  }
  input.addEventListener('input', () => {
    saveDraft();
    resizeInput();
  });
  function renderComposer() {
    const editable =
      state.authorized && !!state.active && (blocks.has(state.active) || !!state.edit);
    avatar.hidden = !editable;
    input.hidden = !editable;
    send.hidden = !editable;
    cancel.hidden = !editable || !state.edit;
    login.hidden = state.authorized;
    hint.hidden = !state.authorized || editable;
    input.disabled = state.pending;
    send.disabled = state.pending;
    cancel.disabled = state.pending;
    const nextText = editable ? draft().text : '';
    if (input.value !== nextText) input.value = nextText;
    input.placeholder = state.edit ? '修改你的评论…' : '写下你的公开评论…';
    send.textContent = state.edit ? '保存' : '发布';
    send.setAttribute('aria-label', state.edit ? '保存评论修改' : '发布公开评论');
    if (editable) resizeInput();
  }
  function updateBubbles() {
    blocks.forEach((node, id) => {
      let bubble = node.querySelector(':scope > .pagenest-paragraph-bubble');
      if (!bubble) {
        node.classList.add('pagenest-paragraph-block');
        bubble = button('', () => openPanel(id, bubble), 'pagenest-paragraph-bubble');
        bubble.dataset.block = id;
        node.append(bubble);
      }
      const count = state.comments.filter((c) => c.block_id === id).length;
      bubble.replaceChildren(icon('bubble'));
      if (count) bubble.append(el('span', '', String(count)));
      bubble.classList.toggle('pagenest-paragraph-has-comments', count > 0);
      bubble.setAttribute('aria-label', count ? `此段有 ${count} 条公开评论` : '评论此段');
      node.classList.toggle('pagenest-paragraph-selected', state.open && state.active === id);
    });
  }
  function dateLabel(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? ''
      : date.toLocaleDateString('zh-CN', { month: 'numeric', day: 'numeric' });
  }
  function returnToBlock(id) {
    const node = blocks.get(id);
    if (!node) return;
    close(() => {
      node.scrollIntoView({ block: 'center', behavior: 'auto' });
      node.querySelector('.pagenest-paragraph-bubble')?.focus({ preventScroll: true });
    });
  }
  function commentCard(comment) {
    const card = el('article', 'pagenest-paragraph-comment');
    card.dataset.id = String(comment.id);
    const face = authorAvatar(comment.author_name || '读者', comment.author_avatar_url);
    const body = el('div', 'pagenest-paragraph-comment-body');
    const author = el('p', 'pagenest-paragraph-author', comment.author_name || '读者');
    if (state.authorized && comment.can_edit)
      author.append(el('span', 'pagenest-paragraph-self-label', '我'));
    body.append(author, el('p', 'pagenest-paragraph-text', comment.text));
    const meta = el('div', 'pagenest-paragraph-meta');
    meta.append(el('time', '', dateLabel(comment.created_at)));
    if (comment.association === 'needs_review')
      meta.append(el('span', 'pagenest-paragraph-review', '原段落已变化'));
    if (state.authorized && comment.can_edit && comment.version) {
      meta.append(
        button(
          '编辑',
          () => {
            if (state.pending) return;
            saveDraft();
            state.active = comment.block_id;
            state.edit = { id: comment.id, version: comment.version };
            const value = draft();
            if (!value.initialized) {
              value.text = comment.text;
              value.initialized = true;
            }
            render();
            input.focus({ preventScroll: true });
          },
          'pagenest-paragraph-edit',
        ),
      );
      meta.append(
        button(
          '删除',
          async () => {
            if (state.pending || !confirm('删除这条公开评论？')) return;
            const generation = state.generation;
            setPending(true);
            try {
              await api(`comments/${comment.id}`, 'DELETE', { version: comment.version });
              if (generation !== state.generation) return;
              state.sequence++;
              state.comments = state.comments.filter((c) => c.id !== comment.id);
              state.drafts.delete(`edit:${comment.id}`);
              if (state.edit?.id === comment.id) state.edit = null;
              setStatus('已删除。');
              render();
              updateBubbles();
            } catch (error) {
              if (generation === state.generation && !error.stale)
                setStatus(error.message, 'error');
            } finally {
              if (generation === state.generation) setPending(false);
            }
          },
          'pagenest-paragraph-delete',
        ),
      );
    }
    body.append(meta);
    card.append(face, body);
    return card;
  }
  function render() {
    const comments = state.active
      ? state.comments.filter((c) => c.block_id === state.active)
      : state.comments;
    title.textContent = `评论 ${comments.length}`;
    back.hidden = !state.active;
    const node = blocks.get(state.active);
    quote.textContent = state.active
      ? node
        ? quoteFrom(node)
        : comments[0]?.quote || '原段落已变化，保留原引用。'
      : '围绕文章里的每一段，留下你的理解与疑问。';
    quote.classList.toggle('pagenest-paragraph-chapter-context', !state.active);
    quote.title = quote.textContent;
    content.replaceChildren();
    if (!comments.length) {
      const empty = el('div', 'pagenest-paragraph-empty');
      empty.append(icon('bubble'), el('p', '', state.loaded ? '还没有评论' : '正在加载评论…'));
      if (state.loaded)
        empty.append(
          el('span', '', state.active ? '说说你对这一段的看法' : '从正文中选一段，开始讨论'),
        );
      content.append(empty);
    } else {
      let lastBlock = null;
      comments.forEach((comment) => {
        if (!state.active && comment.block_id !== lastBlock) {
          const contextButton = button(
            comment.quote,
            () => openPanel(comment.block_id),
            'pagenest-paragraph-thread-quote',
          );
          contextButton.title = '查看这一段的评论';
          content.append(contextButton);
          lastBlock = comment.block_id;
        }
        content.append(commentCard(comment));
      });
    }
    if (state.active && node)
      content.append(
        button('回到原文', () => returnToBlock(state.active), 'pagenest-paragraph-return'),
      );
    renderComposer();
    updateBubbles();
  }
  function setPending(on) {
    if (on) {
      // Invalidate reads before the write starts, including reads that finish during it.
      state.sequence++;
      state.refresh = null;
      state.refreshedAt = null;
    }
    state.pending = on;
    panel
      .querySelectorAll(
        '.pagenest-paragraph-edit,.pagenest-paragraph-delete,.pagenest-paragraph-send,.pagenest-paragraph-cancel',
      )
      .forEach((x) => (x.disabled = on));
    input.disabled = on;
    if (!on && state.tone === 'error') setStatus(state.status, state.tone);
  }
  function clearSession(text, departing = false) {
    state.generation++;
    state.sequence++;
    state.refresh = null;
    state.refreshedAt = null;
    state.authorized = false;
    cfg.displayName = '';
    cfg.avatarUrl = '';
    cfg.nonce = '';
    cfg.user = 0;
    avatar.classList.remove('pagenest-paragraph-avatar-loaded');
    avatar.replaceChildren();
    state.pending = false;
    state.comments = [];
    state.drafts.clear();
    state.edit = null;
    input.value = '';
    state.loaded = false;
    if (departing) {
      state.historyEntry = false;
      close();
    }
    setStatus(text, 'error');
    render();
    updateBubbles();
  }
  async function api(path, method = 'GET', body) {
    const generation = state.generation;
    const [route, query = ''] = path.split('?');
    let url = new URL(cfg.api, location.href);
    if (url.searchParams.has('rest_route'))
      url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
    else url = new URL(route, url);
    new URLSearchParams(query).forEach((value, name) => url.searchParams.set(name, value));
    const headers = { Accept: 'application/json' };
    if (state.authorized) headers['X-WP-Nonce'] = cfg.nonce;
    if (body) headers['Content-Type'] = 'application/json';
    const response = await fetch(url, {
      method,
      headers,
      credentials: 'same-origin',
      cache: 'no-store',
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (generation !== state.generation) throw Object.assign(Error('过期响应'), { stale: true });
    if (response.status === 401 || response.status === 403) {
      clearSession('登录或阅读权限已变化，请重新加载。');
      throw Object.assign(Error('权限已变化'), { stale: true });
    }
    const data = await response.json();
    if (generation !== state.generation) throw Object.assign(Error('过期响应'), { stale: true });
    if (!response.ok)
      throw Error(
        response.status === 409
          ? '评论已变化，输入已保留。请重新加载后核对。'
          : data.message || '请求失败，请稍后重试。',
      );
    return data;
  }
  function sameComments(next) {
    const fields = [
      'id',
      'block_id',
      'author_name',
      'author_avatar_url',
      'text',
      'created_at',
      'association',
      'quote',
      'can_edit',
      'version',
    ];
    return (
      next.length === state.comments.length &&
      next.every((comment, index) =>
        fields.every((field) => comment[field] === state.comments[index][field]),
      )
    );
  }
  function load(force = false) {
    if (state.pending) return;
    if (state.refresh) return state.refresh.promise;
    if (!force && state.refreshedAt !== null && Date.now() - state.refreshedAt < 30000) return;
    const refresh = {};
    state.refresh = refresh;
    refresh.promise = refreshComments().finally(() => {
      if (state.refresh === refresh) state.refresh = null;
    });
    return refresh.promise;
  }
  async function refreshComments() {
    const generation = state.generation,
      sequence = ++state.sequence;
    try {
      const data = await api(`comments?post=${encodeURIComponent(cfg.post)}`);
      if (generation !== state.generation || sequence !== state.sequence) return;
      if (!Array.isArray(data)) throw Error('评论列表格式错误。');
      const changed = !state.loaded || !sameComments(data);
      state.comments = data;
      state.loaded = true;
      state.refreshedAt = Date.now();
      setStatus();
      if (changed) {
        if (state.open) {
          saveDraft();
          const scroll = content.scrollTop;
          render();
          content.scrollTop = scroll;
        } else updateBubbles();
      }
    } catch (error) {
      if (!error.stale && generation === state.generation && sequence === state.sequence) {
        const initial = !state.loaded;
        state.loaded = true;
        setStatus(error.message, 'error');
        if (initial && state.open) render();
      }
    }
  }
  composer.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.authorized || state.pending || !state.active) return;
    saveDraft();
    const value = draft(),
      text = value.text.trim();
    if (!text) {
      input.focus();
      return;
    }
    const draftKey = key(),
      editing = state.edit ? { ...state.edit } : null;
    const generation = state.generation;
    setPending(true);
    setStatus();
    try {
      const changed = await api(
        editing ? `comments/${editing.id}` : 'comments',
        editing ? 'PATCH' : 'POST',
        editing
          ? { text, version: editing.version }
          : {
              post_id: cfg.post,
              block_id: state.active,
              text,
              uuid: value.uuid,
              public_confirmed: true,
            },
      );
      if (generation !== state.generation) return;
      state.sequence++;
      state.comments = state.comments.filter((c) => c.id !== changed.id);
      state.comments.push(changed);
      state.drafts.delete(draftKey);
      state.edit = null;
      state.loaded = true;
      input.value = '';
      setStatus(editing ? '已更新。' : '评论已发布。');
      render();
      updateBubbles();
    } catch (error) {
      if (generation === state.generation && !error.stale) setStatus(error.message, 'error');
    } finally {
      if (generation === state.generation) {
        setPending(false);
        renderComposer();
      }
    }
  });

  function readingAnchor() {
    const top = parseFloat(getComputedStyle(rail).top) || 0;
    const node = [...chapter.querySelectorAll('h1,h2,h3,h4,h5,h6,p,pre,li')].find(
      (item) => item.getBoundingClientRect().bottom > top,
    );
    return node ? { node, top: node.getBoundingClientRect().top } : null;
  }
  function restoreReadingAnchor(anchor) {
    if (anchor) scrollBy(0, anchor.node.getBoundingClientRect().top - anchor.top);
  }
  function dockHeight() {
    if (!wide.matches || !rail || !slot || rail.classList.contains('pagenest-reading-short'))
      return 0;
    const column = chapter.closest('.pagenest-article-column');
    if (!column) return 0;
    const bounds = column.getBoundingClientRect(),
      top = Math.max(parseFloat(getComputedStyle(rail).top) || 0, bounds.top);
    return Math.min(680, innerHeight - top - 20, bounds.bottom - top - 2);
  }
  function lockPage(on) {
    if (on) {
      state.pageY = scrollY;
      document.documentElement.classList.add('pagenest-pc-modal-open');
      document.body.classList.add('pagenest-pc-modal-open');
      document.body.style.top = `${-state.pageY}px`;
    } else {
      document.documentElement.classList.remove('pagenest-pc-modal-open');
      document.body.classList.remove('pagenest-pc-modal-open');
      document.body.style.top = '';
      scrollTo(0, state.pageY);
    }
  }
  function fitDialog() {
    if (!dialog.open) return;
    const viewport = window.visualViewport,
      height = viewport?.height || innerHeight;
    dialog.style.setProperty('--pagenest-paragraph-height', `${height}px`);
    dialog.style.setProperty('--pagenest-paragraph-top', `${viewport?.offsetTop || 0}px`);
    dialog.classList.toggle('pagenest-paragraph-short', height <= 520);
    resizeInput();
  }
  function place() {
    if (!state.open || state.historyReturning) return;
    const height = dockHeight(),
      docked = height >= 380;
    const wasDocked = rail?.classList.contains('pagenest-panel-active');
    const anchor = rail && !dialog.open && wasDocked !== docked ? readingAnchor() : null;
    const moving = panel.parentElement !== (docked ? slot : dialog);
    const focus = moving && panel.contains(document.activeElement) ? document.activeElement : null;
    const selection =
      focus === input ? [input.selectionStart, input.selectionEnd, input.selectionDirection] : null;
    if (docked) {
      if (dialog.open) {
        dialog.close();
        lockPage(false);
        if (state.historyEntry) {
          state.historyEntry = false;
          state.historyReturning = true;
          history.back();
        }
      }
      if (panel.parentElement !== slot) slot.append(panel);
      slot.hidden = false;
      rail.style.setProperty('--pagenest-pc-docked-height', `${height}px`);
      rail.classList.add('pagenest-panel-active');
      if (!wasDocked) rail.scrollTop = 0;
    } else {
      if (panel.parentElement !== dialog) dialog.append(panel);
      if (rail) {
        rail.classList.remove('pagenest-panel-active');
        rail.style.removeProperty('--pagenest-pc-docked-height');
      }
      if (slot) slot.hidden = true;
      if (wasDocked) rail.scrollTop = state.tocScroll;
      if (!dialog.open) {
        document.querySelectorAll('dialog[open]').forEach((other) => {
          if (other !== dialog) other.close();
        });
        document.querySelector('.pagenest-mobile-toc')?.removeAttribute('open');
        document.querySelector('.pagenest-nav-open')?.classList.remove('pagenest-nav-open');
        if (!state.historyEntry) {
          history.pushState({ ...history.state, pagenestPcDrawer: true }, '', location.href);
          state.historyEntry = true;
        }
        lockPage(true);
        dialog.showModal();
        if (!focus) closeButton.focus({ preventScroll: true });
      }
      fitDialog();
    }
    closeButton.setAttribute('aria-label', docked ? '关闭评论，返回阅读导航' : '关闭评论');
    closeButton.title = docked ? '返回目录与交流链接' : '关闭评论';
    restoreReadingAnchor(anchor);
    if (focus) {
      focus.focus({ preventScroll: true });
      if (selection) input.setSelectionRange(...selection);
    }
  }
  function openPanel(block = null, trigger = null) {
    if (state.pending) return;
    saveDraft();
    state.active = block;
    state.edit = null;
    if (!state.open) {
      state.opener = trigger || document.activeElement;
      state.openerBlock = trigger ? block : null;
      state.tocScroll = rail?.scrollTop || 0;
    }
    state.open = true;
    render();
    place();
    load();
  }
  function close(after = null) {
    if (!state.open) return;
    const anchor = rail?.classList.contains('pagenest-panel-active') ? readingAnchor() : null;
    saveDraft();
    state.open = false;
    if (dialog.open) {
      dialog.close();
      lockPage(false);
    }
    if (slot) slot.hidden = true;
    if (rail) {
      rail.classList.remove('pagenest-panel-active');
      rail.style.removeProperty('--pagenest-pc-docked-height');
      rail.scrollTop = state.tocScroll;
    }
    restoreReadingAnchor(anchor);
    const target = state.opener?.isConnected
      ? state.opener
      : blocks.get(state.openerBlock)?.querySelector('.pagenest-paragraph-bubble') || opener;
    target?.focus({ preventScroll: true });
    updateBubbles();
    if (state.historyEntry) {
      state.historyEntry = false;
      state.historyReturning = true;
      state.afterClose = typeof after === 'function' ? after : null;
      history.back();
    } else if (typeof after === 'function') {
      if (state.historyReturning) state.afterClose = after;
      else after();
    }
  }
  addEventListener('popstate', () => {
    const returning = state.historyReturning;
    state.historyReturning = false;
    if (state.historyEntry) {
      state.historyEntry = false;
      close();
    }
    const after = state.afterClose;
    state.afterClose = null;
    after?.();
    if (returning && state.open) place();
  });
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    close();
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) close();
  });
  wide.addEventListener('change', place);
  addEventListener('resize', place);
  addEventListener(
    'scroll',
    () => {
      if (state.open && !dialog.open) place();
    },
    { passive: true },
  );
  document.addEventListener('pagenest-reading-layout', place);
  window.visualViewport?.addEventListener('resize', fitDialog);
  window.visualViewport?.addEventListener('scroll', fitDialog);
  addEventListener('focus', () => {
    if (state.open && !state.pending) load();
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && state.open && !state.pending) load();
  });
  document.addEventListener('pagenest-pc-auth-change', () => clearSession('登录状态已变化。'));
  addEventListener('pagehide', () => clearSession('', true));
  addEventListener('beforeunload', (event) => {
    saveDraft();
    if ([...state.drafts.values()].some((d) => d.text.trim())) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  updateBubbles();
  load();
})();
