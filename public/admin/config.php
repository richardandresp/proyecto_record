<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']);   // <-- solo admin

$pdo = getDB();

// Helper: obtener valor de config (clave-valor)
function get_config(PDO $pdo, string $clave, $default = '') {
  $st = $pdo->prepare("SELECT valor FROM config WHERE clave=? LIMIT 1");
  $st->execute([$clave]);
  $row = $st->fetch();
  return $row ? $row['valor'] : $default;
}

// Cargar valores actuales
$SLA_HORAS  = (int) get_config($pdo, 'SLA_HORAS', SLA_HORAS_DEFAULT);
$MAIL_TO    = (string) get_config($pdo, 'MAIL_EXPORT_TO', ''); // opcional, por ahora informativo

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $new_sla = (int)($_POST['sla_horas'] ?? $SLA_HORAS);
  $mail_to = trim($_POST['mail_export_to'] ?? $MAIL_TO);

  if ($new_sla < 1 || $new_sla > 168) { // entre 1 hora y 7 días
    $err = "SLA inválido. Debe estar entre 1 y 168 horas.";
  } else {
    try {
      $pdo->beginTransaction();

      // Upsert SLA_HORAS
      $up1 = $pdo->prepare("
        INSERT INTO config (clave, valor) VALUES ('SLA_HORAS', ?)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor)
      ");
      $up1->execute([$new_sla]);

      // Upsert MAIL_EXPORT_TO (opcional)
      $up2 = $pdo->prepare("
        INSERT INTO config (clave, valor) VALUES ('MAIL_EXPORT_TO', ?)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor)
      ");
      $up2->execute([$mail_to]);

      $pdo->commit();
      $SLA_HORAS = $new_sla;
      $MAIL_TO   = $mail_to;
      $msg = "Configuración actualizada correctamente.";
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = "Error al guardar configuración: " . htmlspecialchars($e->getMessage());
    }
  }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/spinner.html';
?>
<div class="container">
  <h3>Configuración</h3>
  <?php if ($msg): ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

  <form method="post" data-spinner class="row g-3">
    <div class="col-md-4">
      <label class="form-label">SLA para responder (horas) *</label>
      <input type="number" min="1" max="168" name="sla_horas" class="form-control" required value="<?= htmlspecialchars((string)$SLA_HORAS) ?>">
      <div class="form-text">Tiempo máximo para que el líder responda un hallazgo. Predeterminado: 48h.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Correo para exportes (opcional)</label>
      <input type="email" name="mail_export_to" class="form-control" value="<?= htmlspecialchars($MAIL_TO) ?>">
      <div class="form-text">Se usará en el futuro si automatizamos envío de reportes.</div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Guardar Configuración</button>
      <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-secondary">Volver</a>
    </div>
  </form>

  <hr class="my-4">

  <div class="alert alert-info">
    <b>Nota:</b> Esta configuración impacta el cálculo de <i>vencidos</i> (rojo) al cargar el listado o el dashboard.
  </div>
</div>
