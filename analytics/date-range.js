(() => {
  'use strict';

  const start = document.getElementById('wc-ac-range-start');
  const end = document.getElementById('wc-ac-range-end');

  if (!start || !end) {
    return;
  }

  const baseUrl = start.dataset.baseUrl;
  const nonce = start.dataset.nonce;

  const navigate = () => {
    if (!start.value || !end.value) {
      return;
    }

    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('range', 'custom');
    url.searchParams.set('start', start.value);
    url.searchParams.set('end', end.value);
    url.searchParams.set('_wpnonce', nonce);
    window.location.href = url.toString();
  };

  start.addEventListener('change', navigate);
  end.addEventListener('change', navigate);
})();
