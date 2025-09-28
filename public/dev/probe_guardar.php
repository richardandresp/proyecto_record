<?php declare(strict_types=1);
// public/dev/probe_guardar.php

// Log a /public/dev/probe.log (crea la carpeta si no existe)
$logFile = __DIR__ . '/probe.log';
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);

function log_write(string $msg): void {
  global $logFile;
  @file_put_contents($logFile, '['.date('c').'] '.$msg.PHP_EOL, FILE_APPEND);
}

log_write('---- NUEVA PETICIÓN ----');
log_write('Método: ' . ($_SERVER['REQUEST_METHOD'] ?? ''));
log_write('URI: '    . ($_SERVER['REQUEST_URI'] ?? ''));
log_write('Referer: '. ($_SERVER['HTTP_REFERER'] ?? ''));
log_write('UserAgent: '. ($_SERVER['HTTP_USER_AGENT'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  log_write('No es POST. Fin.');
  http_response_code(405);
  echo "<h3>Método no permitido</h3>";
  exit;
}

// Volcado básico de POST (ojo con datos sensibles — aquí no debería haber contraseñas)
log_write('POST hallazgo_id='.(string)($_POST['hallazgo_id'] ?? ''));
log_write('POST respuesta len=' . strlen((string)($_POST['respuesta'] ?? '')));

// Manejo de adjunto si viene
$adjInfo = 'sin archivo';
if (!empty($_FILES['adjunto']['name'] ?? '')) {
  $f = $_FILES['adjunto'];
  $adjInfo = sprintf(
    'name=%s; type=%s; size=%d; error=%d',
    (string)($f['name'] ?? ''), (string)($f['type'] ?? ''),
    (int)($f['size'] ?? 0), (int)($f['error'] ?? -1)
  );

  // Solo para comprobar escritura en disco (directorio temporal local a dev/)
  $destDir = __DIR__ . '/uploads';
  if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  $dest = $destDir . '/probe_' . date('Ymd_His') . '.' . ($ext ?: 'bin');

  if ((int)$f['error'] === UPLOAD_ERR_OK && @move_uploaded_file($f['tmp_name'], $dest)) {
    $adjInfo .= ' | MOVIDO_A=' . $dest;
  } else {
    $adjInfo .= ' | NO_MOVIDO (error o permisos)';
  }
}
log_write('ADJUNTO: ' . $adjInfo);

// Respuesta simple en HTML
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Probe Guardar</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>body{font-family:system-ui,Arial,sans-serif;padding:16px}</style>
</head>
<body>
  <h2>Probe Guardar — Resultado</h2>
  <p><b>Método:</b> <?= htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? '') ?></p>
  <p><b>hallazgo_id:</b> <?= htmlspecialchars((string)($_POST['hallazgo_id'] ?? '')) ?></p>
  <p><b>respuesta (len):</b> <?= strlen((string)($_POST['respuesta'] ?? '')) ?></p>
  <p><b>adjunto:</b> <?= htmlspecialchars($adjInfo) ?></p>

  <hr>
  <p>Se escribió log en: <code><?= htmlspecialchars($logFile) ?></code></p>
  <p><a href="./probe_form.php">← Volver al form de prueba</a></p>
</body>
</html>
