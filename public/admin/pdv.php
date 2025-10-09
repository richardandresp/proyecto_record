<?php
declare(strict_types=1);

/**
 * PDV Admin (lista + crear/editar + activar/inactivar)
 * Ruta: /auditoria_app/public/admin/pdv.php
 */

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega permisos finos cuando los tengas (p.ej. 'pdv.admin')

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // solo admin

$pdo = getDB();

/* ================== ACCIONES POST ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  if ($act === 'save') {
    $id            = (int)($_POST['id'] ?? 0);
    $codigo        = trim($_POST['codigo'] ?? '');
    $nombre        = trim($_POST['nombre'] ?? '');
    $departamento  = (int)($_POST['departamento_id'] ?? 0);
    $municipio     = (int)($_POST['municipio_id'] ?? 0);
    $zona          = (int)($_POST['zona_id'] ?? 0);
    $centro        = (int)($_POST['centro_id'] ?? 0);
    $pdv_tipo      = (int)($_POST['pdv_tipo_id'] ?? 0);
    $direccion     = trim($_POST['direccion'] ?? '');
    $latitud       = trim($_POST['latitud'] ?? '');
    $longitud      = trim($_POST['longitud'] ?? '');
    $activo        = isset($_POST['activo']) ? 1 : 0;

    if ($codigo === '' || $nombre === '') {
      set_flash('danger', 'Código y nombre son obligatorios.');
      header('Location: ' . BASE_URL . '/admin/pdv.php'); exit;
    }

    try {
      if ($id > 0) {
        $st = $pdo->prepare("SELECT id FROM pdv WHERE codigo=? AND id<>? LIMIT 1");
        $st->execute([$codigo, $id]);
        if ($st->fetchColumn()) throw new RuntimeException('El código ya existe en otro PDV.');

        $up = $pdo->prepare("
          UPDATE pdv
          SET codigo=?, nombre=?, departamento_id=?, municipio_id=?, zona_id=?, centro_id=?, pdv_tipo_id=?,
              direccion=?, latitud=?, longitud=?, activo=?
          WHERE id=?
        ");
        $up->execute([
          $codigo, $nombre, $departamento, $municipio, $zona, $centro, $pdv_tipo,
          $direccion, ($latitud!==''?$latitud:null), ($longitud!==''?$longitud:null), $activo, $id
        ]);
        set_flash('success', 'PDV actualizado.');
      } else {
        $st = $pdo->prepare("SELECT id FROM pdv WHERE codigo=? LIMIT 1");
        $st->execute([$codigo]);
        if ($st->fetchColumn()) throw new RuntimeException('El código ya existe.');

        $ins = $pdo->prepare("
          INSERT INTO pdv
            (codigo, nombre, departamento_id, municipio_id, zona_id, centro_id, pdv_tipo_id,
             direccion, latitud, longitud, activo)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->execute([
          $codigo, $nombre, $departamento, $municipio, $zona, $centro, $pdv_tipo,
          $direccion, ($latitud!==''?$latitud:null), ($longitud!==''?$longitud:null), $activo
        ]);
        set_flash('success', 'PDV creado.');
      }
    } catch (Throwable $e) {
      set_flash('danger', 'Error: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/admin/pdv.php'); exit;
  }

  if ($act === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("UPDATE pdv SET activo = IF(activo=1,0,1) WHERE id=?")->execute([$id]);
      set_flash('success', 'Estado actualizado.');
    }
    header('Location: ' . BASE_URL . '/admin/pdv.php'); exit;
  }
}

/* ================== CATÁLOGOS PARA FORM ================== */
$departamentos = $pdo->query("SELECT id, codigo, nombre FROM departamento ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$municipios    = $pdo->query("SELECT id, codigo, nombre, departamento_id FROM municipio ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$zonas         = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$centros       = $pdo->query("SELECT id, nombre, zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$tipos         = $pdo->query("SELECT id, nombre FROM pdv_tipo ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* ================== BÚSQUEDA + PAGINACIÓN ================== */
$q = trim($_GET['q'] ?? '');
$allowedPer = [10,25,50,75,100];
$per_page = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, $allowedPer, true)) $per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$params = [];
$where = '';
if ($q !== '') {
  $where = " WHERE p.codigo LIKE ? OR p.nombre LIKE ? ";
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $params = [$like, $like];
}

/* total */
$sqlCount = "SELECT COUNT(*)
             FROM pdv p
             LEFT JOIN departamento d ON d.id=p.departamento_id
             LEFT JOIN municipio    m ON m.id=p.municipio_id
             LEFT JOIN zona         z ON z.id=p.zona_id
             LEFT JOIN centro_costo c ON c.id=p.centro_id
             LEFT JOIN pdv_tipo     t ON t.id=p.pdv_tipo_id
             {$where}";
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

/* datos */
$sql = "
  SELECT p.id, p.codigo, p.nombre, p.activo,
         p.departamento_id, p.municipio_id, p.zona_id, p.centro_id, p.pdv_tipo_id,
         d.nombre  AS dep_nombre, m.nombre AS mun_nombre,
         z.nombre  AS zona_nombre, c.nombre AS cc_nombre,
         t.nombre  AS tipo_nombre,
         p.direccion, p.latitud, p.longitud
  FROM pdv p
  LEFT JOIN departamento d ON d.id=p.departamento_id
  LEFT JOIN municipio    m ON m.id=p.municipio_id
  LEFT JOIN zona         z ON z.id=p.zona_id
  LEFT JOIN centro_costo c ON c.id=p.centro_id
  LEFT JOIN pdv_tipo     t ON t.id=p.pdv_tipo_id
  {$where}
  ORDER BY p.nombre
  LIMIT ? OFFSET ?
";
$params_data = $params;
$params_data[] = $per_page;
$params_data[] = $offset;

$st = $pdo->prepare($sql);
$st->execute($params_data);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Administrar PDV</h1>
    <button class="btn btn-success"
            data-bs-toggle="modal" data-bs-target="#modalPDV"
            data-id="0">+ Nuevo PDV</button>
  </div>

  <?php foreach (consume_flash() as $f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($f['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endforeach; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-6">
      <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por código o nombre">
    </div>
    <div class="col-md-3">
      <select name="per_page" class="form-select" onchange="this.form.submit()">
        <?php foreach ($allowedPer as $op): ?>
          <option value="<?= $op ?>" <?= ($per_page===$op)?'selected':'' ?>><?= $op ?> por página</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary">Buscar</button>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/pdv.php">Limpiar</a>
    </div>
  </form>

  <div class="mb-2 text-muted">
    Mostrando <?= $total ? ($offset + 1) : 0 ?> – <?= min($offset + $per_page, $total) ?> de <?= $total ?> registros
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Código</th><th>Nombre</th><th>Ubicación</th><th>Zona / CC</th><th>Tipo</th><th>Estado</th><th style="width:220px"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-muted text-center">Sin resultados.</td></tr>
      <?php else: foreach ($rows as $i=>$r): ?>
        <tr>
          <td><?= $offset + $i + 1 ?></td>
          <td><code><?= htmlspecialchars($r['codigo'] ?? '') ?></code></td>
          <td><?= htmlspecialchars($r['nombre'] ?? '') ?></td>
          <td>
            <div><?= htmlspecialchars(($r['dep_nombre'] ?? '—') . ' / ' . ($r['mun_nombre'] ?? '—')) ?></div>
            <small class="text-muted"><?= htmlspecialchars($r['direccion'] ?? '') ?></small>
          </td>
          <td>
            <div><?= htmlspecialchars($r['zona_nombre'] ?? '—') ?></div>
            <small class="text-muted"><?= htmlspecialchars($r['cc_nombre'] ?? '—') ?></small>
          </td>
          <td><?= htmlspecialchars($r['tipo_nombre'] ?? '—') ?></td>
          <td><?= (int)($r['activo'] ?? 0) ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="#modalPDV"
                    data-id="<?= (int)$r['id'] ?>"
                    data-codigo="<?= htmlspecialchars($r['codigo'] ?? '') ?>"
                    data-nombre="<?= htmlspecialchars($r['nombre'] ?? '') ?>"
                    data-dep-id="<?= (int)($r['departamento_id'] ?? 0) ?>"
                    data-mun-id="<?= (int)($r['municipio_id'] ?? 0) ?>"
                    data-zona-id="<?= (int)($r['zona_id'] ?? 0) ?>"
                    data-centro-id="<?= (int)($r['centro_id'] ?? 0) ?>"
                    data-tipo-id="<?= (int)($r['pdv_tipo_id'] ?? 0) ?>"
                    data-dir="<?= htmlspecialchars($r['direccion'] ?? '') ?>"
                    data-lat="<?= htmlspecialchars((string)($r['latitud'] ?? '')) ?>"
                    data-lng="<?= htmlspecialchars((string)($r['longitud'] ?? '')) ?>"
                    data-activo="<?= (int)($r['activo'] ?? 0) ?>"
                    >Editar</button>

            <form method="post" class="d-inline">
              <input type="hidden" name="act" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-warning"
                data-confirm="<?= (int)$r['activo'] ? '¿Inactivar este PDV?' : '¿Activar este PDV?' ?>"
                data-confirm-type="<?= (int)$r['activo'] ? 'warning' : 'info' ?>"
                data-confirm-ok="Sí, continuar">
                <?= (int)$r['activo'] ? 'Inactivar' : 'Activar' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Navegación de páginas -->
  <div class="d-flex justify-content-between align-items-center">
    <div class="text-muted">Página <?= $page ?> de <?= $total_pages ?></div>
    <div class="d-flex gap-2">
      <?php
        // helper para construir URL conservando filtros
        $build = function(array $ovr = []) use ($q, $per_page) {
          $qs = array_merge(['q'=>$q,'per_page'=>$per_page], $ovr);
          return BASE_URL . '/admin/pdv.php?' . http_build_query($qs);
        };
      ?>
      <?php if ($page > 1): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= $build(['page'=>1]) ?>"><i class="bi bi-skip-backward-fill"></i> Primero</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= $build(['page'=>$page-1]) ?>"><i class="bi bi-caret-left-fill"></i> Anterior</a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-skip-backward-fill"></i> Primero</button>
        <button class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-caret-left-fill"></i> Anterior</button>
      <?php endif; ?>

      <?php if ($page < $total_pages): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= $build(['page'=>$page+1]) ?>">Siguiente <i class="bi bi-caret-right-fill"></i></a>
        <a class="btn btn-outline-primary btn-sm" href="<?= $build(['page'=>$total_pages]) ?>">Último <i class="bi bi-skip-forward-fill"></i></a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled>Siguiente <i class="bi bi-caret-right-fill"></i></button>
        <button class="btn btn-outline-secondary btn-sm" disabled>Último <i class="bi bi-skip-forward-fill"></i></button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Crear/Editar PDV -->
<div class="modal fade" id="modalPDV" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Nuevo PDV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Código *</label>
            <input class="form-control" name="codigo" id="f_codigo" required>
          </div>
          <div class="col-md-5">
            <label class="form-label">Nombre *</label>
            <input class="form-control" name="nombre" id="f_nombre" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Tipo</label>
            <select class="form-select" name="pdv_tipo_id" id="f_tipo">
              <option value="0">—</option>
              <?php foreach ($tipos as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Departamento</label>
            <select class="form-select" name="departamento_id" id="f_dep">
              <option value="0">—</option>
              <?php foreach ($departamentos as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars(($d['codigo']?:'').($d['codigo']? ' - ':'').$d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Municipio</label>
            <select class="form-select" name="municipio_id" id="f_mun">
              <option value="0">—</option>
              <?php foreach ($municipios as $m): ?>
                <option value="<?= (int)$m['id'] ?>" data-dep="<?= (int)$m['departamento_id'] ?>">
                  <?= htmlspecialchars(($m['codigo']?:'').($m['codigo']? ' - ':'').$m['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Zona</label>
            <select class="form-select" name="zona_id" id="f_zona">
              <option value="0">—</option>
              <?php foreach ($zonas as $z): ?>
                <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Centro de Costo</label>
            <select class="form-select" name="centro_id" id="f_centro">
              <option value="0">—</option>
              <?php foreach ($centros as $c): ?>
                <option value="<?= (int)$c['id'] ?>" data-zona="<?= (int)$c['zona_id'] ?>">
                  <?= htmlspecialchars($c['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Se filtra por zona.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Dirección</label>
            <input class="form-control" name="direccion" id="f_direccion">
          </div>

          <div class="col-md-3">
            <label class="form-label">Latitud</label>
            <input class="form-control" name="latitud" id="f_lat">
          </div>
          <div class="col-md-3">
            <label class="form-label">Longitud</label>
            <input class="form-control" name="longitud" id="f_lng">
          </div>

          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="f_activo" name="activo" checked>
              <label class="form-check-label" for="f_activo">Activo</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modalPDV');
  const fId   = document.getElementById('f_id');
  const fCod  = document.getElementById('f_codigo');
  const fNom  = document.getElementById('f_nombre');
  const fTipo = document.getElementById('f_tipo');
  const fDep  = document.getElementById('f_dep');
  const fMun  = document.getElementById('f_mun');
  const fZona = document.getElementById('f_zona');
  const fCentro = document.getElementById('f_centro');
  const fDir  = document.getElementById('f_direccion');
  const fLat  = document.getElementById('f_lat');
  const fLng  = document.getElementById('f_lng');
  const fAct  = document.getElementById('f_activo');
  const title = document.getElementById('modalTitle');

  function filterMunicipios(){
    const dep = parseInt(fDep.value || '0', 10);
    Array.from(fMun.options).forEach(opt=>{
      if (!opt.value || opt.value==='0') return opt.hidden=false;
      const d = parseInt(opt.getAttribute('data-dep')||'0', 10);
      opt.hidden = (dep>0 && d!==dep);
    });
    const sel = fMun.selectedOptions[0];
    if (sel && sel.hidden) fMun.value = '0';
  }

  function filterCentros(){
    const zona = parseInt(fZona.value || '0', 10);
    Array.from(fCentro.options).forEach(opt=>{
      if (!opt.value || opt.value==='0') return opt.hidden=false;
      const z = parseInt(opt.getAttribute('data-zona')||'0', 10);
      opt.hidden = (zona>0 && z!==zona);
    });
    const sel = fCentro.selectedOptions[0];
    if (sel && sel.hidden) fCentro.value = '0';
  }

  fDep.addEventListener('change', filterMunicipios);
  fZona.addEventListener('change', filterCentros);

  modal?.addEventListener('show.bs.modal', (ev)=>{
    const btn = ev.relatedTarget;
    const id  = btn?.getAttribute('data-id') ?? '0';
    title.textContent = (id==='0') ? 'Nuevo PDV' : 'Editar PDV';

    // Limpia
    fId.value = id;
    fCod.value = '';
    fNom.value = '';
    fTipo.value = '0';
    fDep.value = '0';
    fMun.value = '0';
    fZona.value = '0';
    fCentro.value = '0';
    fDir.value = '';
    fLat.value = '';
    fLng.value = '';
    fAct.checked = true;

    // Si edición, tomar todos los data-* y setear
    if (id !== '0') {
      fCod.value = btn.getAttribute('data-codigo') || '';
      fNom.value = btn.getAttribute('data-nombre') || '';
      fTipo.value = btn.getAttribute('data-tipo-id') || '0';

      // Setear maestro → filtrar dependientes → luego setear hijo
      fDep.value = btn.getAttribute('data-dep-id') || '0';
      filterMunicipios();
      fMun.value = btn.getAttribute('data-mun-id') || '0';

      fZona.value = btn.getAttribute('data-zona-id') || '0';
      filterCentros();
      fCentro.value = btn.getAttribute('data-centro-id') || '0';

      fDir.value = btn.getAttribute('data-dir') || '';
      fLat.value = btn.getAttribute('data-lat') || '';
      fLng.value = btn.getAttribute('data-lng') || '';
      fAct.checked = (btn.getAttribute('data-activo') || '1') === '1';
    }
  });
})();
</script>

<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
