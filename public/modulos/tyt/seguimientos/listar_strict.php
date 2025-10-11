<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env_mod.php';

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
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .tyt-table{table-layout:fixed}
  .tyt-table .text-trunc{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
  .sem{display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:#adb5bd}
</style>
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
          <th class="text-center">Faltan</th><th class="text-center">Sem.</th><th class="text-center">Acciones</th>
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
          <td class="text-center">[+][det]</td>
        </tr>
        <tr><td colspan="<?=$COLS?>" class="bg-body-tertiary">
          <?php if(!$pend): ?>
            <div class="text-muted">Sin pendientes.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead class="table-light">
                  <tr><th>Documento</th><th>Responsable</th><th>Solicitud</th><th class="text-end">Acciones</th></tr>
                </thead>
                <tbody>
                  <?php foreach($pend as $it): ?>
                    <tr>
                      <td><?=hx($it['requisito_nombre'])?></td>
                      <td><?= !empty($it['resp_user_id']) ? ('Usuario #'.(int)$it['resp_user_id']) : ('Área #'.(int)($it['area_id']??0)) ?></td>
                      <td>
                        <?php if(!empty($it['solicitado_en'])): ?>
                          Desde <?=hx(date('Y-m-d',strtotime($it['solicitado_en'])))?>,
                          Reintentos <?= (int)($it['solicitado_cont']??1) ?>
                          <?php if(!empty($it['solicitado_motivo'])): ?>
                            <div class="text-muted small">Motivo: <?=hx($it['solicitado_motivo'])?></div>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted">Sin solicitud</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">—</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

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
