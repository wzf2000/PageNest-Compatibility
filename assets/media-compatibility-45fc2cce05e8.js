(() => {
  'use strict';
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const icons = {
    previous: 'M15 5 8 12l7 7',
    next: 'm9 5 7 7-7 7',
    close: 'm6 6 12 12M6 18 18 6',
    pause: 'M9 5v14M15 5v14',
    play: 'm8 5 11 7-11 7Z',
  };
  const initialize = (gallery) => {
    if (gallery.dataset.mediaReady) return;
    let options;
    try {
      options = JSON.parse(gallery.dataset.pagenestMedia);
    } catch {
      return;
    }
    const track = gallery.querySelector('.pagenest-media-track');
    const items = Array.from(track?.children || []);
    if (!items.length) return;
    const links = Array.from(gallery.querySelectorAll('[data-pagenest-lightbox]'));
    const labels = {
      previousImages: 'Previous images',
      nextImages: 'Next images',
      playSlideshow: 'Play slideshow',
      pauseSlideshow: 'Pause slideshow',
      imageViewer: 'Image viewer',
      previousImage: 'Previous image',
      nextImage: 'Next image',
      closeViewer: 'Close viewer',
      ...options.labels,
    };
    gallery.dataset.mediaReady = '1';
    const decorate = (element, label, icon) => {
      element.setAttribute('aria-label', label);
      element.title = label;
      element.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="${icons[icon]}"/></svg>`;
    };
    const button = (label, icon, action, className = '') => {
      const element = document.createElement('button');
      element.type = 'button';
      element.className = `pagenest-media-button ${className}`;
      decorate(element, label, icon);
      element.addEventListener('click', action);
      return element;
    };
    let position = 0;
    let paused = !options.autoplay;
    let timer;
    let settling;
    let animating = false;
    let pauseButton;
    let dialog;
    let returnFocus;
    let viewport;
    const small = window.matchMedia('(max-width: 599px)');
    const columns = () => (small.matches ? 1 : Number(options.columns) || 3);
    const count = items.length;
    const normalize = (index) => ((index % count) + count) % count;
    const accessibility = () => {
      Array.from(track.children).forEach((item, index) => {
        const visible = index >= count + position && index < count + position + columns();
        item.inert = !visible;
        if (visible) item.removeAttribute('aria-hidden');
        else item.setAttribute('aria-hidden', 'true');
        if (item.hasAttribute('data-media-clone')) {
          item.querySelectorAll('a').forEach((link) => {
            link.setAttribute('tabindex', visible ? '0' : '-1');
          });
        }
      });
    };
    const paint = (animate) => {
      if (!viewport) return;
      const step = items[0].getBoundingClientRect().width + parseFloat(getComputedStyle(track).gap);
      track.style.transitionDuration = animate && !motion.matches ? '500ms' : '0ms';
      track.style.transform = `translate3d(${-step * (count + position)}px, 0, 0)`;
    };
    const finish = () => {
      clearTimeout(settling);
      animating = false;
      position = normalize(position);
      paint(false);
      accessibility();
    };
    const move = (direction) => {
      if (items.length <= columns() || animating) return;
      position += direction * columns();
      animating = !motion.matches;
      paint(true);
      if (animating) settling = setTimeout(finish, 550);
      else finish();
    };
    const stop = () => {
      clearInterval(timer);
      timer = undefined;
    };
    const start = () => {
      stop();
      if (
        !paused &&
        !motion.matches &&
        !document.hidden &&
        !gallery.matches(':hover') &&
        !gallery.contains(document.activeElement) &&
        !dialog?.open &&
        items.length > columns()
      )
        timer = setInterval(() => move(1), options.interval);
    };
    const swipe = (element, action) => {
      let origin;
      let suppressClick = false;
      element.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch' || !event.isPrimary) return;
        origin = { x: event.clientX, y: event.clientY };
      });
      element.addEventListener('pointerup', (event) => {
        if (!origin) return;
        const x = event.clientX - origin.x;
        const y = event.clientY - origin.y;
        origin = undefined;
        if (Math.abs(x) > 40 && Math.abs(x) > Math.abs(y) * 1.5) {
          suppressClick = true;
          action(x < 0 ? 1 : -1);
          setTimeout(() => {
            suppressClick = false;
          }, 350);
        }
      });
      element.addEventListener('pointercancel', () => {
        origin = undefined;
      });
      element.addEventListener(
        'click',
        (event) => {
          if (suppressClick) {
            event.preventDefault();
            event.stopPropagation();
          }
        },
        true,
      );
    };
    if (options.layout === 'slider') {
      viewport = document.createElement('div');
      viewport.className = 'pagenest-media-viewport';
      track.before(viewport);
      viewport.append(track);
      const clone = (item, index) => {
        const copy = item.cloneNode(true);
        copy.dataset.mediaClone = String(index);
        copy.setAttribute('aria-hidden', 'true');
        [copy, ...copy.querySelectorAll('[id]')].forEach((node) => node.removeAttribute('id'));
        copy.querySelectorAll('a, button, input, select, textarea, [tabindex]').forEach((node) => {
          node.setAttribute('tabindex', '-1');
          node.removeAttribute('data-pagenest-lightbox');
        });
        return copy;
      };
      track.prepend(...items.map(clone));
      track.append(...items.map(clone));
      gallery.classList.add('pagenest-media-enhanced');
      track.addEventListener('transitionend', (event) => {
        if (event.target === track && event.propertyName === 'transform') finish();
      });
      const controls = document.createElement('div');
      controls.className = 'pagenest-media-controls';
      const previous = button(
        labels.previousImages,
        'previous',
        () => move(-1),
        'pagenest-media-previous',
      );
      const next = button(labels.nextImages, 'next', () => move(1), 'pagenest-media-next');
      previous.disabled = next.disabled = count <= columns();
      pauseButton = button(
        paused ? labels.playSlideshow : labels.pauseSlideshow,
        paused ? 'play' : 'pause',
        () => {
          paused = !paused;
          decorate(
            pauseButton,
            paused ? labels.playSlideshow : labels.pauseSlideshow,
            paused ? 'play' : 'pause',
          );
          pauseButton.setAttribute('aria-pressed', String(paused));
          start();
        },
        'pagenest-media-pause',
      );
      pauseButton.setAttribute('aria-pressed', String(paused));
      viewport.append(previous, next);
      controls.append(pauseButton);
      gallery.append(controls);
      gallery.addEventListener('mouseenter', stop);
      gallery.addEventListener('mouseleave', start);
      gallery.addEventListener('focusin', stop);
      gallery.addEventListener('focusout', () => setTimeout(start, 0));
      gallery.addEventListener('keydown', (event) => {
        if (dialog?.open || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        // Keep focus on the controls while the previously focused image moves away.
        if (track.contains(document.activeElement)) next.focus({ preventScroll: true });
        move(event.key === 'ArrowLeft' ? -1 : 1);
      });
      swipe(viewport, move);
      document.addEventListener('visibilitychange', start);
      motion.addEventListener('change', () => {
        finish();
        start();
      });
      new ResizeObserver(() => {
        finish();
        previous.disabled = next.disabled = count <= columns();
        start();
      }).observe(viewport);
      paint(false);
      accessibility();
      start();
    }
    if (
      !links.length ||
      typeof HTMLDialogElement === 'undefined' ||
      !HTMLDialogElement.prototype.showModal
    )
      return;
    let lightboxIndex = 0;
    let image;
    let caption;
    let counter;
    const display = (index) => {
      lightboxIndex = ((index % links.length) + links.length) % links.length;
      const link = links[lightboxIndex];
      image.src = link.href;
      image.alt = link.querySelector('img')?.alt || '';
      caption.textContent = link.dataset.caption || '';
      caption.scrollTop = 0;
      counter.textContent = `${lightboxIndex + 1} / ${links.length}`;
      requestAnimationFrame(() => {
        // Long descriptions remain keyboard-readable without adding a stop for short captions.
        if (caption.scrollHeight > caption.clientHeight) caption.tabIndex = 0;
        else caption.removeAttribute('tabindex');
      });
      if (!motion.matches) {
        image.getAnimations().forEach((animation) => animation.cancel());
        image.animate(
          [
            { opacity: 0, transform: 'translateY(6px)' },
            { opacity: 1, transform: 'translateY(0)' },
          ],
          { duration: 180, easing: 'ease-out' },
        );
      }
    };
    const close = () => dialog.close();
    const createDialog = () => {
      dialog = document.createElement('dialog');
      dialog.className = 'pagenest-media-lightbox';
      dialog.setAttribute('aria-label', labels.imageViewer);
      const controls = document.createElement('div');
      controls.className = 'pagenest-media-lightbox-controls';
      const closeButton = button(labels.closeViewer, 'close', close, 'pagenest-media-close');
      closeButton.autofocus = true;
      controls.append(
        button(labels.previousImage, 'previous', () => display(lightboxIndex - 1)),
        button(labels.nextImage, 'next', () => display(lightboxIndex + 1)),
        closeButton,
      );
      image = document.createElement('img');
      image.className = 'pagenest-media-lightbox-image';
      caption = document.createElement('p');
      caption.className = 'pagenest-media-caption';
      counter = document.createElement('p');
      counter.className = 'pagenest-media-counter';
      counter.setAttribute('aria-live', 'polite');
      const details = document.createElement('div');
      details.className = 'pagenest-media-lightbox-details';
      details.append(caption, counter);
      dialog.append(controls, image, details);
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) close();
      });
      dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
          const focusable = Array.from(dialog.querySelectorAll('button, [tabindex="0"]'));
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
          } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
          }
        }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          event.stopPropagation();
          display(lightboxIndex + (event.key === 'ArrowLeft' ? -1 : 1));
        }
      });
      swipe(image, (direction) => display(lightboxIndex + direction));
      dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        if (viewport && returnFocus?.closest('.pagenest-media-item')?.inert) {
          position = items.indexOf(returnFocus.closest('.pagenest-media-item'));
          finish();
        }
        returnFocus?.focus({ preventScroll: true });
        start();
      });
      document.body.append(dialog);
    };
    const open = (event, link, index) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      stop();
      if (!dialog) createDialog();
      returnFocus = link;
      display(index);
      dialog.showModal();
    };
    links.forEach((link, index) =>
      link.addEventListener('click', (event) => open(event, link, index)),
    );
    track.querySelectorAll('[data-media-clone] a').forEach((link) => {
      const original = items[
        Number(link.closest('[data-media-clone]').dataset.mediaClone)
      ].querySelector('[data-pagenest-lightbox]');
      if (original)
        link.addEventListener('click', (event) => open(event, original, links.indexOf(original)));
    });
  };
  const pdfFrames = new WeakSet();
  const pdfSelector =
    '.pagenest-media-pdf > iframe.pagenest-media-pdf-frame[data-pagenest-pdf-viewer]';
  const initializePdf = (frame) => {
    const config = window.PageNestMedia;
    if (pdfFrames.has(frame) || !config?.pdfViewerUrl || !config?.pdfViewerStyles) return;
    let viewer, stylesheet;
    try {
      viewer = new URL(config.pdfViewerUrl, location.href);
      stylesheet = new URL(config.pdfViewerStyles, location.href);
      if (viewer.origin !== location.origin || stylesheet.origin !== location.origin) return;
    } catch {
      return;
    }
    pdfFrames.add(frame);
    const adapt = () => {
      try {
        const source = new URL(frame.src, location.href);
        if (source.origin !== viewer.origin || source.pathname !== viewer.pathname) return;
        const document = frame.contentDocument;
        if (
          !document ||
          document.location.origin !== viewer.origin ||
          document.location.pathname !== viewer.pathname ||
          !document.head
        )
          return;
        if (document.querySelector('link[data-pagenest-pdf-style]')) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = stylesheet.href;
        link.dataset.pagenestPdfStyle = '';
        document.head.append(link);
      } catch {
        /* Cross-origin or redirected frames keep their own presentation. */
      }
    };
    frame.addEventListener('load', adapt);
    adapt();
  };
  const scan = (root) => {
    if (root.matches?.('[data-pagenest-media]')) initialize(root);
    if (root.matches?.(pdfSelector)) initializePdf(root);
    root.querySelectorAll?.(pdfSelector).forEach(initializePdf);
    root.querySelectorAll?.('[data-pagenest-media]').forEach(initialize);
  };
  scan(document);
  new MutationObserver((changes) =>
    changes.forEach((change) =>
      change.addedNodes.forEach((node) => {
        if (node.nodeType === 1) scan(node);
      }),
    ),
  ).observe(document.body, { childList: true, subtree: true });
})();
