<?php declare(strict_types=1);

// public/hallazgos/nuevo_guardar.php

session_start();

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/notify.php';

login_required();
$rol = $_SESSION['rol'] ?? 'lectura';
if (!in_array($rol, ['admin','auditor'], true)) {
  http_response_code(403);
  exit('No autorizado');
}

$pdo = get_pdo();
$uid = (int)($_SESSION['usuario_id'] ?? 0);

// Función para limpiar texto sin convertir a mayúsculas
$cleanText = function(?string $s): string {
  $s = (string)$s;
  $s = preg_replace("/[ \t]+/u", " ", $s);
  return trim($s);
};

// INPUTS
$fecha       = $_POST['fecha']      ?? date('Y-m-d');
$zona_id     = (int)($_POST['zona_id']   ?? 0);
$centro_id   = (int)($_POST['centro_id'] ?? 0);
$pdv_codigo  = $cleanText($_POST['pdv_codigo'] ?? '');
$nombre_pdv  = $cleanText($_POST['nombre_pdv'] ?? '');
$cedula      = $cleanText($_POST['cedula'] ?? '');
$raspas      = (int)($_POST['raspas_faltantes'] ?? 0);
$faltante    = (float)str_replace(',', '.', preg_replace('/[^\d\-,.]/', '', (string)($_POST['faltante_dinero'] ?? '0')));
$sobrante    = (float)str_replace(',', '.', preg_replace('/[^\d\-,.]/', '', (string)($_POST['sobrante_dinero'] ?? '0')));
$observ      = $cleanText($_POST['observaciones'] ?? '');

if (!$fecha || !$zona_id || !$centro_id || !$nombre_pdv || !$cedula || !$observ) {
  set_flash('warning','Completa los campos obligatorios.');
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}

// Validar fecha no futura
$hoy = (new DateTime('today'))->format('Y-m-d');
if ($fecha > $hoy) {
  set_flash('warning','La fecha del hallazgo no puede ser futura.');
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}

// SLA: fecha_limite = fecha + 2 días (fin de día)
$fecha_limite = (new DateTime($fecha.' 00:00:00'))->modify('+2 days')->format('Y-m-d 23:59:59');

// Resolver responsables por vigencia a la fecha (snapshot)
$lider_id = null; $sup_id = null; $aux_id = null;

// LÍDER
$st = $pdo->prepare("
  SELECT usuario_id FROM lider_centro
  WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$centro_id, $fecha]);
$lider_id = ($r = $st->fetchColumn()) ? (int)$r : null;

// SUPERVISOR
$st = $pdo->prepare("
  SELECT usuario_id FROM supervisor_zona
  WHERE zona_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$zona_id, $fecha]);
$sup_id = ($r = $st->fetchColumn()) ? (int)$r : null;

// AUXILIAR
$st = $pdo->prepare("
  SELECT usuario_id FROM auxiliar_centro
  WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$centro_id, $fecha]);
$aux_id = ($r = $st->fetchColumn()) ? (int)$r : null;

// Insert hallazgo
try {
  $pdo->beginTransaction();

  $ins = $pdo->prepare("
    INSERT INTO hallazgo
      (fecha, zona_id, centro_id, nombre_pdv, pdv_codigo, cedula,
       raspas_faltantes, faltante_dinero, sobrante_dinero,
       observaciones, evidencia_url, estado, fecha_limite,
       lider_id, supervisor_id, auxiliar_id, creado_por, creado_en, actualizado_en)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'pendiente', ?, ?, ?, ?, ?, NOW(), NOW())
  ");

  $ins->execute([
    $fecha, $zona_id, $centro_id, $nombre_pdv, $pdv_codigo, $cedula,
    $raspas, $faltante, $sobrante,
    $observ, $fecha_limite,
    $lider_id, $sup_id, $aux_id,
    $uid
  ]);

  $hid = (int)$pdo->lastInsertId();

  // Subir evidencia si viene
  $ev_public = null;
  if (!empty($_FILES['evidencia']['name'])) {
    $f = $_FILES['evidencia'];
    if ($f['error'] === UPLOAD_ERR_OK) {
      $allowed = ['image/jpeg','image/png','application/pdf','image/webp'];
      $finfo  = finfo_open(FILEINFO_MIME_TYPE);
      $mime   = finfo_file($finfo, $f['tmp_name']); finfo_close($finfo);

      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $okExt = in_array($ext, ['jpg','jpeg','png','pdf','webp'], true);

      if (in_array($mime, $allowed, true) || $okExt) {
        $destDir = rtrim(UPLOADS_PATH, '/\\') . "/hallazgos/{$hid}";
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
        $safe = 'evidencia_'.$hid.'.'.$ext;
        $dest = $destDir.'/'.$safe;
        if (@move_uploaded_file($f['tmp_name'], $dest)) {
          $ev_public = rtrim(BASE_URL,'/')."/uploads/hallazgos/{$hid}/".$safe;
          $up = $pdo->prepare("UPDATE hallazgo SET evidencia_url=? WHERE id=?");
          $up->execute([$ev_public, $hid]);
        }
      }
    }
  }

  $pdo->commit();

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  @file_put_contents(__DIR__.'/var_guardar.log','['.date('c').'] '.$e->getMessage().PHP_EOL,FILE_APPEND);
  set_flash('danger','ERROR guardando hallazgo:<br>'.$e->getMessage());
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}

// ... después de $pdo->commit();

// Cargar datos mínimos para la notificación (incluye PDV)
try {
  $stH = $pdo->prepare("
    SELECT h.*,
           z.nombre AS zona_nombre,
           c.nombre AS centro_nombre
    FROM hallazgo h
    JOIN zona z ON z.id = h.zona_id
    JOIN centro_costo c ON c.id = h.centro_id
    WHERE h.id = ?
    LIMIT 1
  ");
  $stH->execute([$hid]);
  $hNotif = $stH->fetch(PDO::FETCH_ASSOC);

  if ($hNotif) {
    // Notificar a responsables (líder/supervisor/auxiliar). 
    // Si también quieres a admin/auditor, pasa true como segundo parámetro.
    notify_nuevo_hallazgo($hNotif, false);
  }
} catch (\Throwable $e) {
  // Log opcional, no bloquea el flujo
  @file_put_contents(__DIR__.'/var_guardar.log', '['.date('c').'] NOTIFY_NUEVO: '.$e->getMessage().PHP_EOL, FILE_APPEND);
}


set_flash('success','Hallazgo creado correctamente.');
header('Location: '.BASE_URL.'/hallazgos/detalle.php?id='.$hid);
exit;