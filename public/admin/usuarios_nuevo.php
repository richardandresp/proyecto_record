<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

$msg = ''; $err = ''; $tempPass = null;

function gen_temp_password(int $len=10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@$%';
  $out = '';
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
  }
  return $out;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $rol    = trim($_POST['rol'] ?? 'lectura');
  $tel    = trim($_POST['telefono'] ?? '');

  if (!$nombre || !$email || !in_array($rol, $roles, true)) {
    $err = 'Completa nombre, email y rol válido.';
  } else {
    try {
      // Validar email único
      $st = $pdo->prepare("SELECT 1 FROM usuario WHERE email=? LIMIT 1");
      $st->execute([$email]);
      if ($st->fetch()) { throw new Exception('El email ya existe.'); }

      $tempPass = gen_temp_password();
      $hash = password_hash($tempPass, PASSWORD_DEFAULT);

      $ins = $pdo->prepare("
        INSERT INTO usuario (nombre,email,telefono,rol,clave_hash,must_change_password,activo)
        VALUES (?,?,?,?,?,1,1)
      ");
      $ins->execute([$nombre,$email,$tel,$rol,$hash]);

      $msg = "Usuario creado. Entrega esta contraseña temporal al usuario: ".$tempPass;
    } catch (Throwable $e) {
      $err = 'Error al crear: ' . htmlspecialchars($e->getMessage());
      $tempPass = null;
    }
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Nuevo usuario</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Email *</label>
      <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Rol *</label>
      <select name="rol" class="form-select" required>
        <?php foreach ($roles as $r): ?>
          <option value="<?= $r ?>" <?= (($_POST['rol'] ?? '')===$r)?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Crear usuario</button>
      <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>

  <?php if ($tempPass): ?>
    <div class="alert alert-warning mt-3">
      <b>Contraseña temporal:</b> <code><?= htmlspecialchars($tempPass) ?></code><br>
      El usuario deberá <b>cambiarla al iniciar sesión</b>.
    </div>
  <?php endif; ?>
</div>
