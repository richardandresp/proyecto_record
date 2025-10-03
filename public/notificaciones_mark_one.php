<?php
 require_once __DIR__ . '/../includes/session_boot.php';

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

login_required();
$pdo = get_pdo();
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $st = $pdo->prepare("UPDATE notificacion SET leido_en=NOW() WHERE id=? AND usuario_id=? AND leido_en IS NULL");
  $st->execute([$id, $uid]);
}
header('Location: ' . BASE_URL . '/notificaciones.php');
