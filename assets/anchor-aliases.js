window.addEventListener('load', () => {
  let hash;
  try {
    hash = decodeURIComponent(location.hash.slice(1));
  } catch {
    return;
  }
  for (const [from, to] of Object.entries(window.PageNestAnchorAliases || {})) {
    if (!hash.startsWith(from)) continue;
    const target = document.getElementById(to + hash.slice(from.length));
    if (target) target.scrollIntoView();
  }
});
