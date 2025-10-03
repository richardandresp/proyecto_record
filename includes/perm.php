<?php
// includes/perm.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Devuelve todas las "claves" de permiso efectivas del usuario (por rol + overrides individuales).
 */
function user_perm_keys(int $usuario_id): array {
  static $cache = [];
  if (isset($cache[$usuario_id])) return $cache[$usuario_id];

  $pdo = get_pdo();

  // Por rol
  $sqlRol = "
    SELECT DISTINCT p.clave
    FROM usuario u
    JOIN usuario_rol ur   ON ur.usuario_id = u.id
    JOIN rol r            ON r.id = ur.rol_id
    JOIN rol_permiso rp   ON rp.rol_id = r.id
    JOIN permiso p        ON p.id = rp.permiso_id
    WHERE u.id = ?
  ";
  $st = $pdo->prepare($sqlRol);
  $st->execute([$usuario_id]);
  $keys = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'clave');

  // Overrides por usuario (usuario_permiso)
  $sqlUsr = "
    SELECT p.clave, up.concedido
    FROM usuario_permiso up
    JOIN permiso p ON p.id = up.permiso_id
    WHERE up.usuario_id = ?
  ";
  $st = $pdo->prepare($sqlUsr);
  $st->execute([$usuario_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $set = array_fill_keys($keys, true);
  foreach ($rows as $r) {
    $k = $r['clave'];
    if ($r['concedido']) $set[$k] = true;
    else unset($set[$k]);
  }

  return $cache[$usuario_id] = array_keys($set);
}

/**
 * ¿El usuario tiene el permiso "clave"?
 */
function user_has_perm(int $usuario_id, string $perm_clave): bool {
  if ($usuario_id <= 0) return false;
  $keys = user_perm_keys($usuario_id);
  return in_array($perm_clave, $keys, true);
}

/**
 * Requiere un permiso; si no lo tiene, redirige con flash a 403-ish.
 */
function require_perm(string $perm_clave): void {
  if (empty($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . '/login.php'); exit;
  }
  $uid = (int)$_SESSION['usuario_id'];
  if (!user_has_perm($uid, $perm_clave)) {
    require_once __DIR__ . '/flash.php';
    set_flash('danger', 'No tienes permiso para realizar esta acción.');
    header('Location: ' . BASE_URL . '/dashboard.php'); exit;
  }
}
