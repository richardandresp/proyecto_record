<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

// Catálogo de zonas para filtro y formulario
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll();

// Filtros
$q = trim($_GET['q'] ?? '');
$zona_id = (int)($_GET['zona_id'] ?? 0);

$allowedPer = [10,25,50,100];
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, $allowedPer, true)) $per_page = 10;

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$where = []; $params = [];
if ($q !== '')        { $where[] = "c.nombre LIKE ?"; $params[] = "%$q%"; }
if ($zona_id > 0)     { $where[] = "c.zona_id = ?";   $params[] = $zona_id; }
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Total
$sqlCount = "SELECT COUNT(*)
             FROM centro_costo c
             JOIN zona z ON z.id=c.zona_id
             $where_sql";
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// Datos
$sql = "SELECT c.id, c.nombre, c.activo, z.nombre AS zona
        FROM centro_costo c
        JOIN zona z ON z.id=c.zona_id
        $where_sql
        ORDER BY z.nombre, c.nombre
        LIMIT ? OFFSET ?";
$paramsData = $params;
$paramsData[] = $per_page;
$paramsData[] = $offset;

$st = $pdo->prepare($sql);
$st->execute($paramsData);
$rows = $st->fetchAll();

function build_url($page, $per_page) {
  $qs = $_GET; $qs['page'] = $page; $qs['per_page'] = $per_page;
  return BASE_URL . '/admin/centros.php?' . http_build_query($qs);
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Centros de Costo (CC)</h3>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/centro_edit.php">+ Nuevo CC</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-4">
      <input class="form-control" name="q" placeholder="Buscar por nombre de CC..." value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-md-4">
      <select name="zona_id" class="form-select">
        <option value="0">Todas las zonas</option>
        <?php foreach($zonas as $z): ?>
          <option value="<?= $z['id'] ?>" <?= ($zona_id===$z['id'])?'selected':'' ?>>
            <?= htmlspecialchars($z['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex gap-2 align-items-center">
      <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0">Mostrar</label>
        <select name="per_page" class="form-select" style="width:auto">
          <?php foreach([10,25,50,100] as $op): ?>
            <option value="<?= $op ?>" <?= ($per_page===$op)?'selected':'' ?>><?= $op ?></option>
          <?php endforeach; ?>
        </select>
        <span>registros</span>
      </div>
      <button class="btn btn-primary">Aplicar</button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/centros.php">Limpiar</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th>CC</th>
          <th>Zona</th>
          <th>Estado</th>
          <th style="width:260px">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center py-4">Sin resultados</td></tr>
      <?php else: ?>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= htmlspecialchars($r['zona']) ?></td>
            <td><?= ((int)$r['activo']===1) ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?></td>
            <td class="d-flex flex-wrap gap-2">
              <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/admin/centro_edit.php?id=<?= (int)$r['id'] ?>">Editar</a>
              <?php if ((int)$r['activo']===1): ?>
                <a class="btn btn-sm btn-outline-danger"
                   href="<?= BASE_URL ?>/admin/centro_toggle.php?id=<?= (int)$r['id'] ?>&to=0"
                   data-confirm="¿Inactivar este CC? Esto puede afectar asignaciones vigentes."
                   data-confirm-type="warning" data-confirm-ok="Sí, inactivar">
                  Inactivar
                </a>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-success"
                   href="<?= BASE_URL ?>/admin/centro_toggle.php?id=<?= (int)$r['id'] ?>&to=1"
                   data-confirm="¿Activar este CC?"
                   data-confirm-type="info" data-confirm-ok="Sí, activar">
                  Activar
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
    <nav aria-label="Paginación">
      <ul class="pagination">
        <li class="page-item <?= ($page<=1)?'disabled':'' ?>"><a class="page-link" href="<?= build_url(1,$per_page) ?>">« Primero</a></li>
        <li class="page-item <?= ($page<=1)?'disabled':'' ?>"><a class="page-link" href="<?= build_url(max(1,$page-1),$per_page) ?>">‹ Anterior</a></li>
        <li class="page-item disabled"><span class="page-link">Página <?= $page ?> de <?= $total_pages ?> (<?= $total ?>)</span></li>
        <li class="page-item <?= ($page>=$total_pages)?'disabled':'' ?>"><a class="page-link" href="<?= build_url(min($total_pages,$page+1),$per_page) ?>">Siguiente ›</a></li>
        <li class="page-item <?= ($page>=$total_pages)?'disabled':'' ?>"><a class="page-link" href="<?= build_url($total_pages,$per_page) ?>">Último »</a></li>
      </ul>
    </nav>
  <?php endif; ?>
</div>
