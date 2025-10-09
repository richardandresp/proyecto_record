<?php
// header.php - Rutas corregidas
$rootDir = dirname(__DIR__); // Sube un nivel desde includes/

require_once $rootDir . '/includes/session_boot.php';
require_once $rootDir . '/includes/env.php';
require_once $rootDir . '/includes/acl.php';
require_once $rootDir . '/includes/flash.php';
require_once $rootDir . '/includes/notify.php'; // <-- AÑADIDO

$rol            = $_SESSION['rol']    ?? 'lectura';
$nombreUsuario  = $_SESSION['nombre'] ?? '';
$uid            = (int)($_SESSION['usuario_id'] ?? 0);

// Conteo inicial para renderizar el badge desde el servidor
$__initialUnread = 0;
if ($uid > 0) {
  try { $__initialUnread = notif_unread_count($uid); } catch (Throwable $e) { $__initialUnread = 0; }
}

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';

if (!function_exists('get_pdo')) {
  function get_pdo(): PDO { return getDB(); }
}

/**
 * Crea una notificación (con deduplicación por usuario+ref_type+ref_id+codigo).
 * Requiere un índice único en notificacion(usuario_id,ref_type,ref_id,codigo).
 */
function notify_create(
  int $usuario_id,
  string $titulo,
  ?string $cuerpo = null,
  ?string $url = null,
  ?string $ref_type = null,
  ?int $ref_id = null,
  ?string $codigo = null
): bool {
  if ($usuario_id <= 0) return false;
  $pdo = get_pdo();

  $sql = "INSERT IGNORE INTO notificacion
          (usuario_id, titulo, cuerpo, url, ref_type, ref_id, codigo, creado_en)
          VALUES (?,?,?,?,?,?,?, NOW())";
  $st = $pdo->prepare($sql);
  return $st->execute([
    $usuario_id,
    $titulo,
    $cuerpo,
    $url,
    $ref_type,
    $ref_id,
    $codigo
  ]);
}

/** Notifica a una lista de usuarios. */
function notify_many(
  array $userIds,
  string $titulo,
  ?string $cuerpo,
  ?string $url,
  ?string $refType,
  ?int $refId,
  ?string $codigo
): void {
  $userIds = array_values(array_unique(array_map('intval', $userIds)));
  foreach ($userIds as $uid) {
    if ($uid > 0) {
      notify_create($uid, $titulo, $cuerpo, $url, $refType, $refId, $codigo);
    }
  }
}

/**
 * Calcula destinatarios para un hallazgo (snapshot si existe; si no, vigencia por fecha).
 */
function hallazgo_responsables(int $hallazgo_id): array {
  $pdo = get_pdo();

  $st = $pdo->prepare("
    SELECT h.id, h.fecha, h.zona_id, h.centro_id,
           h.lider_id, h.supervisor_id, h.auxiliar_id
    FROM hallazgo h
    WHERE h.id = ?
    LIMIT 1
  ");
  $st->execute([$hallazgo_id]);
  $h = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  if (!$h) return [];

  $uids = [];

  // Snapshot primero
  foreach (['lider_id','supervisor_id','auxiliar_id'] as $k) {
    if (!empty($h[$k])) $uids[] = (int)$h[$k];
  }

  // Si falta alguien, buscar por vigencia
  $f = $h['fecha'] ?? date('Y-m-d');
  if (empty($h['lider_id']) && !empty($h['centro_id'])) {
    $q = $pdo->prepare("SELECT usuario_id FROM lider_centro
                        WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
                        LIMIT 1");
    $q->execute([(int)$h['centro_id'], $f]);
    if ($u = $q->fetchColumn()) $uids[] = (int)$u;
  }
  if (empty($h['auxiliar_id']) && !empty($h['centro_id'])) {
    $q = $pdo->prepare("SELECT usuario_id FROM auxiliar_centro
                        WHERE centro_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
                        LIMIT 1");
    $q->execute([(int)$h['centro_id'], $f]);
    if ($u = $q->fetchColumn()) $uids[] = (int)$u;
  }
  if (empty($h['supervisor_id']) && !empty($h['zona_id'])) {
    $q = $pdo->prepare("SELECT usuario_id FROM supervisor_zona
                        WHERE zona_id=? AND ? BETWEEN desde AND COALESCE(hasta,'9999-12-31')
                        LIMIT 1");
    $q->execute([(int)$h['zona_id'], $f]);
    if ($u = $q->fetchColumn()) $uids[] = (int)$u;
  }

  return array_values(array_unique(array_filter(array_map('intval', $uids))));
}

/** Título y cuerpo “Nuevo hallazgo” con código PDV. */
function build_hallazgo_nuevo_title_body(array $h): array {
  $id   = (int)($h['id'] ?? 0);
  $cod  = trim((string)($h['pdv_codigo'] ?? ''));
  $pdv  = trim((string)($h['nombre_pdv'] ?? ''));
  $zona = trim((string)($h['zona_nombre'] ?? ''));
  $cc   = trim((string)($h['centro_nombre'] ?? ''));

  $title = 'Nuevo Hallazgo #' . $id;
  if ($cod !== '') $title .= ' — ' . $cod;

  $body = [];
  if ($cod !== '' || $pdv !== '') {
    $body[] = 'PDV: ' . trim($cod . ($pdv!=='' ? ' - ' . $pdv : ''));
  }
  if ($zona !== '') $body[] = 'Zona: ' . $zona;
  if ($cc !== '')   $body[] = 'CC: ' . $cc;

  return [$title, implode(' | ', $body)];
}

/** Notifica “nuevo hallazgo” a responsables (y opcionalmente admins/auditores). */
function notify_nuevo_hallazgo(array $h, bool $notifyAdminsAlso = false): void {
  $pdo = get_pdo();

  // URL absoluta o relativa, como prefieras; dejo relativa porque ya la usas así en tus datos
  $url = rtrim(BASE_URL,'/') . '/hallazgos/detalle.php?id=' . (int)$h['id'];

  // Construir título/cuerpo (incluye código PDV si está)
  [$titulo, $cuerpo] = build_hallazgo_nuevo_title_body($h);

  // 1) Destinatarios: snapshot primero
  $uids = hallazgo_responsables((int)$h['id']);

  // 2) Si pidieron también admins/auditores, agrégalos
  if ($notifyAdminsAlso) {
    try {
      $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($rs as $u) $uids[] = (int)$u;
    } catch (\Throwable $e) { /* noop */ }
  }

  // 3) Paracaídas: si quedó vacío, avisa al creador y a admin/auditor
  $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
  if (!$uids) {
    $fallback = [];

    // creador del hallazgo
    if (!empty($h['creado_por'])) $fallback[] = (int)$h['creado_por'];

    // admin/auditor activos
    try {
      $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($rs as $u) $fallback[] = (int)$u;
    } catch (\Throwable $e) { /* noop */ }

    $uids = array_values(array_unique(array_filter($fallback)));
  }

  // 4) Notificar (deduplicación por UNIQUE KEY si la tienes)
  notify_many($uids, $titulo, $cuerpo, $url, 'hallazgo', (int)$h['id'], 'H_NUEVO');
}

/** Notifica a responsables con título/cuerpo personalizados. */
function notify_hallazgo_to_responsables(
  array $h,
  string $codigo,
  string $titulo,
  string $cuerpo,
  ?int $excludeUserId = null,
  bool $alsoAdmins = false
): void {
  $uids = hallazgo_responsables((int)($h['id'] ?? 0));

  if ($alsoAdmins) {
    try {
      $pdo = get_pdo();
      $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($rs as $u) $uids[] = (int)$u;
    } catch (\Throwable $e) {}
  }

  if ($excludeUserId) {
    $uids = array_values(array_filter($uids, fn($u) => (int)$u !== (int)$excludeUserId));
  }

  $uids = array_values(array_unique(array_filter($uids)));
  if (!$uids) return;

  $url = rtrim(BASE_URL,'/') . '/hallazgos/detalle.php?id=' . (int)($h['id'] ?? 0);
  notify_many($uids, $titulo, $cuerpo, $url, 'hallazgo', (int)($h['id'] ?? 0), $codigo);
}

/** Notifica “hallazgo respondido”. */
function notify_hallazgo_respondido(array $h): void {
  $url    = rtrim(BASE_URL,'/') . '/hallazgos/detalle.php?id=' . (int)$h['id'];
  $titulo = 'Respuesta en hallazgo #' . (int)$h['id'];
  $cuerpo = 'Estado: ' . (string)($h['estado'] ?? '');
  $uids   = [];

  if (!empty($h['creado_por'])) $uids[] = (int)$h['creado_por'];

  $pdo = get_pdo();
  $rs = $pdo->query("SELECT id FROM usuario WHERE activo=1 AND rol IN ('admin','auditor')")->fetchAll(PDO::FETCH_COLUMN);
  foreach ($rs as $u) $uids[] = (int)$u;

  $resps = hallazgo_responsables((int)$h['id']);
  $uids  = array_merge($uids, $resps);

  notify_many(array_unique($uids), $titulo, $cuerpo, $url, 'hallazgo', (int)$h['id'], 'H_RESPUESTA');
}

/* ===== Helpers lectura/lectura múltiple/contador ===== */

function notif_mark_read(int $notifId, int $userId): bool {
  if ($notifId <= 0 || $userId <= 0) return false;
  $pdo = get_pdo();
  $st = $pdo->prepare("UPDATE notificacion SET leido_en = NOW()
                       WHERE id = ? AND usuario_id = ? AND leido_en IS NULL");
  return $st->execute([$notifId, $userId]);
}

function notif_mark_all_read(int $userId): bool {
  if ($userId <= 0) return false;
  $pdo = get_pdo();
  $st = $pdo->prepare("UPDATE notificacion SET leido_en = NOW()
                       WHERE usuario_id = ? AND leido_en IS NULL");
  return $st->execute([$userId]);
}

function notif_unread_count(int $userId): int {
  if ($userId <= 0) return 0;
  $pdo = get_pdo();
  $st = $pdo->prepare("SELECT COUNT(*) FROM notificacion WHERE usuario_id=? AND leido_en IS NULL");
  $st->execute([$userId]);
  return (int)$st->fetchColumn();
}
