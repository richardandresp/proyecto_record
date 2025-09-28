<?php
// includes/db.php

// Asegura que env.php esté cargado para tener constantes
if (!defined('DB_HOST')) {
  $envPath = __DIR__ . '/env.php';
  if (is_file($envPath)) {
    require_once $envPath;
  } else {
    throw new RuntimeException('No se encontraron constantes de conexión ni env.php');
  }
}

// Alias retrocompatible
if (!function_exists('getDB')) {
  function getDB(): PDO {
    return get_pdo();
  }
}

/**
 * Retorna un PDO singleton a MariaDB/MySQL.
 */
function get_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  // DSN y opciones
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // usa preparadas nativas
  ];

  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

  // --- Sesión SQL coherente con Colombia (UTC-5) ---
  // Usa offset para evitar depender de tablas de zonas horarias en MySQL
  try { $pdo->exec("SET time_zone = '-05:00'"); } catch (Throwable $e) {}

  // (Opcional) Modo SQL y STRICT si quieres detectar datos inválidos antes:
  // try { $pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"); } catch (Throwable $e) {}

  return $pdo;
}
