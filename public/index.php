<?php
// auditoria_app/public/index.php
require_once __DIR__.'/../includes/session_boot.php';
require_once __DIR__.'/../includes/env.php';
require_once __DIR__.'/../includes/acl.php';
require_once __DIR__ . '/../includes/acl_suite.php';  // <- NUEVO
require_once __DIR__ . '/../includes/acl.php';        // permisos finos internos

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if (!module_enabled_for_user($uid, 'auditoria')) {
    render_403_and_exit();
}

// Además, exige permiso del módulo (ejemplo):
require_perm('auditoria.access');

require_module('auditoria', 'access');   // si NO tiene, aquí ya sale 403 estático
header('Location: '.rtrim(BASE_URL,'/').'/dashboard.php');
exit;
