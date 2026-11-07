/* Local navigation and sharing. No browser history, tokens or personal storage is collected. */
(() => {
  'use strict';
  // A fragment is not sent to PHP. Add it at activation time only when the target is this page.
  const preserveFragment = (link) => {
    if (!location.hash || !(link instanceof HTMLAnchorElement)) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin) return;
    let key;
    if (url.pathname.endsWith('/wp-login.php') && !url.searchParams.has('action'))
      key = 'redirect_to';
    if (url.searchParams.get('action') === 'pagenest_social_start') key = 'return';
    if (!key || !url.searchParams.has(key)) return;
    const target = new URL(url.searchParams.get(key), location.href);
    if (
      target.origin !== location.origin ||
      target.pathname !== location.pathname ||
      (target.search && target.search !== location.search) ||
      target.hash
    )
      return;
    if (!target.search) target.search = location.search;
    target.hash = location.hash;
    url.searchParams.set(key, target.href);
    link.href = url.href;
  };
  document.addEventListener(
    'click',
    (event) => preserveFragment(event.target.closest?.('a')),
    true,
  );
  document.addEventListener(
    'auxclick',
    (event) => preserveFragment(event.target.closest?.('a')),
    true,
  );

  const generate = (dialog) => {
    const element = dialog.querySelector('[data-pagenest-social-qr]');
    if (!element || element.childNodes.length) return;
    try {
      const code = qrcode(0, 'M');
      code.addData(element.dataset.pagenestSocialQr, 'Byte');
      code.make();
      const canvas = document.createElement('canvas');
      const count = code.getModuleCount(),
        cell = 4;
      canvas.width = canvas.height = (count + 8) * cell;
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-label', '文章链接二维码');
      const context = canvas.getContext('2d');
      context.fillStyle = '#fff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.fillStyle = '#000';
      for (let row = 0; row < count; row++) {
        for (let column = 0; column < count; column++) {
          if (code.isDark(row, column))
            context.fillRect((column + 4) * cell, (row + 4) * cell, cell, cell);
        }
      }
      element.append(canvas);
    } catch {
      element.textContent = '二维码暂时无法显示，请复制下方文章链接。';
    }
  };
  const nativeAdapter = {
    open(dialog, { trigger, onClose }) {
      const root = document.documentElement;
      const x = scrollX,
        y = scrollY,
        overflow = root.style.overflow;
      const cleanup = () => {
        dialog.removeEventListener('close', cleanup);
        root.style.overflow = overflow;
        const behavior = root.style.scrollBehavior;
        root.style.scrollBehavior = 'auto';
        if (trigger.isConnected) trigger.focus({ preventScroll: true });
        scrollTo(x, y);
        root.style.scrollBehavior = behavior;
        document.dispatchEvent(
          new CustomEvent('pagenest-overlay-change', { detail: { open: false } }),
        );
        onClose();
      };
      dialog.addEventListener('close', cleanup);
      dialog.showModal();
      root.style.overflow = 'hidden';
      document.dispatchEvent(
        new CustomEvent('pagenest-overlay-change', { detail: { open: true } }),
      );
      dialog.querySelector('[data-pagenest-share-close]').focus({ preventScroll: true });
    },
    close(dialog) {
      dialog.close();
    },
  };
  let adapter = nativeAdapter,
    active = null;
  const close = () => {
    if (active) active.adapter.close(active.dialog);
  };
  const open = (trigger) => {
    const id = trigger?.getAttribute('data-pagenest-share-open');
    const dialog = id && document.getElementById(id);
    if (!dialog?.matches('dialog.pagenest-social-qr') || active) return false;
    if (typeof dialog.showModal !== 'function' && adapter === nativeAdapter) return false;
    generate(dialog);
    const record = { dialog, trigger, adapter };
    active = record;
    trigger.setAttribute('aria-expanded', 'true');
    try {
      adapter.open(dialog, {
        trigger,
        onClose: () => {
          trigger.setAttribute('aria-expanded', 'false');
          if (active === record) active = null;
        },
      });
    } catch {
      trigger.setAttribute('aria-expanded', 'false');
      active = null;
      return false;
    }
    return true;
  };
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-pagenest-share-open]');
    if (trigger && open(trigger)) event.preventDefault();
    if (event.target.closest?.('[data-pagenest-share-close]')) close();
    if (active && event.target === active.dialog) {
      const box = active.dialog.getBoundingClientRect();
      if (
        event.clientX < box.left ||
        event.clientX > box.right ||
        event.clientY < box.top ||
        event.clientY > box.bottom
      )
        close();
    }
  });
  document.querySelectorAll('dialog.pagenest-social-qr').forEach((dialog) => {
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      close();
    });
    const copy = dialog.querySelector('[data-pagenest-share-copy]');
    copy?.addEventListener('click', async () => {
      const input = dialog.querySelector('[data-pagenest-share-url]');
      const status = dialog.querySelector('[role="status"]');
      try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = '链接已复制，可粘贴到微信。';
      } catch {
        input.focus({ preventScroll: true });
        input.select();
        status.textContent = '请选择并复制文章链接。';
      }
    });
  });
  window.PageNestSocialShare = {
    open,
    close,
    setDialogAdapter(next) {
      if (active || typeof next?.open !== 'function' || typeof next?.close !== 'function')
        return false;
      adapter = next;
      return true;
    },
  };
})();
