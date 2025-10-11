<?php
require_once __DIR__ . "/../includes/env_mod.php";
header('Content-Type: application/json; charset=utf-8');
if (function_exists('user_has_perm') && !user_has_perm('tyt.cv.view')) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$zona = (int)($_GET['zona_id'] ?? 0);
$pdo  = getDB();

if ($zona>0) {
  $st = $pdo->prepare("SELECT id, nombre FROM centro_costo WHERE activo=1 AND zona_id=:z ORDER BY nombre");
  $st->execute([':z'=>$zona]);
} else {
  $st = $pdo->query("SELECT id, nombre FROM centro_costo WHERE activo=1 ORDER BY nombre");
}
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
