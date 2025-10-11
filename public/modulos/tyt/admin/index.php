<?php require_once __DIR__.'/../../includes/env_mod.php'; require_once __DIR__.'/../../includes/ui.php';
tyt_header('T&T · Admin'); tyt_nav(); ?>
<div class="container py-4">
  <h1 class="h4">T&T · Admin</h1>
  <ul>
    <li><a href="<?= tyt_url('admin/semaforo.php') ?>">Semáforo (config)</a></li>
    <li><a href="<?= tyt_url('admin/requisitos.php') ?>">Requisitos (checklist)</a></li>
  </ul>
</div>
<?php tyt_footer(); ?>