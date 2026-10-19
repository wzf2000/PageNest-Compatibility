(() => {
  'use strict';
  const t = window.PageNestNativeMedia?.text;
  if (!t) return;
  const { createElement: el, Fragment } = wp.element;
  const { registerBlockType, getBlockType } = wp.blocks;
  const { Button, TextControl, SelectControl, ToggleControl, Notice } = wp.components;
  const { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } = wp.blockEditor;
  const Preview = wp.serverSideRender.default || wp.serverSideRender;
  for (const pdf of [false, true]) {
    const name = pdf ? 'pagenest/pdf' : 'pagenest/gallery';
    if (getBlockType(name)) continue;
    registerBlockType(name, {
      apiVersion: 3,
      title: pdf ? t.pdf : t.gallery,
      category: 'media',
      icon: pdf ? 'media-document' : 'format-gallery',
      supports: { html: false },
      attributes: pdf
        ? {
            id: { type: 'number', default: 0 },
            width: { type: 'string', default: '100%' },
            height: { type: 'string', default: '800' },
          }
        : {
            ids: { type: 'array', items: { type: 'number' }, default: [] },
            layout: { type: 'string', default: 'grid' },
            columns: { type: 'number', default: 3 },
            gap: { type: 'number', default: 15 },
            lightbox: { type: 'boolean', default: true },
            autoplay: { type: 'boolean', default: false },
            interval: { type: 'number', default: 3000 },
          },
      edit: ({ attributes: attrs, setAttributes }) => {
        const props = useBlockProps();
        const number = (key, min, max) =>
          el(TextControl, {
            type: 'number',
            label: t[key],
            value: attrs[key],
            min,
            max,
            onChange: (value) =>
              setAttributes({ [key]: Math.min(max, Math.max(min, Number(value) || min)) }),
          });
        return el(
          Fragment,
          null,
          el(
            InspectorControls,
            null,
            el(
              'div',
              { style: { padding: '16px' } },
              pdf
                ? el(
                    Fragment,
                    null,
                    el(TextControl, {
                      label: t.width,
                      value: attrs.width,
                      onChange: (width) => setAttributes({ width }),
                    }),
                    el(TextControl, {
                      label: t.height,
                      value: attrs.height,
                      onChange: (height) => setAttributes({ height }),
                    }),
                  )
                : el(
                    Fragment,
                    null,
                    el(SelectControl, {
                      label: t.layout,
                      value: attrs.layout,
                      options: [
                        { label: t.grid, value: 'grid' },
                        { label: t.slider, value: 'slider' },
                      ],
                      onChange: (layout) => setAttributes({ layout }),
                    }),
                    number('columns', 1, 12),
                    number('gap', 0, 80),
                    el(ToggleControl, {
                      label: t.lightbox,
                      checked: attrs.lightbox,
                      onChange: (lightbox) => setAttributes({ lightbox }),
                    }),
                    el(ToggleControl, {
                      label: t.autoplay,
                      checked: attrs.autoplay,
                      onChange: (autoplay) => setAttributes({ autoplay }),
                    }),
                    attrs.autoplay ? number('interval', 1000, 60000) : null,
                  ),
            ),
          ),
          el(
            'div',
            props,
            el(
              MediaUploadCheck,
              null,
              el(MediaUpload, {
                allowedTypes: [pdf ? 'application/pdf' : 'image'],
                value: pdf ? attrs.id : attrs.ids,
                multiple: !pdf,
                gallery: !pdf,
                onSelect: (media) =>
                  pdf
                    ? setAttributes({ id: media.id })
                    : setAttributes({ ids: media.slice(0, 100).map((image) => image.id) }),
                render: ({ open }) =>
                  el(Button, { variant: 'secondary', onClick: open }, pdf ? t.document : t.images),
              }),
            ),
            (pdf ? attrs.id : attrs.ids.length)
              ? el(Preview, { block: name, attributes: attrs, httpMethod: 'POST' })
              : el(Notice, { status: 'info', isDismissible: false }, t.empty),
          ),
        );
      },
      save: () => null,
    });
  }
})();
