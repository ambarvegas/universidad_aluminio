<?php
/**
 * Arranque de PHPUnit: apunta la aplicación a la base de prueba local y carga la capa de datos.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/test_db.php';

test_db_definir_constantes();
require __DIR__ . '/../db_mysql.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Crear la base de prueba si no existe (requiere MySQL de XAMPP encendido)
try {
    $admin = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, '', MYSQL_PORT);
    $admin->query('CREATE DATABASE IF NOT EXISTS `' . MYSQL_DB . '` CHARACTER SET utf8mb4');
    $admin->close();
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, "\nNo se pudo conectar a MySQL local (" . MYSQL_HOST . "): " . $e->getMessage() .
        "\n¿Está encendido MySQL en el panel de XAMPP? Ver tests/README.md\n\n");
    exit(1);
}

require __DIR__ . '/support/BaseDatosTestCase.php';
require __DIR__ . '/support/ServidorApi.php';
