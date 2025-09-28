<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notify.php';

login_required();
header('Content-Type: application/json; charset=utf-8');

$uid   = (int)($_SESSION['usuario_id'] ?? 0);
$limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
$offset= max(0, (int)($_GET['offset'] ?? 0));

$list = notif_fetch($uid, $limit, $offset);
echo json_encode(['items' => $list]);
