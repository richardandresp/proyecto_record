<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

if (!tyt_can('tyt.cv.submit')) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.submit)"; exit;
}

$pdo = getDB();
$id  = (int)($_GET['id'] ?? 0);
$row = null;

if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM tyt_cv_persona WHERE id = :id");
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
}

// Determinar tipo por perfil (para aplicar requisitos)
$curPerfil = $row['perfil'] ?? '';
$tipoPersona = ($curPerfil === 'ASESORA') ? 'asesora' : 'aspirante';

// Requisitos aplicables
$stR = $pdo->prepare("SELECT id, nombre, obligatorio, responsable_default_id
                      FROM tyt_cv_requisito
                      WHERE activo=1 AND (aplica_a=:t OR aplica_a='ambos')
                      ORDER BY nombre");
$stR->execute([':t'=>$tipoPersona]);
$reqs = $stR->fetchAll(PDO::FETCH_ASSOC);

// Usuarios (responsables posibles)
$usuarios = $pdo->query("SELECT id, nombre FROM usuario WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Áreas (responsables posibles)
$areas = $pdo->query("SELECT id, nombre FROM tyt_area WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Si estamos editando, carga checks previos
$checks = [];
if ($id>0) {
  $s = $pdo->prepare("SELECT requisito_id, cumple, responsable_id, responsable_area_id FROM tyt_cv_requisito_check WHERE persona_id=:p");
  $s->execute([':p'=>$id]);
  foreach($s->fetchAll(PDO::FETCH_ASSOC) as $c){ $checks[(int)$c['requisito_id']] = $c; }
}

// Cargar Zonas activas
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Si ya hay zona elegida, trae centros de esa zona; si no, trae todos activos
$zonaSel = (int)($row['zona_id'] ?? 0);
if ($zonaSel > 0) {
  $stCC = $pdo->prepare("SELECT id, nombre FROM centro_costo WHERE activo=1 AND zona_id=:z ORDER BY nombre");
  $stCC->execute([':z'=>$zonaSel]);
  $centros = $stCC->fetchAll(PDO::FETCH_ASSOC);
} else {
  $centros = $pdo->query("SELECT id, nombre FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
}

function hx($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msgOk = isset($_GET['ok']) ? "Guardado correctamente." : "";
$msgEr = isset($_GET['e'])  ? $_GET['e'] : "";
tyt_header('T&T · Registrar Hoja de Vida');
tyt_nav();
?>
<div class="container">
  <h1 class="h4 mb-3">T&T · Registrar Hoja de Vida</h1>

  <?php if ($msgOk): ?><div class="alert alert-success"><?= hx($msgOk) ?></div><?php endif; ?>
  <?php if ($msgEr): ?><div class="alert alert-danger">Error: <?= hx($msgEr) ?></div><?php endif; ?>

  <form class="row g-3" method="post" action="<?= tyt_url('cv/save.php') ?>" enctype="multipart/form-data">
    <?php if ($id>0): ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
    <?php endif; ?>

    <div class="col-md-2">
      <label class="form-label">Tipo Doc.</label>
      <select name="doc_tipo" class="form-select" required>
        <?php
          $opts = ['CC'=>'CC','CE'=>'CE','PA'=>'Pasaporte'];
          $cur  = $row['doc_tipo'] ?? 'CC';
          foreach($opts as $v=>$t){ $sel = ($cur===$v)?'selected':''; echo "<option value='$v' $sel>$t</option>"; }
        ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">N° Documento</label>
      <input name="doc_numero" class="form-control" required maxlength="30" value="<?= hx($row['doc_numero'] ?? '') ?>">
      <?php if ($id>0): ?><small class="text-muted">Si cambias este valor, se actualiza la HV existente por la unicidad doc_tipo+doc_numero.</small><?php endif; ?>
    </div>

    <div class="col-md-6">
      <label class="form-label">Nombre completo</label>
      <input name="nombre_completo" class="form-control" required maxlength="180" value="<?= hx($row['nombre_completo'] ?? '') ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">Perfil</label>
      <select name="perfil" class="form-select" required>
        <?php
          $perfiles = ['TAT','EMP','ASESORA'];
          $curp = $row['perfil'] ?? '';
          echo '<option value="">Seleccione…</option>';
          foreach($perfiles as $p){ $sel = ($curp===$p)?'selected':''; echo "<option $sel>$p</option>"; }
        ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Zona</label>
      <select name="zona_id" class="form-select" id="selZona">
        <option value="">(sin zona)</option>
        <?php
          $curZ = (int)($row['zona_id'] ?? 0);
          foreach($zonas as $z){
            $sel = ($curZ === (int)$z['id']) ? 'selected' : '';
            echo '<option value="'.(int)$z['id'].'" '.$sel.'>'.hx($z['nombre']).'</option>';
          }
        ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Centro de costo</label>
      <select name="cc_id" class="form-select" id="selCC">
        <option value="">(sin centro)</option>
        <?php
          $curC = (int)($row['cc_id'] ?? 0);
          foreach($centros as $c){
            $sel = ($curC === (int)$c['id']) ? 'selected' : '';
            echo '<option value="'.(int)$c['id'].'" '.$sel.'>'.hx($c['nombre']).'</option>';
          }
        ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label">Cargo (ID)</label>
      <input name="cargo_id" class="form-control" inputmode="numeric" value="<?= hx($row['cargo_id'] ?? '') ?>" placeholder="Ej: 5">
    </div>

    <div class="col-md-4">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" value="<?= hx($row['email'] ?? '') ?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= hx($row['telefono'] ?? '') ?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">Dirección</label>
      <input name="direccion" class="form-control" value="<?= hx($row['direccion'] ?? '') ?>">
    </div>

    <div class="col-12">
      <label class="form-label">Comentarios</label>
      <textarea name="comentarios" class="form-control" rows="3"></textarea>
    </div>

    <?php if (tyt_can('tyt.cv.attach')): ?>
      <div class="col-12">
        <label class="form-label">Hoja de vida (PDF/JPG/PNG)</label>
        <input type="file" name="hoja_vida" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
        <small class="text-muted">Máx. 5 MB.</small>
      </div>
    <?php endif; ?>

    <div class="col-12">
      <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mdlChecklist">
        Checklist de documentos
      </button>
      <small class="text-muted ms-2">Marca los documentos entregados y asigna responsable del trámite.</small>
    </div>

    <div class="col-12 d-flex gap-2">
      <a href="<?= tyt_url('cv/listar.php') ?>" class="btn btn-outline-secondary">Volver</a>
      <button class="btn btn-primary">Guardar</button>
    </div>

    <!-- Modal Checklist -->
    <div class="modal fade" id="mdlChecklist" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Checklist de documentos</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php if (!$reqs): ?>
              <div class="alert alert-warning">Aún no hay requisitos configurados.</div>
            <?php else: ?>
              <?php
              $puedeAsignarArea = tyt_can('tyt.cv.review'); // GH/Admin
              ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Ok</th><th>Documento</th><th>Obligatorio</th><th>Responsable</th><th>Área responsable</th></tr></thead>
                  <tbody>
                  <?php foreach($reqs as $rq):
                    $rid = (int)$rq['id'];
                    $prev = $checks[$rid] ?? null;
                    $checked = ($prev && (int)$prev['cumple']===1) ? 'checked' : '';
                    $respSel = (int)($prev['responsable_id'] ?? ($rq['responsable_default_id'] ?? 0));
                  ?>
                    <tr>
                      <td><input type="checkbox" name="req_chk[<?= $rid ?>]" value="1" <?= $checked ?>></td>
                      <td><?= hx($rq['nombre']) ?></td>
                      <td><?= ((int)$rq['obligatorio']===1) ? '<span class="badge text-bg-danger">Sí</span>' : 'No' ?></td>
                      <td>
                        <select name="req_resp[<?= $rid ?>]" class="form-select form-select-sm">
                          <option value="">(sin asignar)</option>
                          <?php foreach($usuarios as $u):
                            $sel = ($respSel === (int)$u['id']) ? 'selected' : ''; ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $sel ?>><?= hx($u['nombre']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <?php
                          $areaSel = (int)($prev['responsable_area_id'] ?? ($rq['responsable_area_id'] ?? 0));
                          if ($puedeAsignarArea):
                        ?>
                          <select name="req_area[<?= $rid ?>]" class="form-select form-select-sm">
                            <option value="">(sin asignar)</option>
                            <?php foreach($areas as $a):
                              $sel = ($areaSel === (int)$a['id']) ? 'selected' : ''; ?>
                              <option value="<?= (int)$a['id'] ?>" <?= $sel ?>><?= hx($a['nombre']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        <?php else: ?>
                          <span class="text-muted">
                            <?= $areaSel ? hx(array_values(array_filter($areas, fn($x)=> (int)$x['id']===$areaSel))[0]['nombre'] ?? '(sin asignar)') : '(sin asignar)' ?>
                          </span>
                          <!-- No mandamos req_area[] si no tiene permiso, para que no pueda cambiar el área -->
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const selZona = document.getElementById('selZona');
  const selCC   = document.getElementById('selCC');
  if (!selZona || !selCC) return;

  selZona.addEventListener('change', async () => {
    const z = selZona.value || '';
    selCC.innerHTML = '<option value="">(cargando…)</option>';
    try {
      const res = await fetch('<?= tyt_url('api/centros.php') ?>?zona_id=' + encodeURIComponent(z));
      const data = await res.json();
      selCC.innerHTML = '<option value="">(sin centro)</option>';
      data.forEach(row => {
        const opt = document.createElement('option');
        opt.value = row.id; opt.textContent = row.nombre;
        selCC.appendChild(opt);
      });
    } catch(e) {
      selCC.innerHTML = '<option value="">(error)</option>';
    }
  });
});
</script>

<?php tyt_footer(); ?>