<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega un permiso fino si luego lo quieres: 'auditoria.admin.zonas'
require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']);

$pdo = getDB();

/* ========= Helpers ========= */
function qp(string $k, $def=null) { return $_GET[$k] ?? $def; }
function postp(string $k, $def=null) { return $_POST[$k] ?? $def; }

/* ========= Acciones (POST) ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = (string)postp('act', '');
  try {
    if ($act === 'save') {
      $id     = (int)postp('id', 0);
      $nombre = trim((string)postp('nombre', ''));
      $activo = (int)!empty($_POST['activo']);

      if ($nombre === '') throw new RuntimeException('El nombre es obligatorio.');

      // Unicidad
      $chk = $pdo->prepare("SELECT id FROM zona WHERE nombre=? AND id<>? LIMIT 1");
      $chk->execute([$nombre, $id]);
      if ($chk->fetch()) throw new RuntimeException('Ya existe una zona con ese nombre.');

      if ($id > 0) {
        $up = $pdo->prepare("UPDATE zona SET nombre=?, activo=? WHERE id=?");
        $up->execute([$nombre, $activo, $id]);
        set_flash('success', 'Zona actualizada correctamente.');
      } else {
        $ins = $pdo->prepare("INSERT INTO zona (nombre, activo) VALUES (?,?)");
        $ins->execute([$nombre, $activo]);
        set_flash('success', 'Zona creada correctamente.');
      }
      header('Location: ' . BASE_URL . '/admin/zonas.php');
      exit;
    }

    if ($act === 'toggle') {
      $id = (int)postp('id', 0);
      if ($id <= 0) throw new RuntimeException('ID inválido');

      $pdo->prepare("UPDATE zona SET activo = IF(activo=1,0,1) WHERE id=?")->execute([$id]);
      set_flash('success', 'Estado actualizado.');
      header('Location: ' . BASE_URL . '/admin/zonas.php?' . http_build_query(['page'=>(int)qp('page',1),'per_page'=>(int)qp('per_page',25),'q'=>qp('q','')]));
      exit;
    }

    throw new RuntimeException('Acción inválida.');
  } catch (Throwable $e) {
    set_flash('danger', 'Error: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/admin/zonas.php');
    exit;
  }
}

/* ========= Listado con búsqueda + paginación ========= */
$q        = trim((string)qp('q', ''));
$per_page = (int)qp('per_page', 25);
$allowed  = [10,25,50,75,100];
if (!in_array($per_page, $allowed, true)) $per_page = 25;
$page     = max(1, (int)qp('page', 1));
$offset   = ($page - 1) * $per_page;

$where = [];
$args  = [];
if ($q !== '') {
  $where[] = "nombre LIKE ?";
  $like = '%'.str_replace(['%','_'], ['\\%','\\_'], $q).'%';
  $args[] = $like;
}
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM zona $where_sql")
                  ->execute($args) ?: 0;
$stc = $pdo->prepare("SELECT COUNT(*) FROM zona $where_sql");
$stc->execute($args);
$total = (int)$stc->fetchColumn();

$sql = "SELECT id, nombre, activo, creado_en
        FROM zona
        $where_sql
        ORDER BY nombre ASC
        LIMIT ? OFFSET ?";
$args_data = $args;
$args_data[] = $per_page;
$args_data[] = $offset;

$st = $pdo->prepare($sql);
$st->execute($args_data);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$total_pages = max(1, (int)ceil($total / $per_page));

/* ========= Render ========= */
include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Zonas</h3>
    <button class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#modalForm"
            data-id="0"
            data-nombre=""
            data-activo="1">
      + Nueva zona
    </button>
  </div>

  <!-- Flashes como toasts ya los maneja header/alerts.js. Por si acaso imprimimos fallback -->
  <?php if (!empty($__FLASHES)): ?>
    <?php foreach ($__FLASHES as $f): ?>
      <div class="alert alert-<?= htmlspecialchars($f['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-6">
      <label class="form-label small fw-bold">Buscar</label>
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nombre de la zona">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Registros/pág.</label>
      <select class="form-select" name="per_page">
        <?php foreach ($allowed as $n): ?>
          <option value="<?= $n ?>" <?= $per_page===$n?'selected':'' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button class="btn btn-primary">Aplicar</button>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/zonas.php">Limpiar</a>
    </div>
  </form>

  <div class="mb-2 text-muted">
    Mostrando <?= $total ? ($offset+1) : 0 ?>–<?= min($offset+$per_page, $total) ?> de <?= $total ?> zonas
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:80px">#ID</th>
          <th>Nombre</th>
          <th style="width:120px">Activo</th>
          <th style="width:220px" class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Sin resultados.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
          <td>
            <?php if ((int)($r['activo'] ?? 0) === 1): ?>
              <span class="badge bg-success">Sí</span>
            <?php else: ?>
              <span class="badge bg-danger">No</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#modalForm"
                    data-id="<?= (int)$r['id'] ?>"
                    data-nombre="<?= htmlspecialchars($r['nombre'] ?? '') ?>"
                    data-activo="<?= (int)($r['activo'] ?? 0) ?>">
              Editar
            </button>

            <form method="post" class="d-inline-block">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm <?= ((int)$r['activo']===1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                      data-confirm="<?= ((int)$r['activo']===1) ? '¿Inactivar esta zona?' : '¿Activar esta zona?' ?>"
                      data-confirm-type="<?= ((int)$r['activo']===1) ? 'warning' : 'info' ?>"
                      data-confirm-ok="Sí, continuar">
                <?= ((int)$r['activo']===1) ? 'Inactivar' : 'Activar' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <div class="d-flex justify-content-between align-items-center">
    <div class="text-muted">Página <?= $page ?> de <?= $total_pages ?></div>
    <div class="d-flex gap-2">
      <?php
        $base = BASE_URL . '/admin/zonas.php';
        $qs   = function($p) use($q,$per_page){ return http_build_query(['q'=>$q,'per_page'=>$per_page,'page'=>$p]); };
      ?>
      <a class="btn btn-outline-primary btn-sm <?= ($page<=1?'disabled':'') ?>" href="<?= $page>1 ? ($base.'?'.$qs(1)) : '#' ?>">
        <i class="bi bi-skip-backward-fill"></i> Primero
      </a>
      <a class="btn btn-outline-primary btn-sm <?= ($page<=1?'disabled':'') ?>" href="<?= $page>1 ? ($base.'?'.$qs($page-1)) : '#' ?>">
        <i class="bi bi-caret-left-fill"></i> Anterior
      </a>
      <a class="btn btn-outline-primary btn-sm <?= ($page>=$total_pages?'disabled':'') ?>" href="<?= $page<$total_pages ? ($base.'?'.$qs($page+1)) : '#' ?>">
        Siguiente <i class="bi bi-caret-right-fill"></i>
      </a>
      <a class="btn btn-outline-primary btn-sm <?= ($page>=$total_pages?'disabled':'') ?>" href="<?= $page<$total_pages ? ($base.'?'.$qs($total_pages)) : '#' ?>">
        Último <i class="bi bi-skip-forward-fill"></i>
      </a>
    </div>
  </div>
</div>

<!-- Modal Crear/Editar -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Nueva zona</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Nombre *</label>
          <input class="form-control" name="nombre" id="f_nombre" required maxlength="120">
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
  const btn    = ev.relatedTarget;
  const id     = btn?.getAttribute('data-id') ?? '0';
  const nombre = btn?.getAttribute('data-nombre') ?? '';
  const activo = (btn?.getAttribute('data-activo') ?? '1') === '1';

  document.getElementById('modalTitle').textContent = id==='0' ? 'Nueva zona' : 'Editar zona';
  document.getElementById('f_id').value   = id;
  document.getElementById('f_nombre').value = nombre;
  document.getElementById('f_activo').checked = activo;
});
</script>

<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
