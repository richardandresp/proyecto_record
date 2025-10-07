<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
login_required();
require_perm('auditoria.hallazgo.reply');
require_roles(['admin','auditor','supervisor','lider','auxiliar']);

$pdo = getDB();

$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido.'); }

// Hallazgo
$st = $pdo->prepare("
  SELECT h.*, z.nombre AS zona, c.nombre AS centro
  FROM hallazgo h
  JOIN zona z ON z.id=h.zona_id
  JOIN centro_costo c ON c.id=h.centro_id
  WHERE h.id=?
");
$st->execute([$id]);
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) { http_response_code(404); exit('Hallazgo no encontrado.'); }

// Vigencia líder
if ($rol === 'lider') {
  $chk = $pdo->prepare("
    SELECT 1
    FROM lider_centro
    WHERE usuario_id=? AND centro_id=?
      AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
    LIMIT 1
  ");
  $chk->execute([$uid, (int)$h['centro_id'], $h['fecha']]);
  if (!$chk->fetch()) { http_response_code(403); exit('Fuera de tu vigencia/centro.'); }
}

// Evidencia pública si es relativa
$evid = $h['evidencia_url'] ?? null;
if ($evid && str_starts_with((string)$evid, '/uploads/')) {
  $evid = rtrim(BASE_URL,'/') . $evid;
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/spinner.html';
?>
<div class="container">
  <h3>Responder Hallazgo #<?= (int)$h['id'] ?></h3>

  <div class="card mb-3 border-0 shadow-sm">
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-3"><b>Fecha:</b> <?= htmlspecialchars($h['fecha']) ?></div>
        <div class="col-md-3"><b>Zona:</b> <?= htmlspecialchars($h['zona']) ?></div>
        <div class="col-md-3"><b>Centro:</b> <?= htmlspecialchars($h['centro']) ?></div>
        <div class="col-md-3"><b>PDV:</b> <?= htmlspecialchars($h['nombre_pdv']) ?></div>

        <div class="col-md-3"><b>Cédula asesor:</b> <?= htmlspecialchars($h['cedula']) ?></div>
        <div class="col-md-3"><b>Raspas faltantes:</b> <?= number_format((int)$h['raspas_faltantes']) ?></div>
        <div class="col-md-3"><b>Faltante $:</b> <?= number_format((float)$h['faltante_dinero'],2,',','.') ?></div>
        <div class="col-md-3"><b>Sobrante $:</b> <?= number_format((float)$h['sobrante_dinero'],2,',','.') ?></div>

        <div class="col-12">
          <b>Observaciones auditoría:</b><br><?= nl2br(htmlspecialchars($h['observaciones'])) ?>
        </div>

        <div class="col-md-6">
          <b>Evidencia (auditoría):</b>
          <?php if (!empty($h['evidencia_url'])): ?>
            <a href="<?= htmlspecialchars($evid) ?>" target="_blank" rel="noopener">Abrir evidencia</a>
          <?php else: ?>—<?php endif; ?>
        </div>
        <div class="col-md-6">
          <b>Estado actual:</b> <?= htmlspecialchars($h['estado']) ?>
          | <b>F. límite:</b> <?= htmlspecialchars($h['fecha_limite']) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- PRG a responder_guardar.php + spinner + bypass de confirmador global -->
  <form method="post"
        action="<?= BASE_URL ?>/hallazgos/responder_guardar.php"
        enctype="multipart/form-data"
        data-spinner
        id="form-responder"
        data-skip-global-confirm="1">
    <input type="hidden" name="hallazgo_id" value="<?= (int)$h['id'] ?>">
    <input type="hidden" name="__confirmed" value="0">

    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">Gestión Comercial (tu respuesta) *</label>
          <textarea name="respuesta" class="form-control" rows="5" required></textarea>
          <div class="form-text">Mínimo 10 caracteres. Luego no podrás editar (solo Admin/Auditor).</div>
        </div>

        <!-- Evidencias múltiples con inputs dinámicos -->
        <div class="mb-3">
          <label class="form-label">Evidencias (JPG/PNG/PDF, máx 5 MB c/u)</label>

          <div id="adjuntos-wrap" class="d-flex flex-column gap-2">
            <!-- input inicial -->
            <div class="input-group adj-item">
              <input type="file" name="adjuntos[]" class="form-control adj-file" accept=".jpg,.jpeg,.png,.pdf">
              <button type="button" class="btn btn-outline-danger adj-del" title="Quitar" disabled>&times;</button>
            </div>
          </div>

          <div class="mt-2 d-flex gap-2">
            <button type="button" id="adj-add" class="btn btn-outline-primary btn-sm">
              Agregar otra evidencia
            </button>
            <span id="filesList" class="form-text"></span>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit">Guardar Respuesta</button>
          <a class="btn btn-secondary" href="<?= BASE_URL ?>/hallazgos/listado.php">Volver</a>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('form-responder');
  if (!form) return;

  const flag      = form.querySelector('input[name="__confirmed"]');
  const wrap      = document.getElementById('adjuntos-wrap');
  const addBtn    = document.getElementById('adj-add');
  const filesList = document.getElementById('filesList');

  const MAX_MB  = 5;
  const ALLOWED = ['image/jpeg','image/png','application/pdf'];
  const EXT_RE  = /\.(jpe?g|png|pdf)$/i;

  // Añadir un nuevo input de archivo
  function addInput() {
    const row = document.createElement('div');
    row.className = 'input-group adj-item';
    row.innerHTML = `
      <input type="file" name="adjuntos[]" class="form-control adj-file" accept=".jpg,.jpeg,.png,.pdf">
      <button type="button" class="btn btn-outline-danger adj-del" title="Quitar">&times;</button>
    `;
    wrap.appendChild(row);
    refreshState();
  }

  // Quitar input de archivo (siempre deja al menos uno)
  function removeInput(btn) {
    const items = wrap.querySelectorAll('.adj-item');
    if (items.length <= 1) return;
    btn.closest('.adj-item')?.remove();
    refreshState();
  }

  // Actualiza estado: habilita/deshabilita el botón borrar del primer input, muestra lista
  function refreshState() {
    const items = wrap.querySelectorAll('.adj-item');
    items.forEach((it, idx) => {
      const del = it.querySelector('.adj-del');
      if (del) del.disabled = (items.length === 1 && idx === 0);
    });
    renderFiles();
  }

  // Lista/validación básica de todos los archivos de todos los inputs
  function renderFiles() {
    const allFiles = [];
    wrap.querySelectorAll('.adj-file').forEach(inp => {
      Array.from(inp.files || []).forEach(f => allFiles.push(f));
    });

    if (!allFiles.length) { filesList.textContent = 'Sin archivos seleccionados.'; return; }

    let anyError = false;
    const parts = allFiles.map(f => {
      const okType = ALLOWED.includes(f.type) || EXT_RE.test(f.name);
      const okSize = f.size <= MAX_MB*1024*1024;
      if (!okType || !okSize) anyError = true;
      return `${escapeHtml(f.name)} — ${(f.size/1024/1024).toFixed(2)} MB` +
             (!okType ? ' (tipo no permitido)' : '') +
             (!okSize ? ' (> 5 MB)' : '');
    });

    filesList.textContent = `${allFiles.length} archivo(s): ` + parts.join(' | ');
    filesList.classList.toggle('text-danger', anyError);
  }

  // Delegación de eventos
  wrap.addEventListener('change', (e) => {
    if (e.target.classList.contains('adj-file')) renderFiles();
  });
  wrap.addEventListener('click', (e) => {
    if (e.target.classList.contains('adj-del')) removeInput(e.target);
  });
  addBtn.addEventListener('click', addInput);

  // Confirmación + submit nativo (app.js muestra spinner)
  // Botón oculto para requestSubmit si existe
  let hiddenSubmit = form.querySelector('button[type="submit"]');
  if (!hiddenSubmit) {
    hiddenSubmit = document.createElement('button');
    hiddenSubmit.type = 'submit';
    hiddenSubmit.style.display = 'none';
    form.appendChild(hiddenSubmit);
  }

  form.addEventListener('submit', async (ev) => {
    if (flag && flag.value === '1') return; // ya confirmado

    ev.preventDefault();

    const txt = (form.querySelector('textarea[name="respuesta"]')?.value || '').trim();
    if (txt.length < 10) {
      if (window.Swal) await Swal.fire({icon:'warning',title:'Falta información',text:'Mínimo 10 caracteres.'});
      else alert('Mínimo 10 caracteres.');
      return;
    }

    // Recolecta y valida todos los archivos
    const allFiles = [];
    wrap.querySelectorAll('.adj-file').forEach(inp => {
      Array.from(inp.files || []).forEach(f => allFiles.push(f));
    });
    const invalid = allFiles.find(f => !(ALLOWED.includes(f.type) || EXT_RE.test(f.name)) || f.size > MAX_MB*1024*1024);
    if (invalid) {
      if (window.Swal) await Swal.fire({icon:'error',title:'Adjuntos inválidos',text:'Verifica tipo (JPG/PNG/PDF) y tamaño (≤ 5 MB c/u).'});
      else alert('Adjuntos inválidos: tipo y/o tamaño.');
      return;
    }

    const preview = txt.length > 300 ? (txt.substring(0,300) + '…') : txt;

    if (!window.Swal) {
      if (confirm(`¿Confirmas enviar esta respuesta con ${allFiles.length} evidencia(s)?`)) {
        flag.value = '1';
        form.submit();
      }
      return;
    }

    const r = await Swal.fire({
      icon:'question',
      title:'Confirmar envío',
      html: `
        <div class="text-start">
          <div class="mb-2 p-2 border rounded bg-light" style="white-space:pre-wrap;max-height:160px;overflow:auto;">
            ${escapeHtml(preview)}
          </div>
          <ul class="mb-0">
            <li><b>Longitud:</b> ${txt.length} caracteres</li>
            <li><b>Evidencias:</b> ${allFiles.length}</li>
          </ul>
        </div>
      `,
      showCancelButton: true,
      confirmButtonText: 'Sí, enviar',
      cancelButtonText: 'Revisar',
      reverseButtons: true
    });

    if (!r.isConfirmed) return;

    flag.value = '1';
    Swal.fire({title:'Enviando…', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});

    if (typeof form.requestSubmit === 'function') {
      hiddenSubmit.disabled = false;
      form.requestSubmit(hiddenSubmit);
    } else {
      form.submit();
    }
  });

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m =>
      m==='&' ? '&amp;' :
      m==='<' ? '&lt;'  :
      m==='>' ? '&gt;'  :
      m=='"'  ? '&quot;': '&#39;'
    );
  }

  // Estado inicial
  refreshState();
});
</script>
