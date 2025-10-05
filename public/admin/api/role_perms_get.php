<?php
// public/admin/api/role_perms_get.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/session_boot.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

login_required();
require_roles(['admin']); // Solo admin gestiona permisos

$pdo = get_pdo();

$rol_id    = (int)($_GET['rol_id'] ?? 0);
$modulo_id = (int)($_GET['modulo_id'] ?? 0);

if ($rol_id<=0 || $modulo_id<=0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'parámetros inválidos']); exit;
}

// Permisos definidos para el módulo
$sql = "SELECT p.id, p.clave, p.nombre
        FROM permiso p
        WHERE p.modulo_id = ?
        ORDER BY p.nombre ASC";
$st = $pdo->prepare($sql);
$st->execute([$modulo_id]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC);

// Cuáles están asignados al rol
$st2 = $pdo->prepare("SELECT permiso_id FROM rol_permiso WHERE rol_id=?");
$st2->execute([$rol_id]);
$have = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN)) ?: [];

$out = array_map(function($p) use ($have){
  return [
    'id'      => (int)$p['id'],
    'clave'   => (string)$p['clave'],
    'nombre'  => (string)$p['nombre'],
    'checked' => in_array((int)$p['id'], $have, true),
  ];
}, $perms);

echo json_encode(['ok'=>true, 'items'=>$out], JSON_UNESCAPED_UNICODE);
