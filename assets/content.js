(() => {
  'use strict';
  let sequence = 0;
  // Static trusted drawings. Configuration can select a name, never SVG markup.
  const drawings = {
    dice: '<rect x="5" y="5" width="22" height="22" rx="5"/><circle cx="11" cy="11" r="1"/><circle cx="21" cy="11" r="1"/><circle cx="16" cy="16" r="1"/><circle cx="11" cy="21" r="1"/><circle cx="21" cy="21" r="1"/>',
    text: '<path d="M7 7h18M16 7v18M11 25h10M7 12V7m18 5V7"/>',
    dinosaur:
      '<path d="M5 25h6v-6l5-5V7h10v7h-6v5l-4 3v5m0-8h6M11 19l-6-5v7m11-11h5m-2 17h3"/><circle cx="23" cy="10" r=".7"/>',
    tiles:
      '<rect x="5" y="5" width="9" height="9" rx="2"/><rect x="18" y="5" width="9" height="9" rx="2"/><rect x="5" y="18" width="9" height="9" rx="2"/><rect x="18" y="18" width="9" height="9" rx="2"/>',
    fibonacci:
      '<rect x="5" y="5" width="22" height="22" rx="3"/><path d="M18 5v22M5 18h13m-5 0v9m0-4h5M7 24C7 15 13 8 25 8"/>',
    graduate: '<path d="m3 12 13-6 13 6-13 6-13-6Zm5 3v8c5 4 11 4 16 0v-8m5-3v10"/>',
    medal:
      '<circle cx="16" cy="21" r="8"/><path d="m9 14-4-10h8l3 9 3-9h8l-4 10m-7 3 1.2 2.5 2.8.4-2 2 .5 2.8-2.5-1.3-2.5 1.3.5-2.8-2-2 2.8-.4Z"/>',
    image:
      '<rect x="4" y="5" width="24" height="22" rx="4"/><circle cx="11" cy="12" r="2"/><path d="m5 23 7-7 5 5 4-4 7 6"/>',
    cloud:
      '<path d="M9 24H7a5 5 0 0 1-1-10 8 8 0 0 1 15-4 7 7 0 0 1 4 14h-2m-7 4V16m-4 4 4-4 4 4"/>',
    tool: '<rect x="5" y="5" width="22" height="22" rx="5"/><path d="M10 11h12m-12 5h12m-12 5h8"/>',
  };
  const badges = {
    calendar:
      '<rect x="19" y="19" width="12" height="12" rx="2"/><path d="M22 17v4m6-4v4m-9 3h12m-9 3h3"/>',
    trophy:
      '<path d="M21 19h9v4a4.5 4.5 0 0 1-9 0v-4Zm0 2h-2v2c0 2 1 3 3 3m8-5h2v2c0 2-1 3-3 3m-3 1v4m-3 0h6"/>',
    medal: '<circle cx="25" cy="26" r="5"/><path d="m22 21-2-5h4l1 5 2-5h4l-3 6"/>',
  };
  function icon(name) {
    const [base, badge] = name.split('-');
    const drawing = drawings[base] || drawings.tool;
    const wrapper = document.createElement('span');
    wrapper.className = 'pagenest-resource-icon';
    wrapper.setAttribute('aria-hidden', 'true');
    // All inserted markup comes exclusively from the static drawings above.
    wrapper.innerHTML =
      '<svg viewBox="0 0 36 36" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' +
      (badges[badge]
        ? '<g transform="translate(0 0) scale(.82)">' +
          drawing +
          '</g><g class="pagenest-resource-icon-badge">' +
          badges[badge] +
          '</g>'
        : '<g transform="translate(2 2)">' + drawing + '</g>') +
      '</svg>';
    return wrapper;
  }
  const label = (options, key, fallback, count) =>
    (options.labels[key] || fallback).replaceAll('{count}', String(count ?? ''));
  function make(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function sections(root, options) {
    return options.sections.flatMap((config, index) => {
      const heading = root.querySelector('[data-pagenest-section="' + index + '"]');
      const list = root.querySelector('[data-pagenest-list="' + index + '"]');
      return heading ? [{ heading, list, config, index }] : [];
    });
  }
  function reveal(root, items, update = () => {}) {
    let id;
    try {
      id = decodeURIComponent(location.hash.slice(1));
    } catch {
      return;
    }
    if (!id) return;
    const item = items.find((item) => item.heading.dataset.pagenestAnchor === id);
    const target = item?.heading || document.getElementById(id);
    if (!target || !root.contains(target)) return;
    if (item?.details) item.details.open = true;
    let parent = target.parentElement;
    while (parent && parent !== root) {
      if (parent.tagName === 'DETAILS') parent.open = true;
      parent = parent.parentElement;
    }
    update();
    requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
  }
  function fragments(root, items, update) {
    const run = () => reveal(root, items, update);
    window.addEventListener('hashchange', run);
    root.addEventListener('click', (event) => {
      const link = event.target.closest('a[href]');
      if (!link) return;
      const url = new URL(link.href, location.href);
      if (url.origin === location.origin && url.pathname === location.pathname && url.hash)
        setTimeout(run, 0);
    });
    run();
  }
  function guide(root, options) {
    const items = sections(root, options).filter((item) => item.list && !item.config.start_list);
    if (!items.length) return;
    const rootId = ++sequence;
    for (const item of items) {
      const details = make('details', 'pagenest-content-reading');
      details.id = 'pagenest-reading-' + rootId + '-' + item.index;
      details.open = item.config.default_open !== false;
      const count = [...item.list.children].filter((node) => node.tagName === 'LI').length;
      const summary = make('summary', '', label(options, 'list', '{count} reading entries', count));
      item.list.before(details);
      details.append(summary, item.list);
      item.details = details;
    }
    const controls = make('div', 'pagenest-content-controls');
    controls.setAttribute('role', 'group');
    controls.setAttribute('aria-label', label(options, 'controls', 'Reading view'));
    const overview = make('button', '', label(options, 'overview', 'Section overview'));
    const expand = make('button', '', label(options, 'expand', 'Expand reading lists'));
    const status = make('span', 'pagenest-content-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    for (const button of [overview, expand]) {
      button.type = 'button';
      button.setAttribute('aria-controls', items.map((item) => item.details.id).join(' '));
    }
    controls.append(overview, expand, status);
    items[0].heading.before(controls);
    function update() {
      const count = items.filter((item) => item.details.open).length;
      overview.setAttribute('aria-pressed', String(count === 0));
      expand.setAttribute('aria-pressed', String(count === items.length));
      status.textContent =
        count === 0
          ? label(options, 'closed', 'Lists are collapsed; expand a section to read.')
          : count === items.length
            ? label(options, 'opened', 'All reading lists are expanded.')
            : label(options, 'partial', 'Some reading lists are expanded.');
    }
    overview.addEventListener('click', () => {
      items.forEach((item) => (item.details.open = false));
      update();
    });
    expand.addEventListener('click', () => {
      items.forEach((item) => (item.details.open = true));
      update();
    });
    items.forEach((item) => item.details.addEventListener('toggle', update));
    update();
    fragments(root, items, update);
  }
  function resources(root, options) {
    const items = sections(root, options).filter((item) => item.list);
    if (!items.length) return;
    const searchText = new WeakMap();
    const cardsBySlug = new Map();
    const grouped = new Set();
    for (const item of items) {
      item.list.classList.add('pagenest-resource-grid');
      item.heading.dataset.category = item.index;
      item.list.dataset.category = item.index;
      item.cards = [...item.list.children].filter((el) => el.tagName === 'LI');
      for (const card of item.cards) {
        const original = card.cloneNode(true);
        original.querySelectorAll('[data-pagenest-related]').forEach((related) => related.remove());
        const category = item.config.label || item.heading.textContent;
        searchText.set(
          card,
          (original.textContent + ' ' + category).normalize('NFKC').toLocaleLowerCase(),
        );
        card.classList.add('pagenest-resource-card');
        const content = make('div', 'pagenest-resource-card-content');
        while (card.firstChild) content.append(card.firstChild);
        card.append(icon(card.dataset.resourceIcon || 'tool'), content);
        const link = content.querySelector('a');
        if (link) {
          link.classList.add('pagenest-resource-card-title');
          const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
          let afterTitle = false;
          while (walker.nextNode()) {
            const node = walker.currentNode;
            if (link.contains(node)) {
              afterTitle = true;
              continue;
            }
            if (afterTitle && node.textContent.trim()) {
              node.textContent = node.textContent.replace(/^\s*—\s*/, '');
              break;
            }
          }
        }
        if (card.dataset.resourceSlug) cardsBySlug.set(card.dataset.resourceSlug, card);
      }
    }
    for (const item of items) {
      for (const card of item.cards) {
        const parent = cardsBySlug.get(card.dataset.resourceParent);
        const related = parent?.querySelector('[data-pagenest-related]');
        if (!related?.querySelector('a')) continue;
        related.hidden = false;
        related.setAttribute('aria-label', label(options, 'related', 'Related entries'));
        grouped.add(card);
      }
    }
    const ui = make('div', 'pagenest-resource-controls');
    const searchLabel = make('label', '', label(options, 'search', 'Search resources'));
    const input = make('input');
    input.type = 'search';
    input.placeholder = label(options, 'placeholder', 'Name or keyword');
    input.autocomplete = 'off';
    searchLabel.append(input);
    const categoryLabel = make('label', '', label(options, 'category', 'Resource category'));
    const select = make('select');
    const all = make('option', '', label(options, 'all', 'All categories'));
    all.value = '';
    select.append(all);
    categoryLabel.append(select);
    const reset = make('button', '', label(options, 'reset', 'Reset filters'));
    reset.type = 'button';
    const count = make('p', 'pagenest-resource-count');
    count.setAttribute('role', 'status');
    count.setAttribute('aria-live', 'polite');
    ui.append(searchLabel, categoryLabel, reset, count);
    for (const item of items) {
      const option = make('option', '', item.config.label || item.heading.textContent);
      option.value = String(item.index);
      select.append(option);
    }
    const empty = make(
      'p',
      'pagenest-resource-empty',
      label(options, 'empty', 'No matching resources. Try another keyword.'),
    );
    empty.hidden = true;
    const toggle = grouped.size ? make('button', 'pagenest-resource-toggle') : null;
    if (toggle) {
      toggle.type = 'button';
      const listIds = [];
      for (const item of items) {
        if (!item.list.id) item.list.id = 'pagenest-resources-' + ++sequence;
        listIds.push(item.list.id);
      }
      toggle.setAttribute('aria-controls', listIds.join(' '));
      ui.append(toggle);
    }
    items[0].heading.before(ui, empty);
    let expanded = false;
    function filter() {
      let total = 0;
      let visible = 0;
      const words = input.value
        .trim()
        .normalize('NFKC')
        .toLocaleLowerCase()
        .split(/\s+/)
        .filter(Boolean);
      for (const item of items) {
        let shown = 0;
        for (const card of item.cards) {
          const match =
            (!select.value || select.value === String(item.index)) &&
            words.every((word) => searchText.get(card).includes(word));
          const show =
            match && (!grouped.has(card) || expanded || words.length || select.value !== '');
          card.hidden = !show;
          if (match) total++;
          if (show) shown++;
        }
        item.heading.hidden = item.list.hidden = shown === 0;
        visible += shown;
      }
      const automatic = words.length > 0 || select.value !== '';
      if (toggle) {
        toggle.disabled = automatic;
        toggle.setAttribute('aria-expanded', String(expanded || automatic));
        toggle.textContent = automatic
          ? label(options, 'matched_related', 'Matching related entries are visible')
          : expanded
            ? label(options, 'hide_related', 'Collapse related entries')
            : label(options, 'show_related', 'Show all related entries');
      }
      count.textContent = automatic
        ? label(options, 'filtered_count', '{count} matching entries', total)
        : label(options, 'count', '{count} entries', total);
      if (!automatic && !expanded && grouped.size)
        count.textContent +=
          ' · ' + label(options, 'group_hint', 'Related entries appear in their parent cards');
      empty.hidden = visible !== 0;
    }
    toggle?.addEventListener('click', () => {
      expanded = !expanded;
      filter();
    });
    input.addEventListener('input', filter);
    select.addEventListener('change', filter);
    reset.addEventListener('click', () => {
      input.value = '';
      select.value = '';
      expanded = false;
      filter();
      input.focus();
    });
    filter();
    function follow() {
      let id;
      try {
        id = decodeURIComponent(location.hash.slice(1));
      } catch {
        return;
      }
      const item = items.find((item) => item.heading.dataset.pagenestAnchor === id);
      if (!item) return;
      input.value = '';
      select.value = '';
      expanded = item.cards.some((card) => grouped.has(card));
      filter();
      reveal(root, items);
    }
    window.addEventListener('hashchange', follow);
    root.addEventListener('click', (event) => {
      const link = event.target.closest('a[href]');
      if (link?.hash && link.origin === location.origin && link.pathname === location.pathname)
        setTimeout(follow, 0);
    });
    follow();
  }
  function run() {
    document.querySelectorAll('[data-pagenest-content]').forEach((root) => {
      if (root.dataset.pagenestEnhanced) return;
      let options;
      try {
        options = JSON.parse(root.dataset.pagenestOptions);
      } catch {
        return;
      }
      root.dataset.pagenestEnhanced = '1';
      if (root.dataset.pagenestContent === 'guide') guide(root, options);
      if (root.dataset.pagenestContent === 'resources') resources(root, options);
    });
  }
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', run, { once: true });
  else run();
})();
