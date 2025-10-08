<?php
// includes/render_403.php
function render_403(string $title='Acceso denegado', string $msg='No estás autorizado para ver esta página.'): void {
  http_response_code(403);
  // Evita que el navegador intente “reutilizar” redirecciones previas
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  // Si quieres, puedes leer BASE_URL; si no, usa rutas relativas simples
  $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

  echo '<!doctype html><html lang="es"><head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>403 - '.htmlspecialchars($title).'</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body class="bg-light">
    <div class="container py-5">
      <div class="row justify-content-center">
        <div class="col-md-8">
          <div class="alert alert-danger shadow-sm">
            <h4 class="alert-heading mb-2">403 — '.htmlspecialchars($title).'</h4>
            <p class="mb-0">'.htmlspecialchars($msg).'</p>
          </div>
          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="'.$base.'/dashboard.php">Ir al Dashboard</a>
            <a class="btn btn-outline-primary" href="/suite_operativa/public/core/home.php">Volver a la Suite</a>
          </div>
        </div>
      </div>
    </div>
  </body></html>';
}
