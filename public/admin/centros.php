<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access'];

require_once __DIR__ . '/../../includes/page_boot.php';
require_roles(['admin']); // solo admin

$pdo = getDB();

/* ========================
   Catálogo de Zonas (para el select)
   ======================== */
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* ========================
   Acciones POST
   ======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  // Crear / Editar
  if ($act === 'save') {
    $id      = (int)($_POST['id'] ?? 0);
    $nombre  = trim($_POST['nombre'] ?? '');
    $zona_id = (int)($_POST['zona_id'] ?? 0);
    $cp      = trim($_POST['codigo_postal'] ?? '');
    $activo  = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '' || $zona_id <= 0) {
      set_flash('danger', 'Nombre y zona son obligatorios.');
      header('Location: ' . BASE_URL . '/admin/centros.php'); exit;
    }

    try {
      if ($id > 0) {
        $up = $pdo->prepare("UPDATE centro_costo SET nombre=?, zona_id=?, codigo_postal=?, activo=? WHERE id=?");
        $up->execute([$nombre, $zona_id, $cp, $activo, $id]);
        set_flash('success', 'Centro de costo actualizado.');
      } else {
        $ins = $pdo->prepare("INSERT INTO centro_costo (nombre, zona_id, codigo_postal, activo) VALUES (?,?,?,?)");
        $ins->execute([$nombre, $zona_id, $cp, $activo]);
        set_flash('success', 'Centro de costo creado.');
      }
    } catch (Throwable $e) {
      set_flash('danger', 'Error: ' . htmlspecialchars($e->getMessage()));
    }
    header('Location: ' . BASE_URL . '/admin/centros.php'); exit;
  }

  // Activar / Desactivar
  if ($act === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("UPDATE centro_costo SET activo = IF(activo=1,0,1) WHERE id=?")->execute([$id]);
      set_flash('success', 'Estado actualizado.');
    }
    header('Location: ' . BASE_URL . '/admin/centros.php'); exit;
  }
}

/* ========================
   Filtros + Paginación
   ======================== */
$q        = trim($_GET['q'] ?? '');
$f_zona   = (int)($_GET['zona_id'] ?? 0);

$perPage  = (int)($_GET['per_page'] ?? 10);
$allowed  = [10,25,50,75,100];
if (!in_array($perPage, $allowed, true)) $perPage = 10;

$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($q !== '') {
  $where[] = "(c.nombre LIKE ? OR c.codigo_postal LIKE ?)";
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $params[] = $like; $params[] = $like;
}
if ($f_zona > 0) {
  $where[] = "c.zona_id = ?";
  $params[] = $f_zona;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Conteo total */
$stc = $pdo->prepare("SELECT COUNT(*) FROM centro_costo c $whereSQL");
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $perPage));

/* Datos paginados (JOIN solo para mostrar nombre de zona) */
$sql = "SELECT c.id, c.nombre, c.codigo_postal, c.zona_id, c.activo, z.nombre AS zona
        FROM centro_costo c
        LEFT JOIN zona z ON z.id = c.zona_id
        $whereSQL
        ORDER BY z.nombre ASC, c.nombre ASC
        LIMIT ? OFFSET ?";
$params2 = array_merge($params, [$perPage, $offset]);

$st = $pdo->prepare($sql);
$st->execute($params2);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Helper URL */
function build_url(array $overrides = []): string {
  $qs = array_merge($_GET, $overrides);
  return BASE_URL . '/admin/centros.php?' . http_build_query($qs);
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Centros de Costo</h1>
    <button class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#modalForm"
            data-id="0"
            data-nombre=""
            data-zona_id="0"
            data-cp=""
            data-activo="1">
      + Nuevo centro
    </button>
  </div>

  <!-- Filtros -->
  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-5">
      <label class="form-label small fw-semibold">Buscar</label>
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nombre o código postal">
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-semibold">Zona</label>
      <select name="zona_id" class="form-select">
        <option value="0">Todas</option>
        <?php foreach ($zonas as $z): ?>
          <option value="<?= (int)$z['id'] ?>" <?= $f_zona===(int)$z['id']?'selected':'' ?>>
            <?= htmlspecialchars($z['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-semibold">Registros por pág.</label>
      <select name="per_page" class="form-select">
        <?php foreach($allowed as $op): ?>
          <option value="<?= $op ?>" <?= ($perPage===$op)?'selected':'' ?>><?= $op ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1 d-grid">
      <button class="btn btn-primary">Aplicar</button>
    </div>
  </form>

  <div class="mb-2 text-muted small">
    Mostrando <?= $total ? ($offset + 1) : 0 ?>–<?= min($offset + $perPage, $total) ?> de <?= $total ?> resultados
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:70px">#</th>
          <th>Zona</th>
          <th>Centro de Costo</th>
          <th>Código Postal</th>
          <th>Activo</th>
          <th style="width:220px" class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Sin resultados.</td></tr>
        <?php else: foreach ($rows as $i => $r): ?>
          <tr>
            <td><?= $offset + $i + 1 ?></td>
            <td><?= htmlspecialchars($r['zona'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
            <td><code><?= htmlspecialchars($r['codigo_postal'] ?? '') ?></code></td>
            <td><?= ((int)$r['activo']===1) ? '<span class="badge bg-success">Sí</span>' : '<span class="badge bg-danger">No</span>' ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#modalForm"
                        data-id="<?= (int)$r['id'] ?>"
                        data-nombre="<?= htmlspecialchars($r['nombre'] ?? '') ?>"
                        data-zona_id="<?= (int)($r['zona_id'] ?? 0) ?>"
                        data-cp="<?= htmlspecialchars($r['codigo_postal'] ?? '') ?>"
                        data-activo="<?= (int)$r['activo'] ?>">
                  Editar
                </button>

                <form method="post" class="d-inline">
                  <input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button
                    class="btn btn-sm btn-outline-warning"
                    data-confirm="¿Cambiar estado de este centro?"
                    data-confirm-type="warning"
                    data-confirm-ok="Sí, cambiar">
                    Activar/Desactivar
                  </button>
                </form>

              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <div class="d-flex justify-content-between align-items-center mt-2">
    <div class="small text-muted">Página <?= $page ?> de <?= $total_pages ?></div>
    <div class="d-flex gap-2">
      <?php if ($page > 1): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= build_url(['page'=>1]) ?>"><i class="bi bi-skip-backward-fill"></i> Primero</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= build_url(['page'=>$page-1]) ?>"><i class="bi bi-caret-left-fill"></i> Anterior</a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-skip-backward-fill"></i> Primero</button>
        <button class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-caret-left-fill"></i> Anterior</button>
      <?php endif; ?>

      <?php if ($page < $total_pages): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= build_url(['page'=>$page+1]) ?>">Siguiente <i class="bi bi-caret-right-fill"></i></a>
        <a class="btn btn-outline-primary btn-sm" href="<?= build_url(['page'=>$total_pages]) ?>">Último <i class="bi bi-skip-forward-fill"></i></a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled>Siguiente <i class="bi bi-caret-right-fill"></i></button>
        <button class="btn btn-outline-secondary btn-sm" disabled>Último <i class="bi bi-skip-forward-fill"></i></button>
      <?php endif; ?>
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
        <h5 class="modal-title" id="modalTitle">Nuevo centro</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Nombre *</label>
          <input class="form-control" name="nombre" id="f_nombre" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Zona *</label>
          <select class="form-select" name="zona_id" id="f_zona" required>
            <option value="0">Seleccione...</option>
            <?php foreach($zonas as $z): ?>
              <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Código Postal</label>
          <input class="form-control" name="codigo_postal" id="f_cp" placeholder="(opcional)">
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
/* Rellena modal (crear/editar) */
document.getElementById('modalForm')?.addEventListener('show.bs.modal', (ev) => {
  const btn = ev.relatedTarget;
  const id      = btn?.getAttribute('data-id') ?? '0';
  const nombre  = btn?.getAttribute('data-nombre') ?? '';
  const zona_id = btn?.getAttribute('data-zona_id') ?? '0';
  const cp      = btn?.getAttribute('data-cp') ?? '';
  const activo  = (btn?.getAttribute('data-activo') ?? '1') === '1';

  document.getElementById('modalTitle').textContent = (id === '0') ? 'Nuevo centro' : 'Editar centro';
  document.getElementById('f_id').value = id;
  document.getElementById('f_nombre').value = nombre;
  document.getElementById('f_zona').value = zona_id;
  document.getElementById('f_cp').value = cp;
  document.getElementById('f_activo').checked = activo;
});

/* Botones con confirm reutilizando el modal genérico del header */
document.querySelectorAll('[data-submit-form]')?.forEach(btn => {
  btn.addEventListener('click', (ev) => {
    ev.preventDefault();
    const form = btn.closest('form');
    const msg  = btn.getAttribute('data-confirm') || '¿Confirmar acción?';
    const typ  = btn.getAttribute('data-confirm-type') || 'question';
    const ok   = btn.getAttribute('data-confirm-ok') || 'Aceptar';

    if (window.showConfirm) {
      // si tienes helper en alerts.js (SweetAlert2)
      window.showConfirm(msg, typ, ok).then(yes => { if (yes) form.submit(); });
    } else {
      if (confirm(msg)) form.submit();
    }
  });
});
</script>

<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
