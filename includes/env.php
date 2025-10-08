<?php
// -----------------------------------------
// Zona horaria global (PHP)
// -----------------------------------------
date_default_timezone_set('America/Bogota');

// -----------------------------------------
// Config DB
// -----------------------------------------
// auditoria_app/includes/env.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'auditoria_db');
define('DB_USER', 'root');      // ← tu usuario real
define('DB_PASS', '');          // ← tu contraseña real (vacía en XAMPP por defecto)
define('DB_CHARSET', 'utf8mb4');

// -----------------------------------------
// Opciones generales
// -----------------------------------------
define('APP_NAME', 'Auditoría');
define('SLA_HORAS_DEFAULT', 48);

// -----------------------------------------
// Rutas de la app
// -----------------------------------------
define('BASE_PATH', dirname(__DIR__));            // .../auditoria_app
define('PUBLIC_PATH', BASE_PATH . '/public');     // .../auditoria_app/public
define('UPLOADS_PATH', PUBLIC_PATH . '/uploads'); // .../auditoria_app/public/uploads

// Si sirves como http://localhost/auditoria_app/public, deja así:
define('BASE_URL', '/auditoria_app/public');

// Si en tu XAMPP sirves directamente /public como raíz, usarías simplemente:
// define('BASE_URL', '');
