<?php
// public/admin/zona_toggle.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/flash.php';
login_required();

if (($_SESSION['rol'] ?? 'lectura') !== 'admin') {
  http_response_code(403); exit('Sin permiso');
}

$id = (int)($_GET['id'] ?? 0);
$to = (int)($_GET['to'] ?? -1);
if ($id<=0 || ($to!==0 && $to!==1)) { http_response_code(400); exit('Parámetros inválidos'); }

$pdo = getDB();
$up = $pdo->prepare("UPDATE zona SET activo=? WHERE id=?");
$up->execute([$to, $id]);

set_flash('success', $to ? 'Zona activada.' : 'Zona inactivada.');
header('Location: ' . BASE_URL . '/admin/zonas.php');
exit;
