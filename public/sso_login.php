<?php
$redirect = $_GET['redirect'] ?? '/auditoria_app/public/dashboard.php';
header('Location: /auditoria_app/public/login.php?redirect=' . urlencode($redirect));
exit;
