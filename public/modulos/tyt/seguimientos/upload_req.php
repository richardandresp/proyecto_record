<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

$pdo = getDB();
$pid = (int)($_POST['persona_id'] ?? 0);
$rid = (int)($_POST['requisito_id'] ?? 0);

if ($pid<=0 || $rid<=0) { http_response_code(400); exit('Parámetros inválidos'); }

// Permiso: GH/Admin o responsable asignado
$userId = (int)($_SESSION['usuario_id'] ?? 0);
$esGH   = tyt_can('tyt.cv.attach');
$st = $pdo->prepare("SELECT responsable_id FROM tyt_cv_requisito_check WHERE persona_id=:p AND requisito_id=:r");
$st->execute([':p'=>$pid, ':r'=>$rid]);
$respUserId = (int)$st->fetchColumn();
if (!$esGH && $respUserId !== $userId) { http_response_code(403); exit('No autorizado'); }

if (empty($_FILES['anexo']) || $_FILES['anexo']['error'] !== UPLOAD_ERR_OK) {
  header("Location: ". tyt_url('seguimientos/listar.php?e=Archivo inválido')); exit;
}

$f = $_FILES['anexo'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext,['pdf','jpg','jpeg','png'])) {
  header("Location: ". tyt_url('seguimientos/listar.php?e=Formato no permitido')); exit;
}

$dir = __DIR__ . "/../uploads/req/{$pid}/{$rid}";
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$fname = date('Ymd_His') . "_" . preg_replace('/[^a-zA-Z0-9._-]/','_', $f['name']);
$dest = $dir . "/" . $fname;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
  header("Location: ". tyt_url('seguimientos/listar.php?e=Error guardando archivo')); exit;
}
$rel = "uploads/req/{$pid}/{$rid}/" . $fname;

// Guarda registro
$pdo->prepare("INSERT INTO tyt_cv_req_anexo (persona_id, requisito_id, nombre_archivo, ruta, peso_bytes, subido_por)
               VALUES (:p,:r,:n,:ruta,:sz,:u)")
    ->execute([
      ':p'=>$pid, ':r'=>$rid, ':n'=>$f['name'], ':ruta'=>$rel, ':sz'=>$f['size'], ':u'=>$userId
    ]);

// Observación
$pdo->prepare("INSERT INTO tyt_cv_observacion (persona_id, autor_id, estado_origen, estado_destino, comentario)
               VALUES (:p,:u,(SELECT estado FROM tyt_cv_persona WHERE id=:p),'',(CONCAT('Anexo subido (req ',:r,'): ',:n)))")
    ->execute([':p'=>$pid, ':u'=>$userId, ':r'=>$rid, ':n'=>$f['name']]);

header("Location: ". tyt_url('seguimientos/listar.php?ok=Anexo subido')); exit;
