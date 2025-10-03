<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

$up = $pdo->prepare("UPDATE zona SET activo=? WHERE id=?");
$up->execute([$to, $id]);

set_flash('success', $to ? 'Zona activada.' : 'Zona inactivada.');
header('Location: ' . BASE_URL . '/admin/zonas.php');
exit;
