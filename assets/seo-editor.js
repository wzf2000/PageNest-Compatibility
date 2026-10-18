(() => {
  'use strict';
  document.querySelectorAll('[data-pn-seo-editor]').forEach((root) => {
    const title = root.querySelector('[data-pn-seo-title]'),
      description = root.querySelector('[data-pn-seo-description]'),
      image = root.querySelector('[data-pn-seo-image]'),
      preview = root.querySelector('[data-pn-seo-preview-image]');
    const update = () => {
      root.querySelector('[data-pn-seo-preview-title]').textContent =
        title.value || title.placeholder;
      root.querySelector('[data-pn-seo-preview-description]').textContent =
        description.value || description.placeholder;
    };
    title.addEventListener('input', update);
    description.addEventListener('input', update);
    update();
    root.querySelector('[data-pn-seo-clear]').addEventListener('click', () => {
      image.value = '0';
      preview.removeAttribute('src');
      preview.hidden = true;
    });
    root.querySelector('[data-pn-seo-select]').addEventListener('click', () => {
      const frame = wp.media({
        title: window.PageNestSEOEditor.choose,
        library: { type: 'image' },
        multiple: false,
      });
      frame.on('select', () => {
        const selected = frame.state().get('selection').first().toJSON();
        if (
          !selected.id ||
          !(selected.type === 'image' || String(selected.mime || '').startsWith('image/'))
        )
          return;
        image.value = selected.id;
        preview.src = selected.sizes?.medium?.url || selected.url;
        preview.hidden = false;
      });
      frame.open();
    });
  });
})();
