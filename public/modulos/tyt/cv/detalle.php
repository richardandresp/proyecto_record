<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (!tyt_can('tyt.cv.view')) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.view)"; exit;
}

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);
if ($id<=0) { http_response_code(400); echo "ID inválido"; exit; }

$p = $pdo->prepare("SELECT *, DATEDIFF(CURDATE(), DATE(fecha_estado)) AS dias FROM tyt_cv_persona WHERE id=:id");
$p->execute([':id'=>$id]);
$per = $p->fetch(PDO::FETCH_ASSOC);
if (!$per) { http_response_code(404); echo "No encontrado"; exit; }

$a = $pdo->prepare("SELECT id, nombre_archivo, ruta, cargado_en FROM tyt_cv_anexo WHERE persona_id=:id ORDER BY id DESC");
$a->execute([':id'=>$id]);
$anexos = $a->fetchAll(PDO::FETCH_ASSOC);

$o = $pdo->prepare("SELECT autor_id, estado_origen, estado_destino, comentario, creado_en
                    FROM tyt_cv_observacion WHERE persona_id=:id ORDER BY id DESC");
$o->execute([':id'=>$id]);
$obs = $o->fetchAll(PDO::FETCH_ASSOC);

function hx($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function semaforo_class($d){ if($d<=5) return 'bg-success'; if($d<=12) return 'bg-warning'; return 'bg-danger'; }

tyt_header('T&T · Detalle HV');
tyt_nav();
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Detalle HV · <?= hx($per['nombre_completo']) ?></h1>
    <div>
      <a class="btn btn-sm btn-outline-secondary" href="<?= tyt_url('cv/listar.php') ?>">Volver</a>
      <a class="btn btn-sm btn-primary" href="<?= tyt_url('cv/editar.php?id='.$id) ?>">Editar</a>
    </div>
  </div>

  <div class="row g-3">
    
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Datos</div>
        <div class="card-body">
          <div><strong>Documento:</strong> <?= hx($per['doc_tipo'].' '.$per['doc_numero']) ?></div>
          <div><strong>Perfil:</strong> <?= hx($per['perfil']) ?></div>
          <div><strong>Email:</strong> <?= hx($per['email']) ?></div>
          <div><strong>Teléfono:</strong> <?= hx($per['telefono']) ?></div>
          <div><strong>Dirección:</strong> <?= hx($per['direccion']) ?></div>
          <div><strong>Zona / CC / Cargo:</strong> <?= hx($per['zona_id']) ?> / <?= hx($per['cc_id']) ?> / <?= hx($per['cargo_id']) ?></div>
          <div class="mt-2">
            <strong>Estado:</strong>
            <span class="badge text-bg-secondary"><?= hx($per['estado']) ?></span>
            <span class="badge <?= semaforo_class((int)$per['dias']) ?> rounded-circle"
                  style="width:12px;height:12px;display:inline-block;"></span>
            <small class="text-muted ms-1"><?= (int)$per['dias'] ?> día(s)</small>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Cambiar estado</div>
        <div class="card-body">
          <?php if (tyt_can('tyt.cv.review')): ?>
            <form class="row gy-2" method="post" action="<?= tyt_url('cv/estado.php') ?>">
              <input type="hidden" name="id" value="<?= $id ?>">
              <div class="col-12 col-md-6">
                <select class="form-select" name="to" required>
                  <?php
                    $vals = ['recibido','revision','contacto_inicial','en_capacitacion','en_activacion','entregado_comercial','rechazado'];
                    foreach($vals as $v){ $sel = ($v===$per['estado'])?'selected':''; echo "<option $sel>$v</option>"; }
                  ?>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <input name="coment" class="form-control" placeholder="Comentario (opcional)">
              </div>
              <div class="col-12">
                <button class="btn btn-sm btn-outline-primary">Actualizar</button>
              </div>
            </form>
            <hr>
            <form class="row gy-2" method="post" action="<?= tyt_url('cv/solicitar_docs.php') ?>">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <!-- Si quieres que al solicitar pase a 'revision', deja este hidden.
                   Si no quieres cambiar de estado, quita la siguiente línea. -->
              <input type="hidden" name="next" value="revision">
              <div class="col-12">
                <button class="btn btn-sm btn-outline-warning">Solicitar documentos (obligatorios faltantes)</button>
              </div>
            </form>
          <?php else: ?>
            <div class="alert alert-secondary">No tienes permiso para cambiar estado.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Anexos</div>
        <div class="card-body">
          <?php if (!$anexos): ?>
            <div class="text-muted">Sin anexos.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($anexos as $ax): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><?= hx($ax['nombre_archivo']) ?> <small class="text-muted">(<?= hx($ax['cargado_en']) ?>)</small></span>
                  <a class="btn btn-sm btn-outline-secondary"
                     href="<?= tyt_url('cv/download.php?id='.$ax['id']) ?>">Descargar</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (tyt_can('tyt.cv.attach')): ?>
            <form class="mt-2" method="post" action="<?= tyt_url('cv/upload_anexo.php') ?>" enctype="multipart/form-data">
              <input type="hidden" name="persona_id" value="<?= (int)$id ?>">
              <div class="input-group input-group-sm">
                <input type="file" name="anexo" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="btn btn-outline-primary">Subir</button>
              </div>
              <small class="text-muted">Solo Gestión Humana/Admin.</small>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Historial</div>
        <div class="card-body">
          <?php if (!$obs): ?>
            <div class="text-muted">Sin observaciones.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead><tr><th>Fecha</th><th>Autor</th><th>De</th><th>A</th><th>Comentario</th></tr></thead>
                <tbody>
                <?php foreach($obs as $o): ?>
                  <tr>
                    <td><?= hx($o['creado_en']) ?></td>
                    <td>#<?= (int)$o['autor_id'] ?></td>
                    <td><?= hx($o['estado_origen'] ?? '') ?></td>
                    <td><?= hx($o['estado_destino'] ?? '') ?></td>
                    <td><?= hx($o['comentario'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php
// --- Checklist para mostrar en Detalle ---
$stChk = $pdo->prepare("
  SELECT r.id, r.nombre, r.obligatorio,
         COALESCE(c.cumple,0) AS cumple,
         c.responsable_id,
         u.nombre AS responsable_nombre
  FROM tyt_cv_requisito r
  LEFT JOIN tyt_cv_requisito_check c
    ON c.requisito_id = r.id AND c.persona_id = :pid
  LEFT JOIN usuario u
    ON u.id = c.responsable_id
  WHERE r.activo=1 AND (r.aplica_a=:tipo OR r.aplica_a='ambos')
  ORDER BY r.nombre
");
$tipoPersona = ($per['perfil'] === 'ASESORA') ? 'asesora' : 'aspirante';
$stChk->execute([':pid'=>$id, ':tipo'=>$tipoPersona]);
$reqList = $stChk->fetchAll(PDO::FETCH_ASSOC);
$tot = count($reqList);
$ok  = array_sum(array_map(fn($x)=> (int)$x['cumple'], $reqList));
?>
<div class="col-12">
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Checklist de documentos</span>
      <span class="badge text-bg-secondary"><?= $ok ?>/<?= $tot ?> completos</span>
    </div>
    <div class="card-body">
      <?php if(!$reqList): ?>
        <div class="text-muted">No hay requisitos configurados para este perfil.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>OK</th><th>Documento</th><th>Obligatorio</th><th>Responsable</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($reqList as $rq): ?>
              <tr>
                <td><?= ((int)$rq['cumple']===1) ? '✅' : '—' ?></td>
                <td><?= hx($rq['nombre']) ?></td>
                <td><?= ((int)$rq['obligatorio']===1) ? '<span class="badge text-bg-danger">Sí</span>' : 'No' ?></td>
                <td><?= $rq['responsable_nombre'] ? hx($rq['responsable_nombre']) : '<span class="text-muted">(sin asignar)</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="mt-2">
        <a class="btn btn-sm btn-outline-primary" href="<?= tyt_url('cv/editar.php?id='.$id) ?>">Editar / marcar checklist</a>
      </div>
    </div>
  </div>
</div>

</div>
<?php tyt_footer(); ?>