<?php declare(strict_types=1);
// public/dev/probe_form.php

// AJUSTA BASE_URL si fuera necesario… pero lo uso sólo para el link de regreso.
require_once __DIR__ . '/../../includes/env.php';
$baseUrl = defined('BASE_URL') ? BASE_URL : '/auditoria_app/public';

// Puedes pasar ?hid=17 para simular id de hallazgo
$hid = (int)($_GET['hid'] ?? 0);
if ($hid <= 0) $hid = 1; // cualquier id para la prueba
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Probe Form</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
  <div class="container">
    <h3>Prueba de Envío (sin JS/Spinner)</h3>
    <p class="text-muted">Este formulario envía directamente a <code>/public/dev/probe_guardar.php</code> sin confirmaciones ni overlays.</p>

    <form method="post" action="<?= $baseUrl ?>/dev/probe_guardar.php" enctype="multipart/form-data" id="probe-form" class="card p-3 shadow-sm">
      <input type="hidden" name="hallazgo_id" value="<?= $hid ?>">
      <div class="mb-3">
        <label class="form-label">Respuesta *</label>
        <textarea class="form-control" name="respuesta" rows="4" required>prueba directa</textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Adjunto (opcional)</label>
        <input type="file" name="adjunto" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
      </div>
      <button class="btn btn-primary" type="submit">Enviar directo</button>
      <a class="btn btn-outline-secondary" href="<?= $baseUrl ?>/hallazgos/listado.php">Volver</a>
    </form>

    <hr>
    <p class="small text-muted">Si esto envía bien, el problema está en la capa JS (confirmación/overlay). Si no, es la URL o el backend.</p>
  </div>
</body>
</html>
