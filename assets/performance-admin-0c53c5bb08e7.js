/* Progressive selection feedback only. No task execution, polling or settings dirtiness. */
(() => {
  const form = document.querySelector('.pn-performance .pn-perf-selection');
  if (!form) return;
  const ids = form.querySelector('[name="post_ids"]');
  const mode = form.querySelector('[name="cache_mode"]');
  const repeats = form.querySelector('[name="repeats"]');
  const validation = form.querySelector('[data-pn-perf-validation]');
  const buttons = [...form.querySelectorAll('button[name="operation"]')];
  function update() {
    const extra = ids.value
      .trim()
      .split(/[\s,，]+/u)
      .filter(Boolean);
    const count = form.querySelectorAll('[name="pages[]"]:checked').length + extra.length;
    const malformed = extra.some((id) => !/^[1-9][0-9]*$/.test(id));
    const message = malformed
      ? '请填写有效的正整数 ID，用逗号或空格分隔。'
      : count < 1
        ? '至少选择 1 个页面，才能保存或提交。'
        : count > 8
          ? '勾选项与补充 ID 合计最多 8 项，请减少选择。'
          : '';
    validation.textContent = message;
    validation.hidden = !message;
    form.querySelector('[data-pn-perf-count]').textContent = `已选择 ${count} / 8 项`;
    const navigations = count * Number(repeats.value) * (mode.value === 'paired' ? 2 : 1);
    form.querySelector('[data-pn-perf-estimate]').textContent = message
      ? '有效选择后显示本次导航上限。'
      : `最多 ${navigations} 次导航 · ${repeats.value} 轮。服务器会验证公开性并合并相同 URL；失败阶段可能跳过。`;
    for (const button of buttons) button.disabled = !!message;
    ids.setCustomValidity(malformed ? message : '');
    return !message;
  }
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('submit', (event) => {
    if (!update()) {
      event.preventDefault();
      const target = ids.validationMessage ? ids : form.querySelector('[name="pages[]"]') || ids;
      if (target === ids) ids.closest('details').open = true;
      target.focus();
    }
  });
  update();
})();
