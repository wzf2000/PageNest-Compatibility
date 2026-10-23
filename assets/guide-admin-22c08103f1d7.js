(() => {
  'use strict';
  const cfg = window.PageNestGuide,
    form = document.querySelector('[data-pn-guide-form]');
  if (!cfg || !form) return;
  const t = cfg.text,
    groups = form.querySelector('[data-pn-guide-groups]'),
    target = form.querySelector('[data-pn-guide-target]'),
    status = form.querySelector('[data-pn-guide-status]'),
    dirtyStatus = form.querySelector('[data-pn-guide-dirty]'),
    catalog = form.querySelector('[data-pn-guide-catalog]'),
    more = form.querySelector('[data-pn-guide-more]');
  let dirty = false,
    page = 1,
    nextPage = null,
    generation = 0,
    controller;
  const el = (tag, text) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (text, action) => {
    const node = el('button', text);
    node.type = 'button';
    node.className = 'button';
    node.addEventListener('click', action);
    return node;
  };
  function changed() {
    dirty = true;
    if (dirtyStatus) dirtyStatus.textContent = t.unsaved;
    renumber();
  }
  function renumber() {
    const chosen = Number(target.value);
    target.replaceChildren();
    [...groups.children].forEach((group, index) => {
      group.querySelector('[data-pn-group-title]').name =
        `pagenest_companion_guide[groups][${index}][title]`;
      const option = el('option', group.querySelector('input').value || `${t.group} ${index + 1}`);
      option.value = index;
      target.append(option);
      [...group.querySelector('[data-pn-guide-items]').children].forEach((item, j) => {
        item.querySelector('input').name =
          `pagenest_companion_guide[groups][${index}][items][${j}]`;
      });
      const actions = group.querySelector('[data-pn-group-actions]');
      actions.children[0].disabled = index === 0;
      actions.children[1].disabled = index === groups.children.length - 1;
      const items = group.querySelector('[data-pn-guide-items]');
      group.querySelector('[data-pn-group-empty]').hidden = items.children.length > 0;
      [...items.children].forEach((item, j) => {
        const actions = item.querySelector('[data-pn-item-actions]');
        actions.children[0].disabled = j === 0;
        actions.children[1].disabled = j === items.children.length - 1;
      });
    });
    target.disabled = groups.children.length === 0;
    if (target.disabled) {
      const placeholder = el('option', t.addGroupFirst);
      placeholder.value = '';
      target.append(placeholder);
    } else target.value = Math.min(chosen, target.options.length - 1);
    catalog.querySelectorAll('[data-pn-catalog-add]').forEach((control) => {
      control.disabled = target.disabled;
    });
  }
  function actions(container, item) {
    const move = (direction) => {
      const neighbor = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
      if (!neighbor) return;
      if (direction < 0) item.parentNode.insertBefore(item, neighbor);
      else item.parentNode.insertBefore(neighbor, item);
      changed();
      // The control may become disabled at a boundary; focus a usable control in the moved row.
      [...container.children].find((control) => !control.disabled)?.focus();
    };
    container.append(
      button(t.up, () => move(-1)),
      button(t.down, () => move(1)),
      button(t.remove, () => {
        const parent = item.parentElement;
        item.remove();
        changed();
        parent.querySelector('input,button')?.focus();
      }),
    );
  }
  function setup(group) {
    const empty = el('p', t.emptyGroup);
    empty.className = 'pn-admin-empty';
    empty.dataset.pnGroupEmpty = '';
    group.append(empty);
    actions(group.querySelector('[data-pn-group-actions]'), group);
    group
      .querySelectorAll('[data-pn-guide-item]')
      .forEach((item) => actions(item.querySelector('[data-pn-item-actions]'), item));
  }
  [...groups.children].forEach(setup);
  renumber();
  form.querySelector('[data-pn-add-group]').addEventListener('click', () => {
    if (groups.children.length >= 20) {
      status.textContent = t.limit;
      return;
    }
    const group = el('fieldset');
    group.dataset.pnGuideGroup = '';
    group.append(el('legend', t.group));
    const label = el('label', t.title),
      title = el('input');
    title.type = 'text';
    title.dataset.pnGroupTitle = '';
    title.maxLength = 100;
    label.append(title);
    const controls = el('div');
    controls.dataset.pnGroupActions = '';
    const items = el('ol');
    items.dataset.pnGuideItems = '';
    group.append(label, controls, items);
    groups.append(group);
    setup(group);
    changed();
    target.value = groups.children.length - 1;
    title.focus();
  });
  async function search(append = false) {
    controller?.abort();
    controller = new AbortController();
    const turn = ++generation;
    if (!append) {
      page = 1;
      catalog.replaceChildren();
    } else page = nextPage;
    status.textContent = t.loading;
    more.hidden = true;
    try {
      const url = new URL(cfg.api, location.href);
      if (url.origin !== location.origin) throw Error();
      url.searchParams.set('q', form.querySelector('[data-pn-guide-query]').value);
      url.searchParams.set('page', page);
      const response = await fetch(url, {
        signal: controller.signal,
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': cfg.nonce },
      });
      if (!response.ok) throw Error();
      const data = await response.json();
      if (turn !== generation) return;
      data.items.forEach((item) => {
        const li = el('li', item.title + ' '),
          preview = el('a', t.preview),
          url = new URL(item.url, location.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return;
        preview.href = url.href;
        preview.target = '_blank';
        preview.rel = 'noopener';
        const add = button(t.add, () => {
          const group = groups.children[Number(target.value)],
            count = groups.querySelectorAll('[data-pn-guide-item]').length;
          if (!group || count >= 200) {
            status.textContent = t.limit;
            return;
          }
          const items = group.querySelector('[data-pn-guide-items]');
          if ([...items.querySelectorAll('input')].some((input) => Number(input.value) === item.id))
            return;
          const row = el('li'),
            input = el('input'),
            text = el('span', item.title + ' '),
            actionsBox = el('div');
          row.dataset.pnGuideItem = '';
          input.type = 'hidden';
          input.value = item.id;
          actionsBox.dataset.pnItemActions = '';
          row.append(input, text, preview.cloneNode(true), actionsBox);
          items.append(row);
          actions(actionsBox, row);
          changed();
        });
        add.dataset.pnCatalogAdd = '';
        add.disabled = target.disabled;
        li.append(preview, document.createTextNode(' '), add);
        catalog.append(li);
      });
      nextPage = data.next_page;
      more.hidden = nextPage === null;
      status.textContent = catalog.childElementCount ? '' : t.empty;
    } catch {
      if (turn === generation) status.textContent = t.error;
    }
  }
  form.querySelector('[data-pn-guide-search]').addEventListener('click', () => search());
  form.querySelector('[data-pn-guide-query]').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.isComposing) {
      event.preventDefault();
      search();
    }
  });
  more.addEventListener('click', () => search(true));
  form.addEventListener('input', (event) => {
    if (event.target.name) changed();
  });
  form.addEventListener('submit', () => {
    dirty = false;
  });
  addEventListener('beforeunload', (event) => {
    if (dirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
