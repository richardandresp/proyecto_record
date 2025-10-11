<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

if (function_exists('user_has_perm') && !user_has_perm('tyt.cv.submit')) {
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
      <select name="zona_id" class="form-select">
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
      <select name="cc_id" class="form-select">
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

    <div class="col-12">
      <label class="form-label">Hoja de vida (PDF/JPG/PNG)</label>
      <input type="file" name="hoja_vida" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
      <small class="text-muted">Máx. 5 MB. Opcional al editar.</small>
    </div>

    <div class="col-12 d-flex gap-2">
      <a href="<?= tyt_url('cv/listar.php') ?>" class="btn btn-outline-secondary">Volver</a>
      <button class="btn btn-primary">Guardar</button>
    </div>
  </form>
</div>
<?php tyt_footer(); ?>