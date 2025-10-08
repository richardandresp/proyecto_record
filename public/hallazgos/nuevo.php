<?php
declare(strict_types=1);

// Requisitos para esta página:
$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access','auditoria.hallazgo.list'];

// Boot común
require_once __DIR__ . '/../../includes/page_boot.php';

// (desde aquí continúa tu código actual de listado… ya tienes $pdo, $uid, $rol)


$pdo   = getDB();
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$hoy = (new DateTime('now'))->format('Y-m-d');

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4 mb-3">Nuevo hallazgo</h1>

  <form id="frm-hallazgo"
        method="post"
        action="<?= BASE_URL ?>/hallazgos/nuevo_guardar.php"
        enctype="multipart/form-data"
        data-spinner>
    <div class="row g-3">
      <div class="col-12 col-md-3">
        <label class="form-label">Fecha *</label>
        <input type="date" class="form-control" name="fecha" id="fecha"
               value="<?= htmlspecialchars($hoy) ?>"
               max="<?= htmlspecialchars($hoy) ?>" required>
        <div class="form-text" id="hint_fecha"></div>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Zona *</label>
        <select class="form-select" name="zona_id" id="zona_id" required>
          <option value="">Seleccione...</option>
          <?php foreach ($zonas as $z): ?>
            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6">
        <label class="form-label">Centro de costo *</label>
        <select class="form-select" name="centro_id" id="centro_id" required>
          <option value="">Seleccione una zona primero...</option>
        </select>
        <div class="form-text" id="hint_cc"></div>
      </div>

      <!-- PDV -->
      <div class="col-6 col-md-3">
        <label class="form-label">Código PDV *</label>
        <div class="input-group">
          <input type="text" class="form-control" name="pdv_codigo" id="pdv_codigo" required>
          <button class="btn btn-outline-secondary" type="button" id="btnBuscarPDV" title="Buscar PDV por nombre/código">Buscar</button>
        </div>
        <div class="form-text" id="hint_pdv"></div>
      </div>
      <div class="col-6 col-md-9">
        <label class="form-label">Nombre PDV *</label>
        <input type="text" class="form-control" name="nombre_pdv" id="nombre_pdv" required>
      </div>

      <!-- Asesor -->
      <div class="col-6 col-md-3">
        <label class="form-label">Cédula Asesor *</label>
        <div class="input-group">
          <input type="text" class="form-control" name="cedula" id="cedula" required>
          <button class="btn btn-outline-secondary" type="button" id="btnBuscarAsesor" title="Buscar asesor por cédula">Buscar</button>
        </div>
        <div class="form-text" id="hint_asesor"></div>
      </div>
      <div class="col-6 col-md-9">
        <label class="form-label">Nombre Asesor</label>
        <input type="text" class="form-control" name="asesor_nombre" id="asesor_nombre"
               placeholder="Se completa si existe; si no, se registrará al guardar">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Raspas faltantes</label>
        <input type="number" class="form-control" name="raspas_faltantes" min="0" step="1" value="0">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Faltante dinero ($)</label>
        <input type="text" class="form-control money" name="faltante_dinero" id="faltante_dinero" placeholder="$ 0,00">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Sobrante dinero ($)</label>
        <input type="text" class="form-control money" name="sobrante_dinero" id="sobrante_dinero" placeholder="$ 0,00">
      </div>

      <div class="col-12">
        <label class="form-label">Observaciones *</label>
        <textarea class="form-control" name="observaciones" rows="3" required></textarea>
      </div>

      <div class="col-12">
        <label class="form-label">Evidencia (opcional)</label>
        <input type="file" class="form-control" name="evidencia" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx">
      </div>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <a href="<?= BASE_URL ?>/hallazgos/listado.php" class="btn btn-secondary">Cancelar</a>
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
  const BASE   = '<?= rtrim(BASE_URL, "/") ?>';
  const form   = document.getElementById('frm-hallazgo');
  const fechaIn= document.getElementById('fecha');
  const hintFecha = document.getElementById('hint_fecha');

  const selZona= document.getElementById('zona_id');
  const selCC  = document.getElementById('centro_id');
  const hintCC = document.getElementById('hint_cc');

  const pdvCod = document.getElementById('pdv_codigo');
  const pdvNom = document.getElementById('nombre_pdv');

  const cedula = document.getElementById('cedula');
  const asNom  = document.getElementById('asesor_nombre');

  const hintPDV = document.getElementById('hint_pdv');
  const hintAs  = document.getElementById('hint_asesor');

  function debounce(fn, ms=350){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

  // ====== Fecha: no futuro ======
  function checkFecha(){
    hintFecha.textContent = '';
    const val = fechaIn.value;
    if (!val) return;
    const today = '<?= $hoy ?>';
    if (val > today) {
      hintFecha.textContent = 'La fecha no puede ser mayor a hoy.';
      fechaIn.classList.add('is-invalid');
    } else {
      fechaIn.classList.remove('is-invalid');
    }
  }
  fechaIn.addEventListener('change', checkFecha);
  fechaIn.addEventListener('blur', checkFecha);

  // ====== Cargar CC por zona (API: centros_por_zona.php) ======
  async function loadCentros(zonaId){
    selCC.innerHTML = '<option value="">Cargando centros…</option>';
    hintCC.textContent = '';
    if (!zonaId) {
      selCC.innerHTML = '<option value="">Seleccione una zona primero...</option>';
      return;
    }
    try {
      const url = `${BASE}/api/centros_por_zona.php?zona_id=${encodeURIComponent(zonaId)}`;
      const resp = await fetch(url, { credentials: 'same-origin' });
      const txt  = await resp.text();
      if (!resp.ok) throw new Error(txt || `http ${resp.status}`);
      if (txt.trim().startsWith('<')) throw new Error('html-response');
      const j = JSON.parse(txt);

      const arr = (j && j.ok && Array.isArray(j.centros)) ? j.centros : [];
      selCC.innerHTML = '';
      if (!arr.length) {
        selCC.innerHTML = '<option value="">(Sin centros activos)</option>';
        hintCC.textContent = 'La zona seleccionada no tiene centros activos.';
        return;
      }
      selCC.appendChild(new Option('Seleccione...', ''));
      arr.forEach(cc => selCC.appendChild(new Option(cc.nombre, cc.id)));
    } catch(e){
      console.error(e);
      selCC.innerHTML = '<option value="">Error cargando centros</option>';
      hintCC.textContent = 'No se pudieron cargar los centros de costo.';
    }
  }

  selZona?.addEventListener('change', () => loadCentros(selZona.value));
  // Carga inicial si ya hay una zona seleccionada (o deja vacío)
  if (selZona && selZona.value) loadCentros(selZona.value);

  // ====== LOOKUP PDV por código + centro ======
  async function lookupPDV(){
    hintPDV.textContent = '';
    const codigo = (pdvCod.value || '').trim();
    const ccId   = selCC.value || '';
    if (!codigo || !ccId) return;
    try {
      const url = `${BASE}/api/pdv_lookup.php?codigo=${encodeURIComponent(codigo)}&centro_id=${encodeURIComponent(ccId)}`;
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) { hintPDV.textContent = 'No se pudo consultar el PDV.'; return; }
      const data = await resp.json();
      if (data && data.nombre) {
        pdvNom.value = data.nombre;
        hintPDV.textContent = 'PDV encontrado y cargado.';
      } else {
        hintPDV.textContent = 'PDV no existe en este centro: se registrará al guardar.';
      }
    } catch { hintPDV.textContent = 'Error consultando PDV.'; }
  }
  pdvCod.addEventListener('blur', lookupPDV);
  pdvCod.addEventListener('input', debounce(lookupPDV, 500));
  selCC.addEventListener('change', () => { if (pdvCod.value.trim()) lookupPDV(); });

  // ====== LOOKUP Asesor por cédula ======
  async function lookupAsesor(){
    hintAs.textContent = '';
    const ced = (cedula.value || '').trim();
    if (!ced) return;
    try {
      const url = `${BASE}/api/asesor_lookup.php?cedula=${encodeURIComponent(ced)}`;
      const resp = await fetch(url, { credentials: 'same-origin' });
      if (!resp.ok) { hintAs.textContent = 'No se pudo consultar el asesor.'; return; }
      const data = await resp.json();
      if (data && data.nombre) {
        asNom.value = data.nombre;
        hintAs.textContent = 'Asesor encontrado y cargado.';
      } else {
        hintAs.textContent = 'Asesor no existe: se registrará al guardar.';
      }
    } catch { hintAs.textContent = 'Error consultando asesor.'; }
  }
  cedula.addEventListener('blur', lookupAsesor);
  cedula.addEventListener('input', debounce(lookupAsesor, 500));

  // ====== Botones de búsqueda PDV ======
  document.getElementById('btnBuscarPDV')?.addEventListener('click', async () => {
    if (!selZona.value) { alert('Seleccione Zona'); return; }
    if (!selCC.value)   { alert('Seleccione Centro de costo'); return; }
    const pdvCentroSel = document.getElementById('pdv_centro');
    const pdvQ = document.getElementById('pdv_q');
    const pdvResults = document.getElementById('pdv_results');
    pdvCentroSel.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = selCC.value; opt.textContent = selCC.options[selCC.selectedIndex].text;
    pdvCentroSel.appendChild(opt);
    pdvQ.value = '';
    pdvResults.innerHTML = '<tr><td colspan="4" class="text-muted">Sin resultados.</td></tr>';
    new bootstrap.Modal(document.getElementById('modalPDV')).show();
  });

  document.getElementById('pdv_go')?.addEventListener('click', async () => {
    const pdvCentroSel = document.getElementById('pdv_centro');
    const pdvQ = document.getElementById('pdv_q');
    const pdvResults = document.getElementById('pdv_results');
    const q = (pdvQ.value || '').trim();
    const cc = pdvCentroSel.value || '';
    if (!cc) { alert('Centro requerido'); return; }
    try {
      const url = `${BASE}/api/pdv_buscar.php?centro_id=${encodeURIComponent(cc)}&q=${encodeURIComponent(q)}`;
      const resp = await fetch(url, { credentials: 'same-origin' });
      const data = resp.ok ? await resp.json() : [];
      if (!Array.isArray(data) || data.length===0) {
        pdvResults.innerHTML = '<tr><td colspan="4" class="text-muted">Sin coincidencias.</td></tr>'; return;
      }
      pdvResults.innerHTML = '';
      data.forEach(it => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><code>${escapeHtml(it.codigo)}</code></td>
          <td>${escapeHtml(it.nombre)}</td>
          <td>${escapeHtml(it.centro)}</td>
          <td><button type="button" class="btn btn-sm btn-primary">Elegir</button></td>`;
        tr.querySelector('button').addEventListener('click', () => {
          pdvCod.value = it.codigo;
          pdvNom.value = it.nombre;
          document.getElementById('hint_pdv').textContent = 'PDV encontrado y cargado.';
          bootstrap.Modal.getInstance(document.getElementById('modalPDV')).hide();
        });
        pdvResults.appendChild(tr);
      });
    } catch {
      pdvResults.innerHTML = '<tr><td colspan="4" class="text-danger">Error buscando PDV.</td></tr>';
    }
  });

  document.getElementById('btnBuscarAsesor')?.addEventListener('click', lookupAsesor);

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m =>
      m==='&' ? '&amp;' : m==='<' ? '&lt;' : m==='>' ? '&gt;' : m==='"' ? '&quot;' : '&#39;'
    );
  }

  // ====== Formato de moneda (UI) ======
  const fmt = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 2 });
  function normalizeMoneyInput(el){
    const raw = (el.value || '').toString();
    const num = Number(raw.replace(/[^\d,-.]/g,'').replace(/\./g,'').replace(',', '.'));
    if (!isNaN(num)) el.value = fmt.format(num);
  }
  document.querySelectorAll('input.money').forEach(el => el.addEventListener('blur', () => normalizeMoneyInput(el)));

  // ====== Submit con validaciones ======
  let sending = false;
  form.addEventListener('submit', async (ev) => {
    if (sending) return;
    ev.preventDefault();

    checkFecha();
    if (fechaIn.classList.contains('is-invalid')) return;

    const fecha  = fechaIn.value || '';
    const zonaOk = selZona?.value;
    const ccOk   = selCC?.value;
    const pdvOk  = (pdvCod?.value || '').trim() && (pdvNom?.value || '').trim();
    const cedOk  = (cedula?.value || '').trim();
    const obsOk  = (form.querySelector('textarea[name="observaciones"]')?.value.trim() || '').length > 0;

    if (!fecha || !zonaOk || !ccOk || !pdvOk || !cedOk || !obsOk) {
      await Swal.fire({ icon:'warning', title:'Campos obligatorios', text:'Completa los campos con *' });
      return;
    }

    const zonaTx = selZona.options[selZona.selectedIndex]?.text || '';
    const ccTx   = selCC.options[selCC.selectedIndex]?.text || '';
    const resumen = `
      <div class="text-start">
        <p><b>¿Confirmas guardar este hallazgo?</b></p>
        <ul>
          <li><b>Fecha:</b> ${fecha}</li>
          <li><b>Zona:</b> ${escapeHtml(zonaTx)}</li>
          <li><b>CC:</b> ${escapeHtml(ccTx)}</li>
          <li><b>PDV:</b> [${escapeHtml(pdvCod.value.trim())}] ${escapeHtml(pdvNom.value.trim())}</li>
          <li><b>Asesor:</b> ${escapeHtml(cedula.value.trim())} — ${escapeHtml(asNom.value.trim())}</li>
        </ul>
      </div>
    `;

    const ok = (await Swal.fire({
      icon:'question', title:'Confirmar', html:resumen,
      showCancelButton:true, confirmButtonText:'Sí, guardar', cancelButtonText:'Cancelar'
    })).isConfirmed;
    if (!ok) return;

    document.querySelectorAll('input.money').forEach(el => {
      const raw = (el.value || '').toString();
      const num = Number(raw.replace(/[^\d,-.]/g,'').replace(/\./g,'').replace(',', '.'));
      if (!isNaN(num)) el.value = num.toString();
    });

    const btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn) btn.disabled = true;
    sending = true;
    form.submit();
  });
});
</script>
