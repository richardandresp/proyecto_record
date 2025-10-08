<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // añade aquí un permiso fino si lo tienes p.ej. 'auditoria.admin.users'

require_once __DIR__ . '/../../includes/page_boot.php'; // ya expone $pdo, $uid, $rol, BASE_URL y set_flash()/consume_flash()

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

$roles = ['auditor','supervisor','lider','auxiliar','lectura','admin'];

$st = $pdo->prepare("SELECT id,nombre,email,telefono,rol,activo FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { http_response_code(404); exit('Usuario no encontrado'); }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $tel    = trim($_POST['telefono'] ?? '');
  // si el select está disabled para admin, conserva su rol original
  $rolSel = ($u['rol'] === 'admin') ? 'admin' : trim($_POST['rol'] ?? $u['rol']);

  // Validaciones
  if (!$nombre || !$email) {
    $err = 'Nombre y email son obligatorios.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Email inválido.';
  } elseif (!in_array($rolSel, $roles, true)) {
    $err = 'Rol inválido.';
  }

  // Reglas de seguridad
  if (!$err) {
    if ($u['rol'] === 'admin' && $rolSel !== 'admin') {
      $err = 'No se puede cambiar el rol de un admin desde aquí.';
    }
    if (!$err && $id === (int)($_SESSION['usuario_id'] ?? 0) && $u['rol'] === 'admin' && $rolSel !== 'admin') {
      $err = 'No puedes degradarte a ti mismo.';
    }
  }

  // Email único (si cambió)
  if (!$err && $email !== $u['email']) {
    $chk = $pdo->prepare("SELECT 1 FROM usuario WHERE email=? AND id<>? LIMIT 1");
    $chk->execute([$email, $u['id']]);
    if ($chk->fetchColumn()) $err = 'Ese email ya está en uso por otro usuario.';
  }

  if ($err) {
    // Mostrar error en esta misma página
    set_flash('danger', $err);
    // refrescar datos con lo recién enviado (para no perder lo ingresado)
    $u['nombre']   = $nombre;
    $u['email']    = $email;
    $u['telefono'] = $tel;
    $u['rol']      = $rolSel;
  } else {
    // Actualizar y redirigir a la lista con éxito
    $up = $pdo->prepare("UPDATE usuario SET nombre=?, email=?, telefono=?, rol=? WHERE id=?");
    $up->execute([$nombre, $email, $tel, $rolSel, $u['id']]);

    set_flash('success', 'Usuario actualizado correctamente.');
    header('Location: ' . BASE_URL . '/admin/usuarios.php');
    exit;
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Editar usuario</h3>

  <?php if (function_exists('consume_flash')): ?>
    <?php foreach (consume_flash() as $f): ?>
      <div class="alert alert-<?= htmlspecialchars($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($u['nombre'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Email *</label>
      <input name="email" type="email" class="form-control" required value="<?= htmlspecialchars($u['email'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Rol *</label>
      <select name="rol" class="form-select" <?= ($u['rol']==='admin') ? 'disabled' : '' ?>>
        <?php foreach ($roles as $r): ?>
          <option value="<?= $r ?>" <?= (($u['rol'] ?? '')===$r)?'selected':'' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (($u['rol'] ?? '')==='admin'): ?>
        <div class="form-text text-warning">El rol de un admin no puede modificarse aquí.</div>
      <?php endif; ?>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Guardar cambios</button>
      <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>
</div>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
