<?php
// public/admin/asesores.php
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role(['admin','auditor']); // define quién puede administrar

$pdo = get_pdo();

// Acciones POST (guardar / activar-inactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  if ($act === 'save') {
    $id      = (int)($_POST['id'] ?? 0);
    $cedula  = trim($_POST['cedula'] ?? '');
    $nombre  = trim($_POST['nombre'] ?? '');
    $activo  = isset($_POST['activo']) ? 1 : 0;

    if ($cedula === '' || $nombre === '') {
      $_SESSION['flash_err'] = 'Cédula y nombre son obligatorios.';
      header('Location: ' . BASE_URL . '/admin/asesores.php');
      exit;
    }

    try {
      if ($id > 0) {
        // Verifica unicidad de cédula
        $st = $pdo->prepare("SELECT id FROM asesor WHERE cedula=? AND id<>? LIMIT 1");
        $st->execute([$cedula, $id]);
        if ($st->fetchColumn()) throw new RuntimeException('La cédula ya existe en otro asesor.');

        $st = $pdo->prepare("UPDATE asesor SET cedula=?, nombre=?, activo=? WHERE id=?");
        $st->execute([$cedula, $nombre, $activo, $id]);
        $_SESSION['flash_ok'] = 'Asesor actualizado.';
      } else {
        $st = $pdo->prepare("SELECT id FROM asesor WHERE cedula=? LIMIT 1");
        $st->execute([$cedula]);
        if ($st->fetchColumn()) throw new RuntimeException('La cédula ya existe.');

        $st = $pdo->prepare("INSERT INTO asesor (cedula, nombre, activo) VALUES (?,?,?)");
        $st->execute([$cedula, $nombre, $activo]);
        $_SESSION['flash_ok'] = 'Asesor creado.';
      }
    } catch (Throwable $e) {
      $_SESSION['flash_err'] = 'Error: ' . $e->getMessage();
    }
    header('Location: ' . BASE_URL . '/admin/asesores.php');
    exit;
  }

  if ($act === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("UPDATE asesor SET activo = IF(activo=1,0,1) WHERE id=?");
    $st->execute([$id]);
    $_SESSION['flash_ok'] = 'Estado actualizado.';
    header('Location: ' . BASE_URL . '/admin/asesores.php');
    exit;
  }
}

// Búsqueda
$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT id, cedula, nombre, activo FROM asesor";
if ($q !== '') {
  $sql .= " WHERE cedula LIKE ? OR nombre LIKE ?";
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $params = [$like, $like];
}
$sql .= " ORDER BY nombre ASC LIMIT 200";
$rows = $pdo->prepare($sql);
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4">Administrar Asesores</h1>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por cédula o nombre">
    </div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary">Buscar</button>
      <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalForm"
              data-id="0" data-cedula="" data-nombre="" data-activo="1">Nuevo asesor</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Cédula</th><th>Nombre</th><th>Activo</th><th style="width:160px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i=>$r): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><code><?= htmlspecialchars($r['cedula']) ?></code></td>
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= $r['activo'] ? 'Sí' : 'No' ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#modalForm"
                      data-id="<?= (int)$r['id'] ?>"
                      data-cedula="<?= htmlspecialchars($r['cedula']) ?>"
                      data-nombre="<?= htmlspecialchars($r['nombre']) ?>"
                      data-activo="<?= (int)$r['activo'] ?>">Editar</button>

              <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado activo?');">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-warning">Activar/Desactivar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="5" class="text-muted">Sin resultados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Crear/Editar -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Nuevo asesor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Cédula *</label>
          <input class="form-control" name="cedula" id="f_cedula" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Nombre *</label>
          <input class="form-control" name="nombre" id="f_nombre" required>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="f_activo" name="activo" checked>
          <label class="form-check-label" for="f_activo">Activo</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('modalForm')?.addEventListener('show.bs.modal', (ev) => {
  const btn = ev.relatedTarget;
  const id = btn?.getAttribute('data-id') ?? '0';
  const cedula = btn?.getAttribute('data-cedula') ?? '';
  const nombre = btn?.getAttribute('data-nombre') ?? '';
  const activo = (btn?.getAttribute('data-activo') ?? '1') === '1';

  document.getElementById('modalTitle').textContent = id==='0' ? 'Nuevo asesor' : 'Editar asesor';
  document.getElementById('f_id').value = id;
  document.getElementById('f_cedula').value = cedula;
  document.getElementById('f_nombre').value = nombre;
  document.getElementById('f_activo').checked = activo;
});
</script>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
