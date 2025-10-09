<?php
declare(strict_types=1);

/**
 * Hallazgos / Guardar nuevo
 * Requiere:
 *  - includes/page_boot.php  (session/env/db/auth/acl/flash)
 *  - includes/notify.php     (helpers de notificación)
 */

$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access','auditoria.hallazgo.create'];

require_once __DIR__ . '/../../includes/page_boot.php';
require_once __DIR__ . '/../../includes/notify.php';

$pdo = getDB();
$uid = (int)($_SESSION['usuario_id'] ?? 0);

/* ---------------- Helpers ---------------- */
$cleanText = function(?string $s): string {
  $s = (string)$s;
  $s = preg_replace("/[ \t]+/u", " ", $s);
  return trim($s);
};
$toFloat = function($raw): float {
  $val = (string)$raw;
  $val = preg_replace('/[^\d\-,.]/', '', $val);
  $val = str_replace(',', '.', $val);
  return (float)$val;
};
// Fallback de UPLOADS_PATH si no estuviera definido
if (!defined('UPLOADS_PATH')) {
  $fallback = realpath(__DIR__ . '/../../public/uploads');
  if ($fallback === false) $fallback = __DIR__ . '/../../public/uploads';
  define('UPLOADS_PATH', $fallback);
}

/* ---------------- Inputs ---------------- */
$fecha       = $_POST['fecha']      ?? date('Y-m-d');
$zona_id     = (int)($_POST['zona_id']   ?? 0);
$centro_id   = (int)($_POST['centro_id'] ?? 0);
$pdv_codigo  = $cleanText($_POST['pdv_codigo'] ?? '');
$nombre_pdv  = $cleanText($_POST['nombre_pdv'] ?? '');
$cedula      = $cleanText($_POST['cedula'] ?? '');
$raspas      = (int)($_POST['raspas_faltantes'] ?? 0);
$faltante    = $toFloat($_POST['faltante_dinero'] ?? '0');
$sobrante    = $toFloat($_POST['sobrante_dinero'] ?? '0');
$observ      = $cleanText($_POST['observaciones'] ?? '');

/* ---------------- Validaciones ---------------- */
if (!$fecha || !$zona_id || !$centro_id || !$nombre_pdv || !$cedula || !$observ) {
  set_flash('warning','Completa los campos obligatorios.');
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}
$hoy = (new DateTime('today'))->format('Y-m-d');
if ($fecha > $hoy) {
  set_flash('warning','La fecha del hallazgo no puede ser futura.');
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}

// SLA: fecha_limite = fecha + 2 días (fin de día)
$fecha_limite = (new DateTime($fecha.' 00:00:00'))
  ->modify('+2 days')
  ->format('Y-m-d 23:59:59');

/* ---------------- Resolver responsables (snapshot) ---------------- */
$lider_id = null; $sup_id = null; $aux_id = null;

// LÍDER (por centro)
$st = $pdo->prepare("
  SELECT usuario_id FROM lider_centro
  WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$centro_id, $fecha]);
if ($r = $st->fetchColumn()) $lider_id = (int)$r;

// SUPERVISOR (por zona)
$st = $pdo->prepare("
  SELECT usuario_id FROM supervisor_zona
  WHERE zona_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$zona_id, $fecha]);
if ($r = $st->fetchColumn()) $sup_id = (int)$r;

// AUXILIAR (por centro)
$st = $pdo->prepare("
  SELECT usuario_id FROM auxiliar_centro
  WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
  ORDER BY desde DESC LIMIT 1
");
$st->execute([$centro_id, $fecha]);
if ($r = $st->fetchColumn()) $aux_id = (int)$r;

/* ---------------- Insertar hallazgo ---------------- */
try {
  $pdo->beginTransaction();

  $ins = $pdo->prepare("
    INSERT INTO hallazgo
      (fecha, zona_id, centro_id, nombre_pdv, pdv_codigo, cedula,
       raspas_faltantes, faltante_dinero, sobrante_dinero,
       observaciones, evidencia_url, estado, fecha_limite,
       lider_id, supervisor_id, auxiliar_id,
       creado_por, creado_en, actualizado_en)
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

  // Evidencia (opcional)
  if (!empty($_FILES['evidencia']['name'])) {
    $f = $_FILES['evidencia'];
    if ((int)$f['error'] === UPLOAD_ERR_OK) {
      $allowed = ['image/jpeg','image/png','application/pdf','image/webp'];
      $ext     = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
      $okExt   = in_array($ext, ['jpg','jpeg','png','pdf','webp'], true);

      $mime = '';
      if (is_file($f['tmp_name'])) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
      }

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
  @file_put_contents(__DIR__.'/var_guardar.log','['.date('c').'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
  set_flash('danger','ERROR guardando hallazgo:<br>'.$e->getMessage());
  header('Location: '.BASE_URL.'/hallazgos/nuevo.php'); exit;
}

/* ---------------- Notificación (post-commit, no bloqueante) ---------------- */
try {
  $stH = $pdo->prepare("
    SELECT h.*,
           z.nombre AS zona_nombre,
           c.nombre AS centro_nombre
    FROM hallazgo h
    JOIN zona         z ON z.id = h.zona_id
    JOIN centro_costo c ON c.id = h.centro_id
    WHERE h.id = ?
    LIMIT 1
  ");
  $stH->execute([$hid]);
  $hNotif = $stH->fetch(PDO::FETCH_ASSOC);

  if ($hNotif) {
    notify_nuevo_hallazgo($hNotif, true); // true => también admin/auditor
    @file_put_contents(__DIR__.'/var_guardar.log','['.date('c')."] NOTIFY NUEVO OK hid={$hid}\n", FILE_APPEND);
  } else {
    @file_put_contents(__DIR__.'/var_guardar.log','['.date('c')."] NOTIFY NUEVO SIN CARGA hid={$hid}\n", FILE_APPEND);
  }
} catch (Throwable $e) {
  @file_put_contents(__DIR__.'/var_guardar.log','['.date('c')."] NOTIFY NUEVO ERROR hid={$hid} :: ".$e->getMessage()."\n", FILE_APPEND);
}

/* ---------------- Redirect ---------------- */
set_flash('success','Hallazgo creado correctamente.');
header('Location: '.BASE_URL.'/hallazgos/detalle.php?id='.$hid);
exit;
