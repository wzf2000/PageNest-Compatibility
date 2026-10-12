(() => {
  'use strict';
  const root = document.querySelector('.pn-admin');
  if (!root) return;
  root.querySelectorAll('form').forEach((form) => {
    const status = document.createElement('p');
    status.className = 'pn-admin-state';
    status.role = 'status';
    status.hidden = true;
    status.textContent = '有未保存的更改';
    form.prepend(status);
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
