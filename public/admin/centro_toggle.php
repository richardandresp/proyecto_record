<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

if (($_SESSION['rol'] ?? 'lectura') !== 'admin') { http_response_code(403); exit('Sin permiso'); }

$id = (int)($_GET['id'] ?? 0);
$to = (int)($_GET['to'] ?? -1);
if ($id<=0 || ($to!==0 && $to!==1)) { http_response_code(400); exit('Parámetros inválidos'); }

$pdo = getDB();
$up = $pdo->prepare("UPDATE centro_costo SET activo=? WHERE id=?");
$up->execute([$to, $id]);

set_flash('success', $to ? 'CC activado.' : 'CC inactivado.');
header('Location: ' . BASE_URL . '/admin/centros.php');
exit;
