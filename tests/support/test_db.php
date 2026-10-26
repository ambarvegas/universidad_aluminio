<?php
/**
 * Conexión de la suite de pruebas a una base MySQL LOCAL y desechable.
 *
 * Se configura con variables de entorno (valores por defecto para XAMPP):
 *   TEST_DB_HOST=127.0.0.1  TEST_DB_USER=root  TEST_DB_PASS=  TEST_DB_NAME=universidad_test  TEST_DB_PORT=3306
 *
 * Define las constantes MYSQL_* ANTES de cargar db_mysql.php, de modo que la aplicación
 * nunca use las credenciales de config.local.php (producción) durante las pruebas.
 */

function test_db_config(): array {
    $env = fn(string $k, string $def) => ($v = getenv($k)) !== false ? $v : $def;
    $cfg = [
        'host' => $env('TEST_DB_HOST', '127.0.0.1'),
        'user' => $env('TEST_DB_USER', 'root'),
        'pass' => $env('TEST_DB_PASS', ''),
        'name' => $env('TEST_DB_NAME', 'universidad_test'),
        'port' => (int)$env('TEST_DB_PORT', '3306'),
    ];

    // Salvaguardas: jamás apuntar a un servidor remoto ni a una base que no sea de prueba
    if (!in_array($cfg['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORTADO: TEST_DB_HOST debe ser local (127.0.0.1/localhost). Valor: {$cfg['host']}\n");
        exit(1);
    }
    if (stripos($cfg['name'], 'test') === false) {
        fwrite(STDERR, "ABORTADO: TEST_DB_NAME debe contener 'test'. Valor: {$cfg['name']}\n");
        exit(1);
    }
    return $cfg;
}

function test_db_definir_constantes(): void {
    $cfg = test_db_config();
    define('MYSQL_HOST', $cfg['host']);
    define('MYSQL_USER', $cfg['user']);
    define('MYSQL_PASS', $cfg['pass']);
    define('MYSQL_DB',   $cfg['name']);
    define('MYSQL_PORT', $cfg['port']);
}
