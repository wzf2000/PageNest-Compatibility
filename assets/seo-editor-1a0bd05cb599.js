(() => {
  'use strict';
  document.querySelectorAll('[data-pn-seo-editor]').forEach((root) => {
    const title = root.querySelector('[data-pn-seo-title]'),
      description = root.querySelector('[data-pn-seo-description]'),
      image = root.querySelector('[data-pn-seo-image]'),
      preview = root.querySelector('[data-pn-seo-preview-image]');
    const missingDescription = root.querySelector('[data-pn-seo-missing-description]'),
      missingImage = root.querySelector('[data-pn-seo-missing-image]'),
      imageStatus = root.querySelector('[data-pn-seo-image-status]'),
      inheritedDescription = root.querySelector('[data-pn-seo-inherited-description]'),
      inheritedImage = root.querySelector('[data-pn-seo-inherited-image]'),
      privatePreview = root.querySelector('[data-pn-seo-private-preview]');
    let requestNumber = 0;
    const update = () => {
      root.querySelector('[data-pn-seo-preview-title]').textContent =
        title.value || title.placeholder;
      root.querySelector('[data-pn-seo-preview-description]').textContent =
        description.value || description.placeholder;
      if (missingDescription)
        missingDescription.hidden = Boolean((description.value || description.placeholder).trim());
      if (inheritedDescription)
        inheritedDescription.hidden =
          Boolean(description.value.trim()) || root.dataset.pnSeoSiteDescription !== '1';
    };
    title.addEventListener('input', update);
    description.addEventListener('input', update);
    update();
    root.querySelector('[data-pn-seo-clear]').addEventListener('click', () => {
      image.value = '0';
      const fallback = preview.dataset.pnSeoDefaultImage || '';
      if (fallback) preview.src = fallback;
      else preview.removeAttribute('src');
      preview.hidden = !fallback;
      requestNumber++;
      if (imageStatus) imageStatus.textContent = '';
      if (missingImage) missingImage.hidden = preview.dataset.pnSeoDefaultPublic === '1';
      if (privatePreview) privatePreview.hidden = preview.dataset.pnSeoDefaultPrivate !== '1';
      if (inheritedImage) inheritedImage.hidden = preview.dataset.pnSeoDefaultInherited !== '1';
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
        if (!root.dataset.pnSeoPost || !window.PageNestSEOEditor.url) return;
        const currentRequest = ++requestNumber;
        if (imageStatus) imageStatus.textContent = window.PageNestSEOEditor.checking;
        fetch(window.PageNestSEOEditor.url, {
          method: 'POST',
          credentials: 'same-origin',
          body: new URLSearchParams({
            action: 'pagenest_seo_preview_image',
            post: root.dataset.pnSeoPost,
            nonce: root.dataset.pnSeoNonce,
            image: String(selected.id),
          }),
        })
          .then((response) => {
            if (!response.ok) throw new Error('preview');
            return response.json();
          })
          .then((response) => {
            if (currentRequest !== requestNumber) return;
            if (!response.success || typeof response.data?.image !== 'string')
              throw new Error('preview');
            const url = response.data.image;
            if (url) preview.src = url;
            else preview.removeAttribute('src');
            preview.hidden = !url;
            if (missingImage) missingImage.hidden = Boolean(response.data.public_image);
            if (privatePreview) privatePreview.hidden = !response.data.private_preview;
            if (inheritedImage) inheritedImage.hidden = !response.data.inherited;
            if (imageStatus) imageStatus.textContent = '';
          })
          .catch(() => {
            if (currentRequest !== requestNumber) return;
            if (imageStatus) imageStatus.textContent = window.PageNestSEOEditor.failed;
            if (missingImage) missingImage.hidden = true;
            if (privatePreview) privatePreview.hidden = true;
            if (inheritedImage) inheritedImage.hidden = true;
          });
      });
      frame.open();
    });
  });
})();
