<?php
// public/api/notificaciones_mark.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/notify.php';

header('Content-Type: application/json; charset=utf-8');

// No redirigir en APIs
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$uid    = (int)($_SESSION['usuario_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

try {
    if ($action === 'one' && $id > 0) {
        // Marca una
        notif_mark_read($id, $uid);

    } elseif ($action === 'all') {
        // Marca todas del usuario
        notif_mark_all_read($uid);

    } else {
        echo json_encode(['ok' => false, 'error' => 'invalid_action']);
        exit;
    }

    // Regresamos el nuevo conteo de no leídas para actualizar el badge
    $pdo = getDB();
    $st  = $pdo->prepare("SELECT COUNT(*) FROM notificacion WHERE usuario_id=? AND leido_en IS NULL");
    $st->execute([$uid]);
    $unread = (int)$st->fetchColumn();

    echo json_encode(['ok' => true, 'unread_count' => $unread]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
