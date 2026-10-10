(() => {
  'use strict';
  const config = window.pagenestMediaLibrary;
  if (!config || !window.wp?.media) return;
  const labels = {
    folders: 'Folders',
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
  function render() {
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
    if (list.firstElementChild !== panel) list.prepend(panel);
  }
  function update() {
    current = String(props.get(config.taxonomy) || '');
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
