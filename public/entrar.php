<?php
// Portero de Auditoría desde la Suite
require_once __DIR__ . '/../../../includes/session_boot.php';

$self = '/suite_operativa/public/modulos/auditoria/'; // raíz “montada” del módulo

if (empty($_SESSION['usuario_id'])) {
    // Sin sesión → ve al login de Auditoría y vuelve aquí
    header('Location: /auditoria_app/public/login.php?redirect=' . urlencode($self));
    exit;
}

// Con sesión → entra al módulo (raíz)
header('Location: ' . $self);
exit;
