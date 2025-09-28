<?php
session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
$pdo = get_pdo();
$rows = $pdo->query("SELECT id, fecha, zona_id, centro_id, pdv_codigo, estado, creado_en 
                     FROM hallazgo ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/plain; charset=UTF-8');
print_r($rows);
