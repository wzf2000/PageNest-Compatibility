(() => {
  'use strict';
  const config = window.pagenestMediaLibrary;
  if (!config || !window.wp?.media) return;
  const labels = {
    folders: 'Folders',
    searchFolders: 'Find folder…',
    allFiles: 'All files',
    manageFolders: 'Manage folders',
    noFolders: 'No matching folders',
    library: 'Media Library',
    parentFolder: 'Parent folder',
    refreshFolders: 'Refresh folders',
    noSubfolders: 'No subfolders',
    unassigned: 'Unassigned',
    foldersUnavailable: 'Unable to refresh folders',
    ...config.labels,
  };
  let terms = config.terms;
  let props;
  let current = '';
  let observer;
  const expanded = new Set();
  const sidebar = document.createElement('aside');
  sidebar.className = 'pagenest-folder-sidebar';
  sidebar.setAttribute('aria-label', labels.folders);
  const heading = document.createElement('h2');
  heading.textContent = labels.folders;
  const search = document.createElement('input');
  search.type = 'search';
  search.placeholder = labels.searchFolders;
  search.setAttribute('aria-label', labels.searchFolders);
  const tree = document.createElement('div');
  tree.className = 'pagenest-folder-tree';
  sidebar.append(heading, search, tree);
  if (config.manageUrl) {
    const manage = document.createElement('a');
    manage.href = config.manageUrl;
    manage.textContent = labels.manageFolders;
    manage.className = 'button';
    sidebar.append(manage);
  }
  search.addEventListener('input', renderTree);
  const panel = document.createElement('li');
  panel.className = 'pagenest-media-folders';
  const section = document.createElement('section');
  section.setAttribute('aria-label', labels.folders);
  panel.append(section);
  let status = '';
  let pending = false;
  const button = (name, action, className = '') => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = className;
    b.textContent = name;
    b.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      action();
    });
    return b;
  };
  const navigate = (slug) => {
    // Only change the provider's taxonomy filter: search, media type and sort survive.
    props.set(config.taxonomy, slug);
    section.querySelector('[aria-current]')?.focus();
  };
  function renderTree() {
    tree.replaceChildren();
    const select = (name, slug) => {
      const link = button(
        name,
        () => {
          props.set(config.taxonomy, slug);
          [...tree.querySelectorAll('[data-folder]')]
            .find((node) => node.dataset.folder === slug)
            ?.focus();
        },
        'pagenest-folder-select',
      );
      link.dataset.folder = slug;
      if (current === slug) link.setAttribute('aria-current', 'true');
      return link;
    };
    tree.append(select(labels.allFiles, ''), select(labels.unassigned, '-1'));
    const query = search.value.trim().toLocaleLowerCase();
    const byId = new Map(terms.map((term) => [term.id, term]));
    const visible = new Set();
    for (const term of terms) {
      if (!term.name.toLocaleLowerCase().includes(query)) continue;
      let ancestor = term;
      const seen = new Set();
      while (ancestor && !seen.has(ancestor.id)) {
        seen.add(ancestor.id);
        visible.add(ancestor.id);
        ancestor = byId.get(ancestor.parent);
      }
    }
    const visited = new Set();
    function branch(items, depth) {
      const list = document.createElement('ul');
      for (const item of items) {
        if (visited.has(item.id) || !visible.has(item.id)) continue;
        visited.add(item.id);
        const row = document.createElement('li');
        const line = document.createElement('div');
        line.className = 'pagenest-folder-row';
        line.style.paddingInlineStart = `${Math.min(depth, 8) * 12}px`;
        const children = terms.filter(
          (child) => child.parent === item.id && !visited.has(child.id),
        );
        const open = Boolean(query) || expanded.has(item.id);
        if (children.length) {
          const toggle = button(
            open ? '▾' : '▸',
            () => {
              if (expanded.has(item.id)) expanded.delete(item.id);
              else expanded.add(item.id);
              renderTree();
              tree.querySelector(`[data-toggle="${item.id}"]`)?.focus();
            },
            'pagenest-folder-toggle',
          );
          toggle.dataset.toggle = item.id;
          toggle.setAttribute('aria-label', item.name);
          toggle.setAttribute('aria-expanded', String(open));
          line.append(toggle);
        } else {
          const spacer = document.createElement('span');
          spacer.className = 'pagenest-folder-toggle';
          line.append(spacer);
        }
        const link = select(item.name, item.slug);
        const icon = document.createElement('span');
        icon.className = 'dashicons dashicons-category';
        icon.setAttribute('aria-hidden', 'true');
        link.prepend(icon);
        if (Number.isInteger(item.count)) {
          const count = document.createElement('span');
          count.className = 'pagenest-folder-count';
          count.textContent = item.count;
          link.append(count);
        }
        line.append(link);
        row.append(line);
        if (children.length && open) row.append(branch(children, depth + 1));
        list.append(row);
      }
      return list;
    }
    tree.append(
      branch(
        terms.filter((term) => !term.parent || !byId.has(term.parent)),
        0,
      ),
    );
    if (query && !visible.size) {
      const empty = document.createElement('p');
      empty.textContent = labels.noFolders;
      empty.setAttribute('role', 'status');
      tree.append(empty);
    }
  }
  function render() {
    renderTree();
    const active = terms.find((term) => term.slug === current);
    const ancestry = [];
    const seen = new Set();
    let term = active;
    while (term && !seen.has(term.id)) {
      ancestry.unshift(term);
      seen.add(term.id);
      term = terms.find((item) => item.id === term.parent);
    }
    section.replaceChildren();
    const header = document.createElement('div');
    header.className = 'pagenest-folder-heading';
    const nav = document.createElement('nav');
    nav.setAttribute('aria-label', labels.folders);
    const root = button(labels.library, () => navigate(''));
    if (!current) root.setAttribute('aria-current', 'location');
    nav.append(root);
    ancestry.forEach((item) => {
      const separator = document.createElement('span');
      separator.textContent = '/';
      separator.setAttribute('aria-hidden', 'true');
      const link = button(item.name, () => navigate(item.slug));
      if (item === active) link.setAttribute('aria-current', 'location');
      nav.append(separator, link);
    });
    if (current && !active) {
      const text = document.createElement('span');
      text.textContent = labels.unassigned;
      nav.append(text);
    }
    header.append(nav);
    const refresh = button(
      labels.refreshFolders,
      async () => {
        if (pending) return;
        pending = true;
        refresh.disabled = true;
        status = '';
        try {
          terms = await wp.ajax.send('pagenest_media_folders', { data: { nonce: config.nonce } });
        } catch {
          status = labels.foldersUnavailable;
        } finally {
          pending = false;
          render();
        }
      },
      'pagenest-folder-refresh',
    );
    refresh.disabled = pending;
    header.append(refresh);
    section.append(header);
    const grid = document.createElement('div');
    grid.className = 'pagenest-folder-grid';
    if (active) {
      const parent = terms.find((item) => item.id === active.parent);
      grid.append(
        button(
          '↰ ' + labels.parentFolder,
          () => navigate(parent?.slug || ''),
          'pagenest-folder-card pagenest-folder-parent',
        ),
      );
    }
    const children =
      current && !active ? [] : terms.filter((item) => item.parent === (active?.id || 0));
    children.forEach((item) => {
      const card = button(item.name, () => navigate(item.slug), 'pagenest-folder-card');
      const icon = document.createElement('span');
      icon.className = 'dashicons dashicons-category';
      icon.setAttribute('aria-hidden', 'true');
      card.prepend(icon);
      grid.append(card);
    });
    section.append(grid);
    const message = document.createElement('p');
    message.className = 'pagenest-folder-status';
    message.setAttribute('role', 'status');
    message.textContent = status || (!children.length ? labels.noSubfolders : '');
    section.append(message);
  }
  function attach() {
    const frame = wp.media.frame;
    const library = frame?.state()?.get('library');
    const list = document.querySelector('body.upload-php .media-frame-content .attachments');
    if (!library?.props || !list) return;
    if (props !== library.props) {
      if (props) props.off('change:' + config.taxonomy, update);
      props = library.props;
      props.on('change:' + config.taxonomy, update);
      update();
    }
    const host = document.querySelector('body.upload-php .media-frame');
    if (host) {
      host.classList.add('pagenest-has-folder-sidebar');
      if (sidebar.parentElement !== host) host.prepend(sidebar);
    }
    if (list.firstElementChild !== panel) list.prepend(panel);
  }
  function update() {
    current = String(props.get(config.taxonomy) || '');
    let active = terms.find((term) => term.slug === current);
    const seen = new Set();
    while (active && !seen.has(active.id)) {
      seen.add(active.id);
      expanded.add(active.parent);
      active = terms.find((term) => term.id === active.parent);
    }
    render();
  }
  observer = new MutationObserver(attach);
  observer.observe(document.querySelector('#wpbody-content') || document.body, {
    childList: true,
    subtree: true,
  });
  attach();
  window.addEventListener(
    'pagehide',
    () => {
      observer.disconnect();
      if (props) props.off('change:' + config.taxonomy, update);
    },
    { once: true },
  );
})();
