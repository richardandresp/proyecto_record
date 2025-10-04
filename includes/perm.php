<?php
// includes/perm.php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/auth.php'; // asume que aquí está login_required() y la sesión

/**
 * MODO BLANDO (por ahora):
 *  - true  => si no hay permisos configurados/dev, DEJA PASAR todo.
 *  - false => exigirá que el permiso exista y esté concedido.
 *
 * Cuando ya verifiques que tus permisos están bien sembrados en BD,
 * cambia a false para activar el enforcement real.
 */
const PERM_DEFAULT_ALLOW = true;

/**
 * ¿El usuario actual tiene el permiso $key?
 * - Respeta overrides:
 *   * rol 'admin' => siempre true
 *   * usuario_permiso => concede/niega explícito
 *   * rol_permiso => por rol
 */
function current_user_has_perm(string $key): bool {
    if (empty($_SESSION['usuario_id'])) return false;
    $uid = (int)$_SESSION['usuario_id'];
    $rol = $_SESSION['rol'] ?? 'lectura';

    // Admin “dueño del balón”
    if ($rol === 'admin') return true;

    // Si estamos en modo blando y no hay aún permisos sembrados, deja pasar.
    // (sigue consultando por si ya sembraste algunos)
    $pdo = get_pdo();

    // 1) usuario_permiso concede explícito
    $sqlUser = "
      SELECT 1
      FROM usuario_permiso up
      JOIN permiso p ON p.id = up.permiso_id
      WHERE up.usuario_id = ? AND p.clave = ? AND up.concedido = 1
      LIMIT 1";
    $st = $pdo->prepare($sqlUser);
    $st->execute([$uid, $key]);
    if ($st->fetchColumn()) return true;

    // 2) rol_permiso por roles del usuario
    $sqlRole = "
      SELECT 1
      FROM usuario_rol ur
      JOIN rol_permiso rp ON rp.rol_id = ur.rol_id
      JOIN permiso p      ON p.id = rp.permiso_id
      WHERE ur.usuario_id = ? AND p.clave = ?
      LIMIT 1";
    $st = $pdo->prepare($sqlRole);
    $st->execute([$uid, $key]);
    if ($st->fetchColumn()) return true;

    // 3) fallback blando
    if (PERM_DEFAULT_ALLOW) return true;

    return false;
}

/**
 * Para usar en los menús/plantillas sin redirigir.
 */
function can_show(string $key): bool {
    return current_user_has_perm($key);
}

/**
 * Para usar al inicio de una página protegida.
 * Si no tiene permiso, manda flash y redirige al dashboard.
 */
function require_perm(string $key): void {
    login_required();
    if (!current_user_has_perm($key)) {
        set_flash('danger', 'No tienes permiso para acceder a esta sección.');
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}
