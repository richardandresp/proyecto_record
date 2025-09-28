<?php
// public/reportes.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
login_required();

$pdo = getDB();

$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

// Catálogos
$zonas   = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$centros = $pdo->query("SELECT id, nombre, zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Filtros (30 días por defecto)
$hoy    = (new DateTime())->format('Y-m-d');
$hace30 = (new DateTime('-30 days'))->format('Y-m-d');

$desde     = $_GET['desde']     ?? $hace30;
$hasta     = $_GET['hasta']     ?? $hoy;
$f_zona    = (int)($_GET['zona_id']    ?? 0);
$f_centro  = (int)($_GET['centro_id']  ?? 0);
$f_pdv     = trim($_GET['pdv']    ?? '');
$f_asesor  = trim($_GET['asesor'] ?? '');

// WHERE base (para KPIs y capa inicial Zonas)
$where  = [];
$params = [];
$where[] = "h.fecha BETWEEN ? AND ?";
$params[] = $desde; $params[] = $hasta;

if ($f_zona)   { $where[] = "h.zona_id = ?";   $params[] = $f_zona; }
if ($f_centro) { $where[] = "h.centro_id = ?"; $params[] = $f_centro; }
if ($f_pdv !== '') {
  $where[] = "(h.pdv_codigo = ? OR h.nombre_pdv LIKE ?)";
  $params[] = $f_pdv; $params[] = "%$f_pdv%";
}
if ($f_asesor !== '') {
  $where[] = "h.cedula LIKE ?";
  $params[] = "%$f_asesor%";
}

// Visibilidad por rol
$join = '';
if ($rol === 'lider') {
  $join = "JOIN lider_centro lc ON lc.centro_id = h.centro_id
           AND h.fecha >= lc.desde
           AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
           AND lc.usuario_id = ?";
  array_unshift($params, $uid);
} elseif ($rol === 'supervisor') {
  $join = "JOIN supervisor_zona sz ON sz.zona_id = h.zona_id
           AND h.fecha >= sz.desde
           AND (sz.hasta IS NULL OR h.fecha <= sz.hasta)
           AND sz.usuario_id = ?";
  array_unshift($params, $uid);
} elseif ($rol === 'auxiliar') {
  $join = "JOIN auxiliar_centro ax ON ax.centro_id = h.centro_id
           AND h.fecha >= ax.desde
           AND (ax.hasta IS NULL OR h.fecha <= ax.hasta)
           AND ax.usuario_id = ?";
  array_unshift($params, $uid);
}

// KPIs
$sqlKpi = "
  SELECT COUNT(*) total,
         SUM(h.estado='pendiente') pend,
         SUM(h.estado='vencido') venc,
         SUM(h.estado='respondido_lider') resp_lider,
         SUM(h.estado='respondido_admin') resp_admin
  FROM hallazgo h
  $join
  WHERE ".implode(' AND ', $where);
$st = $pdo->prepare($sqlKpi);
$st->execute($params);
$kpi = $st->fetch() ?: ['total'=>0,'pend'=>0,'venc'=>0,'resp_lider'=>0,'resp_admin'=>0];

// ZONAS capa inicial
$sqlTopZ = "
  SELECT z.id AS zona_id, z.nombre AS zona, COUNT(*) AS conteo
  FROM hallazgo h
  JOIN zona z ON z.id=h.zona_id
  $join
  WHERE ".implode(' AND ', $where)."
  GROUP BY z.id, z.nombre
  ORDER BY conteo DESC, z.nombre ASC
  LIMIT 20";
$st = $pdo->prepare($sqlTopZ);
$st->execute($params);
$topZonas = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/spinner.html';
?>
<style>
  /* Sangrías + resaltado por nivel para el drill-down */
  #tbl-drill tbody tr.lvl-1 td:nth-child(2) { padding-left: .25rem; }
  #tbl-drill tbody tr.lvl-2 td:nth-child(2) { padding-left: 1.75rem; }
  #tbl-drill tbody tr.lvl-3 td:nth-child(2) { padding-left: 3.25rem; }
  #tbl-drill tbody tr.lvl-4 td:nth-child(2) { padding-left: 4.75rem; }

  .btn-toggle { width: 2rem; padding: .1rem .25rem; }

  #tbl-drill tr.lvl-1.expanded { background: #eef6ff; border-left: 4px solid #0d6efd; }
  #tbl-drill tr.lvl-2.expanded { background: #effaf3; border-left: 4px solid #198754; }
  #tbl-drill tr.lvl-3.expanded { background: #fff6ef; border-left: 4px solid #fd7e14; }

  #tbl-drill tr.context.ctx-2 { background: #f8fbff; }
  #tbl-drill tr.context.ctx-3 { background: #f5fbf7; }
  #tbl-drill tr.context.ctx-4 { background: #fff8f2; }

  #tbl-drill tbody tr { transition: background-color .18s ease, border-color .18s ease; }

  /* Typeahead */
  .dropdown-menu.typeahead { max-height: 260px; overflow:auto; }
  .typeahead .dropdown-item.small, .typeahead .dropdown-item small { opacity: .8; }
  .typeahead .dropdown-item.active { background: #0d6efd; color: #fff; }
</style>

<div class="container">
  <h3>Reportes</h3>

  <!-- Filtros -->
  <form class="row g-3 mb-3">
    <div class="col-md-2">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($desde) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($hasta) ?>">
    </div>

    <div class="col-md-2">
      <label class="form-label">Zona</label>
      <select name="zona_id" id="f_zona" class="form-select">
        <option value="0">Todas</option>
        <?php foreach($zonas as $z): ?>
          <option value="<?= (int)$z['id'] ?>" <?= ($f_zona==$z['id'])?'selected':'' ?>><?= htmlspecialchars($z['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2">
      <label class="form-label">Centro de Costo</label>
      <select name="centro_id" id="f_centro" class="form-select">
        <option value="0">Todos</option>
        <?php foreach($centros as $c): ?>
          <option data-z="<?= (int)$c['zona_id'] ?>" value="<?= (int)$c['id'] ?>" <?= ($f_centro==$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- PDV con typeahead -->
    <div class="col-md-2 position-relative">
      <label class="form-label">PDV (código o nombre)</label>
      <input type="text" id="inp_pdv" name="pdv" class="form-control" autocomplete="off"
             placeholder="Ej: 1053 o SHOPPING" value="<?= htmlspecialchars($f_pdv) ?>">
      <div id="box_pdv" class="dropdown-menu w-100 typeahead"></div>
      <div class="form-text">Escribe y elige un PDV de la lista.</div>
    </div>

    <!-- Asesora con typeahead -->
    <div class="col-md-2 position-relative">
      <label class="form-label">Asesora (cédula o nombre)</label>
      <input type="text" id="inp_asesor" name="asesor" class="form-control" autocomplete="off"
             placeholder="Ej: 1030… o MARÍA" value="<?= htmlspecialchars($f_asesor) ?>">
      <div id="box_asesor" class="dropdown-menu w-100 typeahead"></div>
      <div class="form-text">Escribe y elige una asesora de la lista.</div>
    </div>

    <div class="col-12 d-flex gap-2 align-items-end">
      <button class="btn btn-primary">Aplicar</button>
      <a class="btn btn-secondary" href="<?= BASE_URL ?>/reportes.php">Limpiar</a>
    </div>
  </form>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Total hallazgos</div>
        <div class="h3 mb-0"><?= (int)$kpi['total'] ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Pendientes</div>
        <div class="h3 mb-0"><?= (int)$kpi['pend'] ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Vencidos</div>
        <div class="h3 mb-0"><?= (int)$kpi['venc'] ?></div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Respondidos</div>
        <span class="badge bg-success me-1">Líder: <?= (int)$kpi['resp_lider'] ?></span>
        <span class="badge bg-primary">Admin: <?= (int)$kpi['resp_admin'] ?></span>
      </div></div>
    </div>
  </div>

  <!-- Tabla única con Drill-down -->
  <div class="card shadow-sm">
    <div class="card-body">
      <h6 class="mb-3">Top Zonas → Centros → PDV → Asesoras</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle" id="tbl-drill">
          <thead class="table-dark">
            <tr>
              <th style="width:42px;"></th>
              <th>Descripción</th>
              <th style="width:160px" class="text-end">Hallazgos</th>
            </tr>
          </thead>
          <tbody id="drill-body">
            <?php if (!$topZonas): ?>
              <tr><td></td><td class="text-center" colspan="2">Sin datos</td></tr>
            <?php else: foreach($topZonas as $r): ?>
              <tr class="lvl-1" data-zona-id="<?= (int)$r['zona_id'] ?>">
                <td><button class="btn btn-sm btn-outline-primary btn-toggle" data-level="zona" data-open="0">+</button></td>
                <td><b>Zona:</b> <?= htmlspecialchars($r['zona']) ?></td>
                <td class="text-end"><span class="badge bg-secondary"><?= (int)$r['conteo'] ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <small class="text-muted">Clic en “+” para desplegar; “–” para contraer.</small>
    </div>
  </div>

  <!-- No respondidos por Líder -->
  <div class="card mt-4 mb-5 shadow-sm">
    <div class="card-body">
      <h6 class="mb-3">Hallazgos sin respuesta del Líder (pendientes + vencidos)</h6>
      <?php
        $st = $pdo->prepare("
          SELECT COALESCE(u.nombre, '—') AS lider,
                 SUM(h.estado IN ('pendiente','vencido')) AS sin_responder
          FROM hallazgo h
          LEFT JOIN usuario u ON u.id = h.lider_id
          $join
          WHERE ".implode(' AND ', $where)."
          GROUP BY h.lider_id, u.nombre
          HAVING sin_responder > 0
          ORDER BY sin_responder DESC, lider ASC
          LIMIT 20");
        $st->execute($params);
        $noRespondidos = $st->fetchAll(PDO::FETCH_ASSOC);
      ?>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-dark"><tr><th>#</th><th>Líder</th><th class="text-end">Sin responder</th></tr></thead>
          <tbody>
            <?php if (!$noRespondidos): ?>
              <tr><td colspan="3" class="text-center">Sin datos</td></tr>
            <?php else: $k=1; foreach($noRespondidos as $r): ?>
              <tr>
                <td><?= $k++ ?></td>
                <td><?= htmlspecialchars($r['lider']) ?></td>
                <td class="text-end"><span class="badge bg-danger"><?= (int)$r['sin_responder'] ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Filtro dependiente (centro por zona en filtros)
  const fz = document.getElementById('f_zona');
  const fc = document.getElementById('f_centro');
  const original = Array.from(fc.options).map(o => ({v:o.value,t:o.textContent,z:o.getAttribute('data-z')||'0'}));
  function applyFilter(){
    const z = fz.value; fc.innerHTML='';
    const optAll = document.createElement('option'); optAll.value='0'; optAll.textContent='Todos'; fc.appendChild(optAll);
    original.forEach(o => {
      if (o.v==='0') return;
      if (z==='0' || o.z===z) {
        const op=document.createElement('option');
        op.value=o.v; op.textContent=o.t; op.dataset.z=o.z;
        if (op.value==='<?= (int)$f_centro ?>') op.selected = true;
        fc.appendChild(op);
      }
    });
  }
  fz.addEventListener('change', applyFilter); applyFilter();

  // Drill-down
  const body  = document.getElementById('drill-body');
  const base  = '<?= rtrim(BASE_URL,"/") ?>';
  const desde = '<?= htmlspecialchars($desde) ?>';
  const hasta = '<?= htmlspecialchars($hasta) ?>';

  body.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.btn-toggle'); if (!btn) return;
    const tr  = btn.closest('tr');
    const level = btn.dataset.level || '';
    const isOpen = btn.dataset.open === '1';

    if (isOpen) { // contraer
      setExpanded(tr, btn, false);
      collapseChildren(tr, true);
      return;
    }

    // expandir
    setExpanded(tr, btn, true);
    btn.disabled = true; btn.textContent = '…';

    try {
      if (level === 'zona') {
        const zid = tr.getAttribute('data-zona-id');
        const url = `${base}/api/cc_por_zona.php?zona_id=${encodeURIComponent(zid)}&desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`;
        const j = await fetchJson(url);
        const rows = (j.ok && Array.isArray(j.centros)) ? j.centros : [];
        insertChildren(tr, rows.map(r => ({
          type:'centro', id:r.id,
          label:`Centro: ${escapeHtml(r.cc)}`,
          count: Number(r.conteo||0)
        })));
      } else if (level === 'centro') {
        const cid = tr.getAttribute('data-centro-id');
        const url = `${base}/api/pdv_por_centro.php?centro_id=${encodeURIComponent(cid)}&desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`;
        const j = await fetchJson(url);
        const rows = (j.ok && Array.isArray(j.pdv)) ? j.pdv : [];
        insertChildren(tr, rows.map(r => ({
          type:'pdv', id:(r.pdv_codigo||''), extra: cid,
          label:`PDV [${escapeHtml(r.pdv_codigo||'')}] — ${escapeHtml(r.nombre_pdv||'')}`,
          count:Number(r.conteo||0)
        })));
      } else if (level === 'pdv') {
        const cid = tr.getAttribute('data-centro-id');
        const cod = tr.getAttribute('data-pdv-codigo');
        const url = `${base}/api/asesoras_por_pdv.php?centro_id=${encodeURIComponent(cid)}&pdv_codigo=${encodeURIComponent(cod)}&desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`;
        const j = await fetchJson(url);
        const rows = (j.ok && Array.isArray(j.asesoras)) ? j.asesoras : [];
        insertChildren(tr, rows.map(r => ({
          type:'asesor',
          label:`Asesora cédula: ${escapeHtml(r.cedula||'')}`,
          count:Number(r.conteo||0)
        })));
      }

      btn.textContent = '–'; btn.dataset.open='1';
    } catch (e) {
      console.error(e);
      btn.textContent = '×';
      setTimeout(()=>{ btn.textContent = '+'; btn.dataset.open='0'; setExpanded(tr, btn, false); }, 1000);
    } finally {
      btn.disabled = false;
    }
  });

  // === Helpers drill ===
  function setExpanded(tr, btn, expand){
    if (expand) {
      btn.textContent = '–';
      btn.classList.remove('btn-outline-primary');
      btn.classList.add('btn-primary');
      btn.dataset.open = '1';
      tr.classList.add('expanded');
    } else {
      btn.textContent = '+';
      btn.classList.remove('btn-primary');
      btn.classList.add('btn-outline-primary');
      btn.dataset.open = '0';
      tr.classList.remove('expanded');
    }
  }
  function fetchJson(url){ return fetch(url, {credentials:'same-origin'}).then(r => r.json()); }
  function getLevel(tr){ const m = (tr.className||'').match(/lvl-(\d+)/); return m ? parseInt(m[1],10) : 0; }
  function collapseChildren(parentTr, removeContext=false){
    const parentLvl = getLevel(parentTr);
    let next = parentTr.nextElementSibling;
    while (next) {
      const lvl = getLevel(next);
      if (!lvl || lvl <= parentLvl) break;
      if (removeContext) next.classList.remove('context','ctx-2','ctx-3','ctx-4');
      const toRemove = next; next = next.nextElementSibling; toRemove.remove();
    }
  }
  function insertChildren(parentTr, items){
    collapseChildren(parentTr, true);
    const nextLvl = getLevel(parentTr) + 1;
    let after = parentTr;

    if (!items.length) {
      const tr = document.createElement('tr');
      tr.className = `lvl-${nextLvl} context ctx-${nextLvl}`;
      tr.innerHTML = `<td></td><td class="text-muted">Sin datos</td><td></td>`;
      after.insertAdjacentElement('afterend', tr);
      return;
    }

    items.forEach(it => {
      const tr = document.createElement('tr');
      tr.className = `lvl-${nextLvl} context ctx-${nextLvl}`;
      let btnHtml = ''; let setAttrs = (node) => {};
      if (it.type === 'centro') {
        btnHtml = `<button class="btn btn-sm btn-outline-primary btn-toggle" data-level="centro" data-open="0">+</button>`;
        setAttrs = (node) => node.setAttribute('data-centro-id', String(it.id));
      } else if (it.type === 'pdv') {
        btnHtml = `<button class="btn btn-sm btn-outline-primary btn-toggle" data-level="pdv" data-open="0">+</button>`;
        setAttrs = (node) => {
          node.setAttribute('data-centro-id', String(it.extra));
          node.setAttribute('data-pdv-codigo', String(it.id));
        };
      }
      tr.innerHTML = `
        <td>${btnHtml}</td>
        <td>${escapeHtml(it.label||'')}</td>
        <td class="text-end"><span class="badge ${it.type==='asesor'?'bg-info':'bg-secondary'}">${Number(it.count||0)}</span></td>
      `;
      setAttrs(tr);
      after.insertAdjacentElement('afterend', tr);
      after = tr;
    });
  }
  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m =>
      m==='&'?'&amp':m==='<'?'&lt':m==='>'?'&gt':m==='"'?'&quot':'&#39;'
    );
  }

  // ====== TYPEAHEADS ======
  const BASE = '<?= rtrim(BASE_URL,"/") ?>';

  initTypeahead({
    input:  document.getElementById('inp_pdv'),
    menu:   document.getElementById('box_pdv'),
    source: async (q)=>{
      const zid = fz?.value||'0', cid = fc?.value||'0';
      const url = `${BASE}/api/pdv_suggest.php?q=${encodeURIComponent(q)}&zona_id=${encodeURIComponent(zid)}&centro_id=${encodeURIComponent(cid)}`;
      const r = await fetch(url,{credentials:'same-origin'}); return r.ok? (await r.json()) : [];
    },
    renderItem: (it)=>{
      const code = escapeHtml(it.codigo||'');
      const name = escapeHtml(it.nombre||'');
      const centro = escapeHtml(it.centro||'');
      const zona = escapeHtml(it.zona||'');
      return `<div class="dropdown-item" data-value="${code}">
                <div><b>${code}</b> — ${name}</div>
                <small>${centro} • ${zona}</small>
              </div>`;
    },
    valueOf: (it)=> (it.codigo || '')
  });

  initTypeahead({
    input:  document.getElementById('inp_asesor'),
    menu:   document.getElementById('box_asesor'),
    source: async (q)=>{
      const url = `${BASE}/api/asesor_suggest.php?q=${encodeURIComponent(q)}`;
      const r = await fetch(url,{credentials:'same-origin'}); return r.ok? (await r.json()) : [];
    },
    renderItem: (it)=>{
      const ced = escapeHtml(it.cedula||'');
      const nom = escapeHtml(it.nombre||'');
      return `<div class="dropdown-item" data-value="${ced}">
                <div><b>${ced}</b> — ${nom}</div>
              </div>`;
    },
    valueOf: (it)=> (it.cedula || '')
  });

  // ---- Utilidades Typeahead ----
  function debounce(fn,ms=250){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }

  function initTypeahead({input, menu, source, renderItem, valueOf}) {
    if (!input || !menu) return;
    let items = [];
    let idx = -1;

    const openMenu = ()=>{ menu.classList.add('show'); };
    const closeMenu= ()=>{ menu.classList.remove('show'); idx=-1; };
    const setItems = (arr)=>{
      items = arr || [];
      if (!items.length) { closeMenu(); return; }
      menu.innerHTML = items.map(renderItem).join('');
      openMenu();
    };

    input.addEventListener('input', debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) { closeMenu(); return; }
      try { setItems(await source(q)); } catch { closeMenu(); }
    }, 200));

    input.addEventListener('blur', ()=> setTimeout(closeMenu, 120));

    menu.addEventListener('click', (ev)=>{
      const el = ev.target.closest('.dropdown-item'); if (!el) return;
      const i = Array.from(menu.children).indexOf(el);
      apply(i);
    });

    input.addEventListener('keydown', (e)=>{
      if (!menu.classList.contains('show')) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter')  { e.preventDefault(); apply(idx>=0?idx:0); }
      else if (e.key === 'Escape') { closeMenu(); }
    });

    function move(delta){
      const children = Array.from(menu.children);
      if (!children.length) return;
      idx = (idx + delta + children.length) % children.length;
      children.forEach(c=>c.classList.remove('active'));
      const cur = children[idx];
      cur.classList.add('active');
      cur.scrollIntoView({block:'nearest'});
    }

    function apply(i){
      if (!items[i]) return;
      input.value = valueOf(items[i]);
      closeMenu();
    }
  }
});
</script>
