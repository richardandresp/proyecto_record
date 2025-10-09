<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega aquí otros permisos finos si los usas

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // todas estas pantallas son solo admin

$pdo = getDB();


$rows = $pdo->query("
  SELECT id, nombre, email, telefono, rol, activo, must_change_password
  FROM usuario
  ORDER BY (rol='admin') DESC, nombre
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Usuarios</h3>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/admin/usuarios_nuevo.php">+ Nuevo usuario</a>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th>Nombre</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Cambiar Pass.</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= htmlspecialchars($u['nombre']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="badge bg-secondary"><?= htmlspecialchars($u['rol']) ?></span></td>
          <td>
            <?= ((int)$u['activo']===1)
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-danger">Inactivo</span>' ?>
          </td>
          <td><?= ((int)$u['must_change_password']===1)
                ? '<span class="badge bg-warning text-dark">Sí</span>'
                : 'No' ?></td>

          <td class="d-flex flex-wrap gap-1">
            <?php if ($u['rol'] !== 'admin'): ?>
              <?php if ((int)$u['activo'] === 1): ?>
                <a class="btn btn-sm btn-outline-danger"
                   href="<?= BASE_URL ?>/admin/usuario_toggle.php?id=<?= (int)$u['id'] ?>&to=0"
                   data-confirm="¿Inactivar este usuario?"
                   data-confirm-type="warning"
                   data-confirm-ok="Sí, inactivar">
                  Inactivar
                </a>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-success"
                   href="<?= BASE_URL ?>/admin/usuario_toggle.php?id=<?= (int)$u['id'] ?>&to=1"
                   data-confirm="¿Activar este usuario?"
                   data-confirm-type="warning"
                   data-confirm-ok="Sí, activar">
                  Activar
                </a>
              <?php endif; ?>

              <a class="btn btn-sm btn-outline-primary"
                 href="<?= BASE_URL ?>/admin/usuario_reset.php?id=<?= (int)$u['id'] ?>"
                 data-confirm="¿Generar clave temporal y obligar cambio al ingresar?"
                 data-confirm-type="question"
                 data-confirm-ok="Sí, generar">
                Resetear clave
              </a>

              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= BASE_URL ?>/admin/usuario_editar.php?id=<?= (int)$u['id'] ?>">
                Editar
              </a>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-secondary" disabled>Admin</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
