<?php
declare(strict_types=1);

/**
 * cron/sla_job.php
 *
 * Crea notificaciones SLA:
 *  - H_SLA_SOON   : Hallazgo vence en ≤ 24h
 *  - H_SLA_OVERDUE: Hallazgo vencido (fecha_limite < ahora)
 *
 * Puedes ejecutarlo por CLI:
 *   php cron/sla_job.php
 *
 * O por web con un token simple:
 *   http://localhost/auditoria_app/cron/sla_job.php?token=TU_TOKEN
 */

$ROOT = dirname(__DIR__); // sube a la raíz del proyecto
require_once $ROOT . '/includes/page_boot.php'; // trae env, db, sesión no requerida, helpers
require_once $ROOT . '/includes/notify.php';

// --- SEGURIDAD BÁSICA ---
$ALLOW_WEB = true; // si quieres permitir dispararlo por web
$CRON_TOKEN = getenv('SLA_CRON_TOKEN') ?: 'CAMBIA_ESTE_TOKEN';

if (php_sapi_name() !== 'cli') {
  if (!$ALLOW_WEB) { http_response_code(403); exit('Forbidden'); }
  $tok = $_GET['token'] ?? '';
  if (!hash_equals($CRON_TOKEN, $tok)) {
    http_response_code(403); exit('Token inválido');
  }
}

// --- CONFIGURACIÓN SLA ---
$WINDOW_SOON_HOURS = 24;        // “por vencer” = dentro de 24h
$ESTADOS_ABIERTOS  = ['pendiente','respondido_lider','respondido_admin']; // no cerrados
$NOTIFY_ADMINS_TOO = true;      // ¿también admins/auditores?

$pdo = getDB();
$now = new DateTimeImmutable('now');
$tzNow = $now->format('Y-m-d H:i:s');

$soonFrom = $now; // ahora
$soonTo   = $now->modify('+' . $WINDOW_SOON_HOURS . ' hours');
$soonToStr = $soonTo->format('Y-m-d H:i:s');

// Helpers
$inPlaceholders = function(array $arr): string {
  return implode(',', array_fill(0, count($arr), '?'));
};

$logFile = $ROOT . '/cron/sla_job.log';
$log = function(string $m) use ($logFile) {
  @file_put_contents($logFile, '['.date('c').'] '.$m.PHP_EOL, FILE_APPEND);
};

// --- 1) Por vencer (SOON) ---
try {
  $sqlSoon = "
    SELECT h.id, h.fecha, h.fecha_limite,
           h.zona_id, h.centro_id, h.lider_id, h.supervisor_id, h.auxiliar_id,
           h.pdv_codigo, h.nombre_pdv, h.estado,
           z.nombre AS zona_nombre, c.nombre AS centro_nombre
    FROM hallazgo h
    JOIN zona z          ON z.id = h.zona_id
    JOIN centro_costo c  ON c.id = h.centro_id
    WHERE h.estado IN (".$inPlaceholders($ESTADOS_ABIERTOS).")
      AND h.fecha_limite >= ?
      AND h.fecha_limite <= ?
  ";
  $paramsSoon = array_merge($ESTADOS_ABIERTOS, [$soonFrom->format('Y-m-d H:i:s'), $soonToStr]);
  $st = $pdo->prepare($sqlSoon);
  $st->execute($paramsSoon);
  $soonRows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($soonRows as $h) {
    $hid = (int)$h['id'];
    [$title, $body] = buildSlaTitleBody($h, 'soon');
    $url = rtrim(BASE_URL, '/') . '/hallazgos/detalle.php?id=' . $hid;

    // Destinatarios: responsables (y opcional admins)
    $uids = hallazgo_responsables($hid);
    if ($NOTIFY_ADMINS_TOO) {
      try {
        $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rs as $u) { $uids[] = (int)$u; }
      } catch (\Throwable $e) {}
    }
    $uids = array_values(array_unique(array_filter(array_map('intval',$uids))));
    if (!$uids) { continue; }

    // Deduplicación por codigo + ref
    notify_many($uids, $title, $body, $url, 'hallazgo', $hid, 'H_SLA_SOON');
  }
  $log("SLA_SOON: procesados ".count($soonRows)." hallazgos.");
} catch (\Throwable $e) {
  $log("ERROR SLA_SOON: ".$e->getMessage());
}

// --- 2) Vencidos (OVERDUE) ---
try {
  $sqlOver = "
    SELECT h.id, h.fecha, h.fecha_limite,
           h.zona_id, h.centro_id, h.lider_id, h.supervisor_id, h.auxiliar_id,
           h.pdv_codigo, h.nombre_pdv, h.estado,
           z.nombre AS zona_nombre, c.nombre AS centro_nombre
    FROM hallazgo h
    JOIN zona z          ON z.id = h.zona_id
    JOIN centro_costo c  ON c.id = h.centro_id
    WHERE h.estado IN (".$inPlaceholders($ESTADOS_ABIERTOS).")
      AND h.fecha_limite < ?
  ";
  $paramsOver = array_merge($ESTADOS_ABIERTOS, [$tzNow]);
  $st = $pdo->prepare($sqlOver);
  $st->execute($paramsOver);
  $overRows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($overRows as $h) {
    $hid = (int)$h['id'];
    [$title, $body] = buildSlaTitleBody($h, 'overdue');
    $url = rtrim(BASE_URL, '/') . '/hallazgos/detalle.php?id=' . $hid;

    $uids = hallazgo_responsables($hid);
    if ($NOTIFY_ADMINS_TOO) {
      try {
        $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rs as $u) { $uids[] = (int)$u; }
      } catch (\Throwable $e) {}
    }
    $uids = array_values(array_unique(array_filter(array_map('intval',$uids))));
    if (!$uids) { continue; }

    notify_many($uids, $title, $body, $url, 'hallazgo', $hid, 'H_SLA_OVERDUE');
  }
  $log("SLA_OVERDUE: procesados ".count($overRows)." hallazgos.");
} catch (\Throwable $e) {
  $log("ERROR SLA_OVERDUE: ".$e->getMessage());
}

// --- FIN ---
if (php_sapi_name() !== 'cli') {
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true, 'ran_at'=>$tzNow], JSON_UNESCAPED_UNICODE);
}

/** ===== Helpers de formato ===== */
function buildSlaTitleBody(array $h, string $kind): array {
  $id   = (int)($h['id'] ?? 0);
  $cod  = trim((string)($h['pdv_codigo'] ?? ''));
  $pdv  = trim((string)($h['nombre_pdv'] ?? ''));
  $zona = trim((string)($h['zona_nombre'] ?? ''));
  $cc   = trim((string)($h['centro_nombre'] ?? ''));
  $lim  = (string)($h['fecha_limite'] ?? '');

  $when = ($kind === 'overdue') ? 'VENCIDO' : 'Por vencer';
  $title = "SLA {$when} — Hallazgo #{$id}";
  if ($cod !== '') $title .= ' — ' . $cod;

  $body = [];
  if ($cod !== '' || $pdv !== '') {
    $body[] = 'PDV: ' . trim($cod . ($pdv!=='' ? ' - ' . $pdv : ''));
  }
  if ($zona !== '') $body[] = 'Zona: ' . $zona;
  if ($cc !== '')   $body[] = 'CC: ' . $cc;
  if ($lim !== '')  $body[] = 'Vence: ' . $lim;

  return [$title, implode(' | ', $body)];
}
