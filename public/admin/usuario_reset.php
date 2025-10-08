<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/flash.php';

login_required();
require_roles(['admin']); // solo admin

$pdo = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  set_flash('danger', 'ID inválido.');
  header('Location: ' . BASE_URL . '/admin/usuarios.php');
  exit;
}

// No permitir resetear admins (opcional)
$st = $pdo->prepare("SELECT nombre, email, rol, activo FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) {
  set_flash('danger', 'Usuario no encontrado.');
  header('Location: ' . BASE_URL . '/admin/usuarios.php'); exit;
}
if ($u['rol'] === 'admin') {
  set_flash('warning', 'No se puede resetear la contraseña de un admin desde aquí.');
  header('Location: ' . BASE_URL . '/admin/usuarios.php'); exit;
}

function gen_temp_password(int $len=10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@$%';
  $out = '';
  for ($i=0; $i<$len; $i++) { $out .= $alphabet[random_int(0, strlen($alphabet)-1)]; }
  return $out;
}

try {
  $temp = gen_temp_password();
  $hash = password_hash($temp, PASSWORD_DEFAULT);

  $up = $pdo->prepare("
    UPDATE usuario
    SET clave_hash = ?, must_change_password = 1
    WHERE id = ?
  ");
  $up->execute([$hash, $id]);

  set_flash('success', 'Contraseña temporal generada para ' . ($u['nombre'] ?? 'usuario') .
                      '. Entrégala al usuario: ' . $temp);
} catch (Throwable $e) {
  set_flash('danger', 'Error al resetear: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/admin/usuarios.php');
exit;
