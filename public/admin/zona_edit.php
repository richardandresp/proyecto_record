<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

$id = (int)($_GET['id'] ?? 0);
$isNew = ($id === 0);

if (!$isNew) {
  $st = $pdo->prepare("SELECT id,nombre,activo FROM zona WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $z = $st->fetch();
  if (!$z) { http_response_code(404); exit('Zona no encontrada'); }
} else {
  $z = ['id'=>0,'nombre'=>'','activo'=>1];
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $activo = (int)($_POST['activo'] ?? 1);

  if ($nombre === '') {
    $err = 'El nombre es obligatorio.';
  } else {
    // nombre único
    $chk = $pdo->prepare("SELECT 1 FROM zona WHERE nombre=? AND id<>? LIMIT 1");
    $chk->execute([$nombre, $z['id']]);
    if ($chk->fetch()) $err = 'Ya existe una zona con ese nombre.';
  }

  if (!$err) {
    if ($isNew) {
      $ins = $pdo->prepare("INSERT INTO zona (nombre, activo) VALUES (?, ?)");
      $ins->execute([$nombre, $activo]);
      set_flash('success', 'Zona creada correctamente.');
    } else {
      $up = $pdo->prepare("UPDATE zona SET nombre=?, activo=? WHERE id=?");
      $up->execute([$nombre, $activo, $z['id']]);
      set_flash('success', 'Zona actualizada correctamente.');
    }
    header('Location: ' . BASE_URL . '/admin/zonas.php');
    exit;
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3><?= $isNew ? 'Nueva zona' : 'Editar zona' ?></h3>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? $z['nombre']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Estado</label>
      <select name="activo" class="form-select">
        <option value="1" <?= ((int)($z['activo'])===1)?'selected':'' ?>>Activa</option>
        <option value="0" <?= ((int)($z['activo'])===0)?'selected':'' ?>>Inactiva</option>
      </select>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary"><?= $isNew ? 'Crear' : 'Guardar' ?></button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/zonas.php">Volver</a>
    </div>
  </form>
</div>
