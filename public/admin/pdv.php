<?php
// public/admin/pdv.php
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

require_role(['admin','auditor']); // define quién puede administrar

$pdo = get_pdo();

// Cargar catálogos
$zonas = $pdo->query("SELECT id,nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Filtros
$zona_id   = (int)($_GET['zona_id'] ?? 0);
$centro_id = (int)($_GET['centro_id'] ?? 0);
$q         = trim($_GET['q'] ?? '');

$params = [];
$sql = "SELECT p.id, p.codigo, p.nombre, p.activo,
               c.id AS centro_id, c.nombre AS centro_nombre,
               z.id AS zona_id, z.nombre AS zona_nombre
        FROM pdv p
        JOIN centro_costo c ON c.id=p.centro_id
        JOIN zona z ON z.id=c.zona_id
        WHERE 1=1";

if ($zona_id > 0)   { $sql .= " AND z.id = ?"; $params[] = $zona_id; }
if ($centro_id > 0) { $sql .= " AND c.id = ?"; $params[] = $centro_id; }
if ($q !== '') {
  $sql .= " AND (p.codigo LIKE ? OR p.nombre LIKE ?)";
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $params[] = $like; $params[] = $like;
}
$sql .= " ORDER BY z.nombre, c.nombre, p.nombre LIMIT 300";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Acciones POST (guardar / activar-inactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  if ($act === 'save') {
    $id        = (int)($_POST['id'] ?? 0);
    $centro_id = (int)($_POST['centro_id'] ?? 0);
    $codigo    = trim($_POST['codigo'] ?? '');
    $nombre    = trim($_POST['nombre'] ?? '');
    $activo    = isset($_POST['activo']) ? 1 : 0;

    if ($centro_id<=0 || $codigo==='' || $nombre==='') {
      $_SESSION['flash_err'] = 'Centro, código y nombre son obligatorios.';
      header('Location: ' . BASE_URL . '/admin/pdv.php');
      exit;
    }

    try {
      if ($id > 0) {
        // Unicidad: código dentro del mismo centro
        $st = $pdo->prepare("SELECT id FROM pdv WHERE codigo=? AND centro_id=? AND id<>? LIMIT 1");
        $st->execute([$codigo, $centro_id, $id]);
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe ese código en el centro seleccionado.');

        $st = $pdo->prepare("UPDATE pdv SET codigo=?, nombre=?, centro_id=?, activo=? WHERE id=?");
        $st->execute([$codigo, $nombre, $centro_id, $activo, $id]);
        $_SESSION['flash_ok'] = 'PDV actualizado.';
      } else {
        $st = $pdo->prepare("SELECT id FROM pdv WHERE codigo=? AND centro_id=? LIMIT 1");
        $st->execute([$codigo, $centro_id]);
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe ese código en el centro seleccionado.');

        $st = $pdo->prepare("INSERT INTO pdv (codigo, nombre, centro_id, activo) VALUES (?,?,?,?)");
        $st->execute([$codigo, $nombre, $centro_id, $activo]);
        $_SESSION['flash_ok'] = 'PDV creado.';
      }
    } catch (Throwable $e) {
      $_SESSION['flash_err'] = 'Error: ' . $e->getMessage();
    }
    header('Location: ' . BASE_URL . '/admin/pdv.php');
    exit;
  }

  if ($act === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("UPDATE pdv SET activo = IF(activo=1,0,1) WHERE id=?");
    $st->execute([$id]);
    $_SESSION['flash_ok'] = 'Estado actualizado.';
    header('Location: ' . BASE_URL . '/admin/pdv.php');
    exit;
  }
}

// Centros (para filtros y para modal)
$centros = [];
if ($zona_id > 0) {
  $stC = $pdo->prepare("SELECT id, nombre FROM centro_costo WHERE zona_id=? AND activo=1 ORDER BY nombre");
  $stC->execute([$zona_id]);
  $centros = $stC->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <h1 class="h4">Administrar PDV</h1>

  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_err'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_err']); unset($_SESSION['flash_err']); ?></div>
  <?php endif; ?>

  <!-- Filtros -->
  <form class="row g-2 mb-3" method="get" id="frmFiltros">
    <div class="col-md-3">
      <label class="form-label">Zona</label>
      <select class="form-select" name="zona_id" id="f_zona">
        <option value="0">Todas</option>
        <?php foreach ($zonas as $z): ?>
          <option value="<?= (int)$z['id'] ?>" <?= $zona_id===$z['id']?'selected':'' ?>>
            <?= htmlspecialchars($z['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Centro de costo</label>
      <select class="form-select" name="centro_id" id="f_centro">
        <option value="0">Todos</option>
        <?php foreach ($centros as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $centro_id===$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-5">
      <label class="form-label">Buscar</label>
      <div class="input-group">
        <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nombre o código">
        <button class="btn btn-primary">Aplicar</button>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalForm"
                data-id="0" data-codigo="" data-nombre="" data-zona="" data-centro="" data-activo="1">Nuevo PDV</button>
      </div>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Zona</th><th>Centro</th><th>Código</th><th>Nombre</th><th>Activo</th><th style="width:200px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i=>$r): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($r['zona_nombre']) ?></td>
            <td><?= htmlspecialchars($r['centro_nombre']) ?></td>
            <td><code><?= htmlspecialchars($r['codigo']) ?></code></td>
            <td><?= htmlspecialchars($r['nombre']) ?></td>
            <td><?= $r['activo'] ? 'Sí' : 'No' ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#modalForm"
                      data-id="<?= (int)$r['id'] ?>"
                      data-codigo="<?= htmlspecialchars($r['codigo']) ?>"
                      data-nombre="<?= htmlspecialchars($r['nombre']) ?>"
                      data-zona="<?= (int)$r['zona_id'] ?>"
                      data-centro="<?= (int)$r['centro_id'] ?>"
                      data-activo="<?= (int)$r['activo'] ?>">Editar</button>

              <form method="post" class="d-inline" onsubmit="return confirm('¿Cambiar estado activo?');">
                <input type="hidden" name="act" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-warning">Activar/Desactivar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">Sin resultados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Crear/Editar PDV -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Nuevo PDV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Zona *</label>
          <select class="form-select" id="f_zona" required>
            <option value="">Seleccione...</option>
            <?php foreach ($zonas as $z): ?>
              <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Primero seleccione la zona para cargar los centros.</div>
        </div>
        <div class="mb-2">
          <label class="form-label">Centro de costo *</label>
          <select class="form-select" name="centro_id" id="f_centro" required>
            <option value="">Seleccione una zona primero...</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Código *</label>
          <input class="form-control" name="codigo" id="f_codigo" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Nombre *</label>
          <input class="form-control" name="nombre" id="f_nombre" required>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="f_activo" name="activo" checked>
          <label class="form-check-label" for="f_activo">Activo</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
// Filtros: carga centros por zona usando tu API
const selZonaFiltro = document.getElementById('f_zona');
const selCentroFiltro = document.getElementById('f_centro');
document.getElementById('f_zona')?.addEventListener('change', async (e) => {
  const zonaId = e.target.value;
  const centroSel = document.getElementById('f_centro');
  centroSel.innerHTML = '<option value="">Cargando...</option>';
  try {
    const resp = await fetch('<?= BASE_URL ?>/api/cc_por_zona.php?zona_id=' + encodeURIComponent(zonaId));
    const data = resp.ok ? await resp.json() : [];
    centroSel.innerHTML = '<option value="">Seleccione...</option>';
    (data||[]).forEach(cc => {
      const o = document.createElement('option');
      o.value = cc.id; o.textContent = cc.nombre;
      centroSel.appendChild(o);
    });
  } catch {
    centroSel.innerHTML = '<option value="">Error cargando centros</option>';
  }
});

// Modal: poblar datos
document.getElementById('modalForm')?.addEventListener('show.bs.modal', async (ev) => {
  const btn = ev.relatedTarget;
  const id = btn?.getAttribute('data-id') ?? '0';
  const codigo = btn?.getAttribute('data-codigo') ?? '';
  const nombre = btn?.getAttribute('data-nombre') ?? '';
  const zona   = btn?.getAttribute('data-zona') ?? '';
  const centro = btn?.getAttribute('data-centro') ?? '';
  const activo = (btn?.getAttribute('data-activo') ?? '1') === '1';

  document.getElementById('modalTitle').textContent = id==='0' ? 'Nuevo PDV' : 'Editar PDV';
  document.getElementById('f_id').value = id;
  document.getElementById('f_codigo').value = codigo;
  document.getElementById('f_nombre').value = nombre;
  document.getElementById('f_activo').checked = activo;

  // Carga zonas
  const zSel = document.getElementById('f_zona');
  if (zona) zSel.value = zona; else zSel.selectedIndex = 0;

  // Carga centros de esa zona
  const cSel = document.getElementById('f_centro');
  cSel.innerHTML = '<option value="">Cargando...</option>';
  if (zSel.value) {
    try {
      const resp = await fetch('<?= BASE_URL ?>/api/cc_por_zona.php?zona_id=' + encodeURIComponent(zSel.value));
      const data = resp.ok ? await resp.json() : [];
      cSel.innerHTML = '<option value="">Seleccione...</option>';
      (data||[]).forEach(cc => {
        const o = document.createElement('option');
        o.value = cc.id; o.textContent = cc.nombre;
        cSel.appendChild(o);
      });
      if (centro) cSel.value = centro;
    } catch {
      cSel.innerHTML = '<option value="">Error cargando centros</option>';
    }
  } else {
    cSel.innerHTML = '<option value="">Seleccione una zona primero...</option>';
  }
});
</script>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
