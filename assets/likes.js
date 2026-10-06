(() => {
  const cfg = window.PageNestLikes;
  if (!cfg) return;
  document.querySelectorAll('[data-pagenest-like]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.disabled) return;
      if (!cfg.nonce) {
        location.assign(cfg.loginUrl);
        return;
      }
      button.disabled = true;
      try {
        const response = await fetch(cfg.api + button.dataset.pagenestLike, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || '点赞失败，请重试。');
        button.querySelector('span').textContent = String(data.count);
        button.setAttribute('aria-pressed', 'true');
        button.firstChild.textContent = '已点赞 ';
      } catch (error) {
        button.disabled = false;
        button.title = error.message;
        let status = button.nextElementSibling;
        if (!status?.matches('[data-pagenest-like-status]')) {
          status = document.createElement('span');
          status.dataset.pagenestLikeStatus = '';
          status.setAttribute('role', 'status');
          button.after(status);
        }
        status.textContent = error.message;
      }
    });
  });
})();
