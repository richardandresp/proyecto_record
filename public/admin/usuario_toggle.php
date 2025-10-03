<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();


// No permitir operar sobre admins del sistema
$st = $pdo->prepare("SELECT rol FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch();
if (!$u) { http_response_code(404); echo "Usuario no encontrado."; exit; }
if ($u['rol'] === 'admin') { http_response_code(403); echo "No se puede modificar un admin."; exit; }

// Toggle
$up = $pdo->prepare("UPDATE usuario SET activo=? WHERE id=?");
$up->execute([$to, $id]);

require_once __DIR__ . '/../../includes/flash.php';
set_flash('success', ($to ? 'Usuario activado.' : 'Usuario inactivado.') );

header('Location: ' . BASE_URL . '/admin/usuarios.php');
exit;
