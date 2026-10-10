(() => {
  'use strict';
  const { createElement: el, RawHTML, Fragment } = wp.element;
  const { registerBlockType, getBlockType } = wp.blocks;
  const { Button, TextControl, Notice } = wp.components;
  const { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } = wp.blockEditor;
  const ServerSideRender = wp.serverSideRender.default || wp.serverSideRender;
  const attributes = (pdf) => {
    const result = { legacyContent: { type: 'string', source: 'html' } };
    const numbers = pdf
      ? ['id', 'embed']
      : [
          'galleryId',
          'row_height',
          'display_tree',
          'sub_galleries_listing',
          'display_tag',
          'disable_overlay',
          'download_all',
          'crop_image',
          'duration',
          'auto_animation',
          'number_lines',
          'show_buttons',
          'img_border_radius',
          'borderWidth',
          'hoverShadowH',
          'hoverShadowV',
          'hoverShadowBlur',
          'hoverShadowSpread',
          'gutterwidth',
          'tree_width',
          'hover_opacity',
          'hover_title_size',
          'hover_desc_size',
        ];
    const strings = pdf
      ? ['target', 'width', 'height', 'url']
      : [
          'display',
          'layout',
          'aspect_ratio',
          'columns',
          'size',
          'targetsize',
          'link',
          'wpmf_orderby',
          'wpmf_order',
          'animation',
          'borderColor',
          'borderStyle',
          'hoverShadowColor',
          'theme',
          'html',
          'hover_color',
          'hover_title_position',
          'hover_title_color',
          'hover_desc_position',
          'hover_desc_color',
          'align',
        ];
    numbers.forEach((key) => {
      result[key] = { type: 'number' };
    });
    strings.forEach((key) => {
      result[key] = { type: 'string' };
    });
    return result;
  };
  const escaped = (value) =>
    String(value)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  const shortcode = (pdf, attrs) => {
    const keys = pdf
      ? ['id', 'embed', 'target', 'width', 'height']
      : [
          'galleryId',
          'display',
          'layout',
          'columns',
          'gutterwidth',
          'size',
          'targetsize',
          'link',
          'wpmf_orderby',
          'wpmf_order',
          'duration',
          'auto_animation',
        ];
    const values = { ...attrs };
    if (pdf && values.embed === undefined) values.embed = 1;
    return `[${pdf ? 'wpmfpdf' : 'wpmfgallery'}${keys
      .filter((key) => values[key] !== undefined && values[key] !== '')
      .map((key) => ` ${key === 'galleryId' ? 'gallery_id' : key}="${escaped(values[key])}"`)
      .join('')}]`;
  };
  ['wpmf/pdfembed', 'wpmf/block-gallery'].forEach((name) => {
    if (!window.pagenestCompanionMediaEditor?.blocks?.includes(name) || getBlockType(name)) return;
    const pdf = name === 'wpmf/pdfembed';
    registerBlockType(name, {
      apiVersion: 3,
      title: pdf ? 'PDF viewer (compatible)' : 'Media gallery (compatible)',
      category: 'media',
      icon: pdf ? 'media-document' : 'format-gallery',
      attributes: attributes(pdf),
      supports: { html: false },
      edit: ({ attributes: attrs, setAttributes }) => {
        const blockProps = useBlockProps();
        const update = (changes) => {
          const next = { ...preview, ...changes };
          let content = attrs.legacyContent || shortcode(pdf, preview);
          const tag = pdf ? 'wpmfpdf' : 'wpmfgallery';
          const original = content.match(new RegExp(`\\[${tag}\\b([^\\]]*)\\]`));
          if (original) {
            let parameters = original[1];
            Object.entries(changes).forEach(([key, value]) => {
              if (!attributes(pdf)[key] && key !== 'url') return;
              const parameter = key === 'galleryId' ? 'gallery_id' : key;
              const pattern = new RegExp(
                `(^|\\s)${parameter}\\s*=\\s*(?:"[^"]*"|'[^']*'|[^\\s]+)`,
                'g',
              );
              parameters = parameters.replace(pattern, '$1').trimEnd();
              if (value !== undefined && value !== '')
                parameters += ` ${parameter}="${escaped(value)}"`;
            });
            content = content.replace(original[0], `[${tag}${parameters}]`);
          } else {
            content = shortcode(pdf, next);
          }
          const saved = { ...changes };
          delete saved.url;
          setAttributes({ ...saved, legacyContent: content });
        };
        const preview = { ...attrs };
        // The stored inner shortcode remains authoritative in editor previews too.
        const stored = attrs.legacyContent || '';
        const match = stored.match(pdf ? /\[wpmfpdf\b([^\]]*)\]/ : /\[wpmfgallery\b([^\]]*)\]/);
        if (match) {
          const pattern = /([a-zA-Z_]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s]+))/g;
          let part;
          while ((part = pattern.exec(match[1]))) {
            const key = part[1] === 'gallery_id' ? 'galleryId' : part[1];
            const definition = attributes(pdf)[key];
            if (definition)
              preview[key] =
                definition.type === 'number'
                  ? Number(part[2] ?? part[3] ?? part[4])
                  : (part[2] ?? part[3] ?? part[4]);
          }
        }
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
                      label: 'Viewer width (100–3000 or 1%–100%)',
                      value: preview.width || '',
                      onChange: (width) => update({ width }),
                    }),
                    el(TextControl, {
                      label: 'Viewer height (100–3000)',
                      value: preview.height || '',
                      onChange: (height) => update({ height }),
                    }),
                  )
                : el(TextControl, {
                    label: 'Configured gallery ID',
                    value: preview.galleryId || '',
                    onChange: (value) => update({ galleryId: Number(value) || 0 }),
                  }),
            ),
          ),
          el(
            'div',
            blockProps,
            pdf
              ? el(
                  MediaUploadCheck,
                  null,
                  el(MediaUpload, {
                    allowedTypes: ['application/pdf'],
                    value: preview.id,
                    onSelect: (media) => {
                      if (media.mime === 'application/pdf' || media.mime_type === 'application/pdf')
                        update({ id: media.id, embed: 1, url: '' });
                    },
                    render: ({ open }) =>
                      el(Button, { variant: 'secondary', onClick: open }, 'Select PDF'),
                  }),
                )
              : el(
                  Notice,
                  { status: 'info', isDismissible: false },
                  'Gallery sources are managed through Media and your organization plugin. Ask your administrator to configure this gallery mapping.',
                ),
            el(ServerSideRender, { block: name, attributes: preview, httpMethod: 'POST' }),
          ),
        );
      },
      save: ({ attributes: attrs }) =>
        el(RawHTML, null, attrs.legacyContent || shortcode(pdf, attrs)),
    });
  });
})();
