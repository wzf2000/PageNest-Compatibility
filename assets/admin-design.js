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
        reveal(nav.querySelector('[aria-current="page"]'));
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
