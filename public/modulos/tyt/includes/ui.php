<?php
require_once __DIR__ . '/env_mod.php';

/** Base URL del módulo detectada dinámicamente */
if (!defined('TYT_BASE')) {
  $script = $_SERVER['SCRIPT_NAME'] ?? '/';
  $pos = strpos($script, '/modulos/tyt/');
  if ($pos !== false) {
    $base = substr($script, 0, $pos + strlen('/modulos/tyt'));
  } else {
    // Fallback: quitar subcarpetas finales tipo /cv, /reportes, /admin, /seguimientos
    $base = rtrim(dirname($script), '/\\');
    $base = preg_replace('#/(cv|reportes|admin|seguimientos)(/.*)?$#', '', $base);
  }
  define('TYT_BASE', $base);
}

function tyt_url(string $rel = ''): string {
  return rtrim(TYT_BASE, '/') . '/' . ltrim($rel, '/');
}

/** ACL helper (si no hay ACL, deja pasar) */
function tyt_can(string $perm): bool {
  return function_exists('user_has_perm') ? user_has_perm($perm) : true;
}

/** Marcar item activo por URL parcial */
function tyt_is_active(array $needles): string {
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  foreach ($needles as $n) {
    if ($n !== '' && strpos($uri, $n) !== false) return 'active';
  }
  return '';
}

/** <head> + apertura body */
function tyt_header(string $title = 'T&T'): void { ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- CDN Bootstrap (rápido). Luego lo puedes cambiar a tus assets del core -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php }

/** Navbar del módulo */
function tyt_nav(): void { ?>
  <nav class="navbar navbar-expand-lg bg-white border-bottom mb-3">
    <div class="container-fluid">
      <a class="navbar-brand" href="<?= tyt_url('') ?>">T&T</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#tytNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div id="tytNav" class="collapse navbar-collapse">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <?php if (tyt_can('tyt.cv.view')): ?>
            <li class="nav-item">
              <a class="nav-link <?= tyt_is_active(['/tyt/cv/']) ?>"
                 href="<?= tyt_url('cv/listar.php') ?>">Hojas de Vida</a>
            </li>
          <?php endif; ?>

          <?php if (tyt_can('tyt.cv.view')): ?>
            <li class="nav-item">
              <a class="nav-link <?= tyt_is_active(['/tyt/seguimientos/']) ?>"
                 href="<?= tyt_url('seguimientos/listar.php') ?>">Seguimientos</a>
            </li>
          <?php endif; ?>

          <?php if (tyt_can('tyt.cv.export')): ?>
            <li class="nav-item">
              <a class="nav-link <?= tyt_is_active(['/tyt/reportes/']) ?>"
                 href="<?= tyt_url('reportes/index.php') ?>">Reportes</a>
            </li>
          <?php endif; ?>

          <?php if (tyt_can('tyt.admin')): ?>
            <li class="nav-item">
              <a class="nav-link <?= tyt_is_active(['/tyt/admin/']) ?>"
                 href="<?= tyt_url('admin/index.php') ?>">Admin</a>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </nav>
<?php }

/** Cierre body/html */
function tyt_footer(): void { ?>
</body>
</html>
<?php }