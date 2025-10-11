<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php"; // <- para tyt_url()

if (!tyt_can('tyt.cv.review')) {
  http_response_code(403); exit('Acceso denegado');
}

$pdo = getDB();
$personaId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$next      = trim($_POST['next'] ?? $_GET['next'] ?? ''); // opcional: cambiar estado

// Helper para redirigir aunque no esté tyt_url()
function _redir(string $path, array $qs = []): void {
  $base = function_exists('tyt_url') ? tyt_url($path) : $path;
  $url  = $base . (empty($qs) ? '' : ('?' . http_build_query($qs)));
  header("Location: $url");
  exit;
}

if ($personaId <= 0) {
  // Si entran directo sin ID, vuelve al listado
  _redir('cv/listar.php', ['e' => 'ID inválido']);
}

// Traer perfil/estado actual
$pr = $pdo->prepare("SELECT perfil, estado FROM tyt_cv_persona WHERE id=:id");
$pr->execute([':id'=>$personaId]);
$per = $pr->fetch(PDO::FETCH_ASSOC);
if (!$per) { _redir('cv/listar.php', ['e' => 'No encontrado']); }

$tipo = ($per['perfil'] === 'ASESORA') ? 'asesora' : 'aspirante';

// Buscar faltantes obligatorios para ese perfil
$sql = "
  SELECT r.id, r.nombre
  FROM tyt_cv_requisito r
  LEFT JOIN tyt_cv_requisito_check c
    ON c.requisito_id = r.id AND c.persona_id = :p
  WHERE r.activo = 1
    AND r.obligatorio = 1
    AND (r.aplica_a = :t OR r.aplica_a = 'ambos')
    AND (c.id IS NULL OR c.cumple = 0)
  ORDER BY r.nombre
";
$st = $pdo->prepare($sql);
$st->execute([':p'=>$personaId, ':t'=>$tipo]);
$faltantes = $st->fetchAll(PDO::FETCH_ASSOC);

$lista  = $faltantes ? "- " . implode("\n- ", array_map(fn($x)=>$x['nombre'], $faltantes)) : "(sin faltantes)";
$coment = "Solicitud de documentos:\n" . $lista;

// Incluye al final del comentario a quién va dirigido (el creador del registro):
$infoReg = $pdo->prepare("SELECT u.id, u.nombre, u.email
                          FROM tyt_cv_persona p
                          LEFT JOIN usuario u ON u.id = p.creado_por
                          WHERE p.id = :id");
$infoReg->execute([':id'=>$personaId]);
$reg = $infoReg->fetch(PDO::FETCH_ASSOC);

$dirigidoA = $reg && $reg['nombre'] ? ("Dirigido a: ".$reg['nombre']." (ID ".$reg['id'].")") : "Dirigido a: registrador";
$coment = $coment . "\n" . $dirigidoA;

try {
  $pdo->beginTransaction();

  // Observación
  $obs = $pdo->prepare("INSERT INTO tyt_cv_observacion
    (persona_id, autor_id, estado_origen, estado_destino, comentario)
    VALUES (:p, :u, :from, :to, :c)");
  $obs->execute([
    ':p'   => $personaId,
    ':u'   => $_SESSION['usuario_id'] ?? 0,
    ':from'=> $per['estado'],
    ':to'  => $next !== '' ? $next : $per['estado'],
    ':c'   => $coment,
  ]);

  // Cambio de estado (si se pidió)
  if ($next !== '') {
    $up = $pdo->prepare("UPDATE tyt_cv_persona SET estado = :e, fecha_estado = NOW() WHERE id = :id");
    $up->execute([':e'=>$next, ':id'=>$personaId]);
  }

  $pdo->commit();
  _redir('cv/detalle.php', ['id'=>$personaId, 'ok_solic'=>1]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  _redir('cv/detalle.php', ['id'=>$personaId, 'e'=>$e->getMessage()]);
}