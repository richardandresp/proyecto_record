<?php
// public/hallazgos/editar.php
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
$rol = $_SESSION['rol'] ?? 'lectura';
if (!in_array($rol, ['admin','auditor'], true)) { http_response_code(403); exit('No autorizado'); }

$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

// Catálogos
$zonas   = $pdo->query("SELECT id,nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$centros = $pdo->query("SELECT id,nombre,zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Hallazgo
$st = $pdo->prepare("
  SELECT h.*, z.nombre AS zona_nombre, c.nombre AS centro_nombre
  FROM hallazgo h
  JOIN zona z ON z.id=h.zona_id
  JOIN centro_costo c ON c.id=h.centro_id
  WHERE h.id=?");
$st->execute([$id]);
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) { http_response_code(404); exit('Hallazgo no encontrado'); }

// Evidencia pública (compat)
$ev = $h['evidencia_url'];
if ($ev && strpos($ev, '/uploads/') === 0) $ev = BASE_URL . $ev;

// Hoy (máximo permitido)
$hoy = (new DateTime('now'))->format('Y-m-d');

// Helpers UI dinero
function fmt_money_input($v) {
  if ($v === null || $v === '') return '';
  $n = (float)$v;
  return number_format($n, 2, ',', '.'); // solo visual; el JS vuelve a COP
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <h3 class="mb-3">Editar Hallazgo #<?= (int)$h['id'] ?></h3>

  <form class="card border-0 shadow-sm" method="post" action="<?= BASE_URL ?>/hallazgos/editar_guardar.php" enctype="multipart/form-data" data-spinner>
    <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Fecha *</label>
          <input type="date" name="fecha" id="fecha" class="form-control" required
                 value="<?= htmlspecialchars(substr($h['fecha'],0,10)) ?>" max="<?= htmlspecialchars($hoy) ?>">
          <div class="form-text" id="hint_fecha">No se permite fecha futura. Cambiar la fecha recalcula responsables y SLA (+2 días al fin del día).</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Zona *</label>
          <select name="zona_id" id="zona" class="form-select" required>
            <?php foreach($zonas as $z): ?>
              <option value="<?= (int)$z['id'] ?>" <?= ($z['id']==$h['zona_id'])?'selected':'' ?>>
                <?= htmlspecialchars($z['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Centro de Costo (CC) *</label>
          <select name="centro_id" id="centro" class="form-select" required>
            <?php foreach($centros as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-z="<?= (int)$c['zona_id'] ?>" <?= ($c['id']==$h['centro_id'])?'selected':'' ?>>
                <?= htmlspecialchars($c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="hint_cc"></div>
        </div>

        <!-- PDV: código + nombre (con lookup automático por código+CC) -->
        <div class="col-md-3">
          <label class="form-label">Código PDV *</label>
          <div class="input-group">
            <input type="text" name="pdv_codigo" id="pdv_codigo" class="form-control" required value="<?= htmlspecialchars($h['pdv_codigo']) ?>">
            <button class="btn btn-outline-secondary" type="button" id="btnBuscarPDV" title="Buscar PDV por nombre/código">Buscar</button>
          </div>
          <div class="form-text" id="hint_pdv"></div>
        </div>

        <div class="col-md-9">
          <label class="form-label">Nombre de PDV *</label>
          <input type="text" name="nombre_pdv" id="nombre_pdv" class="form-control" required maxlength="120" value="<?= htmlspecialchars($h['nombre_pdv']) ?>">
        </div>

        <!-- Asesor: cédula + nombre (lookup automático por cédula) -->
        <div class="col-md-3">
          <label class="form-label">Cédula del asesor *</label>
          <div class="input-group">
            <input type="text" name="cedula" id="cedula" class="form-control" required maxlength="20" value="<?= htmlspecialchars($h['cedula']) ?>">
            <button class="btn btn-outline-secondary" type="button" id="btnBuscarAsesor" title="Buscar asesor por cédula">Buscar</button>
          </div>
          <div class="form-text" id="hint_asesor"></div>
        </div>

        <div class="col-md-9">
          <label class="form-label">Nombre asesor</label>
          <input type="text" name="asesor_nombre" id="asesor_nombre" class="form-control" placeholder="Se completa si existe; si no, se actualizará al guardar">
        </div>

        <div class="col-md-4">
          <label class="form-label">Raspas faltantes</label>
          <input type="number" name="raspas_faltantes" class="form-control" min="0" step="1" value="<?= (int)$h['raspas_faltantes'] ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Faltante dinero ($)</label>
          <input type="text" name="faltante_dinero" id="faltante_dinero" class="form-control money" placeholder="$ 0,00" value="<?= fmt_money_input($h['faltante_dinero']) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Sobrante dinero ($)</label>
          <input type="text" name="sobrante_dinero" id="sobrante_dinero" class="form-control money" placeholder="$ 0,00" value="<?= fmt_money_input($h['sobrante_dinero']) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Observaciones auditoría *</label>
          <textarea name="observaciones" class="form-control" rows="4" required><?= htmlspecialchars($h['observaciones']) ?></textarea>
        </div>

        <div class="col-md-6">
          <label class="form-label">Evidencia (opcional, reemplaza la existente)</label>
          <input type="file" name="evidencia" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx">
          <div class="form-text">
            <?php if($h['evidencia_url']): ?>
              Actual: <a href="<?= htmlspecialchars($ev) ?>" target="_blank" rel="noopener">ver evidencia</a>
            <?php else: ?>
              No hay evidencia cargada.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
        <a class="btn btn-secondary" href="<?= BASE_URL ?>/hallazgos/detalle.php?id=<?= (int)$h['id'] ?>">Cancelar</a>
      </div>
    </div>
  </form>
</div>

<!-- Modal Buscar PDV -->
<div class="modal fade" id="modalPDV" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Buscar Punto de Venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label small">Centro de costo</label>
            <select id="pdv_centro" class="form-select form-select-sm"></select>
          </div>
          <div class="col-md-8">
            <label class="form-label small">Nombre o código</label>
            <div class="input-group">
              <input type="text" id="pdv_q" class="form-control form-control-sm" placeholder="Ej. 'SHOPPING' o '4891'">
              <button class="btn btn-primary btn-sm" id="pdv_go" type="button">Buscar</button>
            </div>
          </div>
        </div>
        <div class="table-responsive" style="max-height: 50vh;">
          <table class="table table-sm table-hover">
            <thead><tr><th>Código</th><th>Nombre</th><th>Centro</th><th></th></tr></thead>
            <tbody id="pdv_results"><tr><td colspan="4" class="text-muted">Sin resultados.</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <small class="text-muted">Seleccione un PDV para completar el formulario.</small>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const fechaIn  = document.getElementById('fecha');
  const hintFecha= document.getElementById('hint_fecha');
  const selZona  = document.getElementById('zona');
  const selCentro= document.getElementById('centro');
  const allCC    = Array.from(selCentro.options).map(o => ({v:o.value, t:o.textContent, z:o.getAttribute('data-z')||''}));
  const hintCC   = document.getElementById('hint_cc');

  const pdvCod   = document.getElementById('pdv_codigo');
  const pdvNom   = document.getElementById('nombre_pdv');
  const hintPDV  = document.getElementById('hint_pdv');

  const cedula   = document.getElementById('cedula');
  const asNom    = document.getElementById('asesor_nombre');
  const hintAs   = document.getElementById('hint_asesor');

  // Debounce utilidad
  function debounce(fn, ms=350){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

  // Fecha no futura
  function checkFecha(){
    hintFecha.textContent = '';
    const val = fechaIn.value;
    if (!val) return;
    const today = '<?= $hoy ?>';
    if (val > today) { hintFecha.textContent = 'La fecha no puede ser mayor a hoy.'; fechaIn.classList.add('is-invalid'); }
    else { fechaIn.classList.remove('is-invalid'); }
  }
  fechaIn.addEventListener('change', checkFecha);
  fechaIn.addEventListener('blur', checkFecha);

  // Filtrar CC por zona
  function applyCC(){
    const z = selZona.value;
    selCentro.innerHTML = '';
    let selectedAdded = false;
    allCC.forEach(o => {
      if (!o.v) return;
      if (!z || o.z===z) {
        const op = document.createElement('option');
        op.value=o.v; op.textContent=o.t; op.setAttribute('data-z', o.z);
        selCentro.appendChild(op);
        if (o.v === '<?= (int)$h['centro_id'] ?>') { op.selected = true; selectedAdded = true; }
      }
    });
    if (!selectedAdded) selCentro.selectedIndex = 0;
  }
  selZona.addEventListener('change', applyCC);
  applyCC();

  // Lookup PDV por código + centro
  async function lookupPDV(){
    hintPDV.textContent = '';
    const codigo = (pdvCod.value || '').trim();
    const ccId   = selCentro.value || '';
    if (!codigo || !ccId) return;
    try {
      const url = '<?= BASE_URL ?>/api/pdv_lookup.php?codigo=' + encodeURIComponent(codigo) + '&centro_id=' + encodeURIComponent(ccId);
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) { hintPDV.textContent = 'No se pudo consultar el PDV.'; return; }
      const data = await resp.json();
      if (data && data.nombre) { pdvNom.value = data.nombre; hintPDV.textContent = 'PDV encontrado y cargado.'; }
      else { hintPDV.textContent = 'PDV no existe en este centro: se actualizará/creará al guardar.'; }
    } catch { hintPDV.textContent = 'Error consultando PDV.'; }
  }
  pdvCod.addEventListener('blur', lookupPDV);
  pdvCod.addEventListener('input', debounce(lookupPDV, 500));
  selCentro.addEventListener('change', () => { if (pdvCod.value.trim()) lookupPDV(); });

  // Lookup Asesor por cédula
  async function lookupAsesor(){
    hintAs.textContent = '';
    const ced = (cedula.value || '').trim();
    if (!ced) return;
    try {
      const url = '<?= BASE_URL ?>/api/asesor_lookup.php?cedula=' + encodeURIComponent(ced);
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) { hintAs.textContent = 'No se pudo consultar el asesor.'; return; }
      const data = await resp.json();
      if (data && data.nombre) { asNom.value = data.nombre; hintAs.textContent = 'Asesor encontrado y cargado.'; }
      else { hintAs.textContent = 'Asesor no existe: se actualizará/creará al guardar.'; }
    } catch { hintAs.textContent = 'Error consultando asesor.'; }
  }
  cedula.addEventListener('blur', lookupAsesor);
  cedula.addEventListener('input', debounce(lookupAsesor, 500));

  // Modal Buscar PDV (opcional)
  const btnBuscarPDV = document.getElementById('btnBuscarPDV');
  const modalPDV = new bootstrap.Modal(document.getElementById('modalPDV'));
  const pdvCentroSel = document.getElementById('pdv_centro');
  const pdvQ = document.getElementById('pdv_q');
  const pdvResults = document.getElementById('pdv_results');

  btnBuscarPDV?.addEventListener('click', () => {
    if (!selZona.value) { alert('Seleccione Zona'); return; }
    if (!selCentro.value)   { alert('Seleccione Centro de costo'); return; }
    pdvCentroSel.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = selCentro.value; opt.textContent = selCentro.options[selCentro.selectedIndex].text;
    pdvCentroSel.appendChild(opt);
    pdvQ.value = '';
    pdvResults.innerHTML = '<tr><td colspan="4" class="text-muted">Sin resultados.</td></tr>';
    modalPDV.show();
  });

  document.getElementById('pdv_go')?.addEventListener('click', async () => {
    const cc = pdvCentroSel.value || '';
    const q  = (pdvQ.value || '').trim();
    if (!cc) { alert('Centro requerido'); return; }
    try {
      const url = '<?= BASE_URL ?>/api/pdv_buscar.php?centro_id='+encodeURIComponent(cc)+'&q='+encodeURIComponent(q);
      const resp = await fetch(url, { credentials: 'same-origin' });
      const data = resp.ok ? await resp.json() : [];
      if (!Array.isArray(data) || data.length===0) { pdvResults.innerHTML = '<tr><td colspan="4" class="text-muted">Sin coincidencias.</td></tr>'; return; }
      pdvResults.innerHTML = '';
      data.forEach(it => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><code>${escapeHtml(it.codigo)}</code></td>
          <td>${escapeHtml(it.nombre)}</td>
          <td>${escapeHtml(it.centro)}</td>
          <td><button type="button" class="btn btn-sm btn-primary">Elegir</button></td>`;
        tr.querySelector('button').addEventListener('click', () => {
          pdvCod.value = it.codigo; pdvNom.value = it.nombre; hintPDV.textContent = 'PDV encontrado y cargado.'; modalPDV.hide();
        });
        pdvResults.appendChild(tr);
      });
    } catch { pdvResults.innerHTML = '<tr><td colspan="4" class="text-danger">Error buscando PDV.</td></tr>'; }
  });

  function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, m => m==='&'?'&amp;':m==='<'?'&lt;':m==='>'?'&gt;':m==='"'?'&quot;':'&#39;'); }

  // Formato de moneda (UI)
  const fmt = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 2 });
  function normalizeMoneyInput(el){
    const raw = (el.value || '').toString();
    const num = Number(raw.replace(/[^\d,-.]/g,'').replace(/\./g,'').replace(',', '.'));
    if (!isNaN(num)) el.value = fmt.format(num);
  }
  document.querySelectorAll('input.money').forEach(el => el.addEventListener('blur', () => normalizeMoneyInput(el)));
});
</script>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
