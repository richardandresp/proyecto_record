<?php
// Sesión compartida Suite + Auditoría (mismo nombre y path)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',   // MUY importante: visible en /auditoria_app y /suite_operativa
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('suite_sess');
    session_start();
}
