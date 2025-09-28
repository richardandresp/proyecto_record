<?php
// public/dashboard.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
login_required();

$pdo = getDB();
$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

// Filtros (últimos 30 días por defecto)
$hoy    = (new DateTime())->format('Y-m-d');
$hace30 = (new DateTime('-30 days'))->format('Y-m-d');
$desde  = $_GET['desde'] ?? $hace30;
$hasta  = $_GET['hasta'] ?? $hoy;

// VISIBILIDAD por rol (mismo criterio del listado)
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

// Rango base
$whereRango = "h.fecha BETWEEN ? AND ?";
$paramsRango = [$desde, $hasta];

// ---------- KPIs ----------
function kpiCount($pdo, $joinVis, $paramsVis, $whereRango, $paramsRango, $extraWhere = '') {
  $sql = "SELECT COUNT(*) FROM hallazgo h
          $joinVis
          WHERE $whereRango " . ($extraWhere ? " AND $extraWhere" : "");
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($paramsVis, $paramsRango));
  return (int)$st->fetchColumn();
}

$kpiPendientes = kpiCount($pdo, $joinVis, $paramsVis, $whereRango, $paramsRango, "h.estado = 'pendiente'");
$kpiVencidos   = kpiCount($pdo, $joinVis, $paramsVis, $whereRango, $paramsRango, "h.estado = 'vencido'");
$kpiRespLider  = kpiCount($pdo, $joinVis, $paramsVis, $whereRango, $paramsRango, "h.estado = 'respondido_lider'");
$kpiRespAdmin  = kpiCount($pdo, $joinVis, $paramsVis, $whereRango, $paramsRango, "h.estado = 'respondido_admin'");

// Vencen hoy
$sqlHoy = "SELECT COUNT(*) FROM hallazgo h
           $joinVis
           WHERE $whereRango AND h.estado='pendiente' AND DATE(h.fecha_limite) = CURDATE()";
$stHoy = $pdo->prepare($sqlHoy);
$stHoy->execute(array_merge($paramsVis, $paramsRango));
$kpiVencenHoy = (int)$stHoy->fetchColumn();

// ---------- Próximos a vencer (pendientes ordenados por fecha_limite) ----------
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
$proximos = $stProx->fetchAll();

// ---------- Top Zonas (con id para usar en JS sin mapear) ----------
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
$topZonas = $stTZ->fetchAll();

// ---------- Serie para gráfica ----------
$sqlSerie = "SELECT DATE(h.fecha) AS dia,
                    SUM(h.estado='pendiente') AS p,
                    SUM(h.estado='vencido') AS v,
                    SUM(h.estado='respondido_lider') AS rl,
                    SUM(h.estado='respondido_admin') AS ra
             FROM hallazgo h
             $joinVis
             WHERE $whereRango
             GROUP BY DATE(h.fecha)
             ORDER BY dia";
$stSe = $pdo->prepare($sqlSerie);
$stSe->execute(array_merge($paramsVis, $paramsRango));
$serie = $stSe->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Dashboard</h3>
    <form method="get" class="d-flex gap-2">
      <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control">
      <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control">
      <button class="btn btn-primary">Aplicar</button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/dashboard.php">Últimos 30 días</a>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-3">
    <div class="col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted">Pendientes</div>
          <div class="display-6"><?= number_format($kpiPendientes) ?></div>
          <small class="text-muted">Dentro del rango</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted">Vencidos</div>
          <div class="display-6 text-danger"><?= number_format($kpiVencidos) ?></div>
          <small class="text-muted">SLA > 2 días</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted">Vencen hoy</div>
          <div class="display-6 text-warning"><?= number_format($kpiVencenHoy) ?></div>
          <small class="text-muted">Prioridad alta</small>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="text-muted">Respondidos</div>
          <div class="display-6 text-success"><?= number_format($kpiRespLider + $kpiRespAdmin) ?></div>
          <small class="text-muted">Líder + Admin</small>
        </div>
      </div>
    </div>
  </div>

  <!-- Gráfica por día -->
  <div class="card border-0 shadow-sm mt-4">
    <div class="card-body">
      <h5 class="card-title mb-3">Evolución (por día)</h5>
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
  <!-- Bloque de control: líderes con “respondido por Admin” -->
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
  // ===== Gráfica por día =====
  const ctx = document.getElementById('chartEstados');
  const data = <?= json_encode($serie ?: []) ?>;

  const labels = data.map(d => d.dia);
  const pendientes = data.map(d => Number(d.p || 0));
  const vencidos   = data.map(d => Number(d.v || 0));
  const respLider  = data.map(d => Number(d.rl || 0));
  const respAdmin  = data.map(d => Number(d.ra || 0));

  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Pendientes', data: pendientes, tension: .3 },
        { label: 'Vencidos', data: vencidos, tension: .3 },
        { label: 'Resp. Líder', data: respLider, tension: .3 },
        { label: 'Resp. Admin', data: respAdmin, tension: .3 },
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

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

    // toggle si ya abierto
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

  // ===== Líderes con respondido por Admin (ranking + % y detalle CC) =====
  const wrapSR = document.getElementById('sr');
  if (wrapSR) {
    const DESDE2 = wrapSR.dataset.desde || '';
    const HASTA2 = wrapSR.dataset.hasta || '';
    const tablaL = document.getElementById('tabla-top-lideres-sr');
    const tbodyL = tablaL.querySelector('tbody');

    // cargar ranking
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

    // toggle detalle por líder
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
              <div class="mb-2"><b>CC con “respondido por Admin” de ${escapeHtml(nombre)}</b></div>
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
