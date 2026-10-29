(() => {
  'use strict';
  const root = document.querySelector('.pn-admin');
  if (!root) return;
  const nav = root.querySelector('.pn-admin-nav');
  const reveal = (item) => {
    if (!nav || !item) return;
    const bounds = nav.getBoundingClientRect(),
      current = item.getBoundingClientRect();
    if (current.left < bounds.left + 4) nav.scrollLeft += current.left - bounds.left - 4;
    else if (current.right > bounds.right - 4) nav.scrollLeft += current.right - bounds.right + 4;
  };
  if (nav) {
    const wrapper = document.createElement('div');
    wrapper.className = 'pn-admin-nav-wrap';
    nav.before(wrapper);
    wrapper.append(nav);
    const overflow = () => {
      const bounds = nav.getBoundingClientRect();
      const links = Array.from(nav.querySelectorAll('a'), (link) => link.getBoundingClientRect());
      wrapper.toggleAttribute(
        'data-overflow-left',
        links.some((link) => link.left < bounds.left - 1),
      );
      wrapper.toggleAttribute(
        'data-overflow-right',
        links.some((link) => link.right > bounds.right + 1),
      );
    };
    let pending;
    const revealCurrent = () => {
      cancelAnimationFrame(pending);
      pending = requestAnimationFrame(() => {
        const focused = document.activeElement?.closest('a');
        reveal(nav.contains(focused) ? focused : nav.querySelector('[aria-current="page"]'));
        overflow();
      });
    };
    nav.addEventListener('focusin', (event) => {
      reveal(event.target.closest('a'));
      overflow();
    });
    nav.addEventListener('scroll', overflow, { passive: true });
    window.addEventListener('pageshow', revealCurrent);
    if (typeof ResizeObserver === 'function') new ResizeObserver(revealCurrent).observe(nav);
    document.fonts?.ready.then(revealCurrent);
    revealCurrent();
  }
  root.querySelectorAll('form').forEach((form) => {
    if (form.method.toLowerCase() !== 'post' || form.matches('[data-pn-guide-form]')) return;
    const submit =
      form.querySelector(':scope > .submit, :scope > [data-pn-savebar]') ||
      form.querySelector('button[type="submit"], input[type="submit"]')?.closest('p');
    const status = document.createElement('span');
    status.className = 'pn-admin-state';
    status.role = 'status';
    status.hidden = true;
    status.textContent = window.PageNestAdmin?.unsaved || 'Unsaved changes.';
    if (submit) {
      submit.classList.add('pn-admin-savebar');
      submit.append(status);
    } else form.append(status);
    const value = (field) => {
      if (field.matches('input[type="checkbox"], input[type="radio"]')) return field.checked;
      if (field.matches('select'))
        return JSON.stringify(Array.from(field.selectedOptions, (option) => option.value));
      return field.value;
    };
    const fields = Array.from(form.elements).filter((field) =>
      field.matches('textarea, select, input:not([type="submit"]):not([type="button"])'),
    );
    const initial = new Map(fields.map((field) => [field, value(field)]));
    const changed = () => {
      status.hidden = !fields.some((field) => value(field) !== initial.get(field));
    };
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('reset', () => requestAnimationFrame(changed));
  });
  root.querySelectorAll('[data-pn-directory-row]').forEach((row) => {
    const picker = row.querySelector('[data-pn-directory-picker]');
    const input = row.querySelector('[data-pn-directory-id]');
    const preview = row.querySelector('[data-pn-directory-preview]');
    const update = () => {
      picker.value = input.value;
      const choice = picker.selectedOptions[0];
      preview.replaceChildren();
      if (input.value && choice?.value) {
        preview.append(`${window.PageNestAdmin?.directoryPending || 'Selected directory:'} `);
        const link = document.createElement('a');
        link.textContent = choice.textContent;
        link.href = choice.dataset.url;
        preview.append(link);
      } else {
        preview.textContent = input.value
          ? window.PageNestAdmin?.directoryCheck || 'This ID will be checked when saved.'
          : window.PageNestAdmin?.directoryUnlinked || 'No directory selected.';
      }
    };
    picker.addEventListener('change', () => {
      input.value = picker.value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('input', update);
  });
  root.querySelectorAll('[data-pn-series-form]').forEach((form) => {
    const list = form.querySelector('[data-pn-series-chapters]');
    const state = form.querySelector('[data-pn-series-state]');
    const changed = () => {
      const token = form.querySelector('[name="pn_series_preview"]');
      if (token) token.remove();
      const save = form.querySelector('[name="pn_series_action"][value="save"]');
      if (save) save.hidden = true;
      if (state)
        state.textContent = window.PageNestAdmin?.seriesChanged || 'Preview again before saving.';
    };
    form.addEventListener('input', changed);
    form.querySelectorAll('[data-pn-series-move]').forEach((button) => {
      button.hidden = false;
      button.addEventListener('click', () => {
        const row = button.closest('[data-pn-series-chapter]');
        const previous = Number(button.dataset.pnSeriesMove) < 0;
        const sibling = previous ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        if (previous) sibling.before(row);
        else sibling.after(row);
        Array.from(list.children).forEach((item, index) => {
          item.querySelector('input[type="number"]').value = index + 1;
          item.querySelector('.pn-series-position').textContent = `${index + 1}.`;
        });
        changed();
        form.dispatchEvent(new Event('input', { bubbles: true }));
        button.focus();
      });
    });
  });
  const result = root.querySelector('[data-pn-series-result]');
  if (result) {
    // Fragment navigation runs after footer scripts and can replace their focus.
    // Wait for the new document to be shown, while preserving any user-selected control.
    const focusResult = () =>
      requestAnimationFrame(() => {
        const active = document.activeElement;
        if (
          active === document.body ||
          active === document.documentElement ||
          active === root.querySelector('#pn-series-maintenance')
        )
          result.focus();
      });
    if (document.readyState === 'complete') focusResult();
    else window.addEventListener('pageshow', focusResult, { once: true });
  }
  const description = root.querySelector('#pagenest-description');
  if (description) {
    const count = root.querySelector('[data-pn-description-count]');
    const update = () => {
      if (count) count.textContent = `${description.value.length} / ${description.maxLength}`;
    };
    description.addEventListener('input', update);
    update();
  }
})();
