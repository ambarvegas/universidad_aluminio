<?php
/**
 * tools/migrar.php — Aplica las migraciones pendientes de migraciones/ (solo línea de comandos).
 *
 * Normalmente no hace falta: api.php las aplica solo en la primera petición tras actualizar.
 *
 *   php tools/migrar.php            aplica las pendientes
 *   php tools/migrar.php --estado   muestra la versión actual y las pendientes, sin aplicar nada
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db_mysql.php';

$conn = db_connect();
echo 'Base: ' . MYSQL_HOST . '/' . MYSQL_DB . "\n";

$estado = db_estado_migraciones($conn);
echo "Versión aplicada: {$estado['version']} (disponible: {$estado['disponible']})\n";

if (!$estado['pendientes']) {
    echo "El esquema está al día.\n";
    exit(0);
}
echo "Pendientes:\n  " . implode("\n  ", $estado['pendientes']) . "\n";
if (in_array('--estado', $argv, true)) {
    exit(0);
}

try {
    foreach (db_migrar($conn) as $nombre) {
        echo "Aplicada: $nombre\n";
    }
    echo "Listo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
