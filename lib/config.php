<?php
/**
 * lib/config.php — Configuración y conexión
 * Lectura de config.local.php / variables de entorno y conexión MySQL.
 * Se carga desde db_mysql.php.
 */

/**
 * Lee un valor de config.local.php (no versionado; ver config.local.example.php).
 * El archivo se carga una sola vez por petición.
 */
function app_config(string $clave, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $file = APP_ROOT . '/config.local.php';
        $loaded = is_file($file) ? include $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }
    return array_key_exists($clave, $cfg) ? $cfg[$clave] : $default;
}

/**
 * Valor de configuración: variable de entorno primero, luego config.local.php.
 */
function app_secret(string $envVar, string $clave, $default = '') {
    $env = getenv($envVar);
    return ($env !== false && $env !== '') ? $env : app_config($clave, $default);
}

// Sin valores por defecto: las credenciales nunca deben vivir en el repositorio.
define('MYSQL_HOST', (string)app_secret('DB_HOST', 'db_host'));
define('MYSQL_USER', (string)app_secret('DB_USER', 'db_user'));
define('MYSQL_PASS', (string)app_secret('DB_PASS', 'db_pass'));
define('MYSQL_DB',   (string)app_secret('DB_NAME', 'db_name'));
define('MYSQL_PORT', (int)app_secret('DB_PORT', 'db_port', 3306));
define('MYSQL_CHARSET', 'utf8mb4');

/**
 * Retorna una conexión mysqli activa.
 * Lanza una excepción si no puede conectarse.
 */
function db_connect(): mysqli {
    if (MYSQL_HOST === '' || MYSQL_USER === '' || MYSQL_DB === '') {
        throw new RuntimeException('Configuración de base de datos incompleta: crea config.local.php a partir de config.local.example.php (db_host, db_user, db_pass, db_name).');
    }
    $conn = mysqli_init();
    if (!$conn) {
        throw new RuntimeException('Error al inicializar mysqli');
    }
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (!@$conn->real_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB, MYSQL_PORT)) {
        throw new RuntimeException('Error de conexión MySQL: ' . mysqli_connect_error());
    }
    $conn->set_charset(MYSQL_CHARSET);
    return $conn;
}
