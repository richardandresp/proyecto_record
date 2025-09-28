<?php
// public/admin/usuario_editar.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
if (($_SESSION['rol'] ?? 'lectura') !== 'admin') { http_response_code(403); exit('Sin permiso'); }

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

$roles = ['auditor','supervisor','lider','auxiliar','lectura','admin']; // si no quieres permitir crear más admins, quita 'admin'

$st = $pdo->prepare("SELECT id,nombre,email,telefono,rol,activo FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('Usuario no encontrado'); }

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $tel    = trim($_POST['telefono'] ?? '');
  $rolSel = trim($_POST['rol'] ?? $u['rol']);

  if (!$nombre || !$email) {
    $err = 'Nombre y email son obligatorios.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Email inválido.';
  } elseif (!in_array($rolSel, $roles, true)) {
    $err = 'Rol inválido.';
  }

  // Reglas de seguridad:
  // - No cambiar el rol de un admin si no quieres permitirlo (opcional)
  // - No permitir que un admin se quite a sí mismo el rol admin
  if (!$err) {
    if ($u['rol'] === 'admin' && $rolSel !== 'admin') {
      // evita degradar admins existentes (puedes permitirlo si quieres)
      $err = 'No se puede cambiar el rol de un admin desde aquí.';
    }
    if (!$err && $id === (int)$_SESSION['usuario_id'] && $rolSel !== 'admin') {
      $err = 'No puedes cambiar tu propio rol.';
    }
  }

  // Validar email único (si cambió)
  if (!$err && $email !== $u['email']) {
    $chk = $pdo->prepare("SELECT 1 FROM usuario WHERE email=? AND id<>? LIMIT 1");
    $chk->execute([$email, $u['id']]);
    if ($chk->fetch()) $err = 'Ese email ya está en uso por otro usuario.';
  }

  if (!$err) {
    $up = $pdo->prepare("UPDATE usuario SET nombre=?, email=?, telefono=?, rol=? WHERE id=?");
    $up->execute([$nombre, $email, $tel, $rolSel, $u['id']]);
    $msg = 'Usuario actualizado correctamente.';
    // refrescar datos
    $st->execute([$id]);
    $u = $st->fetch();
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Editar usuario</h3>
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
    <div class="col-md-4">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= htmlspecialchars($u['telefono']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Rol *</label>
      <select name="rol" class="form-select" required <?= ($u['rol']==='admin') ? 'disabled' : '' ?>>
        <?php foreach ($roles as $r): ?>
          <option value="<?= $r ?>" <?= ($u['rol']===$r)?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($u['rol']==='admin'): ?>
        <div class="form-text text-warning">El rol de un admin no puede modificarse aquí.</div>
      <?php endif; ?>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Guardar cambios</button>
      <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>
</div>
