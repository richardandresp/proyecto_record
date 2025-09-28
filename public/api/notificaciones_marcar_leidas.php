<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if ($uid<=0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$pdo = get_pdo();
$st=$pdo->prepare("UPDATE notificacion SET leido_en=NOW() WHERE usuario_id=? AND leido_en IS NULL");
$st->execute([$uid]);
echo json_encode(['ok'=>true]);
