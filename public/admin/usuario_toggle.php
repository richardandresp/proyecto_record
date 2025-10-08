<?php
declare(strict_types=1);

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access']; // ajusta si tienes un permiso admin específico

require_once __DIR__ . '/../../includes/page_boot.php'; // te da $pdo, $uid, $rol, BASE_URL

// shim de flash si no está cargado
if (!function_exists('set_flash')) {
  function set_flash(string $type, string $msg): void {
    if (!isset($_SESSION)) session_start();
    $_SESSION['__flash'][] = ['type'=>$type, 'msg'=>$msg, 'ts'=>time()];
  }
}

// lee y valida parámetros
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$to = isset($_GET['to']) ? (int)$_GET['to'] : -1;

$back = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/admin/permisos.php');

if ($id <= 0 || ($to !== 0 && $to !== 1)) {
  set_flash('danger', 'Parámetros inválidos.');
  header('Location: ' . $back); exit;
}

// verifica que el usuario exista
$st = $pdo->prepare("SELECT id FROM usuario WHERE id=? LIMIT 1");
$st->execute([$id]);
if (!$st->fetchColumn()) {
  set_flash('danger', 'Usuario no encontrado.');
  header('Location: ' . $back); exit;
}

// actualiza estado (columna 'activo' = 0/1)
$up = $pdo->prepare("UPDATE usuario SET activo=? WHERE id=?");
$up->execute([$to, $id]);

set_flash('success', $to ? 'Usuario activado.' : 'Usuario desactivado.');
header('Location: ' . $back); exit;
