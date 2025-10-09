<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access'];
require_once __DIR__ . '/../../includes/page_boot.php';
require_roles(['admin']);

$pdo = getDB();

/* ======== Catálogos para selects dependientes ======== */
$zonas = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$ccs   = $pdo->query("SELECT id, nombre, zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// PDV opcional
$pdvs  = [];
try {
  $pdvs = $pdo->query("SELECT id, nombre, codigo, centro_id FROM pdv WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $pdvs = [];
}

/* ======== Acciones POST ======== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';

  try {
    if ($act === 'save') {
      $id        = (int)($_POST['id'] ?? 0);
      $cedula    = trim((string)($_POST['cedula'] ?? ''));
      $nombre    = trim((string)($_POST['nombre'] ?? ''));
      $telefono  = trim((string)($_POST['telefono'] ?? ''));
      $activo    = isset($_POST['activo']) ? 1 : 0;

      // opcionales
      $zona_id   = (int)($_POST['zona_id'] ?? 0) ?: null;
      $centro_id = (int)($_POST['centro_id'] ?? 0) ?: null;
      $pdv_id    = (int)($_POST['pdv_id'] ?? 0) ?: null;

      if ($cedula === '' || $nombre === '') {
        throw new RuntimeException('Cédula y nombre son obligatorios.');
      }

      if ($id > 0) {
        // unicidad cédula
        $st = $pdo->prepare("SELECT id FROM asesor WHERE cedula=? AND id<>? LIMIT 1");
        $st->execute([$cedula, $id]);
        if ($st->fetchColumn()) throw new RuntimeException('La cédula ya existe en otro asesor.');

        $st  = $pdo->prepare("UPDATE asesor
                              SET cedula=?, nombre=?, telefono=?, activo=?, zona_id=?, centro_id=?, pdv_id=?
                              WHERE id=?");
        $st->execute([$cedula, $nombre, $telefono, $activo, $zona_id, $centro_id, $pdv_id, $id]);

        set_flash('success', 'Asesor actualizado.');
      } else {
        // unicidad cédula
        $st = $pdo->prepare("SELECT id FROM asesor WHERE cedula=? LIMIT 1");
        $st->execute([$cedula]);
        if ($st->fetchColumn()) throw new RuntimeException('La cédula ya existe.');

        $st  = $pdo->prepare("INSERT INTO asesor (cedula, nombre, telefono, activo, zona_id, centro_id, pdv_id)
                              VALUES (?,?,?,?,?,?,?)");
        $st->execute([$cedula, $nombre, $telefono, $activo, $zona_id, $centro_id, $pdv_id]);

        set_flash('success', 'Asesor creado.');
      }
      header('Location: ' . BASE_URL . '/admin/asesores.php'); exit;
    }

    if ($act === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID inválido');

      $st = $pdo->prepare("UPDATE asesor SET activo = IF(activo=1,0,1) WHERE id=?");
      $st->execute([$id]);
      set_flash('success', 'Estado actualizado.');
      header('Location: ' . BASE_URL . '/admin/asesores.php'); exit;
    }

  } catch (Throwable $e) {
    set_flash('danger', 'Error: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/admin/asesores.php'); exit;
  }
}

/* ======== Filtros + Paginación ======== */
$q                 = trim((string)($_GET['q'] ?? ''));
$estado            = (string)($_GET['estado'] ?? '');
$zona_id_filtro    = (int)($_GET['zona_id'] ?? 0);
$centro_id_filtro  = (int)($_GET['centro_id'] ?? 0);
$pdv_id_filtro     = (int)($_GET['pdv_id'] ?? 0);

$per_page_options  = [10, 25, 50, 75, 100];
$per_page          = (int)($_GET['per_page'] ?? 10);
if (!in_array($per_page, $per_page_options, true)) $per_page = 10;

$page              = max(1, (int)($_GET['page'] ?? 1));
$offset            = ($page - 1) * $per_page;

// where
$params = [];
$where  = [];

if ($q !== '') {
  $where[] = "(a.cedula LIKE ? ESCAPE '\\\\' OR a.nombre LIKE ? ESCAPE '\\\\')";
  $like    = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
  $params[] = $like; $params[] = $like;
}
if ($estado !== '') {
  $where[]  = "a.activo = ?";
  $params[] = ($estado === 'activo') ? 1 : 0;
}
if ($zona_id_filtro > 0) {
  $where[]  = "a.zona_id = ?";
  $params[] = $zona_id_filtro;
}
if ($centro_id_filtro > 0) {
  $where[]  = "a.centro_id = ?";
  $params[] = $centro_id_filtro;
}
if ($pdv_id_filtro > 0) {
  $where[]  = "a.pdv_id = ?";
  $params[] = $pdv_id_filtro;
}

$sql_base = "FROM asesor a
             LEFT JOIN zona z ON z.id=a.zona_id
             LEFT JOIN centro_costo c ON c.id=a.centro_id";

if ($where) { $sql_base .= " WHERE " . implode(' AND ', $where); }

// total
$sql_count = "SELECT COUNT(*) " . $sql_base;
$stc = $pdo->prepare($sql_count);
$stc->execute($params);
$total_records = (int)$stc->fetchColumn();

// datos
$sql = "SELECT a.id, a.cedula, a.nombre, a.telefono, a.activo,
               a.zona_id, a.centro_id, a.pdv_id,
               z.nombre AS zona_nombre, c.nombre AS centro_nombre
        $sql_base
        ORDER BY a.nombre ASC
        LIMIT ? OFFSET ?";
$params_data = array_merge($params, [$per_page, $offset]);
$st = $pdo->prepare($sql);
$st->execute($params_data);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// helper de paginación
function asesores_build_url(array $override = []): string {
  $qs = array_merge($_GET, $override);
  return BASE_URL . '/admin/asesores.php?' . http_build_query($qs);
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Administrar Asesores</h1>

    <button type="button"
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#modalForm"
            data-id="0"
            data-cedula=""
            data-nombre=""
            data-telefono=""
            data-activo="1"
            data-zona=""
            data-centro=""
            data-pdv="">
      + Nuevo asesor
    </button>
  </div>

  <?php if (function_exists('consume_flash')): ?>
    <?php foreach (consume_flash() as $f): ?>
      <div class="alert alert-<?= htmlspecialchars((string)$f['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars((string)$f['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Filtros compactos en una fila -->
  <form class="row g-2 align-items-end mb-2" method="get" action="<?= BASE_URL ?>/admin/asesores.php">
    <div class="col-md-4 col-lg-4">
      <label class="form-label small text-muted">Buscar</label>
      <input class="form-control form-control-sm" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Cédula o nombre">
    </div>

    <div class="col-md-2 col-lg-2">
      <label class="form-label small text-muted">Estado</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos</option>
        <option value="activo"   <?= $estado==='activo'?'selected':'' ?>>Activos</option>
        <option value="inactivo" <?= $estado==='inactivo'?'selected':'' ?>>Inactivos</option>
      </select>
    </div>

    <div class="col-md-2 col-lg-2">
      <label class="form-label small text-muted">Zona</label>
      <select class="form-select form-select-sm" name="zona_id" id="filtro_zona">
        <option value="">Todas</option>
        <?php foreach ($zonas as $z): ?>
          <option value="<?= (int)$z['id'] ?>" <?= $zona_id_filtro===(int)$z['id']?'selected':'' ?>>
            <?= htmlspecialchars((string)$z['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2 col-lg-2">
      <label class="form-label small text-muted">Centro</label>
      <select class="form-select form-select-sm" name="centro_id" id="filtro_centro">
        <option value="">Todos</option>
        <?php foreach ($ccs as $c): ?>
          <option value="<?= (int)$c['id'] ?>" data-z="<?= (int)$c['zona_id'] ?>" <?= $centro_id_filtro===(int)$c['id']?'selected':'' ?>>
            <?= htmlspecialchars((string)$c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2 col-lg-2">
      <label class="form-label small text-muted">PDV</label>
      <select class="form-select form-select-sm" name="pdv_id" id="filtro_pdv">
        <option value="">Todos</option>
        <?php foreach ($pdvs as $p):
          $txt = trim(($p['codigo'] ? $p['codigo'].' - ' : '') . ($p['nombre'] ?? ''));
        ?>
          <option value="<?= (int)$p['id'] ?>" data-centro="<?= (int)$p['centro_id'] ?>" <?= $pdv_id_filtro===(int)$p['id']?'selected':'' ?>>
            <?= htmlspecialchars($txt) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-auto ms-auto d-flex gap-2">
      <div>
        <label class="form-label small text-muted">Por página</label>
        <select class="form-select form-select-sm" name="per_page" onchange="this.form.submit()">
          <?php foreach ($per_page_options as $op): ?>
            <option value="<?= $op ?>" <?= $per_page===$op?'selected':'' ?>><?= $op ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="d-flex align-items-end gap-2">
        <button class="btn btn-primary btn-sm">Buscar</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/asesores.php">Limpiar</a>
      </div>
    </div>
  </form>

  <?php
    $total_pages  = max(1, (int)ceil($total_records / $per_page));
    $start_record = $total_records ? ($offset + 1) : 0;
    $end_record   = min($offset + $per_page, $total_records);
  ?>
  <div class="text-muted small mb-2">
    Mostrando <?= $start_record ?> – <?= $end_record ?> de <?= $total_records ?> registros
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th style="width:60px">#</th>
          <th style="width:140px">Cédula</th>
          <th>Nombre</th>
          <th style="width:140px">Teléfono</th>
          <th style="width:160px">Zona</th>
          <th style="width:220px">Centro</th>
          <th style="width:220px">PDV</th>
          <th style="width:100px">Estado</th>
          <th style="width:220px" class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows): foreach ($rows as $i => $r):
          $id        = (int)($r['id'] ?? 0);
          $cedula    = (string)($r['cedula'] ?? '');
          $nombre    = (string)($r['nombre'] ?? '');
          $telefono  = (string)($r['telefono'] ?? '');
          $zona_id   = (int)($r['zona_id'] ?? 0);
          $centro_id = (int)($r['centro_id'] ?? 0);
          $pdv_id    = (int)($r['pdv_id'] ?? 0);
          $activo    = (int)($r['activo'] ?? 0);

          $pdvNombre = '';
          if ($pdvs && $pdv_id) {
            foreach ($pdvs as $p) {
              if ((int)$p['id'] === $pdv_id) {
                $pdvNombre = trim(($p['codigo'] ? $p['codigo'].' - ' : '') . ($p['nombre'] ?? ''));
                break;
              }
            }
          }
        ?>
          <tr>
            <td class="text-muted"><?= $offset + $i + 1 ?></td>
            <td><code><?= htmlspecialchars($cedula) ?></code></td>
            <td><?= htmlspecialchars($nombre) ?></td>
            <td><?= $telefono ? htmlspecialchars($telefono) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $r['zona_nombre'] ? htmlspecialchars((string)$r['zona_nombre']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $r['centro_nombre'] ? htmlspecialchars((string)$r['centro_nombre']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $pdvNombre ? htmlspecialchars($pdvNombre) : '<span class="text-muted">—</span>' ?></td>
            <td>
              <?= $activo ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm" role="group">
                <button type="button"
                        class="btn btn-outline-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#modalForm"
                        data-id="<?= $id ?>"
                        data-cedula="<?= htmlspecialchars($cedula) ?>"
                        data-nombre="<?= htmlspecialchars($nombre) ?>"
                        data-telefono="<?= htmlspecialchars($telefono) ?>"
                        data-activo="<?= $activo ?>"
                        data-zona="<?= $zona_id ?: '' ?>"
                        data-centro="<?= $centro_id ?: '' ?>"
                        data-pdv="<?= $pdv_id ?: '' ?>">
                  Editar
                </button>

                <form method="post" class="d-inline-block js-swal-confirm"
                      data-confirm="¿Cambiar estado de esta persona asesora?"
                      data-confirm-type="warning"
                      data-confirm-ok="Sí, cambiar">
                  <input type="hidden" name="act" value="toggle">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button type="submit" class="btn btn-sm btn-outline-warning">
                    <?= $activo ? 'Inactivar' : 'Activar' ?>
                  </button>
                </form>

              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">Sin resultados.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginador estilo Hallazgos/PDV -->
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="text-muted small">
      Página <?= $page ?> de <?= $total_pages ?>
    </div>
    <div class="d-flex gap-2">
      <?php if ($page > 1): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= asesores_build_url(['page'=>1]) ?>">
          <i class="bi bi-skip-backward-fill"></i> Primero
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= asesores_build_url(['page'=>$page-1]) ?>">
          <i class="bi bi-caret-left-fill"></i> Anterior
        </a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled>
          <i class="bi bi-skip-backward-fill"></i> Primero
        </button>
        <button class="btn btn-outline-secondary btn-sm" disabled>
          <i class="bi bi-caret-left-fill"></i> Anterior
        </button>
      <?php endif; ?>

      <?php if ($page < $total_pages): ?>
        <a class="btn btn-outline-primary btn-sm" href="<?= asesores_build_url(['page'=>$page+1]) ?>">
          Siguiente <i class="bi bi-caret-right-fill"></i>
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= asesores_build_url(['page'=>$total_pages]) ?>">
          Último <i class="bi bi-skip-forward-fill"></i>
        </a>
      <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled>
          Siguiente <i class="bi bi-caret-right-fill"></i>
        </button>
        <button class="btn btn-outline-secondary btn-sm" disabled>
          Último <i class="bi bi-skip-forward-fill"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal Crear/Editar -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="<?= BASE_URL ?>/admin/asesores.php">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" id="f_id" value="0">

      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Nuevo asesor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Cédula *</label>
          <input class="form-control" name="cedula" id="f_cedula" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Nombre *</label>
          <input class="form-control" name="nombre" id="f_nombre" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Teléfono</label>
          <input class="form-control" name="telefono" id="f_telefono">
        </div>

        <?php if ($zonas): ?>
        <div class="mb-2">
          <label class="form-label">Zona</label>
          <select class="form-select" name="zona_id" id="f_zona">
            <option value="">— Selecciona —</option>
            <?php foreach ($zonas as $z): ?>
              <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars((string)$z['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($ccs): ?>
        <div class="mb-2">
          <label class="form-label">Centro de Costo</label>
          <select class="form-select" name="centro_id" id="f_centro">
            <option value="">— Selecciona —</option>
            <?php foreach ($ccs as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-z="<?= (int)$c['zona_id'] ?>">
                <?= htmlspecialchars((string)$c['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($pdvs): ?>
        <div class="mb-2">
          <label class="form-label">PDV</label>
          <select class="form-select" name="pdv_id" id="f_pdv">
            <option value="">— Selecciona —</option>
            <?php foreach ($pdvs as $p):
              $txt = trim(($p['codigo'] ? $p['codigo'].' - ' : '') . ($p['nombre'] ?? ''));
            ?>
              <option value="<?= (int)$p['id'] ?>" data-centro="<?= (int)$p['centro_id'] ?>">
                <?= htmlspecialchars($txt) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="f_activo" name="activo" checked>
          <label class="form-check-label" for="f_activo">Activo</label>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // --- dependientes (form modal)
  const $zona   = document.getElementById('f_zona');
  const $centro = document.getElementById('f_centro');
  const $pdv    = document.getElementById('f_pdv');

  function filterCentros() {
    if (!$centro || !$zona) return;
    const z = $zona.value || '';
    const opts = Array.from($centro.querySelectorAll('option'));
    const first = document.createElement('option');
    first.value = ''; first.textContent = '— Selecciona —';
    $centro.innerHTML = ''; $centro.appendChild(first);
    opts.forEach(o => {
      const val = o.getAttribute('value') || ''; if (!val) return;
      const oz  = o.getAttribute('data-z') || '';
      if (!z || oz === z) $centro.appendChild(o);
    });
    $centro.value = '';
    filterPDV();
  }

  function filterPDV() {
    if (!$pdv || !$centro) return;
    const c = $centro.value || '';
    const opts = Array.from($pdv.querySelectorAll('option'));
    const first = document.createElement('option');
    first.value = ''; first.textContent = '— Selecciona —';
    $pdv.innerHTML = ''; $pdv.appendChild(first);
    opts.forEach(o => {
      const val = o.getAttribute('value') || ''; if (!val) return;
      const oc  = o.getAttribute('data-centro') || '';
      if (!c || oc === c) $pdv.appendChild(o);
    });
    $pdv.value = '';
  }

  $zona?.addEventListener('change', filterCentros);
  $centro?.addEventListener('change', filterPDV);

  // --- dependientes (filtros cabecera)
  const $fz = document.getElementById('filtro_zona');
  const $fc = document.getElementById('filtro_centro');
  const $fp = document.getElementById('filtro_pdv');

  function filterFiltroCentros() {
    if (!$fc || !$fz) return;
    const z = $fz.value || '';
    const opts = Array.from($fc.querySelectorAll('option'));
    const first = document.createElement('option');
    first.value = ''; first.textContent = 'Todos';
    $fc.innerHTML = ''; $fc.appendChild(first);
    opts.forEach(o => {
      const val = o.getAttribute('value') || ''; if (!val) return;
      const oz  = o.getAttribute('data-z') || '';
      if (!z || oz === z) $fc.appendChild(o);
    });
    filterFiltroPdv();
  }

  function filterFiltroPdv() {
    if (!$fp || !$fc) return;
    const c = $fc.value || '';
    const opts = Array.from($fp.querySelectorAll('option'));
    const first = document.createElement('option');
    first.value = ''; first.textContent = 'Todos';
    $fp.innerHTML = ''; $fp.appendChild(first);
    opts.forEach(o => {
      const val = o.getAttribute('value') || ''; if (!val) return;
      const oc  = o.getAttribute('data-centro') || '';
      if (!c || oc === c) $fp.appendChild(o);
    });
  }

  $fz?.addEventListener('change', filterFiltroCentros);
  $fc?.addEventListener('change', filterFiltroPdv);
  // inicializa dependientes de filtros
  filterFiltroCentros();

  // --- modal populate
  const modalEl = document.getElementById('modalForm');
  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', (ev) => {
      const btn      = ev.relatedTarget;
      const id       = btn?.getAttribute('data-id') ?? '0';
      const cedula   = btn?.getAttribute('data-cedula') ?? '';
      const nombre   = btn?.getAttribute('data-nombre') ?? '';
      const telefono = btn?.getAttribute('data-telefono') ?? '';
      const activo   = (btn?.getAttribute('data-activo') ?? '1') === '1';
      const zona     = btn?.getAttribute('data-zona') ?? '';
      const centro   = btn?.getAttribute('data-centro') ?? '';
      const pdv      = btn?.getAttribute('data-pdv') ?? '';

      document.getElementById('modalTitle').textContent = (id === '0') ? 'Nuevo asesor' : 'Editar asesor';
      document.getElementById('f_id').value       = id;
      document.getElementById('f_cedula').value   = cedula;
      document.getElementById('f_nombre').value   = nombre;
      document.getElementById('f_telefono').value = telefono;
      document.getElementById('f_activo').checked = activo;

      if ($zona)   $zona.value = zona || '';
      filterCentros();
      if ($centro) $centro.value = centro || '';
      filterPDV();
      if ($pdv)    $pdv.value = pdv || '';
    });
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form.js-swal-confirm').forEach(form => {
    form.addEventListener('submit', (ev) => {
      // evita doble disparo
      if (form.dataset.submitting === '1') return;

      ev.preventDefault();

      const msg  = form.getAttribute('data-confirm') || '¿Confirmas la acción?';
      const type = form.getAttribute('data-confirm-type') || 'question';
      const ok   = form.getAttribute('data-confirm-ok') || 'Sí';

      if (window.Swal) {
        Swal.fire({
          title: 'Confirmar',
          text: msg,
          icon: type,
          showCancelButton: true,
          confirmButtonText: ok,
          cancelButtonText: 'Cancelar',
          buttonsStyling: true
        }).then((res) => {
          if (res.isConfirmed) {
            form.dataset.submitting = '1';
            form.submit();
          }
        });
      } else {
        // Fallback por si no está SweetAlert cargado
        if (confirm(msg)) {
          form.submit();
        }
      }
    });
  });
});
</script>

<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
