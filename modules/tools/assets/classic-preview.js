(() => {
  const config = window.siteToolsPreview;
  if (!config) return;
  const cache = new Map();
  async function markup(slug) {
    if (!cache.has(slug))
      cache.set(
        slug,
        fetch(config.url, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify({ post_id: config.postId, slug }),
        })
          .then(async (r) => {
            if (!r.ok) throw new Error('预览暂不可用，请刷新编辑器。');
            return (await r.json()).html;
          })
          .catch((e) => {
            cache.delete(slug);
            throw e;
          }),
      );
    return cache.get(slug);
  }
  function scan() {
    document.querySelectorAll('.editormd-preview-container').forEach((root) => {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT),
        nodes = [];
      while (walker.nextNode())
        if (!walker.currentNode.parentElement.closest('pre,code,.site-tools-classic-preview'))
          nodes.push(walker.currentNode);
      nodes.forEach((node) => {
        const names = (config.shortcodes || []).filter((name) => /^[a-zA-Z0-9_]+$/.test(name));
        if (!names.length) return;
        const pattern = new RegExp(
          '\\[(?:' + names.join('|') + ')\\s+slug=["\'“”]([a-z0-9-]{1,64})["\'“”]\\s*\\]',
          'g',
        );
        const text = node.textContent;
        let match,
          last = 0;
        const fragment = document.createDocumentFragment();
        let found = false;
        while ((match = pattern.exec(text))) {
          found = true;
          fragment.append(document.createTextNode(text.slice(last, match.index)));
          last = pattern.lastIndex;
          const wrapper = document.createElement('div');
          wrapper.className = 'site-tools-classic-preview';
          wrapper.textContent = '正在生成工具预览…';
          fragment.append(wrapper);
          markup(match[1])
            .then((html) => {
              if (!wrapper.isConnected) return;
              const doc = new DOMParser().parseFromString(html, 'text/html');
              doc.querySelectorAll('script').forEach((s) => s.remove());
              wrapper.replaceChildren(...doc.head.childNodes, ...doc.body.childNodes);
              wrapper.querySelectorAll('input,textarea,button,select').forEach((e) => {
                e.disabled = true;
              });
              const note = document.createElement('p');
              note.textContent = '工具外观预览；实际操作请使用页面预览。';
              wrapper.append(note);
            })
            .catch((e) => {
              wrapper.textContent = e.message;
            });
        }
        if (found) {
          fragment.append(document.createTextNode(text.slice(last)));
          node.replaceWith(fragment);
        }
      });
    });
  }
  let timer;
  const schedule = () => {
    clearTimeout(timer);
    timer = setTimeout(scan, 100);
  };
  new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
  scan();
})();
