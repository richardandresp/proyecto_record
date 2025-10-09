<?php
// auditoria_app/public/dashboard.php (encabezado mínimo y guards correctos)

// Diagnóstico opcional (déjalo si te sirve)
if (!empty($_GET['diag'])) {
  require_once __DIR__ . '/../includes/session_boot.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/acl.php';
  require_once __DIR__ . '/../includes/acl_suite.php';
  $uid = (int)($_SESSION['usuario_id'] ?? 0);
  $hasModule = module_enabled_for_user($uid, 'auditoria');
  $hasAccess = function_exists('user_has_perm') ? user_has_perm('auditoria.access') : null;
  echo "UID=$uid<br>Modulo habilitado: " . ($hasModule ? 'SI' : 'NO') . "<br>";
  if ($hasAccess !== null) echo "Permiso auditoria.access: " . ($hasAccess ? 'SI' : 'NO');
  exit;
}

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/acl.php';
require_once __DIR__ . '/../includes/acl_suite.php';

login_required(); // primero: asegurar sesión

$uid = (int)($_SESSION['usuario_id'] ?? 0);

// === ÚNICO guard de módulo (Auditoría) ===
if (!module_enabled_for_user($uid, 'auditoria')) {
  render_403_and_exit();
}

// === ÚNICO permiso fino para entrar al módulo ===
require_perm('auditoria.access');


/* === PDO con fallback === */
$pdo = function_exists('get_pdo') ? get_pdo() : (function_exists('getDB') ? getDB() : null);
if (!$pdo) { http_response_code(500); echo "Error: sin conexión a BD."; exit; }

$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

/* Filtros (últimos 30 días por defecto) */
$hoy    = (new DateTime())->format('Y-m-d');
$hace30 = (new DateTime('-30 days'))->format('Y-m-d');
$desde  = $_GET['desde'] ?? $hace30;
$hasta  = $_GET['hasta'] ?? $hoy;

/* VISIBILIDAD por rol */
$joinVis = '';
$paramsVis = [];
if ($rol === 'lider') {
  $joinVis = "JOIN lider_centro lcvis ON lcvis.centro_id = h.centro_id
              AND h.fecha >= lcvis.desde
              AND (lcvis.hasta IS NULL OR h.fecha <= lcvis.hasta)
              AND lcvis.usuario_id = ?";
  $paramsVis[] = $uid;
} elseif ($rol === 'supervisor') {
  $joinVis = "JOIN supervisor_zona szvis ON szvis.zona_id = h.zona_id
              AND h.fecha >= szvis.desde
              AND (szvis.hasta IS NULL OR h.fecha <= szvis.hasta)
              AND szvis.usuario_id = ?";
  $paramsVis[] = $uid;
} elseif ($rol === 'auxiliar') {
  $joinVis = "JOIN auxiliar_centro axvis ON axvis.centro_id = h.centro_id
              AND h.fecha >= axvis.desde
              AND (axvis.hasta IS NULL OR h.fecha <= axvis.hasta)
              AND axvis.usuario_id = ?";
  $paramsVis[] = $uid;
}

/* Rango */
$whereRango  = "h.fecha BETWEEN ? AND ?";
$paramsRango = [$desde, $hasta];

// === Rango anterior del mismo tamaño (inclusivo) ===
$dtDesde = new DateTime($desde);
$dtHasta = new DateTime($hasta);
$days = max(1, (int)$dtDesde->diff($dtHasta)->days + 1);

$prevHasta = (clone $dtDesde)->modify('-1 day');
$prevDesde = (clone $prevHasta)->modify('-'.($days-1).' days');

$prev_desde = $prevDesde->format('Y-m-d');
$prev_hasta = $prevHasta->format('Y-m-d');

// === Etiquetas por día del rango actual (Y-m-d) ===
$labels = [];
for ($d = clone $dtDesde, $i=0; $i < $days; $i++, $d->modify('+1 day')) {
  $labels[] = $d->format('Y-m-d');
}
// Etiquetas del rango anterior (mismo tamaño, desplazado)
$prev_labels = [];
for ($d = clone $prevDesde, $i=0; $i < $days; $i++, $d->modify('+1 day')) {
  $prev_labels[] = $d->format('Y-m-d');
}

// === Helper para traer serie por día
$fetchSerie = function(string $desde, string $hasta) use($pdo, $joinVis, $paramsVis): array {
  $sql = "SELECT DATE(h.fecha) AS dia,
                 SUM(h.estado='pendiente')           AS p,
                 SUM(h.estado='vencido')             AS v,
                 SUM(h.estado='respondido_lider')    AS rl,
                 SUM(h.estado='respondido_admin')    AS ra
          FROM hallazgo h
          $joinVis
          WHERE h.fecha BETWEEN ? AND ?
          GROUP BY DATE(h.fecha)
          ORDER BY dia";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($paramsVis, [$desde, $hasta]));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $r) {
    $map[$r['dia']] = [
      'p'  => (int)($r['p']  ?? 0),
      'v'  => (int)($r['v']  ?? 0),
      'rl' => (int)($r['rl'] ?? 0),
      'ra' => (int)($r['ra'] ?? 0),
    ];
  }
  return $map;
};

$currMap = $fetchSerie($desde, $hasta);
$prevMap = $fetchSerie($prev_desde, $prev_hasta);

// === Alinear a los labels actuales (índice 0..N-1) ===
$currP=[]; $currV=[]; $currRL=[]; $currRA=[];
$prevP=[]; $prevV=[]; $prevRL=[]; $prevRA=[];
for ($i=0; $i<$days; $i++) {
  $d  = $labels[$i];
  $pd = $prev_labels[$i];

  $c = $currMap[$d]  ?? ['p'=>0,'v'=>0,'rl'=>0,'ra'=>0];
  $p = $prevMap[$pd] ?? ['p'=>0,'v'=>0,'rl'=>0,'ra'=>0];

  $currP[]  = $c['p'];  $prevP[]  = $p['p'];
  $currV[]  = $c['v'];  $prevV[]  = $p['v'];
  $currRL[] = $c['rl']; $prevRL[] = $p['rl'];
  $currRA[] = $c['ra']; $prevRA[] = $p['ra'];
}

// Labels "bonitos" para la gráfica (fecha corta)
$labelsPretty = array_map(function($yymd){
  $dt = DateTime::createFromFormat('Y-m-d', $yymd);
  return $dt ? $dt->format('d/m') : $yymd;
}, $labels);

// === 2) Helper para contar KPIs dado un rango ===
function kpi_pack(PDO $pdo, string $joinVis, array $paramsVis, string $desde, string $hasta): array {
  $whereBase  = "h.fecha BETWEEN ? AND ?";
  $paramsBase = [$desde, $hasta];

  $count = function(string $extraWhere = '') use($pdo, $joinVis, $paramsVis, $whereBase, $paramsBase): int {
    $sql = "SELECT COUNT(*) FROM hallazgo h $joinVis WHERE $whereBase".($extraWhere?" AND $extraWhere":"");
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($paramsVis, $paramsBase));
    return (int)$st->fetchColumn();
  };

  // KPIs
  $pendientes = $count("h.estado='pendiente'");
  $vencidos   = $count("h.estado='vencido'");
  $respLider  = $count("h.estado='respondido_lider'");
  $respAdmin  = $count("h.estado='respondido_admin'");
  $vencenHoy  = (function() use($pdo,$joinVis,$paramsVis): int {
    $sql = "SELECT COUNT(*) FROM hallazgo h $joinVis
            WHERE h.estado='pendiente' AND DATE(h.fecha_limite)=CURDATE()";
    $st = $pdo->prepare($sql);
    $st->execute($paramsVis);
    return (int)$st->fetchColumn();
  })();

  return [
    'pendientes' => $pendientes,
    'vencidos'   => $vencidos,
    'respondidos'=> $respLider + $respAdmin,
    'vencen_hoy' => $vencenHoy, // ojo: este no compara por rango; es "hoy"
  ];
}

// === 3) Helper de delta (% y flecha) ===
function kpi_delta_badge(int $curr, int $prev, string $type): string {
  // type: 'good_up' (subir es bueno), 'good_down' (bajar es bueno)
  if ($prev === 0) {
    if ($curr === 0) return '<small class="text-muted">= 0%</small>';
    $arrow = $type==='good_down' ? 'text-danger bi-arrow-up-short' : 'text-success bi-arrow-up-short';
    return '<small class="'.($type==='good_down'?'text-danger':'text-success').'"><i class="bi '.$arrow.'"></i>100%</small>';
  }
  $d = (($curr - $prev) * 100) / $prev;
  $d = round($d);
  $isUp = $d > 0;

  // color/semántica por KPI
  if ($type === 'good_down') { // menos es mejor (pendientes, vencidos)
    $class = $isUp ? 'text-danger' : 'text-success';
  } else { // good_up (respondidos)
    $class = $isUp ? 'text-success' : 'text-danger';
  }
  $icon = $isUp ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
  return '<small class="'.$class.'"><i class="bi '.$icon.'"></i>'.($d>0?'+':'').$d.'%</small>';
}

// === 4) Empaquetar KPIs actual vs. anterior ===
$nowPack  = kpi_pack($pdo, $joinVis, $paramsVis, $desde, $hasta);
$prevPack = kpi_pack($pdo, $joinVis, $paramsVis, $prev_desde, $prev_hasta);

/* Próximos a vencer */
$limitProx = 10;
$sqlProx = "SELECT h.id, h.fecha, h.nombre_pdv, h.fecha_limite, z.nombre AS zona, c.nombre AS cc
            FROM hallazgo h
            JOIN zona z ON z.id=h.zona_id
            JOIN centro_costo c ON c.id=h.centro_id
            $joinVis
            WHERE $whereRango AND h.estado='pendiente'
            ORDER BY h.fecha_limite ASC
            LIMIT $limitProx";
$stProx = $pdo->prepare($sqlProx);
$stProx->execute(array_merge($paramsVis, $paramsRango));
$proximos = $stProx->fetchAll(PDO::FETCH_ASSOC);

/* Top Zonas */
$sqlTopZ = "SELECT z.id, z.nombre, COUNT(*) AS total
            FROM hallazgo h
            JOIN zona z ON z.id = h.zona_id
            $joinVis
            WHERE $whereRango
            GROUP BY z.id, z.nombre
            ORDER BY total DESC, z.nombre
            LIMIT 5";
$stTZ = $pdo->prepare($sqlTopZ);
$stTZ->execute(array_merge($paramsVis, $paramsRango));
$topZonas = $stTZ->fetchAll(PDO::FETCH_ASSOC);

/* === (3) Diagnóstico actualizado (sin funciones de la Suite) === */
echo "<!-- PERM-DIAG ".htmlspecialchars(json_encode([
  'auditoria.access'        => user_has_perm('auditoria.access') ? 1 : 0,
  'auditoria.hallazgo.list' => user_has_perm('auditoria.hallazgo.list') ? 1 : 0,
  'auditoria.hallazgo.view' => user_has_perm('auditoria.hallazgo.view') ? 1 : 0,
  'auditoria.reportes.view' => user_has_perm('auditoria.reportes.view') ? 1 : 0,
  'mod_suite_db'            => module_enabled_for_user($uid, 'auditoria') ? 1 : 0,
]))." -->";

include __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3>Dashboard</h3>
      <!-- Contexto del rol (badge + texto) -->
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge bg-dark text-uppercase"><?= htmlspecialchars($rol) ?></span>
        <?php if (!in_array($rol, ['admin','auditor'], true)): ?>
          <small class="text-muted">Ves datos de tus zonas/CC asignados.</small>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Rangos rápidos (chips) -->
    <form method="get" class="d-flex gap-2 align-items-end">
      <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control">
      <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control">
      <button class="btn btn-primary">Aplicar</button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/dashboard.php">Últimos 30 días</a>

      <!-- Chips -->
      <div class="btn-group ms-2" role="group" aria-label="Rangos rápidos">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-range="hoy">Hoy</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-range="7d">7d</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-range="30d">30d</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-range="mes">Mes actual</button>
      </div>
    </form>
  </div>

  <!-- KPIs - Tarjetas con tooltips informativos -->
  <div class="row g-3">
    <!-- Pendientes -->
    <div class="col-md-3">
      <a class="card border-0 shadow-sm text-decoration-none text-dark kpi-card"
         href="<?= BASE_URL ?>/hallazgos/listado.php?estado=pendiente&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
         data-bs-toggle="tooltip" 
         data-bs-placement="top"
         title="📋 Hallazgos pendientes de respuesta<br>⬇️ <span class='text-success'>Bajar es bueno</span><br>Comparado con período anterior del mismo tamaño">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div class="text-muted">Pendientes</div>
            <?= kpi_delta_badge($nowPack['pendientes'], $prevPack['pendientes'], 'good_down') ?>
          </div>
          <div class="display-6"><?= number_format($nowPack['pendientes']) ?></div>
          <small class="text-muted">vs. <?= htmlspecialchars($prev_desde) ?>–<?= htmlspecialchars($prev_hasta) ?></small>
        </div>
      </a>
    </div>

    <!-- Vencidos -->
    <div class="col-md-3">
      <a class="card border-0 shadow-sm text-decoration-none text-dark kpi-card"
         href="<?= BASE_URL ?>/hallazgos/listado.php?estado=vencido&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
         data-bs-toggle="tooltip" 
         data-bs-placement="top"
         title="⏰ Hallazgos con SLA vencido (> 2 días)<br>⬇️ <span class='text-success'>Bajar es bueno</span><br>Comparado con período anterior del mismo tamaño">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div class="text-muted">Vencidos</div>
            <?= kpi_delta_badge($nowPack['vencidos'], $prevPack['vencidos'], 'good_down') ?>
          </div>
          <div class="display-6 text-danger"><?= number_format($nowPack['vencidos']) ?></div>
          <small class="text-muted">SLA &gt; 2 días</small><br>
          <small class="text-muted">vs. <?= htmlspecialchars($prev_desde) ?>–<?= htmlspecialchars($prev_hasta) ?></small>
        </div>
      </a>
    </div>

    <!-- Vencen hoy -->
    <div class="col-md-3">
      <a class="card border-0 shadow-sm text-decoration-none text-dark kpi-card"
         href="<?= BASE_URL ?>/hallazgos/listado.php?estado=pendiente&desde=<?= urlencode(date('Y-m-d')) ?>&hasta=<?= urlencode(date('Y-m-d')) ?>"
         data-bs-toggle="tooltip" 
         data-bs-placement="top"
         title="🚨 Hallazgos que vencen HOY<br>⚠️ <span class='text-warning'>Prioridad alta</span><br>Foto del día actual (no comparativo)">
        <div class="card-body">
          <div class="text-muted">Vencen hoy</div>
          <div class="display-6 text-warning"><?= number_format($nowPack['vencen_hoy']) ?></div>
          <small class="text-muted">Prioridad alta</small>
        </div>
      </a>
    </div>

    <!-- Respondidos -->
    <div class="col-md-3">
      <a class="card border-0 shadow-sm text-decoration-none text-dark kpi-card"
         href="<?= BASE_URL ?>/hallazgos/listado.php?estado=respondido_lider&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>"
         data-bs-toggle="tooltip" 
         data-bs-placement="top"
         title="✅ Hallazgos respondidos por líderes<br>⬆️ <span class='text-success'>Subir es bueno</span><br>Incluye respuestas de líderes y administradores">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div class="text-muted">Respondidos</div>
            <?= kpi_delta_badge($nowPack['respondidos'], $prevPack['respondidos'], 'good_up') ?>
          </div>
          <div class="display-6 text-success"><?= number_format($nowPack['respondidos']) ?></div>
          <small class="text-muted">Líder + Admin — vs. <?= htmlspecialchars($prev_desde) ?>–<?= htmlspecialchars($prev_hasta) ?></small>
        </div>
      </a>
    </div>
  </div>

  <!-- Gráfica por día con comparativa -->
  <div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
      <h5 class="card-title mb-3">
        Evolución (por día) 
        <span class="badge bg-light text-dark ms-2" data-bs-toggle="tooltip" 
              title="Líneas sólidas: Período actual (<?= htmlspecialchars($desde) ?> - <?= htmlspecialchars($hasta) ?>)<br>Líneas punteadas: Período anterior (<?= htmlspecialchars($prev_desde) ?> - <?= htmlspecialchars($prev_hasta) ?>)">
          <i class="bi bi-info-circle"></i>
        </span>
      </h5>
      <canvas id="chartEstados" height="110"></canvas>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <!-- Próximos a vencer -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Próximos a vencer</h5>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th><th>Fecha</th><th>F. Límite</th><th>Zona</th><th>CC</th><th>PDV</th><th></th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$proximos): ?>
                <tr><td colspan="7" class="text-center py-3">Sin pendientes próximos a vencer</td></tr>
              <?php else: ?>
                <?php foreach($proximos as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['fecha']) ?></td>
                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($r['fecha_limite']) ?></span></td>
                    <td><?= htmlspecialchars($r['zona']) ?></td>
                    <td><?= htmlspecialchars($r['cc']) ?></td>
                    <td><?= htmlspecialchars($r['nombre_pdv']) ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/hallazgos/detalle.php?id=<?= (int)$r['id'] ?>">Ver</a></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <a class="btn btn-outline-primary btn-sm" href="<?= BASE_URL ?>/hallazgos/listado.php?estado=pendiente&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>">Ver todos</a>
        </div>
      </div>
    </div>

    <!-- Top Zonas (clic ⇒ desplegar CC) -->
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Top Zonas (por hallazgos)</h5>
          <div id="dz" data-desde="<?= htmlspecialchars($desde) ?>" data-hasta="<?= htmlspecialchars($hasta) ?>"></div>
          <div class="table-responsive">
            <table class="table table-sm align-middle" id="tabla-top-zonas">
              <thead class="table-light"><tr>
                <th style="width:50px">#</th><th>Zona</th><th>Total</th><th style="width:120px"></th>
              </tr></thead>
              <tbody>
                <?php if (!$topZonas): ?>
                  <tr><td colspan="4" class="text-center py-3">Sin datos</td></tr>
                <?php else: $i=1; foreach($topZonas as $tz): ?>
                  <tr class="fila-zona" data-zid="<?= (int)$tz['id'] ?>">
                    <td><?= $i ?></td>
                    <td>
                      <button type="button" class="btn btn-sm btn-link p-0 toggle-cc-zona"
                              data-zid="<?= (int)$tz['id'] ?>" data-zona-nombre="<?= htmlspecialchars($tz['nombre']) ?>">
                        <?= htmlspecialchars($tz['nombre']) ?>
                      </button>
                    </td>
                    <td><span class="badge bg-secondary"><?= (int)$tz['total'] ?></span></td>
                    <td>
                      <a class="btn btn-sm btn-outline-primary"
                         href="<?= BASE_URL ?>/hallazgos/listado.php?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>&zona_id=<?= (int)$tz['id'] ?>">
                        Ver hallazgos
                      </a>
                    </td>
                  </tr>
                  <!-- detalle dinámico insertado aquí -->
                <?php $i++; endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <small class="text-muted">Haz clic en una zona para ver sus CC.</small>
        </div>
      </div>
    </div>
  </div>

  <?php if (in_array($rol, ['admin','supervisor'], true)): ?>
  <!-- Bloque de control: líderes con "respondido por Admin" -->
  <div class="row g-3 mt-2">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Líderes con hallazgos NO respondidos (respondidos por Admin)</h5>
          <div id="sr" data-desde="<?= htmlspecialchars($desde) ?>" data-hasta="<?= htmlspecialchars($hasta) ?>"></div>

          <div class="table-responsive">
            <table class="table table-sm align-middle" id="tabla-top-lideres-sr">
              <thead class="table-light">
                <tr>
                  <th style="width:50px">#</th>
                  <th>Líder</th>
                  <th>Total Admin</th>
                  <th>%</th>
                  <th style="width:120px"></th>
                </tr>
              </thead>
              <tbody>
                <tr><td colspan="5" class="text-center py-3 text-muted">Cargando...</td></tr>
              </tbody>
            </table>
          </div>
          <small class="text-muted">Clic en un líder para ver sus CC con hallazgos respondidos por Admin.</small>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Chart.js (solo en dashboard) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // ===== Inicializar tooltips de Bootstrap =====
  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl, {
    html: true,
    delay: {show: 300, hide: 100}
  }));

  // ===== B) Rangos rápidos =====
  const d = document.querySelector('input[name="desde"]');
  const h = document.querySelector('input[name="hasta"]');
  const form = d?.closest('form');

  function fmt(dt){ return dt.toISOString().slice(0,10); }

  document.querySelectorAll('[data-range]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const r = btn.getAttribute('data-range');
      const today = new Date();
      let desde = null, hasta = null;

      if (r==='hoy'){ desde=today; hasta=today; }
      if (r==='7d'){ hasta=today; desde=new Date(today); desde.setDate(hasta.getDate()-6); }
      if (r==='30d'){ hasta=today; desde=new Date(today); desde.setDate(hasta.getDate()-29); }
      if (r==='mes'){
        hasta=today;
        desde=new Date(today.getFullYear(), today.getMonth(), 1);
      }

      if (desde && hasta && d && h && form){
        d.value = fmt(desde);
        h.value = fmt(hasta);
        form.submit();
      }
    });
  });

  // ===== Gráfica con comparativa de períodos =====
  const wrap = document.getElementById('chartEstados')?.closest('.card');
  if (wrap) {
    const sk = document.createElement('div');
    sk.id = 'chart-skel';
    sk.style.minHeight = '140px';
    sk.className = 'placeholder-wave';
    sk.innerHTML = '<div class="placeholder col-12" style="height:110px;"></div>';
    wrap.querySelector('.card-body')?.prepend(sk);
  }

  const labels   = <?= json_encode($labelsPretty) ?>;

  const curr = {
    p:  <?= json_encode($currP)  ?>,
    v:  <?= json_encode($currV)  ?>,
    rl: <?= json_encode($currRL) ?>,
    ra: <?= json_encode($currRA) ?>
  };
  const prev = {
    p:  <?= json_encode($prevP)  ?>,
    v:  <?= json_encode($prevV)  ?>,
    rl: <?= json_encode($prevRL) ?>,
    ra: <?= json_encode($prevRA) ?>
  };

  const ctx = document.getElementById('chartEstados');

  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        // Actual
        { label: 'Pendientes',       data: curr.p,  tension: .3 },
        { label: 'Vencidos',         data: curr.v,  tension: .3 },
        { label: 'Resp. Líder',      data: curr.rl, tension: .3 },
        { label: 'Resp. Admin',      data: curr.ra, tension: .3 },

        // Previo (línea punteada + opacidad baja)
        { label: 'Pendientes (prev)', data: prev.p,  tension: .3, borderDash:[6,4], pointRadius:0, borderWidth:1 },
        { label: 'Vencidos (prev)',   data: prev.v,  tension: .3, borderDash:[6,4], pointRadius:0, borderWidth:1 },
        { label: 'Resp. Líder (prev)',data: prev.rl, tension: .3, borderDash:[6,4], pointRadius:0, borderWidth:1 },
        { label: 'Resp. Admin (prev)',data: prev.ra, tension: .3, borderDash:[6,4], pointRadius:0, borderWidth:1 },
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            title: items => {
              // mostrará el rango actual para el índice
              return items[0]?.label || '';
            }
          }
        }
      },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  // Oculta skeleton
  document.getElementById('chart-skel')?.remove();

  // ===== Top Zonas → CC (toggle sin recargar) =====
  const tablaZ = document.getElementById('tabla-top-zonas');
  const dz = document.getElementById('dz');
  const DESDE = dz?.dataset.desde || '';
  const HASTA = dz?.dataset.hasta || '';

  tablaZ?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.toggle-cc-zona');
    if (!btn) return;

    const tr = btn.closest('tr');
    const zonaId = btn.getAttribute('data-zid');
    const zonaNombre = btn.getAttribute('data-zona-nombre') || '';

    const next = tr.nextElementSibling;
    if (next && next.classList.contains('detalle-cc-zona')) { next.remove(); return; }
    document.querySelectorAll('#tabla-top-zonas .detalle-cc-zona').forEach(x => x.remove());

    const detailRow = document.createElement('tr');
    detailRow.className = 'detalle-cc-zona';
    detailRow.innerHTML = `
      <td></td>
      <td colspan="3">
        <div class="p-2 bg-light border rounded d-flex align-items-center gap-2">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <span>Cargando CC de <b>${escapeHtml(zonaNombre)}</b>...</span>
        </div>
      </td>`;
    tr.insertAdjacentElement('afterend', detailRow);

    try {
      const url = '<?= BASE_URL ?>/api/top_cc_por_zona.php?zona_id=' + encodeURIComponent(zonaId)
                + '&desde=' + encodeURIComponent(DESDE)
                + '&hasta=' + encodeURIComponent(HASTA);
      const res = await fetch(url, {credentials:'same-origin'});
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Error al cargar CC');

      const items = json.items || [];
      if (!items.length) {
        detailRow.innerHTML = `<td></td><td colspan="3"><div class="p-2 bg-light border rounded text-muted">Sin CC con hallazgos en esta zona y rango.</div></td>`;
        return;
      }

      const rows = items.map((it, idx) => {
        const total = Number(it.total_all) || 0;
        const venc  = Number(it.total_vencidos) || 0;
        const pct   = total ? Math.round((venc * 100) / total) : 0;
        const barCls = pct >= 30 ? 'bg-danger' : (pct >= 10 ? 'bg-warning' : 'bg-success');

        return `
          <tr>
            <td style="width:50px">${idx+1}</td>
            <td>${escapeHtml(it.cc)}</td>
            <td><span class="badge bg-secondary">${total}</span></td>
            <td><span class="badge bg-danger">${venc}</span></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress" style="width:90px;height:8px;">
                  <div class="progress-bar ${barCls}" role="progressbar" style="width:${pct}%"></div>
                </div>
                <small>${pct}%</small>
              </div>
            </td>
            <td style="width:120px">
              <a class="btn btn-sm btn-outline-primary"
                href="<?= BASE_URL ?>/hallazgos/listado.php?desde=${encodeURIComponent(DESDE)}&hasta=${encodeURIComponent(HASTA)}&zona_id=${encodeURIComponent(zonaId)}&centro_id=${encodeURIComponent(it.cc_id)}">
                Ver
              </a>
            </td>
          </tr>
        `;
      }).join('');

      detailRow.innerHTML = `
        <td></td>
        <td colspan="3">
          <div class="p-2 bg-white border rounded">
            <div class="mb-2"><b>CC en ${escapeHtml(zonaNombre)}</b></div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                      <th style="width:50px">#</th>
                      <th>CC</th>
                      <th>Total</th>
                      <th>Vencidos</th>
                      <th>% Venc.</th>
                      <th style="width:120px"></th>
                    </tr>
                  </thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        </td>`;
    } catch (e) {
      detailRow.innerHTML = `<td></td><td colspan="3"><div class="p-2 bg-light border rounded text-danger">Error: ${escapeHtml(e.message||e)}</div></td>`;
    }
  });

  // ===== Líderes con respondido por Admin =====
  const wrapSR = document.getElementById('sr');
  if (wrapSR) {
    const DESDE2 = wrapSR.dataset.desde || '';
    const HASTA2 = wrapSR.dataset.hasta || '';
    const tablaL = document.getElementById('tabla-top-lideres-sr');
    const tbodyL = tablaL.querySelector('tbody');

    (async () => {
      try {
        const url = '<?= BASE_URL ?>/api/top_lideres_sin_respuesta.php?desde=' + encodeURIComponent(DESDE2) + '&hasta=' + encodeURIComponent(HASTA2);
        const res = await fetch(url, {credentials:'same-origin'});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error cargando líderes');

        const items = json.items || [];
        if (!items.length) {
          tbodyL.innerHTML = '<tr><td colspan="5" class="text-center py-3">Sin líderes con hallazgos respondidos por Admin en el rango.</td></tr>';
          return;
        }
        tbodyL.innerHTML = items.map((it, idx) => {
          const admin = Number(it.total_admin)||0;
          const all   = Number(it.total_all)||0;
          const pct   = all ? Math.round((admin*100)/all) : 0;
          const barCls = pct >= 30 ? 'bg-danger' : (pct >= 10 ? 'bg-warning' : 'bg-success');
          return `
            <tr class="fila-lider" data-lid="${Number(it.lider_id)||0}" data-nombre="${escapeHtml(it.lider)}">
              <td>${idx+1}</td>
              <td>
                <button type="button" class="btn btn-sm btn-link p-0 toggle-cc-lider">
                  ${escapeHtml(it.lider)}
                </button>
              </td>
              <td><span class="badge bg-danger">${admin}</span></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress" style="width:90px;height:8px;">
                    <div class="progress-bar ${barCls}" role="progressbar" style="width:${pct}%"></div>
                  </div>
                  <small>${pct}%</small>
                </div>
              </td>
              <td>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= BASE_URL ?>/hallazgos/listado.php?estado=respondido_admin&desde=${encodeURIComponent(DESDE2)}&hasta=${encodeURIComponent(HASTA2)}">
                  Ver hallazgos
                </a>
              </td>
            </tr>
          `;
        }).join('');
      } catch (e) {
        tbodyL.innerHTML = `<tr><td colspan="5" class="text-danger">Error: ${escapeHtml(e.message||e)}</td></tr>`;
      }
    })();

    tablaL.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('.toggle-cc-lider');
      if (!btn) return;
      const tr = btn.closest('tr');
      const lid = tr?.dataset.lid;
      const nombre = tr?.dataset.nombre || '';

      const next = tr.nextElementSibling;
      if (next && next.classList.contains('detalle-cc-lider')) { next.remove(); return; }
      document.querySelectorAll('#tabla-top-lideres-sr .detalle-cc-lider').forEach(x => x.remove());

      const detailRow = document.createElement('tr');
      detailRow.className = 'detalle-cc-lider';
      detailRow.innerHTML = `
        <td></td>
        <td colspan="4">
          <div class="p-2 bg-light border rounded d-flex align-items-center gap-2">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <span>Cargando CC de <b>${escapeHtml(nombre)}</b>...</span>
          </div>
        </td>`;
      tr.insertAdjacentElement('afterend', detailRow);

      try {
        const url = '<?= BASE_URL ?>/api/top_cc_por_lider_sr.php?lider_id=' + encodeURIComponent(lid)
                  + '&desde=' + encodeURIComponent(DESDE2)
                  + '&hasta=' + encodeURIComponent(HASTA2);
        const res = await fetch(url, {credentials:'same-origin'});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error al cargar CC');

        const items = json.items || [];
        if (!items.length) {
          detailRow.innerHTML = `
            <td></td><td colspan="4">
              <div class="p-2 bg-light border rounded text-muted">Sin CC con hallazgos respondidos por Admin para este líder en el rango.</div>
            </td>`;
          return;
        }

        const rows = items.map((it, idx) => {
          const admin = Number(it.total_admin)||0;
          const all   = Number(it.total_all)||0;
          const pct   = all ? Math.round((admin*100)/all) : 0;
          const barCls = pct >= 30 ? 'bg-danger' : (pct >= 10 ? 'bg-warning' : 'bg-success');
          return `
            <tr>
              <td style="width:50px">${idx+1}</td>
              <td>${escapeHtml(it.cc)}</td>
              <td><span class="badge bg-danger">${admin}</span></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <div class="progress" style="width:90px;height:8px;">
                    <div class="progress-bar ${barCls}" role="progressbar" style="width:${pct}%"></div>
                  </div>
                  <small>${pct}%</small>
                </div>
              </td>
              <td style="width:120px">
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= BASE_URL ?>/hallazgos/listado.php?estado=respondido_admin&desde=${encodeURIComponent(DESDE2)}&hasta=${encodeURIComponent(HASTA2)}&centro_id=${encodeURIComponent(it.cc_id)}">
                  Ver
                </a>
              </td>
            </tr>
          `;
        }).join('');

        detailRow.innerHTML = `
          <td></td>
          <td colspan="4">
            <div class="p-2 bg-white border rounded">
              <div class="mb-2"><b>CC con "respondido por Admin" de ${escapeHtml(nombre)}</b></div>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:50px">#</th>
                      <th>CC</th>
                      <th>Total Admin</th>
                      <th>%</th>
                      <th style="width:120px"></th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>
          </td>`;
      } catch (e) {
        detailRow.innerHTML = `
          <td></td>
          <td colspan="4">
            <div class="p-2 bg-light border rounded text-danger">Error: ${escapeHtml(e.message||e)}</div>
          </td>`;
      }
    });
  }

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m =>
      m==='&'?'&amp;':m==='<'?'&lt;':m==='>'?'&gt;':m==='"'?'&quot;':'&#39;'
    );
  }
});
</script>

<style>
.kpi-card {
  transition: all 0.2s ease-in-out;
  cursor: pointer;
}

.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
}

/* Mejorar la legibilidad de los tooltips */
.tooltip {
  --bs-tooltip-bg: rgba(33, 37, 41, 0.95);
}

.tooltip .tooltip-inner {
  text-align: left;
  line-height: 1.4;
  padding: 8px 12px;
}

/* Destacar el texto dentro de los tooltips */
.tooltip .text-success {
  color: #75b798 !important;
  font-weight: 600;
}

.tooltip .text-warning {
  color: #ffc107 !important;
  font-weight: 600;
}
</style>