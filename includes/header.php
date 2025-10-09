<?php
// includes/header.php — Rutas corregidas y badge de notificaciones estable al primer paint
$rootDir = dirname(__DIR__); // Sube un nivel desde includes/

require_once $rootDir . '/includes/session_boot.php';
require_once $rootDir . '/includes/env.php';
require_once $rootDir . '/includes/acl.php';
require_once $rootDir . '/includes/flash.php';
require_once $rootDir . '/includes/notify.php'; // <-- necesario para notif_unread_count()

// --- DIAGNÓSTICO RÁPIDO (coméntalo cuando acabes) ---
if (function_exists('user_has_perm')) {
  $__checks = [
    'auditoria.access',
    'auditoria.hallazgo.list',
    'auditoria.hallazgo.view',
    'auditoria.reportes.view',
  ];
  $__diag = [];
  foreach ($__checks as $k) { $__diag[$k] = user_has_perm($k) ? 1 : 0; }

  // 1) ¿Tiene el módulo activo?
  try {
    $pdoDiag = function_exists('get_pdo') ? get_pdo() : (function_exists('getDB') ? getDB() : null);
    $uidDiag = (int)($_SESSION['usuario_id'] ?? 0);
    $mid = $pdoDiag?->query("SELECT id FROM modulo WHERE clave='auditoria'")->fetchColumn();
    $um  = $pdoDiag && $uidDiag && $mid
         ? (int)$pdoDiag->query("SELECT COALESCE(MAX(activo),0) FROM usuario_modulo WHERE usuario_id=$uidDiag AND modulo_id=$mid")->fetchColumn()
         : -1;
    $__diag['_mod_activo'] = $um; // 1 = activo, 0 = no, -1 = sin chequeo
  } catch (Throwable $e) {
    $__diag['_mod_activo'] = -1;
  }

  // imprime como comentario HTML (lo verás con "Ver código fuente" del navegador)
  echo "\n<!-- PERM-DIAG ".htmlspecialchars(json_encode($__diag))." -->\n";
}
// --- FIN DIAGNÓSTICO ---

// Fallbacks por si no existen helpers
if (!function_exists('user_has_perm')) {
  error_log("Error: función user_has_perm no definida en " . __FILE__);
  function user_has_perm($perm) { return false; }
}
if (!function_exists('user_has_module')) {
  function user_has_module(string $modClave): bool { return user_has_perm($modClave . '.access'); }
}

$rol           = $_SESSION['rol']    ?? 'lectura';
$nombreUsuario = $_SESSION['nombre'] ?? '';

// Contador inicial de notificaciones (para que el badge salga desde el primer render)
$uid = (int)($_SESSION['usuario_id'] ?? 0);
try {
  $__unread_count = $uid ? notif_unread_count($uid) : 0;
} catch (Throwable $e) {
  $__unread_count = 0;
}

function nav_active(string $relativePath): string {
  $current = $_SERVER['REQUEST_URI'] ?? '';
  $target  = rtrim(BASE_URL, '/') . $relativePath;
  return (strpos($current, $target) !== false) ? 'active' : '';
}

// helper de rol global para el menú Administración
$is_admin_or_auditor = in_array($rol, ['admin','auditor'], true);

// ===== permisos de módulo auditoria =====
$can_mod_auditoria = user_has_module('auditoria');
$can_h_list   = user_has_perm('auditoria.hallazgo.list') || user_has_perm('auditoria.hallazgo.view');
$can_h_new    = user_has_perm('auditoria.hallazgo.create');
$can_reports  = user_has_perm('auditoria.reportes.view');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?? 'Sistema' ?></title>
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
</head>
<style>
  /* ===== User menu moderno ===== */
  .user-menu .dropdown-header{
    padding:.75rem 1rem .5rem 1rem;
    background:rgba(255,255,255,.03);
    border-bottom:1px solid rgba(255,255,255,.08);
  }
  .user-menu .avatar{
    width:36px;height:36px;border-radius:50%;
    background:linear-gradient(135deg,#1e88e5,#42a5f5);
    display:inline-flex;align-items:center;justify-content:center;
    color:#fff;font-weight:700;
    box-shadow:0 0 0 2px rgba(255,255,255,.25) inset;
  }
  .user-menu .name{font-weight:600}
  .user-menu .role-badge{
    font-size:.75rem;padding:.1rem .4rem;border-radius:.35rem;
    color:#0dcaf0;background:rgba(13,202,240,.15);border:1px solid rgba(13,202,240,.25)
  }
  .user-menu .dropdown-item{
    display:flex;align-items:center;gap:.5rem
  }
  .user-menu .dropdown-item .bi{
    opacity:.8
  }
  .user-menu .dropdown-item:hover{
    background:rgba(255,255,255,.06)
  }
</style>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 sticky-top">
    <div class="container">
      <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard.php"><?= APP_NAME ?? 'Sistema' ?></a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div id="nav" class="collapse navbar-collapse">
        <ul class="navbar-nav me-auto">
          <li class="nav-item">
            <a class="nav-link <?= nav_active('/dashboard.php') ?>" href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
          </li>

          <!-- Hallazgos -->
          <?php if ($can_mod_auditoria && ($can_h_list || $can_h_new)): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle <?= nav_active('/hallazgos') ?>" href="#" data-bs-toggle="dropdown">Hallazgos</a>
              <ul class="dropdown-menu">
                <?php if ($can_h_new): ?>
                  <li><a class="dropdown-item" href="<?= BASE_URL ?>/hallazgos/nuevo.php">Nuevo</a></li>
                <?php endif; ?>
                <?php if ($can_h_list): ?>
                  <li><a class="dropdown-item" href="<?= BASE_URL ?>/hallazgos/listado.php">Listado</a></li>
                <?php endif; ?>
              </ul>
            </li>
          <?php endif; ?>

          <!-- Reportes -->
          <?php if ($can_mod_auditoria && $can_reports): ?>
            <li class="nav-item">
              <a class="nav-link <?= nav_active('/reportes.php') ?>" href="<?= BASE_URL ?>/reportes.php">Reportes</a>
            </li>
          <?php endif; ?>

          <!-- Administración (solo admin/auditor) -->
          <?php if ($is_admin_or_auditor): ?>
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
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/permisos.php">Permisos y Accesos</a></li>
              </ul>
            </li>
          <?php endif; ?>
        </ul>

        <!-- Derecha: campana + usuario -->
        <ul class="navbar-nav ms-auto align-items-center">

          <!-- Volver al tablero (Suite) -->
          <li class="nav-item me-2">
            <a class="btn btn-outline-light" href="/suite_operativa/public/core/home.php" title="Volver al tablero">
              <i class="bi bi-grid"></i> Suite Operativa
            </a>
          </li>

          <!-- Menú de usuario -->
<li class="nav-item dropdown">
  <button class="btn btn-outline-light d-flex align-items-center gap-2" type="button"
          data-bs-toggle="dropdown" aria-expanded="false">
    <i class="bi bi-person-circle"></i>
    <span><?= htmlspecialchars($nombreUsuario ?: 'Usuario') ?></span>
    <i class="bi bi-caret-down-fill small opacity-75 ms-1"></i>
  </button>

  <div class="dropdown-menu dropdown-menu-end text-light bg-dark border user-menu" style="min-width:260px;">
    <div class="dropdown-header d-flex align-items-center gap-3">
      <div class="avatar"><?= strtoupper(substr($nombreUsuario ?: 'U',0,1)) ?></div>
      <div>
        <div class="name"><?= htmlspecialchars($nombreUsuario ?: 'Usuario') ?></div>
        <div class="role-badge text-uppercase"><?= htmlspecialchars($rol) ?></div>
      </div>
    </div>

    <a class="dropdown-item text-light" href="<?= BASE_URL ?>/mi_perfil.php">
      <i class="bi bi-person"></i> Mi perfil
    </a>
    <a class="dropdown-item text-light" href="<?= BASE_URL ?>/cambiar_password.php">
      <i class="bi bi-key"></i> Cambiar contraseña
    </a>

    <div class="dropdown-divider"></div>

    <!-- Salir: hace logout en esta app y redirige a la Suite -->
    <a class="dropdown-item text-light" href="#" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i> Salir
    </a>
  </div>
</li>

            <!-- Campanita -->
          <li class="nav-item dropdown me-2">
            <button class="btn btn-outline-light position-relative" id="notifBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Notificaciones">
              <i class="bi bi-bell"></i>
              <span
                id="notif-badge"
                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                style="<?= $__unread_count ? '' : 'display:none;' ?>"
              ><?= $__unread_count ? ($__unread_count > 99 ? '99+' : (string)$__unread_count) : '' ?></span>
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

    <script>
      // Logout suave: primero cierra sesión en esta app y luego te lleva a la suite.
      (function(){
        const link = document.getElementById('logoutLink');
        if(!link) return;

        const LOGOUT_URL = '<?= rtrim(BASE_URL,"/") ?>/logout.php';
        const SUITE_HOME = '/suite_operativa/public/core/home.php'; // <- destino final

        link.addEventListener('click', async (ev) => {
          ev.preventDefault();

          // Si tienes SweetAlert en alerts.js puedes usar tu confirm genérico:
          if (window.Swal) {
            const r = await Swal.fire({
              icon: 'question',
              title: '¿Deseas cerrar sesión?',
              showCancelButton: true,
              confirmButtonText: 'Sí, salir',
              cancelButtonText: 'Cancelar'
            });
            if (!r.isConfirmed) return;
          } else {
            if (!confirm('¿Deseas cerrar sesión?')) return;
          }

          try {
            // Intenta cerrar sesión en esta app (misma sesión PHP)
            await fetch(LOGOUT_URL, {credentials:'same-origin'});
          } catch(_) { /* no importa, seguimos */ }

          // Redirige a la suite
          window.location.href = SUITE_HOME;
        });
      })();
    </script>

            </ul>
          </li>

        </ul>
      </div>
    </div>
  </nav>

  <?php
  // Recoge flashes de sesión una sola vez, quedarán en $__FLASHES
  $__FLASHES = function_exists('consume_flash') ? consume_flash() : [];
  ?>
  <!-- Toast stack -->
  <div id="toast-stack" class="position-fixed top-0 end-0 p-3" style="z-index: 1080;"></div>

  <!-- Confirm Modal (genérico) -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-body p-4">
          <div class="d-flex align-items-start gap-3">
            <div class="flex-shrink-0">
              <div class="rounded-circle d-flex align-items-center justify-content-center"
                   style="width:48px;height:48px;background:#fff4e5;border:1px solid #ffe4c2;">
                <span class="fs-4" style="color:#ff9800;">!</span>
              </div>
            </div>
            <div class="flex-grow-1">
              <h5 class="mb-1 fw-semibold" id="confirmTitle">Confirmar</h5>
              <div class="text-muted" id="confirmMessage">¿Estás seguro?</div>
            </div>
          </div>
          <div class="d-flex justify-content-end gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <a id="confirmOkBtn" class="btn btn-primary" href="#">Aceptar</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS (bundle) -->
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
  let originalTitle = document.title;
  let audioPing;

  // — util —
  function esc(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

  function setBadge(n){
    const prev = unreadCount;
    unreadCount = Math.max(0, n|0);
    // numero
    badge.textContent = unreadCount > 0 ? (unreadCount>99 ? '99+' : String(unreadCount)) : '';
    badge.style.display = unreadCount>0 ? '' : 'none';
    // título
    document.title = unreadCount>0 ? `(${unreadCount}) ${originalTitle}` : originalTitle;
    // ping opcional cuando sube
    if (unreadCount > prev) {
      try {
        if (!audioPing) {
          audioPing = new Audio('<?= BASE_URL ?>/assets/snd/ping.mp3'); // pon el archivo si quieres sonido
        }
        // audioPing.play().catch(()=>{});
      } catch(e){}
    }
  }

  async function pullCount(){
    try{
      const r = await fetch(`${BASE}/api/notificaciones_count.php`, {credentials:'same-origin'});
      const j = await r.json();
      if (r.ok && j && j.ok) setBadge(j.unread||0);
    }catch(e){
      // opcional: silenciar
    }
  }

  async function loadList(){
    box.innerHTML = '<div class="text-muted px-3 py-2">Cargando…</div>';
    try{
      const r = await fetch(`${BASE}/api/notificaciones_list.php?limit=10`, {credentials:'same-origin'});
      const j = await r.json();
      if (!r.ok || !j || !j.ok) throw 0;
      const items = Array.isArray(j.items)? j.items : [];
      if (!items.length){ box.innerHTML = '<div class="text-muted px-3 py-2">Sin notificaciones.</div>'; setBadge(0); return; }

      const unread = items.filter(it => !it.leido_en);
      const read   = items.filter(it =>  it.leido_en);

      function item(it, isUnread){
        const when = (it.creado_en||'').toString().replace('T',' ').substring(0,19);
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
      if (unread.length){ html += `<div class="notif-section-title">No leídas (${unread.length})</div>` + unread.map(n => item(n,true)).join(''); }
      if (read.length){ if (unread.length) html += '<hr class="my-1">'; html += `<div class="notif-section-title">Leídas</div>` + read.map(n => item(n,false)).join(''); }
      box.innerHTML = html;
      setBadge(unread.length);
    } catch(e){
      box.innerHTML = '<div class="text-danger px-3 py-2">No se pudieron cargar.</div>';
    }
  }

  // Exponer para usar desde otras páginas (refresh on demand)
  window.notifPullCount = pullCount;
  window.notifLoadList  = loadList;

  // Interacciones
  box.addEventListener('click', async (ev)=>{
    const a = ev.target.closest('[data-notif-id]');
    if (!a) return;
    const id  = a.getAttribute('data-notif-id');
    const url = a.getAttribute('data-url') || '';
    if (!a.classList.contains('unread')) { if (url){ ev.preventDefault(); location.href=url; } return; }

    ev.preventDefault();
    try{
      const body = new URLSearchParams({action:'one', id});
      const r = await fetch(`${BASE}/api/notificaciones_mark.php`, {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'}, body
      });
      if (r.ok){
        a.classList.remove('unread'); a.querySelector('.dot')?.remove();
        setBadge(unreadCount - 1);
      }
    }catch(e){}
    if (url) location.href = url;
  });

  async function markAll(ev){
    ev?.preventDefault();
    try{
      const body = new URLSearchParams({action:'all'});
      const r = await fetch(`${BASE}/api/notificaciones_mark.php`, {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'}, body
      });
      if (r.ok){
        setBadge(0);
        box.querySelectorAll('.notif-item.unread').forEach(el=>{ el.classList.remove('unread'); el.querySelector('.dot')?.remove(); });
      }
    }catch(e){}
  }

  btn?.addEventListener('click', loadList);
  mark?.addEventListener('click', markAll);

  // Poll: más rápido cuando la pestaña está activa
  let _timer = null;
  function _armarTimer() {
    clearInterval(_timer);
    const ms = document.hidden ? 20000 : 5000;
    _timer = setInterval(pullCount, ms);
  }
  document.addEventListener('visibilitychange', _armarTimer);
  _armarTimer();

  // Primer fetch
  pullCount();

  // Refresco on-demand desde otras páginas
  window.addEventListener('message', (e) => {
    if (e?.data === 'notif:refresh') pullCount();
  });
})();
</script>

