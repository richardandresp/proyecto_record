<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if ($uid<=0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$limit = max(1,min(20,(int)($_GET['limit'] ?? 10)));
$pdo = get_pdo();
$st=$pdo->prepare("SELECT id,titulo,cuerpo,url,codigo,creado_en,leido_en FROM notificacion WHERE usuario_id=? ORDER BY COALESCE(leido_en,creado_en) DESC LIMIT ?");
$st->bindValue(1,$uid,PDO::PARAM_INT);
$st->bindValue(2,$limit,PDO::PARAM_INT);
$st->execute();
echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
