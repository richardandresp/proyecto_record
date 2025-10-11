<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env_mod.php';

// === UMBRALES (fallbacks si no hay tyt_config) ===
$SLA_DIAS         = 12;
$REQ_VERDE_MIN    = 3;
$REQ_NARANJA_MIN  = 1;

// Si tienes alguna función tipo tyt_get_cfg()/get_cfg(), aprovechamos:
if (function_exists('tyt_get_cfg')) {
  $SLA_DIAS        = (int)tyt_get_cfg('tyt.sla_dias', $SLA_DIAS);
  $REQ_VERDE_MIN   = (int)tyt_get_cfg('tyt.req.verde_min', $REQ_VERDE_MIN);
  $REQ_NARANJA_MIN = (int)tyt_get_cfg('tyt.req.naranja_min', $REQ_NARANJA_MIN);
} elseif (function_exists('get_cfg')) {
  $SLA_DIAS        = (int)get_cfg('tyt.sla_dias', $SLA_DIAS);
  $REQ_VERDE_MIN   = (int)get_cfg('tyt.req.verde_min', $REQ_VERDE_MIN);
  $REQ_NARANJA_MIN = (int)get_cfg('tyt.req.naranja_min', $REQ_NARANJA_MIN);
}

// === Helpers de semáforo ===
function sem_req_class(?int $dias, int $vmin, int $nmin): string {
  if ($dias === null) return 'gris';       // sin solicitud
  if ($dias >= $vmin) return 'rojo';
  if ($dias >= $nmin) return 'naranja';
  return 'verde';
}
function can_review(): bool { return function_exists('tyt_can') ? (tyt_can('tyt.cv.review') || tyt_can('tyt.admin')) : true; }
function can_attach(): bool { return function_exists('tyt_can') ? (tyt_can('tyt.cv.attach') || tyt_can('tyt.admin')) : true; }

function pdo(): PDO { return function_exists('getDB') ? getDB() : new PDO(
  'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS ?? '',
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);}

function hx($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = pdo();
$pp  = max(5, (int)($_GET['pp'] ?? 25));
$sql = "
SELECT p.id, p.doc_tipo, p.doc_numero, p.nombre_completo, p.perfil, p.estado,
       z.nombre AS zona_nombre, c.nombre AS cc_nombre,
       COALESCE(p.creado_en, p.fecha_estado) AS creado_en,
       COALESCE(p.fecha_estado, p.creado_en) AS fecha_estado,
       TIMESTAMPDIFF(DAY, COALESCE(p.fecha_estado, p.creado_en), NOW()) AS dias,
       (SELECT COUNT(*) FROM tyt_cv_requisito_check rc
         WHERE rc.persona_id = p.id AND (rc.cumple IS NULL OR rc.cumple = 0)
       ) AS faltan
FROM tyt_cv_persona p
LEFT JOIN zona z ON z.id = p.zona_id
LEFT JOIN centro_costo c ON c.id = p.cc_id
ORDER BY p.id DESC
LIMIT :pp";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':pp', $pp, PDO::PARAM_INT);
$stmt->execute();
$persons = $stmt->fetchAll();

// pendientes por persona
$pend_by = [];
if ($persons) {
  $ids = implode(',', array_map('intval', array_column($persons,'id')));
  $q = $pdo->query("
    SELECT rc.persona_id, rc.requisito_id, r.nombre AS requisito_nombre,
           rc.responsable_id AS resp_user_id, rc.responsable_area_id AS area_id,
           rc.solicitado_en, rc.solicitado_cont, rc.solicitado_motivo, rc.declara_entregado
    FROM tyt_cv_requisito_check rc
    LEFT JOIN tyt_cv_requisito r ON r.id = rc.requisito_id
    WHERE rc.persona_id IN ($ids) AND (rc.cumple IS NULL OR rc.cumple = 0)
    ORDER BY rc.persona_id, rc.requisito_id
  ");
  foreach ($q as $r) { $pend_by[(int)$r['persona_id']][] = $r; }
}

$COLS = 11;
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Seguimientos (STRICT)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .tyt-table{table-layout:fixed}
    .tyt-table .text-trunc{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
    .sem{display:inline-block;width:.7rem;height:.7rem;border-radius:50%}
    .sem.verde{background:#28a745}.sem.naranja{background:#fd7e14}.sem.rojo{background:#dc3545}.sem.gris{background:#adb5bd}
  </style>
</head>
<body>
<div class="container-fluid p-3">
  <h4 class="mb-3">Seguimientos (STRICT)</h4>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle tyt-table" id="segTbl">
      <colgroup>
        <col style="width:12%"><col style="width:20%"><col style="width:8%"><col style="width:10%">
        <col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:5%">
        <col style="width:5%"><col style="width:4%"><col style="width:7%">
      </colgroup>
      <thead class="table-light">
        <tr>
          <th>Documento</th><th>Nombre</th><th>Perfil</th><th>Estado</th><th>Zona</th>
          <th>Centro Costo</th><th>F. registro</th><th class="text-center">Días</th>
          <th class="text-center">Faltan</th><th class="text-center">🚥</th><th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($persons as $p):
        $pid=(int)$p['id']; $pend=$pend_by[$pid] ?? []; ?>
        <tr>
          <td class="text-trunc" title="<?=hx($p['doc_tipo'].' '.$p['doc_numero'])?>"><?=hx($p['doc_tipo'].' '.$p['doc_numero'])?></td>
          <td class="text-trunc" title="<?=hx($p['nombre_completo'])?>"><?=hx($p['nombre_completo'])?></td>
          <td><?=hx($p['perfil'])?></td>
          <td><?=hx($p['estado'])?></td>
          <td class="text-trunc"><?=hx($p['zona_nombre']??'-')?></td>
          <td class="text-trunc"><?=hx($p['cc_nombre']??'-')?></td>
          <td><?=hx($p['creado_en']?date('Y-m-d',strtotime($p['creado_en'])):'')?></td>
          <td class="text-center"><?= (int)$p['dias'] ?></td>
          <td class="text-center"><?= (int)$p['faltan'] ?></td>
          <td class="text-center"><span class="sem"></span></td>
          <td class="text-center">
            <?php $detalleUrl = function_exists('tyt_url')
                  ? tyt_url('cv/detalle.php?id='.$pid) : ('../cv/detalle.php?id='.$pid); ?>
            <button class="btn btn-sm btn-outline-secondary tyt-tgl"
                    data-bs-toggle="collapse" data-bs-target="#pend<?= $pid ?>"
                    aria-expanded="false" aria-controls="pend<?= $pid ?>"
                    title="Ver documentos">
              <i class="bi bi-plus-square"></i>
            </button>
            <a class="btn btn-sm btn-outline-primary" href="<?= $detalleUrl ?>" title="Detalle">
              <i class="bi bi-box-arrow-up-right"></i>
            </a>
          </td>
        </tr>
        <tr class="collapse" id="pend<?= $pid ?>">
          <td colspan="<?= $COLS ?>" class="bg-body-tertiary">
            <?php if(!$pend): ?>
              <div class="text-muted">Sin pendientes.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead class="table-light">
                    <tr><th>Documento</th><th>Responsable</th><th>Solicitud</th><th class="text-end">Acciones</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach($pend as $it):
                      $diasSol = !empty($it['solicitado_en']) ? (int)((time() - strtotime($it['solicitado_en']))/86400) : null;
                      $semReq  = sem_req_class($diasSol, $REQ_VERDE_MIN, $REQ_NARANJA_MIN);
                      $soyResp = isset($_SESSION['usuario_id']) && ((int)$_SESSION['usuario_id'] === (int)($it['resp_user_id'] ?? 0));
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?=hx($it['requisito_nombre'])?></div>
                        <div class="text-muted small">Declarado entregado: <strong><?= !empty($it['declara_entregado'])?'Sí':'No' ?></strong></div>
                      </td>

                      <td>
                        <?php if (!empty($it['resp_user_id'])): ?>
                          <i class="bi bi-person-badge me-1"></i>Usuario #<?= (int)$it['resp_user_id'] ?>
                        <?php else: ?>
                          <i class="bi bi-diagram-3 me-1"></i>Área #<?= (int)($it['area_id'] ?? 0) ?>
                        <?php endif; ?>
                      </td>

                      <td>
                        <?php if(!empty($it['solicitado_en'])): ?>
                          <div class="d-flex align-items-center gap-2">
                            <span class="sem <?= $semReq ?>"></span>
                            <div>
                              <div><strong>Desde:</strong> <?=hx(date('Y-m-d', strtotime($it['solicitado_en'])))?></div>
                              <div class="small text-muted">Días: <?= (int)$diasSol ?> · Reintentos: <?= (int)($it['solicitado_cont']??1) ?></div>
                              <?php if(!empty($it['solicitado_motivo'])): ?>
                                <div class="small text-muted">Motivo: <?=hx($it['solicitado_motivo'])?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php else: ?>
                          <span class="text-muted"><span class="sem gris me-2"></span>Sin solicitud</span>
                        <?php endif; ?>
                      </td>

                      <td class="text-end">
                        <?php if (can_review()): ?>
                          <!-- Asignar responsable -->
                          <button class="btn btn-sm btn-outline-secondary"
                                  data-bs-toggle="modal" data-bs-target="#mdlResp"
                                  data-persona="<?= (int)$pid ?>"
                                  data-req="<?= (int)$it['requisito_id'] ?>"
                                  data-user="<?= (int)($it['resp_user_id'] ?? 0) ?>"
                                  data-area="<?= (int)($it['area_id'] ?? 0) ?>"
                                  title="Asignar responsable">
                            <i class="bi bi-person-gear"></i>
                          </button>

                          <!-- Solicitar documento -->
                          <button class="btn btn-sm btn-outline-warning"
                                  data-bs-toggle="modal" data-bs-target="#mdlSolic"
                                  data-persona="<?= (int)$pid ?>"
                                  data-req="<?= (int)$it['requisito_id'] ?>"
                                  data-nom="<?= hx($it['requisito_nombre']) ?>"
                                  title="Solicitar documento">
                            <i class="bi bi-send"></i>
                          </button>

                          <!-- Completar -->
                          <form method="post" action="<?= function_exists('tyt_url') ? tyt_url('seguimientos/accion.php') : 'accion.php' ?>"
                                class="d-inline">
                            <input type="hidden" name="accion" value="completar">
                            <input type="hidden" name="persona_id" value="<?= (int)$pid ?>">
                            <input type="hidden" name="requisito_id" value="<?= (int)$it['requisito_id'] ?>">
                            <button class="btn btn-sm btn-outline-success" title="Marcar completo"><i class="bi bi-check2"></i></button>
                          </form>
                        <?php endif; ?>

                        <!-- Subir anexo (responsable o quien tenga permiso de adjuntar) -->
                        <?php if ($soyResp || can_attach()): ?>
                          <form method="post" action="<?= function_exists('tyt_url') ? tyt_url('seguimientos/upload_req.php') : 'upload_req.php' ?>"
                                enctype="multipart/form-data" class="d-inline ms-1">
                            <input type="hidden" name="persona_id" value="<?= (int)$pid ?>">
                            <input type="hidden" name="requisito_id" value="<?= (int)$it['requisito_id'] ?>">
                            <label class="btn btn-sm btn-outline-primary mb-0" title="Subir anexo">
                              <i class="bi bi-upload"></i>
                              <input type="file" name="anexo" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="this.form.submit()">
                            </label>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Asignar responsable -->
<div class="modal fade" id="mdlResp" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <form method="post" action="<?= function_exists('tyt_url') ? tyt_url('seguimientos/accion.php') : 'accion.php' ?>" class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-person-gear me-1"></i>Asignar responsable</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <input type="hidden" name="accion" value="asignar">
        <input type="hidden" name="persona_id" id="resp_persona">
        <input type="hidden" name="requisito_id" id="resp_req">
        <div class="mb-2">
          <label class="form-label small mb-1">Usuario (ID)</label>
          <input type="number" class="form-control form-control-sm" name="user_id" id="resp_user" placeholder="Opcional">
        </div>
        <div>
          <label class="form-label small mb-1">Área (ID)</label>
          <input type="number" class="form-control form-control-sm" name="area_id" id="resp_area" placeholder="Opcional">
        </div>
        <div class="form-text">Indica <b>usuario</b> o <b>área</b> (uno de los dos).</div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Solicitar documento -->
<div class="modal fade" id="mdlSolic" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <form method="post" action="<?= function_exists('tyt_url') ? tyt_url('seguimientos/accion.php') : 'accion.php' ?>" class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-send me-1"></i>Solicitar documento</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <input type="hidden" name="accion" value="solicitar">
        <input type="hidden" name="persona_id" id="solic_persona">
        <input type="hidden" name="requisito_id" id="solic_req">
        <div class="small mb-2">
          <strong>Requisito:</strong> <span id="solic_nom">—</span>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1">Motivo / Detalle</label>
          <textarea class="form-control form-control-sm" name="motivo" rows="3" placeholder="Ej. Documento ilegible o faltante"></textarea>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-warning" type="submit">Enviar solicitud</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tyt-tgl').forEach(btn => {
    const icon = btn.querySelector('.bi');
    const sel  = btn.getAttribute('data-bs-target');
    const el   = document.querySelector(sel);
    if (!el || !icon) return;
    el.addEventListener('show.bs.collapse', () => { icon.classList.replace('bi-plus-square','bi-dash-square'); });
    el.addEventListener('hide.bs.collapse', () => { icon.classList.replace('bi-dash-square','bi-plus-square'); });
  });

  // Llenado de modales
  const mdlResp = document.getElementById('mdlResp');
  if (mdlResp) {
    mdlResp.addEventListener('show.bs.modal', ev => {
      const btn = ev.relatedTarget;
      document.getElementById('resp_persona').value = btn.getAttribute('data-persona');
      document.getElementById('resp_req').value     = btn.getAttribute('data-req');
      document.getElementById('resp_user').value    = btn.getAttribute('data-user') || '';
      document.getElementById('resp_area').value    = btn.getAttribute('data-area') || '';
    });
  }
  const mdlSolic = document.getElementById('mdlSolic');
  if (mdlSolic) {
    mdlSolic.addEventListener('show.bs.modal', ev => {
      const btn = ev.relatedTarget;
      document.getElementById('solic_persona').value = btn.getAttribute('data-persona');
      document.getElementById('solic_req').value     = btn.getAttribute('data-req');
      document.getElementById('solic_nom').textContent = btn.getAttribute('data-nom') || '—';
    });
  }
});
</script>

<!-- Activa el auditor -->
<script>
  (function(){const t=document.getElementById('segTbl');if(!t)return;
    const exp=t.tHead.querySelectorAll('tr:last-child th').length, rows=[...t.tBodies[0].rows], issues=[];
    rows.forEach((tr,i)=>{const onlyTd=tr.children.length===1&&tr.children[0].hasAttribute('colspan');
      if(onlyTd){const cs=+tr.children[0].getAttribute('colspan'); if(cs!==exp){tr.style.background='#ffe5e5';issues.push({i:i+1,tds:1,cs,exp});}}
      else{const c=tr.querySelectorAll('td').length; if(c!==exp){tr.style.background='#ffe5e5';issues.push({i:i+1,tds:c,exp});}}
    });
    console.log('[STRICT] Problemas:', issues);
  })();
</script>
</body>
</html>