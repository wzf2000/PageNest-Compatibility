/* Presentation only: never write spaced text back to Markdown or note registries. */
(() => {
  'use strict';
  const excluded =
    'pre,code,script,style,textarea,svg,math,mjx-container,.MathJax,.MathJax_Display,.MathJax_Preview,.pagenest-paragraph-bubble,[data-pagenest-private],.pagenest-content-nav';
  const han = /\p{Script=Han}/u;
  const latin = /[A-Za-z0-9]/;
  const boundary = (a, b) => (han.test(a) && latin.test(b)) || (latin.test(a) && han.test(b));
  function space(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node,
      previous = null;
    while ((node = walker.nextNode())) {
      if (node.parentElement.closest(excluded)) {
        previous = null;
        continue;
      }
      const block = node.parentElement.closest('p,li,td,th,h1,h2,h3,h4,h5,h6,div');
      // A delimiter may span inline nodes; keep that entire block untouched.
      if (block && /(?:\$|\\\(|\\\[)/.test(block.textContent)) {
        previous = null;
        continue;
      }
      // Protect unrendered TeX (including a CDN failure) and escaped dollars.
      const pieces = node.data.split(
        /(\\\([\s\S]*?\\\)|\\\[[\s\S]*?\\\]|\$\$[\s\S]*?\$\$|(?<!\\)\$(?!\$)[^$\n]+(?<!\\)\$)/g,
      );
      const spaced = pieces
        .map((s, i) =>
          i % 2
            ? s
            : s
                .replace(/(\p{Script=Han})([A-Za-z0-9])/gu, '$1 $2')
                .replace(/([A-Za-z0-9])(\p{Script=Han})/gu, '$1 $2'),
        )
        .join('');
      if (node.data !== spaced) node.data = spaced;
      if (
        previous &&
        previous.block === block &&
        boundary(previous.node.data.slice(-1), node.data.slice(0, 1))
      ) {
        node.data = ' ' + node.data;
      }
      if (node.data) previous = { node, block };
    }
  }
  function run() {
    document.querySelectorAll('.pagenest-content-chapter').forEach(space);
  }
  function ready() {
    if (window.MathJax && MathJax.Hub) MathJax.Hub.Queue(run);
    else run();
  }
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', ready, { once: true });
  else ready();
})();
