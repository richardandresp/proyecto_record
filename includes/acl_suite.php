<?php
// auditoria_app/includes/acl_suite.php
declare(strict_types=1);

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/db.php'; // define getDB()

if (!function_exists('module_enabled_for_user')) {
    /**
     * ¿El usuario tiene habilitado un módulo (por clave)?
     * bypass para roles altos (admin/auditor)
     */
    function module_enabled_for_user(int $uid, string $modClave): bool {
        if ($uid <= 0) return false;

        $rol = $_SESSION['rol'] ?? 'lectura';
        if (in_array($rol, ['admin', 'auditor'], true)) return true;

        $pdo = getDB();
        $sql = "SELECT 1
                FROM usuario_modulo um
                JOIN modulo m ON m.id = um.modulo_id AND m.activo = 1
                WHERE um.usuario_id = ? AND um.activo = 1 AND m.clave = ?
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$uid, $modClave]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('render_403_and_exit')) {
    function render_403_and_exit(): void {
        http_response_code(403);
        $tpl = __DIR__ . '/403.php';
        if (is_file($tpl)) {
            include $tpl;
        } else {
            echo "<h1>403 · No autorizado</h1><p>No tienes acceso.</p>";
        }
        exit;
    }
}
