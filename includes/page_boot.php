<?php
// auditoria_app/includes/page_boot.php
// Boot mínimo para páginas públicas del módulo

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/acl_suite.php';
require_once __DIR__ . '/flash.php'; 

login_required();

// Config por página (si no la definiste antes, usa auditoría y sin permisos extra)
$__MODULE = $REQUIRED_MODULE ?? 'auditoria';
$__PERMS  = isset($REQUIRED_PERMS) && is_array($REQUIRED_PERMS) ? $REQUIRED_PERMS : [];

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$rol = $_SESSION['rol'] ?? 'lectura';

// Guard de módulo
if (!module_enabled_for_user($uid, $__MODULE)) {
  render_403_and_exit();
}

// Permisos finos
foreach ($__PERMS as $perm) {
  require_perm($perm);
}

// Conexión PDO lista
$pdo = getDB();
