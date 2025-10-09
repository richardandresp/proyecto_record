<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // agrega aquí otros permisos finos si los usas

require_once __DIR__ . '/../../includes/page_boot.php'; // session/env/db/auth/acl/acl_suite/flash
require_roles(['admin']); // todas estas pantallas son solo admin

$pdo = getDB();


$up = $pdo->prepare("UPDATE zona SET activo=? WHERE id=?");
$up->execute([$to, $id]);

set_flash('success', $to ? 'Zona activada.' : 'Zona inactivada.');
header('Location: ' . BASE_URL . '/admin/zonas.php');
exit;
