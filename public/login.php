<?php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session_boot.php'; // <— ÚNICO arranque de sesión

// --------- SESIÓN COMPARTIDA CON LA SUITE ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',   // clave para que la cookie sirva en /auditoria_app y /suite_operativa
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_name('suite_sess');     // mismo nombre que usa la Suite
  session_start();
}

// Redirección deseada (por defecto: tu dashboard)
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? (BASE_URL . '/dashboard.php');

// Si ya hay sesión, no mostrar login: ve directo al destino
if (!empty($_SESSION['usuario_id'])) {
  header('Location: ' . $redirect);
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = trim($_POST['password'] ?? '');

  if ($email && $pass) {
    try {
      $pdo = getDB();
      $st = $pdo->prepare("SELECT id,nombre,email,rol,clave_hash,activo,must_change_password FROM usuario WHERE email=? LIMIT 1");
      $st->execute([$email]);
      $u = $st->fetch();

      if ($u && (int)$u['activo'] === 1) {
        // Compatibilidad con usuario demo
        $isDemo = ($u['email'] === 'admin@demo.local');
        $passOk = $isDemo ? ($pass === 'admin123') : password_verify($pass, $u['clave_hash']);

        if ($passOk) {
          $_SESSION['usuario_id'] = (int)$u['id'];
          $_SESSION['nombre']     = $u['nombre'];
          $_SESSION['rol']        = $u['rol'];

          if (!$isDemo && (int)$u['must_change_password'] === 1) {
            // Forzar cambio de contraseña (mantén tu flujo actual)
            header('Location: ' . BASE_URL . '/cambiar_password.php?first=1');
            exit;
          }

          // ÉXITO: si venimos desde la Suite, volverá al Home de la Suite.
          // Si no hubo redirect, irá a tu dashboard por defecto.
          $dest = $_POST['redirect'] ?? (BASE_URL . '/dashboard.php');
          header('Location: ' . $dest);
          exit;
        }
      }
      $error = 'Credenciales inválidas.';
    } catch (Throwable $e) {
      $error = 'Error de conexión o consulta.';
    }
  } else {
    $error = 'Completa email y contraseña.';
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Login - <?= APP_NAME ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h4 mb-3 text-center"><?= APP_NAME ?> — Acceso</h1>

          <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
          <?php endif; ?>

          <form method="post">
            <!-- Mantén el redirect que trae la Suite -->
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input name="email" type="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Contraseña</label>
              <input name="password" type="password" class="form-control" required>
            </div>
            <button class="btn btn-primary w-100">Entrar</button>
          </form>

          <hr>
          <p class="text-muted small mb-0">Demo: <code>admin@demo.local</code> / <code>admin123</code></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
