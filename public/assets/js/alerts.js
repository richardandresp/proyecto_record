// public/assets/js/alerts.js
(() => {
  'use strict';

  if (!window.Swal) {
    console.warn('[alerts.js] SweetAlert2 no está cargado');
    return;
  }

  const THEME = {
    confirmButtonText: 'Aceptar',
    cancelButtonText: 'Cancelar',
    buttonsStyling: false,
    reverseButtons: true,
    width: '28rem',
    customClass: {
      popup: 'swal2-popup rounded-4 shadow-lg',
      title: 'swal2-title fw-semibold',
      htmlContainer: 'swal2-html-container',
      actions: 'swal2-actions gap-3',
      confirmButton: 'btn btn-primary btn-lg rounded-pill px-4 shadow-sm',
      cancelButton: 'btn btn-outline-secondary btn-lg rounded-pill px-4',
      denyButton: 'btn btn-warning btn-lg rounded-pill px-4 text-dark'
    },
    showClass: { popup: 'animate__animated animate__fadeInDown' },
    hideClass: { popup: 'animate__animated animate__fadeOutUp' }
  };

  // Confirmaciones globales data-confirm (links/botones)
  // IGNORA elementos dentro de contenedores con data-skip-global-confirm
  document.addEventListener('click', (e) => {
    const el = e.target.closest('a[data-confirm], button[data-confirm]');
    if (!el) return;

    if (el.closest('[data-skip-global-confirm="1"], [data-skip-global-confirm=true], [data-skip-global-confirm]')) {
      return; // no interceptar
    }

    e.preventDefault();
    const href   = el.getAttribute('href');
    const form   = el.closest('form');
    const text   = el.getAttribute('data-confirm') || '¿Deseas continuar?';
    const type   = el.getAttribute('data-confirm-type') || 'warning';
    const ok     = el.getAttribute('data-confirm-ok') || 'Sí';
    const cancel = el.getAttribute('data-confirm-cancel') || 'Cancelar';

    Swal.fire({
      icon: type, title: 'Confirmar', html: text,
      showCancelButton: true,
      confirmButtonText: ok,
      cancelButtonText: cancel,
      ...THEME
    }).then(res => {
      if (!res.isConfirmed) return;
      if (href) {
        window.location.href = href;
      } else if (form) {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      }
    });
  });

  // Helpers
  window.AppAlert = {
    success: (msg, title='¡Listo!') => Swal.fire({icon:'success', title, html:msg, ...THEME}),
    error:   (msg, title='Ups...')  => Swal.fire({icon:'error', title, html:msg, ...THEME}),
    info:    (msg, title='Aviso')   => Swal.fire({icon:'info', title, html:msg, ...THEME}),
    warn:    (msg, title='Atención')=> Swal.fire({icon:'warning', title, html:msg, ...THEME}),
    theme: THEME
  };
})();
