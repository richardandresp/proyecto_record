<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega aquí otros permisos finos si los usas

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // todas estas pantallas son solo admin

$pdo = getDB();

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega uno fino si tienes p.ej. 'auditoria.admin.users'
require_once __DIR__ . '/../../includes/page_boot.php'; // ya tienes $pdo, $uid, $rol, BASE_URL y flash

// ==== roles: traer de tabla 'rol' (fallback a lista fija si no existe) ====
$roles = [];
try {
  $rows = $pdo->query("SELECT clave FROM rol ORDER BY clave")->fetchAll(PDO::FETCH_COLUMN);
  if ($rows) {
    $roles = array_values(array_unique(array_map('strval', $rows)));
  }
} catch (Throwable $e) {
  // si no existe tabla 'rol', usa listado fijo
}
if (!$roles) {
  $roles = ['auditor','supervisor','lider','auxiliar','lectura','admin'];
}

$msg = ''; $err = ''; $tempPass = null;

function gen_temp_password(int $len=10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@$%';
  $out = '';
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet)-1)];
  }
  return $out;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $rol    = trim($_POST['rol'] ?? '');
  $tel    = trim($_POST['telefono'] ?? '');

  if (!$nombre || !$email || !$rol) {
    $err = 'Completa nombre, email y rol.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Email inválido.';
  } elseif (!in_array($rol, $roles, true)) {
    $err = 'Rol inválido.';
  }

  if (!$err) {
    try {
      // Email único
      $st = $pdo->prepare("SELECT 1 FROM usuario WHERE email=? LIMIT 1");
      $st->execute([$email]);
      if ($st->fetchColumn()) {
        throw new Exception('El email ya existe.');
      }

      $tempPass = gen_temp_password();
      $hash = password_hash($tempPass, PASSWORD_DEFAULT);

      $ins = $pdo->prepare("
        INSERT INTO usuario (nombre,email,telefono,rol,clave_hash,must_change_password,activo)
        VALUES (?,?,?,?,?,1,1)
      ");
      $ins->execute([$nombre,$email,$tel,$rol,$hash]);

      set_flash('success', 'Usuario creado correctamente.');
      // si quieres mostrar la contraseña temporal en la lista, puedes guardarla en otro flash
      set_flash('warning', 'Contraseña temporal: ' . $tempPass);

      header('Location: ' . BASE_URL . '/admin/usuarios.php');
      exit;
    } catch (Throwable $e) {
      $err = 'Error al crear: ' . $e->getMessage();
      $tempPass = null; // no mostramos pass si falló
    }
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Nuevo usuario</h3>

  <?php if (!empty($err)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nombre *</label>
      <input name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Email *</label>
      <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Rol *</label>
      <select name="rol" class="form-select" required>
        <option value="">Seleccione...</option>
        <?php foreach ($roles as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>" <?= (($_POST['rol'] ?? '')===$r)?'selected':'' ?>>
            <?= htmlspecialchars(ucfirst($r)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Crear usuario</button>
      <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>
</div>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
