<?php declare(strict_types=1);
/**
 * Módulo T&T – Bootstrap del módulo dentro de la Suite
 * Ubicación: /public/modulos/tyt/includes/env_mod.php
 */

$SUITE_ROOT = realpath(__DIR__ . '/../../../..');

$includes = [
    $SUITE_ROOT . '/includes/env.php',
    $SUITE_ROOT . '/includes/session_boot.php',
    $SUITE_ROOT . '/includes/acl_core.php',
    $SUITE_ROOT . '/includes/db.php',
];

foreach ($includes as $inc) {
    if (is_string($inc) && file_exists($inc)) {
        require_once $inc;
    }
}

define('TYT_MOD_PATH', realpath(__DIR__ . '/..'));
function tyt_path(string $rel = ''): string {
    return TYT_MOD_PATH . '/' . ltrim($rel, '/');
}

if (function_exists('user_has_perm') && !user_has_perm('tyt.access')) {
    http_response_code(403);
    echo 'Acceso denegado (tyt.access)';
    exit;
}