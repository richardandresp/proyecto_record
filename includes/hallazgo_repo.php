<?php
require_once __DIR__ . '/db.php';

/**
 * Carga un hallazgo con nombres “amigables” (zona, centro, etc.).
 * Devuelve array asociativo o null si no existe.
 */
function load_hallazgo_with_names(int $id): ?array {
    $pdo = getDB();
    $st = $pdo->prepare("
        SELECT
            h.*,
            z.nombre AS zona_nombre,
            c.nombre AS centro_nombre
        FROM hallazgo h
        LEFT JOIN zona z          ON z.id = h.zona_id
        LEFT JOIN centro_costo c  ON c.id = h.centro_id
        WHERE h.id = ?
        LIMIT 1
    ");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
