<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (!tyt_can('tyt.admin')) { http_response_code(403); exit('Acceso denegado'); }
$pdo = getDB();

function hx($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

$usuarios = $pdo->query("SELECT id, nombre FROM usuario WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Carga áreas
$areas = $pdo->query("SELECT id, nombre FROM tyt_area WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Crear
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create') {
  $nombre = trim($_POST['nombre'] ?? '');
  $ob = isset($_POST['obligatorio']) ? 1 : 0;
  $apl = $_POST['aplica_a'] ?? 'ambos';
  $area = ($_POST['responsable_area_id'] ?? '')!=='' ? (int)$_POST['responsable_area_id'] : null;
  if ($nombre!=='') {
    $st=$pdo->prepare("INSERT INTO tyt_cv_requisito (nombre,obligatorio,aplica_a,responsable_area_id,activo) VALUES (?,?,?,?,1)");
    $st->execute([$nombre,$ob,$apl,$area]);
  }
  header("Location: ".tyt_url('admin/requisitos.php')); exit;
}

// Update inline
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update') {
  $id=(int)($_POST['id']??0);
  if($id>0){
    $nombre = trim($_POST['nombre'] ?? '');
    $ob = isset($_POST['obligatorio']) ? 1 : 0;
    $apl = $_POST['aplica_a'] ?? 'ambos';
    $area = ($_POST['responsable_area_id'] ?? '')!=='' ? (int)$_POST['responsable_area_id'] : null;
    $st=$pdo->prepare("UPDATE tyt_cv_requisito SET nombre=?, obligatorio=?, aplica_a=?, responsable_area_id=? WHERE id=?");
    $st->execute([$nombre,$ob,$apl,$area,$id]);
  }
  header("Location: ".tyt_url('admin/requisitos.php')); exit;
}

// Toggle activo
if (($_GET['toggle']??'')!=='') {
  $id=(int)$_GET['toggle']; if($id>0){
    $pdo->prepare("UPDATE tyt_cv_requisito SET activo=1-activo WHERE id=?")->execute([$id]);
  }
  header("Location: ".tyt_url('admin/requisitos.php')); exit;
}

$reqs=$pdo->query("SELECT r.*, u.nombre AS resp_nombre, a.nombre AS area_nombre
                   FROM tyt_cv_requisito r
                   LEFT JOIN usuario u ON u.id=r.responsable_default_id
                   LEFT JOIN tyt_area a ON a.id=r.responsable_area_id
                   ORDER BY r.activo DESC, r.nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

tyt_header('T&T · Requisitos');
tyt_nav();
?>
<div class="container">
  <h1 class="h4 mb-3">Requisitos (checklist)</h1>

  <!-- Crear -->
  <form method="post" class="row g-2 mb-4">
    <input type="hidden" name="action" value="create">
    <div class="col-md-4">
      <input name="nombre" class="form-control" placeholder="Nombre del documento" required>
    </div>
    <div class="col-md-2">
      <select name="aplica_a" class="form-select">
        <option value="ambos">Ambos</option>
        <option value="aspirante">Aspirante</option>
        <option value="asesora">Asesora</option>
      </select>
    </div>
    <div class="col-md-3">
      <select name="responsable_area_id" class="form-select">
        <option value="">(sin área por defecto)</option>
        <?php foreach($areas as $a): ?>
          <option value="<?= (int)$a['id'] ?>"><?= hx($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-center gap-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="obligatorio" id="ob1" checked>
        <label class="form-check-label" for="ob1">Obligatorio</label>
      </div>
      <button class="btn btn-primary">Agregar</button>
    </div>
  </form>

  <!-- Listado/Edición -->
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr><th>Estado</th><th>Documento</th><th>Aplica a</th><th>Obligatorio</th><th>Área por defecto</th><th>Acciones</th></tr>
      </thead>
      <tbody>
      <?php foreach($reqs as $r): ?>
        <tr>
          <td><?= $r['activo'] ? '<span class="badge text-bg-success">activo</span>' : '<span class="badge text-bg-secondary">inactivo</span>' ?></td>
          <td>
            <form method="post" class="d-flex gap-2">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input class="form-control form-control-sm" name="nombre" value="<?= hx($r['nombre']) ?>">
          </td>
          <td>
              <select name="aplica_a" class="form-select form-select-sm">
                <?php foreach(['ambos','aspirante','asesora'] as $ap): ?>
                  <option <?= $r['aplica_a']===$ap?'selected':'' ?> value="<?= $ap ?>"><?= $ap ?></option>
                <?php endforeach; ?>
              </select>
          </td>
          <td>
              <input type="checkbox" class="form-check-input" name="obligatorio" <?= ((int)$r['obligatorio']===1)?'checked':'' ?>>
          </td>
          <td>
              <select name="responsable_area_id" class="form-select form-select-sm">
                <option value="">(sin)</option>
                <?php foreach($areas as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= ((int)$r['responsable_area_id']===(int)$a['id'])?'selected':'' ?>>
                    <?= hx($a['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
          </td>
          <td class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary">Guardar</button>
              </form>
              <a class="btn btn-sm btn-outline-secondary" href="<?= tyt_url('admin/requisitos.php?toggle='.(int)$r['id']) ?>">
                <?= $r['activo'] ? 'Desactivar' : 'Activar' ?>
              </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php tyt_footer(); ?>