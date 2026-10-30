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
    braces: '<path d="M12 5H9v8l-4 3 4 3v8h3m8-22h3v8l4 3-4 3v8h-3M14 12l4 8m0-8-4 8"/>',
    clock: '<circle cx="16" cy="16" r="12"/><path d="M16 8v8l6 4M6 5l-2 3m22-3 2 3"/>',
    mine: '<circle cx="16" cy="16" r="7"/><path d="M16 3v4m0 18v4M3 16h4m18 0h4M7 7l3 3m12 12 3 3M7 25l3-3M22 10l3-3"/><circle cx="14" cy="13" r="1"/>',
    puzzle:
      '<rect x="4" y="4" width="24" height="24" rx="3"/><path d="M12 4v24m8-24v16M4 12h24M4 20h16m3 4h2M7 8h2m6 0h2m6 0h2M7 16h2m6 0h2m6 0h2M7 24h2m6 0h2"/>',
    encoding:
      '<path d="m10 5-6 5 6 5m12 2 6 5-6 5M15 8h11m-4-4 4 4-4 4M17 24H6m4-4-4 4 4 4M18 13l-4 6"/>',
    qr: '<path d="M4 4h9v9H4Zm15 0h9v9h-9ZM4 19h9v9H4ZM19 19h4v4h5v5h-9v-5m9-6v2M8 8h1m14 0h1M8 23h1"/>',
    sudoku:
      '<rect x="4" y="4" width="24" height="24" rx="2"/><path d="M12 4v24m8-24v24M4 12h24M4 20h24m-5-12h2M7 16h2m6 8h2m-2-8h2"/>',
    snake:
      '<path d="M5 25h17a5 5 0 0 0 0-10H11a5 5 0 0 1 0-10h10v7h-5m-7 8H5"/><circle cx="24" cy="7" r="1"/><path d="m28 6 2-1m-2 3h2"/>',
    tiers:
      '<rect x="5" y="5" width="9" height="5" rx="1"/><rect x="5" y="14" width="16" height="5" rx="1"/><rect x="5" y="23" width="23" height="5" rx="1"/>',
    heart:
      '<path d="M4 5h6v7H4m0 8h6v7H4m6-19h5v16h-5m5-8h5m0-2c0-4 4-5 6-2 3-3 7 0 4 4l-5 5-5-5Z"/>',
    shield:
      '<path d="M16 4c-4 4-8 4-11 4v10c0 5 5 8 11 11 6-3 11-6 11-11v-5M5 28 26 7l3-4-5 1L4 25m5-4 5 5"/>',
    evidence:
      '<path d="M18 4H6v24h19V16M10 10h6m-6 6h4m-4 6h9"/><circle cx="23" cy="9" r="5"/><path d="m27 13 3 3"/>',
    cards:
      '<path d="m9 5 17 3-3 20-17-3Z"/><rect x="4" y="9" width="18" height="19" rx="2"/><path d="M8 14h10m-10 5h7m-7 5h5"/>',
    flasks:
      '<path d="M4 4h8M6 4v9l-4 11c-1 3 1 4 3 4h7c3 0 4-2 3-4l-5-11V4m10 0h8m-6 0v9l-5 11c-1 3 1 4 3 4h7c2 0 4-2 3-4l-4-11V4M4 19h9m7 0h8"/>',
    bug: '<rect x="10" y="10" width="12" height="17" rx="6"/><path d="M13 10V6h6v4m-9 6H5m17 0h5M10 22l-5 4m17-4 5 4M10 12 5 8m17 4 5-4m-11 8v10"/>',
    route:
      '<circle cx="7" cy="25" r="3"/><circle cx="24" cy="7" r="3"/><path d="M10 25h10a5 5 0 0 0 0-10h-8a4 4 0 0 1 0-8h9m-4-3 4 3-4 3"/>',
    book: '<path d="M16 8C12 5 7 4 3 5v21c5-1 9 0 13 3 4-3 8-4 13-3V5c-4-1-9 0-13 3v21M7 11h5m-5 5h5m8-5h5m-5 5h5"/>',
    sliders:
      '<path d="M5 8h22M5 16h22M5 24h22"/><rect x="10" y="5" width="4" height="6" rx="1"/><rect x="20" y="13" width="4" height="6" rx="1"/><rect x="7" y="21" width="4" height="6" rx="1"/>',
    crop: '<path d="M8 3v21h21M3 8h21v21M12 12h8v8"/>',
    diff: '<path d="M4 5h10v22H4Zm14 0h10v22H18ZM7 11h4m-4 6h4m10-6h4m-2-2v4m-2 8h4"/>',
    nonogram:
      '<rect x="10" y="10" width="18" height="18" rx="1"/><path d="M16 10v18m6-18v18M10 16h18M10 22h18M5 12v2m0 4v2m0 4v2M12 5h2m4 0h2m4 0h2m-14 8 2 2m4 4 2 2m4-8 2 2m-14 10 2 2"/>',
    bulb: '<path d="M11 22c0-5-5-6-5-12a10 10 0 0 1 20 0c0 6-5 7-5 12ZM11 26h10m-8 4h6m-3-9v-7m-4-2 4 3 4-3"/>',
    markdown:
      '<rect x="3" y="6" width="26" height="21" rx="3"/><path d="M7 22V12l4 5 4-5v10m5-6 3 4 3-4m-3-5v9"/>',
    table:
      '<rect x="4" y="5" width="24" height="22" rx="2"/><path d="M4 12h24M4 20h24M12 12v15m8-15v15"/>',
    mask: '<path d="M3 11c5 2 8-5 13 1 5-6 8 1 13-1-1 10-5 14-13 10C8 25 4 21 3 11Z"/><path d="M7 15c2-2 4-2 6 0-2 3-4 3-6 0Zm12 0c2-2 4-2 6 0-2 3-4 3-6 0Z"/>',
    moon: '<path d="M19 3a13 13 0 1 0 10 21A13 13 0 0 1 19 3Z"/><path d="m18 22 1-7 3-3 1 5 4 3-1 4-4-1-2 2Z"/>',
    wolf: '<path d="m6 21 4-7 1-9 7 8 5 2 4 5-3 4-7-1-1 6-5-3-4 1-1-4-3-1Z"/><circle cx="18" cy="17" r=".7"/><path d="M26 3a4 4 0 1 0 3 6 4 4 0 0 1-3-6Z"/>',
    broom: '<path d="m24 3-9 14m-3-2 8 5-5 9-12-8 6-8Zm-5 5-2 5m6-2-2 5m6-2-2 5"/>',
    compare:
      '<path d="M3 5h10v22H3Zm16 0h10v22H19ZM3 12h10m6 0h10M3 20h10m6 0h10m-9-4h6m-4-2-2 2 2 2m2-4 2 2-2 2"/>',
    merge:
      '<path d="M4 4h9v8H4Zm15 0h9v8h-9ZM4 8h9m6 0h9M8 12v5h16v-5m-8 5v5m-3-3 3 3 3-3M8 24h16v5H8Z"/>',
    pivot:
      '<path d="M4 5h14v12H4Zm0 6h14M11 5v12m11-9h6v18H10v-5m12 5V14m-7 12v-5m12-11 1-2-2-1"/>',
    database:
      '<ellipse cx="16" cy="7" rx="11" ry="4"/><path d="M5 7v18c0 6 22 6 22 0V7M5 16c0 6 22 6 22 0m-14 6 3 2 3-2"/>',
    sprout:
      '<path d="M16 28V15M16 20C6 20 4 14 4 8c8 0 12 4 12 12Zm0-5C16 7 21 4 28 4c0 7-4 11-12 11ZM8 28h16"/>',
    constellation:
      '<path d="m6 24 9-17 11 9-8 11-12-3"/><circle cx="6" cy="24" r="2"/><circle cx="15" cy="7" r="2"/><circle cx="26" cy="16" r="2"/><circle cx="18" cy="27" r="2"/>',
    sand: '<path d="M10 20l2 9h12l2-9M13 25h10M4 11l9 4m9-5 6-3M12 3v2m3 3v2m2 5v2m3 1v3"/><circle cx="15" cy="4" r=".6"/><circle cx="18" cy="12" r=".6"/>',
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
      item.introductions = [];
      for (
        let node = item.heading.nextElementSibling;
        node && node !== item.list;
        node = node.nextElementSibling
      ) {
        if (node.tagName === 'P') item.introductions.push({ node, hidden: node.hidden });
      }
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
        for (const introduction of item.introductions)
          introduction.node.hidden = introduction.hidden || shown === 0;
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
