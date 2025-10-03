<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/hallazgo_repo.php';

login_required();
require_roles(['admin','auditor','supervisor','lider','auxiliar']); // lectura para todos estos

$pdo = getDB();

$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

// ---------- helpers ----------
function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch();
  } catch (Throwable $e) {
    return false;
  }
}
function badgeEstado(string $estado): string {
  $map = [
    'pendiente'        => ['Pendiente', 'bg-warning text-dark'],
    'vencido'          => ['Vencido', 'bg-danger'],
    'respondido_lider' => ['Respondido (Líder)', 'bg-success'],
    'respondido_admin' => ['Respondido (Admin)', 'bg-primary'],
  ];
  [$txt, $cls] = $map[$estado] ?? [$estado, 'bg-secondary'];
  return "<span class='badge {$cls}'>$txt</span>";
}
function money_cell($v): string {
  if ($v === null || $v === '') return '';
  $n = (float)$v;
  $cls = $n < 0 ? 'amount-negative' : ($n > 0 ? 'amount-positive' : '');
  return "<span class='amount-cell {$cls}'>$" . number_format($n, 2, ',', '.') . "</span>";
}
function build_url(array $override = []): string {
  $qs = array_merge($_GET, $override);
  return BASE_URL . '/hallazgos/listado.php?' . http_build_query($qs);
}

// ---------- marcar vencidos por SLA (opcional, simple; sin notificaciones aquí) ----------
$pdo->exec("UPDATE hallazgo 
            SET estado='vencido', actualizado_en=NOW()
            WHERE estado='pendiente' AND NOW() > fecha_limite");

// ---------- catálogos ----------
$zonas   = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$centros = $pdo->query("SELECT id, nombre, zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// ---------- filtros ----------
$hoy     = (new DateTime())->format('Y-m-d');
$hace30  = (new DateTime('-30 days'))->format('Y-m-d');

$desde    = $_GET['desde']    ?? $hace30;
$hasta    = $_GET['hasta']    ?? $hoy;
$f_zona   = (int)($_GET['zona_id']   ?? 0);

// CLAVE: detectar si centro_id vino en la URL para que por defecto sea "Todos"
$hasCentro = array_key_exists('centro_id', $_GET);
$f_centro  = $hasCentro ? (int)$_GET['centro_id'] : 0;

$f_estado = $_GET['estado']   ?? '';

// Filtros extendidos para Raspas / Faltante / Sobrante
// Modo: 0=Todos, 1=Con (>0), 2=Sin (=0), 3=Personalizado (op + val)
$f_raspas_mode = (int)($_GET['raspas_mode'] ?? 0);
$f_raspas_op   = $_GET['raspas_op'] ?? '>';
$f_raspas_val  = $_GET['raspas_val'] ?? '';

$f_falt_mode = (int)($_GET['falt_mode'] ?? 0);
$f_falt_op   = $_GET['falt_op'] ?? '>';
$f_falt_val  = $_GET['falt_val'] ?? '';

$f_sobr_mode = (int)($_GET['sobr_mode'] ?? 0);
$f_sobr_op   = $_GET['sobr_op'] ?? '>';
$f_sobr_val  = $_GET['sobr_val'] ?? '';

// Sanitiza operador
$validOps = ['=','>','>=','<','<=','<>'];
if (!in_array($f_raspas_op, $validOps, true)) $f_raspas_op = '>';
if (!in_array($f_falt_op,   $validOps, true)) $f_falt_op   = '>';
if (!in_array($f_sobr_op,   $validOps, true)) $f_sobr_op   = '>';

// Normaliza montos con coma/punto
$norm = static function($s) {
  if ($s === '' || $s === null) return null;
  $s = preg_replace('/[^\d\-,.]/', '', (string)$s);
  if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '.', $s);
  }
  return (float)$s;
};

// ---------- paginación ----------
$allowedPer = [10,25,50,100];
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, $allowedPer, true)) $per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// ---------- WHERE base ----------
$where   = [];
$params  = [];

// fechas inclusivas por día
$where[]  = "DATE(h.fecha) >= ? AND DATE(h.fecha) <= ?";
$params[] = $desde;
$params[] = $hasta;

if ($f_zona)   { $where[] = "h.zona_id = ?";   $params[] = $f_zona; }
if ($f_centro) { $where[] = "h.centro_id = ?"; $params[] = $f_centro; }
if ($f_estado) { $where[] = "h.estado = ?";    $params[] = $f_estado; }

// --- Raspas (usa COALESCE para incluir NULL como 0) ---
if ($f_raspas_mode === 1) {
  $where[] = "COALESCE(h.raspas_faltantes,0) > 0";
} elseif ($f_raspas_mode === 2) {
  $where[] = "COALESCE(h.raspas_faltantes,0) = 0";
} elseif ($f_raspas_mode === 3 && $f_raspas_val !== '') {
  $where[]  = "COALESCE(h.raspas_faltantes,0) {$f_raspas_op} ?";
  $params[] = (int)$f_raspas_val;
}

// --- Faltante $ ---
if ($f_falt_mode === 1) {
  $where[] = "COALESCE(h.faltante_dinero,0) > 0";
} elseif ($f_falt_mode === 2) {
  $where[] = "COALESCE(h.faltante_dinero,0) = 0";
} elseif ($f_falt_mode === 3 && $f_falt_val !== '') {
  $where[]  = "COALESCE(h.faltante_dinero,0) {$f_falt_op} ?";
  $params[] = $norm($f_falt_val);
}

// --- Sobrante $ ---
if ($f_sobr_mode === 1) {
  $where[] = "COALESCE(h.sobrante_dinero,0) > 0";
} elseif ($f_sobr_mode === 2) {
  $where[] = "COALESCE(h.sobrante_dinero,0) = 0";
} elseif ($f_sobr_mode === 3 && $f_sobr_val !== '') {
  $where[]  = "COALESCE(h.sobrante_dinero,0) {$f_sobr_op} ?";
  $params[] = $norm($f_sobr_val);
}

// ---------- visibilidad por rol ----------
// Usamos LEFT JOIN + fallback si snapshot (h.lider_id / h.supervisor_id / h.auxiliar_id) está NULL.
$roleJoin = '';
if (!in_array($rol, ['admin','auditor'], true)) {
  if ($rol === 'lider') {
    // primer placeholder aparece en el JOIN -> debe ir al inicio de $params en orden
    array_unshift($params, $uid);
    $roleJoin = "LEFT JOIN lider_centro lc 
                   ON lc.centro_id = h.centro_id
                  AND h.fecha >= lc.desde
                  AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
                  AND lc.usuario_id = ?";
    $where[]  = "(h.lider_id = ? OR (h.lider_id IS NULL AND lc.usuario_id IS NOT NULL))";
    $params[] = $uid;
  } elseif ($rol === 'supervisor') {
    array_unshift($params, $uid);
    $roleJoin = "LEFT JOIN supervisor_zona sz
                   ON sz.zona_id = h.zona_id
                  AND h.fecha >= sz.desde
                  AND (sz.hasta IS NULL OR h.fecha <= sz.hasta)
                  AND sz.usuario_id = ?";
    $where[]  = "(h.supervisor_id = ? OR (h.supervisor_id IS NULL AND sz.usuario_id IS NOT NULL))";
    $params[] = $uid;
  } elseif ($rol === 'auxiliar') {
    array_unshift($params, $uid);
    $roleJoin = "LEFT JOIN auxiliar_centro ax
                   ON ax.centro_id = h.centro_id
                  AND h.fecha >= ax.desde
                  AND (ax.hasta IS NULL OR h.fecha <= ax.hasta)
                  AND ax.usuario_id = ?";
    $where[]  = "(h.auxiliar_id = ? OR (h.auxiliar_id IS NULL AND ax.usuario_id IS NOT NULL))";
    $params[] = $uid;
  }
}

$where_sql = "WHERE " . implode(' AND ', $where);

// ---------- total ----------
$countSql = "SELECT COUNT(*)
             FROM hallazgo h
             JOIN zona z ON z.id = h.zona_id
             JOIN centro_costo c ON c.id = h.centro_id
             $roleJoin
             $where_sql";
$stc = $pdo->prepare($countSql);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

// ---------- datos ----------
$sql = "SELECT h.id, h.fecha, h.nombre_pdv, h.pdv_codigo, h.cedula,
               z.nombre AS zona, c.nombre AS centro,
               h.raspas_faltantes, h.faltante_dinero, h.sobrante_dinero,
               h.estado, h.fecha_limite, h.evidencia_url
        FROM hallazgo h
        JOIN zona z ON z.id = h.zona_id
        JOIN centro_costo c ON c.id = h.centro_id
        $roleJoin
        $where_sql
        ORDER BY h.fecha DESC, h.id DESC
        LIMIT ? OFFSET ?";
$params_data = $params;
$params_data[] = $per_page;
$params_data[] = $offset;

$st = $pdo->prepare($sql);
$st->execute($params_data);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ---------- vista ----------
include __DIR__ . '/../../includes/header.php';
?>
<style>
  .record-info { font-size: .9rem; color:#6c757d; }
  .amount-cell { font-weight:600; font-family: 'Courier New', monospace; }
  .amount-negative { color:#dc3545; }
  .amount-positive { color:#198754; }
  .filter-adv { display:none; }
</style>

<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Listado de Hallazgos</h3>
    <div class="d-flex gap-2">
      <?php if (in_array($rol, ['admin','auditor'], true)): ?>
        <a class="btn btn-success" href="<?= BASE_URL ?>/hallazgos/nuevo.php">
          <i class="bi bi-plus-lg"></i> Nuevo
        </a>
      <?php endif; ?>
      <a class="btn btn-outline-success" 
         href="<?= BASE_URL ?>/export_csv.php?<?= http_build_query([
            'desde'=>$desde,'hasta'=>$hasta,
            'zona_id'=>$f_zona,'centro_id'=>$f_centro,'estado'=>$f_estado,
            'raspas_mode'=>$f_raspas_mode,'raspas_op'=>$f_raspas_op,'raspas_val'=>$f_raspas_val,
            'falt_mode'=>$f_falt_mode,'falt_op'=>$f_falt_op,'falt_val'=>$f_falt_val,
            'sobr_mode'=>$f_sobr_mode,'sobr_op'=>$f_sobr_op,'sobr_val'=>$f_sobr_val,
            'per_page'=>$per_page,'page'=>$page
         ]) ?>">
        <i class="bi bi-file-earmark-arrow-down"></i> Exportar CSV
      </a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-2">
      <label class="form-label small fw-bold">Desde</label>
      <input type="date" class="form-control form-control-sm" name="desde" value="<?= htmlspecialchars($desde) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Hasta</label>
      <input type="date" class="form-control form-control-sm" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Zona</label>
      <select name="zona_id" id="f_zona" class="form-select form-select-sm">
        <option value="0">Todas</option>
        <?php foreach($zonas as $z): ?>
          <option value="<?= (int)$z['id'] ?>" <?= ($f_zona===(int)$z['id'])?'selected':'' ?>><?= htmlspecialchars($z['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Centro de Costo</label>
      <select name="centro_id" id="f_centro" class="form-select form-select-sm"
              data-current-centro="<?= $hasCentro ? (int)$f_centro : 0 ?>">
        <option value="0">Todos</option>
        <?php foreach($centros as $c): ?>
          <option data-z="<?= (int)$c['zona_id'] ?>" value="<?= (int)$c['id'] ?>">
            <?= htmlspecialchars($c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Estado</label>
      <select name="estado" class="form-select form-select-sm">
        <option value="">Todos</option>
        <option value="pendiente" <?= ($f_estado==='pendiente')?'selected':'' ?>>Pendiente</option>
        <option value="vencido" <?= ($f_estado==='vencido')?'selected':'' ?>>Vencido</option>
        <option value="respondido_lider" <?= ($f_estado==='respondido_lider')?'selected':'' ?>>Respondido (Líder)</option>
        <option value="respondido_admin" <?= ($f_estado==='respondido_admin')?'selected':'' ?>>Respondido (Admin)</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Registros por pág.</label>
      <select name="per_page" class="form-select form-select-sm">
        <?php foreach($allowedPer as $op): ?>
          <option value="<?= $op ?>" <?= ($per_page===$op)?'selected':'' ?>><?= $op ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Filtros avanzados (Raspas/Faltante/Sobrante) -->
    <div class="col-md-2">
      <label class="form-label small fw-bold">Raspas</label>
      <select name="raspas_mode" id="raspas_mode" class="form-select form-select-sm">
        <option value="0" <?= $f_raspas_mode===0?'selected':'' ?>>Todos</option>
        <option value="1" <?= $f_raspas_mode===1?'selected':'' ?>>Con (&gt; 0)</option>
        <option value="2" <?= $f_raspas_mode===2?'selected':'' ?>>Sin (= 0)</option>
        <option value="3" <?= $f_raspas_mode===3?'selected':'' ?>>Personalizado</option>
      </select>
    </div>
    <div class="col-md-2 filter-adv" id="raspas_adv">
      <label class="form-label small fw-bold">Condición Raspas</label>
      <div class="d-flex gap-2">
        <select name="raspas_op" class="form-select form-select-sm" style="max-width:100px">
          <?php foreach(['>','>=','=','<=','<','<>'] as $op): ?>
            <option value="<?= $op ?>" <?= $f_raspas_op===$op?'selected':'' ?>><?= $op ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" class="form-control form-select-sm" name="raspas_val" value="<?= htmlspecialchars($f_raspas_val) ?>" placeholder="valor">
      </div>
    </div>

    <div class="col-md-2">
      <label class="form-label small fw-bold">Faltante $</label>
      <select name="falt_mode" id="falt_mode" class="form-select form-select-sm">
        <option value="0" <?= $f_falt_mode===0?'selected':'' ?>>Todos</option>
        <option value="1" <?= $f_falt_mode===1?'selected':'' ?>>Con (&gt; 0)</option>
        <option value="2" <?= $f_falt_mode===2?'selected':'' ?>>Sin (= 0)</option>
        <option value="3" <?= $f_falt_mode===3?'selected':'' ?>>Personalizado</option>
      </select>
    </div>
    <div class="col-md-2 filter-adv" id="falt_adv">
      <label class="form-label small fw-bold">Condición Faltante</label>
      <div class="d-flex gap-2">
        <select name="falt_op" class="form-select form-select-sm" style="max-width:100px">
          <?php foreach(['>','>=','=','<=','<','<>'] as $op): ?>
            <option value="<?= $op ?>" <?= $f_falt_op===$op?'selected':'' ?>><?= $op ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-select-sm" name="falt_val" value="<?= htmlspecialchars($f_falt_val) ?>" placeholder="monto">
      </div>
    </div>

    <div class="col-md-2">
      <label class="form-label small fw-bold">Sobrante $</label>
      <select name="sobr_mode" id="sobr_mode" class="form-select form-select-sm">
        <option value="0" <?= $f_sobr_mode===0?'selected':'' ?>>Todos</option>
        <option value="1" <?= $f_sobr_mode===1?'selected':'' ?>>Con (&gt; 0)</option>
        <option value="2" <?= $f_sobr_mode===2?'selected':'' ?>>Sin (= 0)</option>
        <option value="3" <?= $f_sobr_mode===3?'selected':'' ?>>Personalizado</option>
      </select>
    </div>
    <div class="col-md-2 filter-adv" id="sobr_adv">
      <label class="form-label small fw-bold">Condición Sobrante</label>
      <div class="d-flex gap-2">
        <select name="sobr_op" class="form-select form-select-sm" style="max-width:100px">
          <?php foreach(['>','>=','=','<=','<','<>'] as $op): ?>
            <option value="<?= $op ?>" <?= $f_sobr_op===$op?'selected':'' ?>><?= $op ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-select-sm" name="sobr_val" value="<?= htmlspecialchars($f_sobr_val) ?>" placeholder="monto">
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Aplicar filtros</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/hallazgos/listado.php"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
    </div>
  </form>

  <div class="mb-2 record-info">
    Mostrando registros <?= $total ? ($offset + 1) : 0 ?> a <?= min($offset + $per_page, $total) ?> de <?= $total ?> totales
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>Fecha</th>
          <th>Zona</th>
          <th>Centro</th>
          <th>PDV</th>
          <th>Asesor</th>
          <th class="text-end">Raspas</th>
          <th class="text-end">Faltante $</th>
          <th class="text-end">Sobrante $</th>
          <th>Estado</th>
          <th>Fecha límite</th>
          <th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" class="text-center text-muted">Sin resultados con los filtros actuales.</td></tr>
      <?php else: ?>
        <?php foreach($rows as $i => $r): 
          $pdvTxt = trim(($r['pdv_codigo'] ?? '') . ' - ' . ($r['nombre_pdv'] ?? ''));
          $viewUrl = BASE_URL . '/hallazgos/detalle.php?id=' . (int)$r['id'];
          $respUrl = BASE_URL . '/hallazgos/responder.php?id=' . (int)$r['id'];
          $editUrl = BASE_URL . '/hallazgos/editar.php?id=' . (int)$r['id'];
        ?>
          <tr>
            <td><?= $offset + $i + 1 ?></td>
            <td><?= htmlspecialchars($r['fecha']) ?></td>
            <td><?= htmlspecialchars($r['zona']) ?></td>
            <td><?= htmlspecialchars($r['centro']) ?></td>
            <td><?= htmlspecialchars($pdvTxt) ?></td>
            <td><?= htmlspecialchars($r['cedula']) ?></td>
            <td class="text-end"><?= (int)$r['raspas_faltantes'] ?></td>
            <td class="text-end"><?= money_cell($r['faltante_dinero']) ?></td>
            <td class="text-end"><?= money_cell($r['sobrante_dinero']) ?></td>
            <td><?= badgeEstado($r['estado']) ?></td>
            <td><small class="<?= $r['estado']==='vencido'?'text-danger fw-bold':'' ?>"><?= htmlspecialchars($r['fecha_limite']) ?></small></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm" role="group" aria-label="acciones">
                <a class="btn btn-outline-secondary" href="<?= $viewUrl ?>" title="Ver"><i class="bi bi-eye"></i></a>
                <?php if (in_array($rol, ['admin','auditor','supervisor','lider','auxiliar'], true)): ?>
                  <a class="btn btn-outline-primary" href="<?= $respUrl ?>" title="Responder"><i class="bi bi-reply"></i></a>
                <?php endif; ?>
                <?php if (in_array($rol, ['admin','auditor'], true)): ?>
                  <a class="btn btn-outline-warning" href="<?= $editUrl ?>" title="Editar"><i class="bi bi-pencil-square"></i></a>
                <?php endif; ?>

                <button
                  type="button"
                  class="btn btn-outline-dark"
                  data-bs-toggle="modal"
                  data-bs-target="#modalAccion"
                  data-id="<?= (int)$r['id'] ?>"
                  data-fecha="<?= htmlspecialchars($r['fecha']) ?>"
                  data-zona="<?= htmlspecialchars($r['zona']) ?>"
                  data-centro="<?= htmlspecialchars($r['centro']) ?>"
                  data-pdv="<?= htmlspecialchars($pdvTxt) ?>"
                  data-cedula="<?= htmlspecialchars($r['cedula']) ?>"
                  data-raspas="<?= (int)$r['raspas_faltantes'] ?>"
                  data-faltante="<?= (float)$r['faltante_dinero'] ?>"
                  data-sobrante="<?= (float)$r['sobrante_dinero'] ?>"
                  data-estado="<?= htmlspecialchars($r['estado']) ?>"
                  data-fechalim="<?= htmlspecialchars($r['fecha_limite']) ?>"
                  data-evidencia="<?= htmlspecialchars($r['evidencia_url'] ?? '') ?>"
                >
                  <i class="bi bi-three-dots"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <div class="record-info">Página <?= $page ?> de <?= $total_pages ?></div>
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

<!-- MODAL MEJORADO DE ACCIONES -->
<div class="modal fade" id="modalAccion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><span class="me-2">Hallazgo #</span><span id="m-id"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div><strong>Fecha:</strong> <span id="m-fecha"></span></div>
            <div><strong>Zona:</strong> <span id="m-zona"></span></div>
            <div><strong>Centro:</strong> <span id="m-centro"></span></div>
            <div><strong>PDV:</strong> <span id="m-pdv"></span></div>
            <div><strong>Asesor:</strong> <span id="m-cedula"></span></div>
          </div>
          <div class="col-md-6">
            <div><strong>Raspas:</strong> <span id="m-raspas"></span></div>
            <div><strong>Faltante:</strong> <span id="m-faltante"></span></div>
            <div><strong>Sobrante:</strong> <span id="m-sobrante"></span></div>
            <div><strong>Estado:</strong> <span id="m-estado"></span></div>
            <div><strong>Fecha límite:</strong> <span id="m-fechalim"></span></div>
          </div>
          <div class="col-12" id="m-evid-wrap" style="display:none;">
            <strong>Evidencia:</strong> <a id="m-evidencia" href="#" target="_blank" rel="noopener">Ver archivo</a>
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <div class="text-muted small">Revisa y elige la acción que deseas realizar.</div>
        <div class="d-flex gap-2">
          <a id="m-ver" href="#" class="btn btn-outline-secondary"><i class="bi bi-eye"></i> Ver</a>
          <?php if (in_array($rol, ['admin','auditor','supervisor','lider','auxiliar'], true)): ?>
            <a id="m-resp" href="#" class="btn btn-primary"><i class="bi bi-reply"></i> Responder</a>
          <?php endif; ?>
          <?php if (in_array($rol, ['admin','auditor'], true)): ?>
            <a id="m-edit" href="#" class="btn btn-warning"><i class="bi bi-pencil-square"></i> Editar</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // Filtrar centros por zona (combo dependiente) con "Todos" por defecto
  (function(){
    const fz = document.getElementById('f_zona');
    const fc = document.getElementById('f_centro');
    const opts = Array.from(fc.querySelectorAll('option'));
    const currentFromUrl = fc.getAttribute('data-current-centro') || '0'; // '0' si no vino en la URL

    function apply(){
      const z = fz.value;
      const prev = fc.value;
      fc.innerHTML = '';

      const optAll = opts.find(o => o.value === '0');
      if (optAll) fc.appendChild(optAll);

      opts.forEach(o => {
        if (o.value === '0') return;
        const oz = o.getAttribute('data-z');
        if (z === '0' || oz === z) fc.appendChild(o);
      });

      if (currentFromUrl === '0') {
        fc.value = '0';
      } else if (fc.querySelector(`option[value="${CSS.escape(currentFromUrl)}"]`)) {
        fc.value = currentFromUrl;
      } else if (fc.querySelector(`option[value="${CSS.escape(prev)}"]`)) {
        fc.value = prev;
      } else {
        fc.value = '0';
      }
    }

    fz.addEventListener('change', apply);
    apply();
  })();

  function toggleAdv(idSelect, idWrap){
    const sel = document.getElementById(idSelect);
    const wrap = document.getElementById(idWrap);
    function t(){ wrap.style.display = (parseInt(sel.value,10) === 3) ? 'block':'none'; }
    sel.addEventListener('change', t); t();
  }
  toggleAdv('raspas_mode','raspas_adv');
  toggleAdv('falt_mode','falt_adv');
  toggleAdv('sobr_mode','sobr_adv');

  const modalEl = document.getElementById('modalAccion');
  modalEl.addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const id = btn.getAttribute('data-id');
    const viewUrl = '<?= BASE_URL ?>/hallazgos/detalle.php?id=' + id;
    const respUrl = '<?= BASE_URL ?>/hallazgos/responder.php?id=' + id;
    const editUrl = '<?= BASE_URL ?>/hallazgos/editar.php?id=' + id;

    this.querySelector('#m-id').textContent = id;
    this.querySelector('#m-fecha').textContent = btn.getAttribute('data-fecha') || '';
    this.querySelector('#m-zona').textContent = btn.getAttribute('data-zona') || '';
    this.querySelector('#m-centro').textContent = btn.getAttribute('data-centro') || '';
    this.querySelector('#m-pdv').textContent = btn.getAttribute('data-pdv') || '';
    this.querySelector('#m-cedula').textContent = btn.getAttribute('data-cedula') || '';
    this.querySelector('#m-raspas').textContent = btn.getAttribute('data-raspas') || '0';

    function money(v){
      const n = parseFloat(v||0);
      return isNaN(n) ? '' : new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',minimumFractionDigits:2}).format(n);
    }
    this.querySelector('#m-faltante').textContent = money(btn.getAttribute('data-faltante'));
    this.querySelector('#m-sobrante').textContent = money(btn.getAttribute('data-sobrante'));

    const est = (btn.getAttribute('data-estado') || '').toLowerCase();
    let badge = '<span class="badge bg-secondary">'+est+'</span>';
    if (est==='pendiente') badge = '<span class="badge bg-warning text-dark">Pendiente</span>';
    if (est==='vencido')   badge = '<span class="badge bg-danger">Vencido</span>';
    if (est==='respondido_lider') badge = '<span class="badge bg-success">Respondido (Líder)</span>';
    if (est==='respondido_admin') badge = '<span class="badge bg-primary">Respondido (Admin)</span>';
    this.querySelector('#m-estado').innerHTML = badge;

    this.querySelector('#m-fechalim').textContent = btn.getAttribute('data-fechalim') || '';

    const evid = btn.getAttribute('data-evidencia') || '';
    const wrap = this.querySelector('#m-evid-wrap');
    const aev  = this.querySelector('#m-evidencia');
    if (evid) { wrap.style.display='block'; aev.href=evid; }
    else { wrap.style.display='none'; aev.removeAttribute('href'); }

    this.querySelector('#m-ver').href = viewUrl;
    <?php if (in_array($rol, ['admin','auditor','supervisor','lider','auxiliar'], true)): ?>
      this.querySelector('#m-resp').href = respUrl;
    <?php endif; ?>
    <?php if (in_array($rol, ['admin','auditor'], true)): ?>
      this.querySelector('#m-edit').href = editUrl;
    <?php endif; ?>
  });
</script>

<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) {
  include $__footer;
}
