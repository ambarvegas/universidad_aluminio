<?php
/**
 * Se antepone a cada petición del servidor de pruebas (php -d auto_prepend_file=...):
 * fija la base de prueba local antes de que api.php cargue db_mysql.php.
 */
require_once __DIR__ . '/test_db.php';
test_db_definir_constantes();
