<?php
// auditoria_app/includes/403.php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>403 · No autorizado</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;}
    body{margin:0;display:grid;place-items:center;min-height:100vh;background:#fafafa}
    .card{max-width:520px;background:#fff;border:1px solid #eee;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
    h1{margin:0 0 8px 0;font-size:28px}
    p{margin:0 0 16px 0;color:#444;line-height:1.5}
    .hint{font-size:14px;color:#666}
    .btn{display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;color:#111}
  </style>
</head>
<body>
  <div class="card">
    <h1>403 · No autorizado</h1>
    <p>No tienes permiso para ver esta página o el módulo no está habilitado para tu usuario.</p>
    <p class="hint">Si crees que es un error, solicita al administrador activar el módulo o agregar el permiso correspondiente.</p>
    <a class="btn" href="/suite_operativa/public/core/home.php">Volver al inicio</a>
  </div>
</body>
</html>
