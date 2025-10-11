<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

if (function_exists("user_has_perm") && !user_has_perm("tyt.cv.view")) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.view)"; exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "ID inválido"; exit; }

$pdo = getDB();
$st = $pdo->prepare("SELECT a.id, a.nombre_archivo, a.ruta, a.hash, p.id AS persona
                     FROM tyt_cv_anexo a
                     JOIN tyt_cv_persona p ON p.id = a.persona_id
                     WHERE a.id = :id");
$st->execute([':id' => $id]);
$an = $st->fetch(PDO::FETCH_ASSOC);
if (!$an) { http_response_code(404); echo "No encontrado"; exit; }

// Ruta física: validamos que esté dentro de /modulos/tyt/uploads/
$rel = $an['ruta']; // p.ej. uploads/hv/archivo.pdf
$fs  = tyt_path($rel);
$baseUploads = realpath(tyt_path('uploads'));
$real = realpath($fs);
if (!$real || strpos($real, $baseUploads) !== 0) {
  http_response_code(403); echo "Ruta no permitida"; exit;
}

if (!is_file($real)) { http_response_code(404); echo "Archivo no existe"; exit; }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename($an['nombre_archivo']).'"');
header('Content-Length: ' . filesize($real));
readfile($real);
