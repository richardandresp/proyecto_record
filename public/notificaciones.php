<?php
require_once __DIR__ . '/../includes/session_boot.php';

require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

login_required();
$pdo = get_pdo();
$uid = (int)($_SESSION['usuario_id'] ?? 0);

// Filtros
$onlyUnread = isset($_GET['unread']) && $_GET['unread'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;
$off  = ($page - 1) * $per;

$where = "WHERE usuario_id = ?";
$params = [$uid];
if ($onlyUnread) { $where .= " AND leido_en IS NULL"; }

// Conteo total correcto
$stC = $pdo->prepare("SELECT COUNT(*) FROM notificacion $where");
$stC->execute($params);
$total = (int)$stC->fetchColumn();

$sql = "SELECT id, titulo, cuerpo, url, codigo, creado_en, leido_en
        FROM notificacion
        $where
        ORDER BY (leido_en IS NULL) DESC, creado_en DESC
        LIMIT $per OFFSET $off";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pages = max(1, (int)ceil($total / $per));

include __DIR__ . '/../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h1 class="h5 mb-0">Mis notificaciones</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/notificaciones.php<?= $onlyUnread ? '' : '?unread=1' ?>">
        <?= $onlyUnread ? 'Ver todas' : 'Solo no leídas' ?>
      </a>
      <!-- Botón "Marcar todas" usando fetch a /api/notificaciones_mark.php -->
      <button id="btnMarkAll" class="btn btn-outline-primary btn-sm">Marcar todas como leídas</button>
    </div>
  </div>

  <div class="list-group" id="notifList">
    <?php if (!$rows): ?>
      <div class="list-group-item text-muted">No hay notificaciones.</div>
    <?php else: foreach ($rows as $r): ?>
      <div class="list-group-item d-flex justify-content-between align-items-start <?= $r['leido_en'] ? 'opacity-75' : '' ?>" data-id="<?= (int)$r['id'] ?>">
        <div class="me-3">
          <div class="fw-semibold">
            <?php if (!empty($r['codigo'])): ?>
              <span class="badge bg-secondary me-1"><?= htmlspecialchars($r['codigo']) ?></span>
            <?php endif; ?>
            <?= htmlspecialchars($r['titulo']) ?>
          </div>
          <?php if (!empty($r['cuerpo'])): ?>
            <div class="small text-muted"><?= htmlspecialchars($r['cuerpo']) ?></div>
          <?php endif; ?>
          <div class="small text-muted"><?= htmlspecialchars($r['creado_en']) ?></div>
        </div>
        <div class="d-flex flex-column align-items-end">
          <?php if (!empty($r['url'])): ?>
            <a class="btn btn-sm btn-primary mb-1" href="<?= htmlspecialchars($r['url']) ?>">Abrir</a>
          <?php endif; ?>
          <?php if (!$r['leido_en']): ?>
            <button class="btn btn-sm btn-outline-secondary js-mark-one">Marcar leída</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="mt-3">
      <ul class="pagination pagination-sm">
        <?php for ($i=1; $i<=$pages; $i++):
          $qs = $onlyUnread ? '?unread=1&page='.$i : '?page='.$i; ?>
          <li class="page-item <?= $i===$page ? 'active' : '' ?>">
            <a class="page-link" href="<?= BASE_URL ?>/notificaciones.php<?= $qs ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>

<script>
(function(){
  const API = '<?= rtrim(BASE_URL,"/") ?>/api/notificaciones_mark.php';

  // Marcar UNA como leída (delegación)
  document.getElementById('notifList')?.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('.js-mark-one');
    if (!btn) return;
    const row = btn.closest('[data-id]');
    const id  = row?.getAttribute('data-id');
    if (!id) return;

    btn.disabled = true;
    try {
      const r = await fetch(API, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action:'one', id:String(id) })
      });
      const j = await r.json();
      if (j && j.ok) {
        // Refresca la página para sincronizar lista/contador del header
        location.reload();
      } else {
        alert('No se pudo marcar como leída.');
        btn.disabled = false;
      }
    } catch (e) {
      console.error(e);
      alert('Error de red.');
      btn.disabled = false;
    }
  });

  // Marcar TODAS como leídas
  document.getElementById('btnMarkAll')?.addEventListener('click', async () => {
    if (!confirm('¿Marcar todas como leídas?')) return;
    const btn = document.getElementById('btnMarkAll');
    btn.disabled = true;
    try {
      const r = await fetch(API, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action:'all' })
      });
      const j = await r.json();
      if (j && j.ok) {
        location.reload();
      } else {
        alert('No se pudo completar la acción.');
        btn.disabled = false;
      }
    } catch (e) {
      console.error(e);
      alert('Error de red.');
      btn.disabled = false;
    }
  });
})();
</script>

<?php
$__footer = __DIR__ . '/../includes/footer.php';
if (is_file($__footer)) include $__footer;
