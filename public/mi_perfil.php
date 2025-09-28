<?php
// public/mi_perfil.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
login_required();

$pdo = getDB();
$uid = (int)$_SESSION['usuario_id'];

$st = $pdo->prepare("SELECT id,nombre,email,telefono FROM usuario WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('Usuario no encontrado'); }

$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $tel    = trim($_POST['telefono'] ?? '');

  if (!$nombre || !$email) $err = 'Nombre y email son obligatorios.';
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Email inválido.';

  // Si cambia email, validar único
  if (!$err && $email !== $u['email']) {
    $chk = $pdo->prepare("SELECT 1 FROM usuario WHERE email=? AND id<>? LIMIT 1");
    $chk->execute([$email, $u['id']]);
    if ($chk->fetch()) $err = 'Ese email ya está en uso.';
  }

  if (!$err) {
    $up = $pdo->prepare("UPDATE usuario SET nombre=?, email=?, telefono=? WHERE id=?");
    $up->execute([$nombre,$email,$tel,$u['id']]);
    $msg = 'Perfil actualizado.';
    // refrescar
    $st->execute([$uid]);
    $u = $st->fetch();
    $_SESSION['nombre'] = $u['nombre']; // reflejar en header
  }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h3>Mi perfil</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($u['nombre']) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Email *</label>
      <input name="email" type="email" class="form-control" required value="<?= htmlspecialchars($u['email']) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= htmlspecialchars($u['telefono']) ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Guardar</button>
      <a href="<?= BASE_URL ?>/cambiar_password.php" class="btn btn-outline-secondary">Cambiar contraseña</a>
    </div>
  </form>
</div>
