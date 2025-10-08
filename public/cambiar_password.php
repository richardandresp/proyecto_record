<?php
// public/cambiar_password.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/acl_suite.php';  // <- NUEVO
require_once __DIR__ . '/../includes/acl.php';        // permisos finos internos

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if (!module_enabled_for_user($uid, 'auditoria')) {
    render_403_and_exit();
}

// Además, exige permiso del módulo (ejemplo):
require_perm('auditoria.access');
login_required();

$pdo = getDB();
$uid = (int)$_SESSION['usuario_id'];

$msg = ''; $err = '';
$isFirst = isset($_GET['first']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $actual = trim($_POST['actual'] ?? '');
  $nueva1 = trim($_POST['nueva1'] ?? '');
  $nueva2 = trim($_POST['nueva2'] ?? '');

  if (strlen($nueva1) < 8)     $err = 'La nueva contraseña debe tener al menos 8 caracteres.';
  if (!$err && $nueva1 !== $nueva2) $err = 'Las contraseñas nuevas no coinciden.';

  if (!$err) {
    // Verificar actual
    $st = $pdo->prepare("SELECT clave_hash FROM usuario WHERE id=? LIMIT 1");
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) { $err = 'Usuario no encontrado.'; }
    elseif (!password_verify($actual, $row['clave_hash'])) { $err = 'La contraseña actual es incorrecta.'; }
  }

  if (!$err) {
    $hash = password_hash($nueva1, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE usuario SET clave_hash=?, must_change_password=0 WHERE id=?");
    $up->execute([$hash, $uid]);
    $msg = 'Contraseña actualizada. Redirigiendo al dashboard...';
    header('Refresh: 2; URL=' . BASE_URL . '/dashboard.php');
  }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h3>Cambiar contraseña</h3>
  <?php if ($isFirst): ?>
    <div class="alert alert-warning">Debes actualizar tu contraseña antes de continuar.</div>
  <?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-4">
      <label class="form-label">Contraseña actual</label>
      <input type="password" name="actual" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Nueva contraseña</label>
      <input type="password" name="nueva1" class="form-control" required>
      <div class="form-text">Mínimo 8 caracteres.</div>
    </div>
    <div class="col-md-4">
      <label class="form-label">Confirmar nueva</label>
      <input type="password" name="nueva2" class="form-control" required>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Guardar</button>
      <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Cancelar</a>
    </div>
  </form>
</div>
