<?php
// includes/flash.php
if (session_status() === PHP_SESSION_NONE) session_start();

function set_flash(string $type, string $message): void {
  // success | error | warning | info | question
  $_SESSION['flash'] = ['type'=>$type, 'message'=>$message];
}

function consume_flash(): ?array {
  if (!empty($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
  }
  return null;
}
