(() => {
  'use strict';
  const states = new Map();
  let apiLoading = false;
  function retryApi() {
    if (window.turnstile || apiLoading) return;
    apiLoading = true;
    const script = document.createElement('script');
    script.src =
      'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=pagenestTurnstileReady&render=explicit';
    script.defer = true;
    script.onerror = () => {
      apiLoading = false;
      states.forEach((state) => state.invalidate('验证服务未加载，请检查网络后重试。'));
      script.remove();
    };
    script.onload = () => {
      apiLoading = false;
      render();
    };
    document.head.append(script);
    setTimeout(() => {
      apiLoading = false;
    }, 15000);
  }
  function prepare() {
    document.querySelectorAll('.pagenest-turnstile').forEach((container) => {
      if (states.has(container)) return;
      const form = container.closest('form');
      if (!form) return;
      const buttons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
      const state = {
        container,
        form,
        buttons,
        initiallyDisabled: buttons.map((button) => button.disabled),
        valid: false,
        widget: null,
      };
      states.set(container, state);
      const status = container.querySelector('.pagenest-turnstile-status');
      const retry = container.querySelector('.pagenest-turnstile-retry');
      function invalidate(message) {
        state.valid = false;
        buttons.forEach((button) => {
          button.disabled = true;
        });
        status.textContent = message;
        retry.hidden = false;
      }
      state.invalidate = invalidate;
      invalidate('请完成安全验证后提交。');
      form.addEventListener('submit', (event) => {
        if (!state.valid) {
          event.preventDefault();
          retry.focus();
        }
      });
      retry.addEventListener('click', () => {
        invalidate('正在重新验证…');
        if (window.turnstile && state.widget !== null) window.turnstile.reset(state.widget);
        else {
          render();
          retryApi();
        }
      });
      state.render = () => {
        if (!window.turnstile || state.widget !== null) return;
        try {
          state.widget = window.turnstile.render(
            container.querySelector('.pagenest-turnstile-widget'),
            {
              sitekey: container.dataset.sitekey,
              action: container.dataset.action,
              theme: container.dataset.theme || 'auto',
              language: container.dataset.language || 'zh-cn',
              size:
                container.getBoundingClientRect().width < 300
                  ? 'compact'
                  : container.dataset.size || 'normal',
              appearance: container.dataset.appearance || 'always',
              callback: () => {
                state.valid = true;
                buttons.forEach((button, index) => {
                  button.disabled = state.initiallyDisabled[index];
                });
                status.textContent = '验证完成，可以提交。';
                retry.hidden = true;
              },
              'expired-callback': () => invalidate('验证已过期，请重新验证。'),
              'timeout-callback': () => invalidate('验证超时，请重试。'),
              'error-callback': () => {
                invalidate('验证暂时不可用，请重试。');
                return true;
              },
            },
          );
        } catch {
          invalidate('验证暂时不可用，请重试。');
        }
      };
      setTimeout(() => {
        if (!state.valid && state.widget === null) invalidate('验证服务未加载，请检查网络后重试。');
      }, 15000);
    });
  }
  function render() {
    prepare();
    states.forEach((state) => state.render());
  }
  window.pagenestTurnstileReady = render;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
  else render();
})();
