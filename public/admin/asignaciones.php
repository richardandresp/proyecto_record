<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega aquí otros permisos finos si los usas

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // todas estas pantallas son solo admin

$pdo = getDB();


// Catálogos
$zonas   = $pdo->query("SELECT id, nombre FROM zona WHERE activo=1 ORDER BY nombre")->fetchAll();
$ccs     = $pdo->query("SELECT id, nombre, zona_id FROM centro_costo WHERE activo=1 ORDER BY nombre")->fetchAll();

// Usuarios por rol
$fetchUsers = function($rol) use ($pdo) {
  $st = $pdo->prepare("SELECT id, nombre, email FROM usuario WHERE rol=? AND activo=1 ORDER BY nombre");
  $st->execute([$rol]);
  return $st->fetchAll();
};
$supervisores = $fetchUsers('supervisor');
$lideres      = $fetchUsers('lider');
$auxiliares   = $fetchUsers('auxiliar');

// Alta de asignación
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='crear') {
  try {
    $tipo   = $_POST['tipo'] ?? '';
    $uid    = (int)($_POST['usuario_id'] ?? 0);
    $desde  = $_POST['desde'] ?? '';
    $zona_id= (int)($_POST['zona_id'] ?? 0);
    $cc_id  = (int)($_POST['cc_id'] ?? 0);

    if (!$uid || !$desde) throw new Exception('Usuario y fecha "desde" son obligatorios.');

    if ($tipo==='supervisor_zona') {
      if (!$zona_id) throw new Exception('Zona requerida.');
      $ins = $pdo->prepare("INSERT INTO supervisor_zona (usuario_id, zona_id, desde) VALUES (?,?,?)");
      $ins->execute([$uid, $zona_id, $desde]);
      $msg = "Asignación de Supervisor creada.";
    } elseif ($tipo==='lider_centro') {
      if (!$cc_id) throw new Exception('CC requerido.');
      $ins = $pdo->prepare("INSERT INTO lider_centro (usuario_id, centro_id, desde) VALUES (?,?,?)");
      $ins->execute([$uid, $cc_id, $desde]);
      $msg = "Asignación de Líder creada.";
    } elseif ($tipo==='auxiliar_centro') {
      if (!$cc_id) throw new Exception('CC requerido.');
      $ins = $pdo->prepare("INSERT INTO auxiliar_centro (usuario_id, centro_id, desde) VALUES (?,?,?)");
      $ins->execute([$uid, $cc_id, $desde]);
      $msg = "Asignación de Auxiliar creada.";
    } else {
      throw new Exception('Tipo inválido.');
    }
  } catch(Throwable $e) {
    $err = "Error: ".htmlspecialchars($e->getMessage());
  }
}

// Cierre de vigencia
if (isset($_GET['close'], $_GET['id'], $_GET['t'])) {
  $t = $_GET['t']; $id = (int)$_GET['id'];
  $tabla = $t==='s' ? 'supervisor_zona' : ($t==='l' ? 'lider_centro' : ($t==='a' ? 'auxiliar_centro' : ''));
  if ($tabla && $id>0) {
    $pdo->prepare("UPDATE {$tabla} SET hasta=CURDATE() WHERE id=? AND hasta IS NULL")->execute([$id]);
    header('Location: '.BASE_URL.'/admin/asignaciones.php'); exit;
  }
}

// Listas (vigentes primero)
$asig_sup = $pdo->query("
  SELECT sz.id, u.nombre AS usuario, z.nombre AS zona, sz.desde, sz.hasta
  FROM supervisor_zona sz
  JOIN usuario u ON u.id=sz.usuario_id
  JOIN zona z ON z.id=sz.zona_id
  ORDER BY (sz.hasta IS NULL) DESC, z.nombre, u.nombre
")->fetchAll();

$asig_lider = $pdo->query("
  SELECT lc.id, u.nombre AS usuario, c.nombre AS cc, z.nombre AS zona, lc.desde, lc.hasta
  FROM lider_centro lc
  JOIN usuario u ON u.id=lc.usuario_id
  JOIN centro_costo c ON c.id=lc.centro_id
  JOIN zona z ON z.id=c.zona_id
  ORDER BY (lc.hasta IS NULL) DESC, z.nombre, c.nombre, u.nombre
")->fetchAll();

$asig_aux = $pdo->query("
  SELECT ac.id, u.nombre AS usuario, c.nombre AS cc, z.nombre AS zona, ac.desde, ac.hasta
  FROM auxiliar_centro ac
  JOIN usuario u ON u.id=ac.usuario_id
  JOIN centro_costo c ON c.id=ac.centro_id
  JOIN zona z ON z.id=c.zona_id
  ORDER BY (ac.hasta IS NULL) DESC, z.nombre, c.nombre, u.nombre
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Asignaciones</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <ul class="nav nav-tabs" role="tablist">
    <!-- Orden solicitado: Supervisor, Líder, Auxiliar -->
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-sup" type="button">Supervisor ↔ Zona</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-lider" type="button">Líder ↔ CC</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-aux" type="button">Auxiliar ↔ CC</button></li>
  </ul>

  <div class="tab-content pt-3">
    <!-- SUPERVISOR ↔ ZONA -->
    <div class="tab-pane fade show active" id="tab-sup">
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="tipo" value="supervisor_zona">
        <div class="col-md-4">
          <label class="form-label">Supervisor</label>
          <select name="usuario_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($supervisores as $u): ?>
              <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Zona</label>
          <select name="zona_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($zonas as $z): ?>
              <option value="<?= $z['id'] ?>"><?= htmlspecialchars($z['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">Agregar</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-light"><tr><th>Supervisor</th><th>Zona</th><th>Desde</th><th>Hasta</th><th></th></tr></thead>
          <tbody>
            <?php foreach($asig_sup as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['usuario']) ?></td>
                <td><?= htmlspecialchars($r['zona']) ?></td>
                <td><?= htmlspecialchars($r['desde']) ?></td>
                <td><?= $r['hasta'] ?: '—' ?></td>
                <td>
                  <?php if(!$r['hasta']): ?>
                    <a class="btn btn-sm btn-outline-danger"
                    href="<?= BASE_URL ?>/admin/asignaciones.php?close=1&id=<?= (int)$r['id'] ?>&t=s"
                    data-confirm="¿Cerrar la vigencia desde hoy?"
                    data-confirm-type="warning"
                    data-confirm-ok="Sí, cerrar">
                    Cerrar
                    </a>

                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- LÍDER ↔ CC -->
    <div class="tab-pane fade" id="tab-lider">
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="tipo" value="lider_centro">
        <div class="col-md-4">
          <label class="form-label">Líder</label>
          <select name="usuario_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($lideres as $u): ?>
              <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">CC</label>
          <select name="cc_id" id="lc_cc" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($ccs as $c): ?>
              <option value="<?= $c['id'] ?>" data-z="<?= $c['zona_id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">Agregar</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-light"><tr><th>Líder</th><th>Zona</th><th>CC</th><th>Desde</th><th>Hasta</th><th></th></tr></thead>
          <tbody>
            <?php foreach($asig_lider as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['usuario']) ?></td>
                <td><?= htmlspecialchars($r['zona']) ?></td>
                <td><?= htmlspecialchars($r['cc']) ?></td>
                <td><?= htmlspecialchars($r['desde']) ?></td>
                <td><?= $r['hasta'] ?: '—' ?></td>
                <td>
                  <?php if(!$r['hasta']): ?>
                    <a class="btn btn-sm btn-outline-danger"
                      href="<?= BASE_URL ?>/admin/asignaciones.php?close=1&id=<?= (int)$r['id'] ?>&t=l"
                      data-confirm="¿Cerrar la vigencia desde hoy?"
                      data-confirm-type="warning"
                      data-confirm-ok="Sí, cerrar">
                      Cerrar
                    </a>

                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- AUXILIAR ↔ CC -->
    <div class="tab-pane fade" id="tab-aux">
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="tipo" value="auxiliar_centro">
        <div class="col-md-4">
          <label class="form-label">Auxiliar</label>
          <select name="usuario_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($auxiliares as $u): ?>
              <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">CC</label>
          <select name="cc_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach($ccs as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Desde</label>
          <input type="date" name="desde" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">Agregar</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-light"><tr><th>Auxiliar</th><th>Zona</th><th>CC</th><th>Desde</th><th>Hasta</th><th></th></tr></thead>
          <tbody>
            <?php foreach($asig_aux as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['usuario']) ?></td>
                <td><?= htmlspecialchars($r['zona']) ?></td>
                <td><?= htmlspecialchars($r['cc']) ?></td>
                <td><?= htmlspecialchars($r['desde']) ?></td>
                <td><?= $r['hasta'] ?: '—' ?></td>
                <td>
                  <?php if(!$r['hasta']): ?>
                    <a class="btn btn-sm btn-outline-danger"
                      href="<?= BASE_URL ?>/admin/asignaciones.php?close=1&id=<?= (int)$r['id'] ?>&t=a"
                      data-confirm="¿Cerrar la vigencia desde hoy?"
                      data-confirm-type="warning"
                      data-confirm-ok="Sí, cerrar">
                      Cerrar
                    </a>

                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
