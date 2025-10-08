<?php
// auditoria_app/includes/flash.php

if (!function_exists('set_flash')) {
  function set_flash(string $type, string $msg): void {
    if (!isset($_SESSION)) session_start();
    $_SESSION['__flash'][] = ['type'=>$type, 'msg'=>$msg, 'ts'=>time()];
  }
}

if (!function_exists('consume_flash')) {
  function consume_flash(): array {
    if (!isset($_SESSION)) session_start();
    $f = $_SESSION['__flash'] ?? [];
    unset($_SESSION['__flash']);
    return $f;
  }
}
