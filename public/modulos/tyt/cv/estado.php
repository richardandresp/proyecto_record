<?php
require_once __DIR__ . "/../includes/env_mod.php";
if (function_exists("user_has_perm") && !user_has_perm("tyt.cv.review")) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.review)"; exit;
}

$personaId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$to        = trim($_POST['to'] ?? $_GET['to'] ?? '');
$coment    = trim($_POST['coment'] ?? '');

$valid = ['recibido','revision','contacto_inicial','en_capacitacion','en_activacion','entregado_comercial','rechazado'];
if ($personaId <= 0 || !in_array($to, $valid, true)) {
  http_response_code(400); echo "Parámetros inválidos"; exit;
}

$pdo = getDB();
try {
  $pdo->beginTransaction();

  // Traer estado actual
  $cur = $pdo->prepare("SELECT estado FROM tyt_cv_persona WHERE id = :id FOR UPDATE");
  $cur->execute([':id' => $personaId]);
  $row = $cur->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Persona no encontrada');

  $from = $row['estado'];

  // Actualizar
  $up = $pdo->prepare("UPDATE tyt_cv_persona SET estado = :to, fecha_estado = NOW() WHERE id = :id");
  $up->execute([':to' => $to, ':id' => $personaId]);

  // Bitácora
  $obs = $pdo->prepare("INSERT INTO tyt_cv_observacion
    (persona_id, autor_id, estado_origen, estado_destino, comentario)
    VALUES (:p, :u, :from, :to, :c)");
  $obs->execute([
    ':p' => $personaId,
    ':u' => $_SESSION['usuario_id'] ?? 0,
    ':from' => $from,
    ':to'   => $to,
    ':c'    => $coment !== '' ? $coment : null,
  ]);

  $pdo->commit();
  header("Location: ./listar.php?ok_estado=1"); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header("Location: ./listar.php?e=" . urlencode($e->getMessage())); exit;
}
