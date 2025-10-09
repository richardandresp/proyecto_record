<?php
declare(strict_types=1);

/**
 * Editar Zona
 * Requiere módulo Auditoría y rol admin
 */
$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access'];

require_once __DIR__ . '/../../includes/page_boot.php'; // carga flash.php, etc.
require_roles(['admin']);                               // sólo admin

$pdo = getDB();

// --- obtener ID ---
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

// --- cargar zona ---
$st = $pdo->prepare("SELECT id, nombre, activo FROM zona WHERE id=? LIMIT 1");
$st->execute([$id]);
$zona = $st->fetch(PDO::FETCH_ASSOC);
if (!$zona) { http_response_code(404); exit('Zona no encontrada'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $activo = isset($_POST['activo']) ? 1 : 0;

  if ($nombre === '') {
    $err = 'El nombre es obligatorio.';
  } else {
    try {
      $up = $pdo->prepare("UPDATE zona SET nombre=?, activo=? WHERE id=?");
      $up->execute([$nombre, $activo, $zona['id']]);

      set_flash('success', 'Zona actualizada correctamente.');
      header('Location: ' . BASE_URL . '/admin/zonas.php');
      exit;
    } catch (Throwable $e) {
      $err = 'Error al actualizar: ' . htmlspecialchars($e->getMessage());
    }
  }

  // refrescar datos visibles si hubo error y no redirigimos
  $zona['nombre'] = $nombre;
  $zona['activo'] = $activo;
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Editar zona</h3>

  <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?= $err ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($zona['nombre']) ?>">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="activo" id="chkActivo" <?= ((int)$zona['activo']===1)?'checked':'' ?>>
        <label class="form-check-label" for="chkActivo">Activo</label>
      </div>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Guardar cambios</button>
      <a href="<?= BASE_URL ?>/admin/zonas.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>
</div>
