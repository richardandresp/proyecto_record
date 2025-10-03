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

// Zonas activas para el select
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll();

if (!$isNew) {
  $st = $pdo->prepare("SELECT id, nombre, zona_id, codigo, activo FROM centro_costo WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $cc = $st->fetch();
  if (!$cc) { http_response_code(404); exit('CC no encontrado'); }
} else {
  $cc = ['id'=>0,'nombre'=>'','zona_id'=>($zonas[0]['id']??0),'codigo'=>null,'activo'=>1];
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre  = trim($_POST['nombre'] ?? '');
  $zona_id = (int)($_POST['zona_id'] ?? 0);
  $codigo  = trim($_POST['codigo'] ?? '');
  $activo  = (int)($_POST['activo'] ?? 1);

  // Normalizar: código vacío -> NULL
  $codigo = ($codigo === '') ? null : $codigo;

  if ($nombre === '')               $err = 'El nombre es obligatorio.';
  elseif ($zona_id <= 0)            $err = 'Debes seleccionar una zona.';

  // Validar nombre único por zona
  if (!$err) {
    $chk = $pdo->prepare("SELECT 1 FROM centro_costo WHERE nombre=? AND zona_id=? AND id<>? LIMIT 1");
    $chk->execute([$nombre, $zona_id, $cc['id']]);
    if ($chk->fetch()) $err = 'Ya existe un CC con ese nombre en la misma zona.';
  }

  // Validar código único si viene informado
  if (!$err && $codigo !== null) {
    $chk2 = $pdo->prepare("SELECT 1 FROM centro_costo WHERE codigo=? AND id<>? LIMIT 1");
    $chk2->execute([$codigo, $cc['id']]);
    if ($chk2->fetch()) $err = 'El código de CC ya está siendo usado por otro registro.';
  }

  if (!$err) {
    try {
      if ($isNew) {
        $ins = $pdo->prepare("INSERT INTO centro_costo (nombre, zona_id, codigo, activo) VALUES (?,?,?,?)");
        $ins->execute([$nombre, $zona_id, $codigo, $activo]);
        set_flash('success', 'CC creado correctamente.');
      } else {
        $up = $pdo->prepare("UPDATE centro_costo SET nombre=?, zona_id=?, codigo=?, activo=? WHERE id=?");
        $up->execute([$nombre, $zona_id, $codigo, $activo, $cc['id']]);
        set_flash('success', 'CC actualizado correctamente.');
      }
      header('Location: ' . BASE_URL . '/admin/centros.php');
      exit;
    } catch (PDOException $e) {
      // Capturar violación de clave única (dup)
      if ((int)$e->getCode() === 23000) {
        $err = 'Duplicado: ya existe un CC con el mismo código.';
      } else {
        $err = 'Error al guardar: ' . $e->getMessage();
      }
    }
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3><?= $isNew ? 'Nuevo CC' : 'Editar CC' ?></h3>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? $cc['nombre']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Zona *</label>
      <select name="zona_id" class="form-select" required>
        <option value="">Seleccione...</option>
        <?php foreach($zonas as $z): ?>
          <option value="<?= $z['id'] ?>" <?= ((int)($cc['zona_id'])===(int)$z['id'])?'selected':'' ?>>
            <?= htmlspecialchars($z['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Código (opcional)</label>
      <input name="codigo" class="form-control" value="<?= htmlspecialchars($_POST['codigo'] ?? ($cc['codigo'] ?? '')) ?>">
      <div class="form-text">Si lo informas, debe ser único.</div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Estado</label>
      <select name="activo" class="form-select">
        <option value="1" <?= ((int)$cc['activo']===1)?'selected':'' ?>>Activo</option>
        <option value="0" <?= ((int)$cc['activo']===0)?'selected':'' ?>>Inactivo</option>
      </select>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary"><?= $isNew ? 'Crear' : 'Guardar' ?></button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/centros.php">Volver</a>
    </div>
  </form>
</div>
