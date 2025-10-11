<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

$pdo = getDB();

/* ---------- utilidades ---------- */
/** Devuelve true si existe la columna $col en la tabla $table (en el schema actual) */
function has_col(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = :t
            AND COLUMN_NAME  = :c
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (bool)$st->fetchColumn();
}

function safe_users(PDO $pdo): array {
  // Si existe la col 'activo', filtramos por activos; si no, listamos todos
  $hasActivo = has_col($pdo, 'usuario', 'activo');
  $sql = $hasActivo
    ? "SELECT id, nombre FROM usuario WHERE activo=1 ORDER BY nombre LIMIT 500"
    : "SELECT id, nombre FROM usuario ORDER BY nombre LIMIT 500";
  $st = $pdo->query($sql);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function eff_can(string $p): bool { return tyt_can($p); }
function real_can(string $p): bool { return function_exists('user_has_perm') ? (bool)user_has_perm($p) : true; }

/* ---------- acciones POST ---------- */
$action = $_POST['action'] ?? '';
if ($action === 'set_override') {
  if (!eff_can('tyt.admin')) { http_response_code(403); exit('Acceso denegado (tyt.admin)'); }
  $perms = ['tyt.access','tyt.cv.submit','tyt.cv.view','tyt.cv.review','tyt.cv.attach','tyt.cv.export','tyt.admin'];
  $_SESSION['tyt_perms_override'] = [];
  foreach ($perms as $p) { $_SESSION['tyt_perms_override'][$p] = isset($_POST['perm'][$p]) ? 1 : 0; }
  header('Location: '.tyt_url('admin/whoami.php').'?ok=override'); exit;
}
if ($action === 'clear_override') {
  unset($_SESSION['tyt_perms_override']);
  header('Location: '.tyt_url('admin/whoami.php').'?ok=clear'); exit;
}
if ($action === 'impersonate') {
  if (!eff_can('tyt.admin')) { http_response_code(403); exit('Acceso denegado (tyt.admin)'); }
  $newId = (int)($_POST['new_user_id'] ?? 0);
  if ($newId>0) { $_SESSION['usuario_id'] = $newId; }
  header('Location: '.tyt_url('admin/whoami.php').'?ok=imp'); exit;
}

/* ---------- datos del usuario ---------- */
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$cols = ['id','nombre'];
if (has_col($pdo,'usuario','email'))  $cols[] = 'email';
if (has_col($pdo,'usuario','activo')) $cols[] = 'activo';
$hasZona = has_col($pdo,'usuario','zona_id');
$hasCC   = has_col($pdo,'usuario','cc_id');
if ($hasZona) $cols[] = 'zona_id';
if ($hasCC)   $cols[] = 'cc_id';

$me = null;
if ($uid>0) {
  try {
    $sql = "SELECT ".implode(',', array_map(fn($c)=>"`$c`", $cols))." FROM `usuario` WHERE id=:id";
    $st  = $pdo->prepare($sql);
    $st->execute([':id'=>$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // fallback ultra seguro
    $st = $pdo->prepare("SELECT id, nombre FROM `usuario` WHERE id=:id");
    $st->execute([':id'=>$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
  }
}

$users    = safe_users($pdo);
$allPerms = ['tyt.access','tyt.cv.submit','tyt.cv.view','tyt.cv.review','tyt.cv.attach','tyt.cv.export','tyt.admin'];

tyt_header('T&T · ¿Quién soy?');
tyt_nav();
?>
<div class="container py-3">
  <h1 class="h4">¿Quién soy?</h1>

  <div class="card mb-3">
    <div class="card-body">
      <div><strong>Usuario ID:</strong> <?= $uid ?: '(no definido)' ?></div>
      <?php if ($me): ?>
        <div><strong>Nombre:</strong> <?= htmlspecialchars($me['nombre'] ?? '') ?></div>
        <?php if (isset($me['email'])): ?><div><strong>Email:</strong> <?= htmlspecialchars($me['email']) ?></div><?php endif; ?>
        <?php if (isset($me['activo'])): ?><div><strong>Activo:</strong> <?= ((int)$me['activo']===1)?'Sí':'No' ?></div><?php endif; ?>
        <?php if ($hasZona || $hasCC): ?>
          <div><strong>Zona / CC:</strong>
            <?= $hasZona ? (int)($me['zona_id']??0) : '-' ?> /
            <?= $hasCC   ? (int)($me['cc_id']??0)   : '-' ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="text-muted">No se encontraron datos del usuario actual.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">Permisos (real vs. efectivo)</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>Permiso</th><th>Real</th><th>Efectivo</th><th>Override</th></tr></thead>
              <tbody>
              <?php foreach ($allPerms as $p):
                $real = real_can($p);
                $eff  = eff_can($p);
                $ov   = $_SESSION['tyt_perms_override'][$p] ?? null; ?>
                <tr>
                  <td><code><?= htmlspecialchars($p) ?></code></td>
                  <td><?= $real ? '✅' : '—' ?></td>
                  <td><?= $eff  ? '✅' : '—' ?></td>
                  <td><?= ($ov===null) ? '<span class="text-muted">(sin)</span>' : ($ov? 'forzado ✅' : 'forzado —') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if (eff_can('tyt.admin')): ?>
          <hr>
          <form method="post" class="d-flex flex-column gap-2">
            <input type="hidden" name="action" value="set_override">
            <?php foreach ($allPerms as $p):
              $checked = !empty($_SESSION['tyt_perms_override'][$p]) ? 'checked' : ''; ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="ov_<?= md5($p) ?>" name="perm[<?= htmlspecialchars($p) ?>]" <?= $checked ?>>
                <label class="form-check-label" for="ov_<?= md5($p) ?>"><?= htmlspecialchars($p) ?></label>
              </div>
            <?php endforeach; ?>
            <div class="d-flex gap-2">
              <button class="btn btn-primary btn-sm">Aplicar overrides</button>
              <button class="btn btn-outline-secondary btn-sm" name="action" value="clear_override">Quitar overrides</button>
            </div>
          </form>
          <?php else: ?>
            <div class="alert alert-info mt-3">Solo Admin puede aplicar overrides temporales.</div>
          <?php endif; ?>

        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">Impersonar usuario (solo Admin)</div>
        <div class="card-body">
          <?php if (eff_can('tyt.admin')): ?>
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="impersonate">
            <div class="col-9">
              <select class="form-select" name="new_user_id" required>
                <?php foreach ($users as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id']===$uid)?'selected':'' ?>>
                    #<?= (int)$u['id'] ?> — <?= htmlspecialchars($u['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-3 d-grid">
              <button class="btn btn-outline-primary">Impersonar</button>
            </div>
          </form>
          <p class="text-muted mt-2">Cambia <code>$_SESSION['usuario_id']</code> solo en tu sesión.</p>
          <?php else: ?>
            <div class="alert alert-info">Solo Admin puede impersonar.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php tyt_footer(); ?>
