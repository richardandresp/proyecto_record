<?php declare(strict_types=1);
// public/hallazgos/responder_guardar.php

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

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$rol = $_SESSION['rol'] ?? 'lectura';
$rolesPermitidos = ['admin','auditor','supervisor','lider','auxiliar'];
if (!in_array($rol, $rolesPermitidos, true)) {
  http_response_code(403);
  exit('Sin permiso.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Método no permitido');
}

$pdo = get_pdo();

// ---------- helpers ----------
$logTrace = __DIR__ . '/var_responder_trace.log';
$logErr   = __DIR__ . '/var_responder.log';
$log = function(string $m) use($logTrace) {
  @file_put_contents($logTrace,'['.date('c').'] '.$m.PHP_EOL, FILE_APPEND);
};

$hasColumn = function(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
  } catch (\Throwable $e) { return false; }
};

$tableExists = function(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (\Throwable $e) { return false; }
};

// Función para limpiar texto sin convertir a mayúsculas
$cleanText = function(?string $s): string {
  $s = (string)$s;
  $s = preg_replace("/[ \t]+/u", " ", $s);
  return trim($s);
};

// UPLOADS_PATH fallback si no está definido
if (!defined('UPLOADS_PATH')) {
  $fallback = realpath(__DIR__ . '/../../public/uploads');
  if ($fallback === false) {
    $fallback = __DIR__ . '/../../public/uploads';
  }
  define('UPLOADS_PATH', $fallback);
}

// ---------- inputs ----------
$hid        = (int)($_POST['hallazgo_id'] ?? 0);
$respuesta  = $cleanText($_POST['respuesta'] ?? '');
$confirmed  = (int)($_POST['__confirmed'] ?? 0);

$log('---- NUEVA PETICIÓN ----');
$log('POST hid='.$hid.' len(respuesta)='.strlen($respuesta).' confirmed='.$confirmed);

// Validaciones básicas
if ($hid <= 0) {
  set_flash('danger','ID inválido');
  header('Location: '.BASE_URL.'/hallazgos/listado.php'); exit;
}
if (mb_strlen($respuesta) < 10) {
  set_flash('warning','La respuesta debe tener al menos 10 caracteres.');
  header('Location: '.BASE_URL.'/hallazgos/responder.php?id='.$hid); exit;
}

// Carga hallazgo
$st = $pdo->prepare("
  SELECT h.*, z.nombre AS zona_nombre, c.nombre AS centro_nombre
  FROM hallazgo h
  JOIN zona z ON z.id=h.zona_id
  JOIN centro_costo c ON c.id=h.centro_id
  WHERE h.id=?
");
$st->execute([$hid]);
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) {
  set_flash('danger','Hallazgo no encontrado.');
  header('Location: '.BASE_URL.'/hallazgos/listado.php'); exit;
}

// Vigencia líder
if ($rol === 'lider') {
  $chk = $pdo->prepare("
    SELECT 1
    FROM lider_centro
    WHERE usuario_id=? AND centro_id=?
      AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
    LIMIT 1
  ");
  $chk->execute([$uid, (int)$h['centro_id'], $h['fecha']]);
  if (!$chk->fetch()) {
    set_flash('danger','Fuera de vigencia/centro.');
    header('Location: '.BASE_URL.'/hallazgos/listado.php'); exit;
  }
}

// ---------- archivos (múltiples) ----------
$files = [];
if (!empty($_FILES['adjuntos']) && is_array($_FILES['adjuntos']['name'])) {
  // Normaliza estructura plana
  $names = $_FILES['adjuntos']['name'];
  $types = $_FILES['adjuntos']['type'];
  $tmpns = $_FILES['adjuntos']['tmp_name'];
  $errs  = $_FILES['adjuntos']['error'];
  $sizes = $_FILES['adjuntos']['size'];
  for ($i=0, $n=count($names); $i<$n; $i++) {
    if (!isset($names[$i]) || $names[$i]==='') continue;
    $files[] = [
      'name' => $names[$i],
      'type' => $types[$i] ?? '',
      'tmp_name' => $tmpns[$i] ?? '',
      'error' => (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE),
      'size' => (int)($sizes[$i] ?? 0),
    ];
  }
}

// ---------- transacción ----------
try {
  $pdo->beginTransaction();

  $tieneCreadoEn = $hasColumn($pdo, 'hallazgo_respuesta', 'creado_en');

  $rol_resp = ($rol === 'admin') ? 'admin' : $rol;

  // Inserta respuesta
  if ($tieneCreadoEn) {
    $ins = $pdo->prepare("
      INSERT INTO hallazgo_respuesta (hallazgo_id, usuario_id, rol_al_responder, respuesta, adjunto_url, creado_en)
      VALUES (?, ?, ?, ?, NULL, NOW())
    ");
    $ins->execute([$hid, $uid, $rol_resp, $respuesta]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO hallazgo_respuesta (hallazgo_id, usuario_id, rol_al_responder, respuesta, adjunto_url)
      VALUES (?, ?, ?, ?, NULL)
    ");
    $ins->execute([$hid, $uid, $rol_resp, $respuesta]);
  }
  $rid = (int)$pdo->lastInsertId();
  $log('Respuesta insertada id='.$rid);

  // Manejo de adjuntos múltiples
  $savedUrls = [];
  if ($files) {
    $allowed = ['image/jpeg','image/png','application/pdf'];
    $extOK   = ['jpg','jpeg','png','pdf'];
    $destDir = rtrim(UPLOADS_PATH, '/\\') . "/hallazgos/{$hid}/respuestas/{$rid}";
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }

    foreach ($files as $k => $f) {
      if ((int)$f['error'] !== UPLOAD_ERR_OK) continue;

      $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
      $mime = '';
      if (is_file($f['tmp_name'])) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
      }
      $typeOk = in_array($mime, $allowed, true) || in_array($ext, $extOK, true);
      $sizeOk = ((int)$f['size'] <= 5*1024*1024);
      if (!$typeOk || !$sizeOk) continue;

      $safe = sprintf('evid_%02d.%s', $k+1, $ext ?: 'dat');
      $dest = $destDir . '/' . $safe;
      if (!@move_uploaded_file($f['tmp_name'], $dest)) continue;

      $public = rtrim(BASE_URL,'/') . "/uploads/hallazgos/{$hid}/respuestas/{$rid}/{$safe}";
      $savedUrls[] = $public;
    }

    // Persistencia: si existe tabla secundaria, insertar TODAS allí
    $hasAdjTable = $tableExists($pdo, 'hallazgo_respuesta_adjunto');
    if ($savedUrls) {
      // Siempre setear la primera como portada en hallazgo_respuesta
      $up = $pdo->prepare("UPDATE hallazgo_respuesta SET adjunto_url=? WHERE id=?");
      $up->execute([$savedUrls[0], $rid]);

      if ($hasAdjTable) {
        $insA = $pdo->prepare("
          INSERT INTO hallazgo_respuesta_adjunto (respuesta_id, url, nombre, mime, size, creado_en)
          VALUES (?, ?, ?, ?, ?, NOW())
        ");
        foreach ($savedUrls as $idx => $url) {
          // Recupera metadata simple del array original (nombre/mime/size)
          $f = $files[$idx] ?? null;
          $insA->execute([
            $rid,
            $url,
            (string)($f['name'] ?? basename($url)),
            (string)($f['type'] ?? ''),
            (int)($f['size'] ?? 0),
          ]);
        }
      }
    }
  }

  // Actualizar estado del hallazgo
  $nuevo_estado = $h['estado'];
  if ($rol === 'admin') {
    $nuevo_estado = 'respondido_admin';
  } else {
    if ($h['estado'] !== 'respondido_admin') {
      $nuevo_estado = 'respondido_lider';
    }
  }
  $uph = $pdo->prepare("UPDATE hallazgo SET estado=?, actualizado_en=NOW() WHERE id=?");
  $uph->execute([$nuevo_estado, $hid]);

  $pdo->commit();
  $log('Estado actualizado a '.$nuevo_estado.'; adjuntos='.count($savedUrls));

} catch (\Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  @file_put_contents($logErr,'['.date('c').'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
  $log('ERROR: '.$e->getMessage());
  set_flash('danger', 'Error al guardar respuesta: '.$e->getMessage());
  header('Location: '.BASE_URL.'/hallazgos/responder.php?id='.$hid);
  exit;
}

// ---------- notificaciones (no bloqueante) ----------
try {
  $h['estado'] = $nuevo_estado;
  $titulo = 'Respuesta en hallazgo #'.$hid.(($h['pdv_codigo']??'')!=='' ? ' — Cód.PDV '.$h['pdv_codigo'] : '');
  $cuerpo = 'Estado: '.$nuevo_estado;
  // Notifica a responsables (líder/supervisor/auxiliar) y también a admin/auditor si tu notify lo soporta:
  notify_hallazgo_to_responsables($h, 'H_RESPUESTA', $titulo, $cuerpo);
} catch (\Throwable $e) {
  @file_put_contents($logErr,'['.date('c').'] NOTIFY: '.$e->getMessage().PHP_EOL, FILE_APPEND);
}

// OK → detalle
set_flash('success','Respuesta registrada correctamente.');
header('Location: '.BASE_URL.'/hallazgos/detalle.php?id='.$hid);
exit;