<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega aquí otros permisos finos si los usas

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // todas estas pantallas son solo admin

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
