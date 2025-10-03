<?php 
require_once __DIR__ . '/session_boot.php';

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/flash.php';

$flash = consume_flash();
if ($flash) {
  echo '<meta name="flash" data-type="'.htmlspecialchars($flash['type']).'" data-message="'.htmlspecialchars($flash['message']).'">';
}

$rol = $_SESSION['rol'] ?? 'lectura';
$nombreUsuario = $_SESSION['nombre'] ?? '';

function nav_active(string $relativePath): string {
  $current = $_SERVER['REQUEST_URI'] ?? '';
  $target  = rtrim(BASE_URL, '/') . $relativePath;
  return (strpos($current, $target) !== false) ? 'active' : '';
}
?>
<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<!-- SweetAlert2 + Animaciones -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4/animate.min.css">
<!-- CSS propio -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css">

<!-- Estilos mínimos para distinguir no leídas -->
<style>
  .notif-section-title{padding:.25rem .75rem;font-size:.8rem;color:#6c757d;}
  .notif-item{padding:.5rem .75rem;}
  .notif-item.unread{background:#fff7e6;border-left:4px solid #fd7e14;}
  .notif-item .dot{display:inline-block;width:.5rem;height:.5rem;border-radius:50%;background:#dc3545;margin-right:.35rem;vertical-align:middle;}
  .notif-item .title{font-weight:600;}
  .notif-item .meta{font-size:.75rem;color:#6c757d;}
</style>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 sticky-top">
  <div class="container">
    <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard.php"><?= APP_NAME ?></a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?= nav_active('/dashboard.php') ?>" href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= nav_active('/hallazgos') ?>" href="#" data-bs-toggle="dropdown">Hallazgos</a>
          <ul class="dropdown-menu">
            <?php if (in_array($rol, ['admin','auditor'], true)): ?>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/hallazgos/nuevo.php">Nuevo</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/hallazgos/listado.php">Listado</a></li>
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= nav_active('/reportes.php') ?>" href="<?= BASE_URL ?>/reportes.php">Reportes</a>
        </li>

        <?php if ($rol === 'admin'): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= nav_active('/admin') ?>" href="#" data-bs-toggle="dropdown">Administración</a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/usuarios.php">Usuarios</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/zonas.php">Zonas</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/centros.php">Centros de Costo</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/asignaciones.php">Asignaciones</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/asesores.php">Asesores</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/pdv.php">Puntos de Venta</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/config.php">Configuración</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <!-- Derecha: campana + usuario -->
      <ul class="navbar-nav ms-auto align-items-center">

        <!-- Volver al tablero (Suite) -->
        <li class="nav-item me-2">
          <a class="btn btn-outline-light" href="/suite_operativa/public/core/home.php" title="Volver al tablero">
            <i class="bi bi-grid"></i> Tablero
          </a>
        </li>

        <!-- Campanita -->
        <li class="nav-item dropdown me-2">
          <button class="btn btn-outline-light position-relative" id="notifBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Notificaciones">
            <i class="bi bi-bell"></i>
            <span id="notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"></span>
          </button>
          <div class="dropdown-menu dropdown-menu-end p-0" style="min-width:320px;">
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
              <strong>Notificaciones</strong>
              <a href="#" id="notif-mark" class="small">Marcar todas</a>
            </div>
            <div id="notif-box" style="max-height:300px; overflow:auto;">
              <div class="text-muted px-3 py-2">Cargando…</div>
            </div>
            <div class="border-top text-center">
              <a class="dropdown-item small" href="<?= BASE_URL ?>/notificaciones.php">Ver todas</a>
            </div>
          </div>
        </li>

        <!-- Menú de usuario -->
        <li class="nav-item dropdown">
          <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?= htmlspecialchars($nombreUsuario ?: 'Usuario') ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/mi_perfil.php">Mi perfil</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/cambiar_password.php">Cambiar contraseña</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item" href="<?= BASE_URL ?>/logout.php"
                 data-confirm="¿Deseas cerrar sesión?" data-confirm-type="question" data-confirm-ok="Sí, salir">
                Salir
              </a>
            </li>
          </ul>
        </li>

      </ul>
    </div>
  </div>
</nav>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- JS propios -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script src="<?= BASE_URL ?>/assets/js/alerts.js"></script>

<!-- JS de notificaciones -->
<script>
(function(){
  const BASE  = '<?= rtrim(BASE_URL,"/") ?>';
  const btn   = document.getElementById('notifBtn');
  const badge = document.getElementById('notif-badge');
  const box   = document.getElementById('notif-box');
  const mark  = document.getElementById('notif-mark');

  let unreadCount = 0;

  // ---- helpers ----
  function esc(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  function setBadge(n){
    unreadCount = Math.max(0, n|0);
    badge.textContent = unreadCount > 0 ? (unreadCount>99 ? '99+' : String(unreadCount)) : '';
    badge.style.display = unreadCount>0 ? '' : 'none';
  }

  // contador
  async function pullCount(){
    try{
      const r = await fetch(`${BASE}/api/notificaciones_count.php`, {credentials:'same-origin'});
      const j = await r.json();
      if (r.ok && j && j.ok) setBadge(j.unread||0);
    }catch(e){}
  }

  // lista
  async function loadList(){
    box.innerHTML = '<div class="text-muted px-3 py-2">Cargando…</div>';
    try{
      const r = await fetch(`${BASE}/api/notificaciones_list.php?limit=10`, {credentials:'same-origin'});
      const j = await r.json();
      if (!r.ok || !j || !j.ok) throw 0;
      const items = Array.isArray(j.items)? j.items : [];

      if (!items.length){
        box.innerHTML = '<div class="text-muted px-3 py-2">Sin notificaciones.</div>';
        setBadge(0);
        return;
      }

      const unread = items.filter(it => !it.leido_en);
      const read   = items.filter(it =>  it.leido_en);

      function renderItem(it, isUnread){
        const when = (it.creado_en||'').replace('T',' ').substring(0,19);
        return `
          <a href="${esc(it.url||'#')}" 
             class="dropdown-item notif-item ${isUnread?'unread':''}"
             data-notif-id="${it.id}" data-url="${esc(it.url||'')}">
            ${isUnread?'<span class="dot"></span>':''}
            <div class="title d-inline">${esc(it.titulo||'')}</div>
            ${it.cuerpo?`<div class="text-muted small">${esc(it.cuerpo)}</div>`:''}
            <div class="meta">${esc(when)}</div>
          </a>`;
      }

      let html = '';
      if (unread.length){
        html += `<div class="notif-section-title">No leídas (${unread.length})</div>`;
        html += unread.map(it => renderItem(it, true)).join('');
      }
      if (read.length){
        if (unread.length) html += '<hr class="my-1">';
        html += `<div class="notif-section-title">Leídas</div>`;
        html += read.map(it => renderItem(it, false)).join('');
      }
      box.innerHTML = html;

      // sincroniza badge con las no leídas que acaban de cargarse
      setBadge(unread.length);

    }catch(e){
      box.innerHTML = '<div class="text-danger px-3 py-2">No se pudieron cargar.</div>';
    }
  }

  // marcar una (delegado sobre la lista)
  box.addEventListener('click', async (ev)=>{
    const a = ev.target.closest('[data-notif-id]');
    if (!a) return;
    const id  = a.getAttribute('data-notif-id');
    const url = a.getAttribute('data-url') || '';

    // si ya está leída, simplemente navega
    if (!a.classList.contains('unread')) {
      if (url) { ev.preventDefault(); window.location.href = url; }
      return;
    }

    ev.preventDefault();
    try{
      const body = new URLSearchParams({action:'one', id});
      const r = await fetch(`${BASE}/api/notificaciones_mark.php`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body
      });
      if (r.ok){
        // feedback inmediato
        a.classList.remove('unread');
        const dot = a.querySelector('.dot'); if (dot) dot.remove();
        setBadge(unreadCount - 1);
      }
    }catch(e){}

    if (url) window.location.href = url;
  });

  // marcar todas
  async function markAll(ev){
    ev?.preventDefault();
    try{
      const body = new URLSearchParams({action:'all'});
      const r = await fetch(`${BASE}/api/notificaciones_mark.php`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body
      });
      if (r.ok){
        setBadge(0);
        // apagar visualmente las que estén en el popup
        box.querySelectorAll('.notif-item.unread').forEach(el=>{
          el.classList.remove('unread');
          const dot = el.querySelector('.dot'); if (dot) dot.remove();
        });
      }
    }catch(e){}
  }

  // eventos
  btn?.addEventListener('click', loadList);
  mark?.addEventListener('click', markAll);

  // refresco periódico del contador
  setInterval(pullCount, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pullCount(); });
  pullCount();
})();
</script>
