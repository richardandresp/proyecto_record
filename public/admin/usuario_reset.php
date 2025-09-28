<?php
// public/admin/usuario_reset.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();
if (($_SESSION['rol'] ?? 'lectura') !== 'admin') { http_response_code(403); exit('Sin permiso'); }

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido'); }

// No operar sobre admins si no quieres; aquí permitimos reset, pero puedes bloquearlo:
$st = $pdo->prepare("SELECT id,nombre,email,rol FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); exit('Usuario no encontrado'); }

function gen_temp_password(int $len=10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@$%';
  $out = '';
  for ($i=0; $i<$len; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet)-1)]; }
  return $out;
}

$temp = gen_temp_password();
$hash = password_hash($temp, PASSWORD_DEFAULT);

// Actualiza hash y obliga cambio
$up = $pdo->prepare("UPDATE usuario SET clave_hash=?, must_change_password=1, activo=1 WHERE id=?");
$up->execute([$hash, $id]);

// Muestra la temporal para que el admin la entregue por el canal que use (correo/whatsapp)
include __DIR__ . '/../../includes/header.php';
?>
<div class="container">
  <h3>Reset de contraseña</h3>
  <div class="alert alert-success">
    Se generó una contraseña temporal para <b><?= htmlspecialchars($u['nombre']) ?></b> (<?= htmlspecialchars($u['email']) ?>).
  </div>
  <div class="alert alert-warning">
    <b>Contraseña temporal:</b> <code><?= htmlspecialchars($temp) ?></code><br>
    Al iniciar sesión, el sistema <b>le pedirá cambiarla</b>.
  </div>
  <a href="<?= BASE_URL ?>/admin/usuarios.php" class="btn btn-secondary">Volver a Usuarios</a>
</div>
