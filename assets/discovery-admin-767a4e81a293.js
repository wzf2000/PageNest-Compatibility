(() => {
  'use strict';
  const select = document.getElementById('pagenest-select-share-image');
  if (!select || !window.wp?.media) return;
  const field = document.getElementById('pagenest-share-image');
  const status = document.getElementById('pagenest-share-image-status');
  let frame;
  select.addEventListener('click', () => {
    if (!frame) {
      frame = wp.media({
        title: '选择默认分享图片',
        button: { text: '使用这张图片' },
        library: { type: 'image' },
        multiple: false,
      });
      frame.on('select', () => {
        const image = frame.state().get('selection').first().toJSON();
        field.value = image.id;
        status.textContent = `已选择：${image.title || image.filename}`;
      });
    }
    frame.open();
  });
  document.getElementById('pagenest-clear-share-image').addEventListener('click', () => {
    field.value = 0;
    status.textContent = '使用站点 Logo';
  });
})();
