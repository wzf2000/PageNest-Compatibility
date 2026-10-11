(() => {
  'use strict';
  const cfg = window.pagenestNativeFolders;
  const media = window.wp?.media;
  if (!cfg || !media?.view?.AttachmentsBrowser) return;
  const FolderFilter = media.view.AttachmentFilters.extend({
    id() {
      return 'pagenest-folder-filter-' + this.cid;
    },
    className: 'attachment-filters pagenest-folder-filter',
    attributes: { 'aria-label': cfg.labels.all },
    createFilters() {
      const filters = {};
      const add = (key, text, value, priority) => {
        filters[key] = { text, props: { [cfg.taxonomy]: value }, priority };
      };
      add('all', cfg.labels.all, '', -1);
      add('unassigned', cfg.labels.unassigned, '-1', 1);
      cfg.terms.forEach((term, i) => add('term-' + term.id, term.path, term.slug, i + 2));
      this.filters = filters;
    },
  });
  const original = media.view.AttachmentsBrowser.prototype.createToolbar;
  media.view.AttachmentsBrowser.prototype.createToolbar = function () {
    original.apply(this, arguments);
    const filter = new FolderFilter({
      controller: this.controller,
      model: this.collection.props,
      priority: -65,
    });
    const label = new media.View({
      tagName: 'label',
      className: 'pagenest-folder-label',
      attributes: { for: filter.el.id },
      priority: -66,
    });
    label.$el.text(cfg.labels.all);
    this.toolbar.$el.addClass('pagenest-folder-toolbar');
    const column =
      1 + Number(!!this.toolbar.get('filters')) + Number(!!this.toolbar.get('dateFilter'));
    this.toolbar.el.style.setProperty('--pagenest-folder-column', column);
    this.toolbar.el.style.setProperty('--pagenest-assign-column', column + 1);
    this.toolbar.set('pagenest-folder-label', label);
    this.toolbar.set('pagenest-folders', filter.render());
    const selection = this.options.selection;
    if (!selection) return;
    const assign = new media.view.Button({
      text: cfg.labels.assign,
      classes: ['pagenest-folder-assign'],
      disabled: !selection.length,
      priority: -60,
      click() {
        const ids = selection
          .pluck('id')
          .filter((id) => Number.isInteger(Number(id)) && Number(id) > 0);
        if (ids.length && ids.length <= 200)
          window.open(cfg.assignUrl + '&ids=' + ids.join(','), '_blank', 'noopener');
      },
    });
    this.listenTo(selection, 'add remove reset', () =>
      assign.model.set('disabled', !selection.length || selection.length > 200),
    );
    this.toolbar.set('pagenest-assign', assign);
  };
})();
