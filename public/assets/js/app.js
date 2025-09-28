// public/assets/js/app.js
(() => {
  'use strict';

  const overlay = document.getElementById('globalSpinner');
  const show = () => { if (overlay) overlay.style.display = 'block'; };
  const hide = () => { if (overlay) overlay.style.display = 'none'; };

  // Formularios con data-spinner (SIN fase de captura)
  document.addEventListener('submit', (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (!form.matches('form[data-spinner]')) return;

    // Si algún handler hizo preventDefault (p.ej. confirmación), no mostramos overlay
    if (ev.defaultPrevented) return;

    // Si el form usa flag __confirmed, solo mostramos overlay cuando es '1'
    const flag = form.querySelector('input[name="__confirmed"]');
    if (flag && flag.value !== '1') return;

    show();
  });

  // Enlaces con data-spinner
  document.addEventListener('click', (ev) => {
    const a = ev.target && ev.target.closest ? ev.target.closest('a[data-spinner]') : null;
    if (!a) return;
    const href = a.getAttribute('href') || '';
    if (!href || href === '#' || href.startsWith('javascript:')) return;
    show();
  });

  window.addEventListener('load', hide);
  setTimeout(hide, 8000);
})();
