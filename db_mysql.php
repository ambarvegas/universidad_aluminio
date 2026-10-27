<?php
/**
 * db_mysql.php
 * Módulo de conexión y acceso a datos — MySQL
 * Universidad del Aluminio
 *
 * Punto de entrada único de la capa de datos: carga los módulos de lib/.
 * Lo incluyen api.php, verificar.php, tools/cron_backup.php y las migraciones.
 */

// Raíz del proyecto (rutas a config.local.php, uploads/, backups/, mailer.php, ...)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

require_once __DIR__ . '/lib/config.php';        // config.local.php, credenciales y db_connect()
require_once __DIR__ . '/lib/migraciones.php';   // esquema: migraciones numeradas de migraciones/
require_once __DIR__ . '/lib/lectura.php';       // progreso, carga admin/participante, catálogo
require_once __DIR__ . '/lib/respaldo.php';      // importación masiva de respaldos
require_once __DIR__ . '/lib/usuarios.php';      // login, contraseñas, perfil, tokens
require_once __DIR__ . '/lib/progreso.php';      // guardar progreso, evaluar, restablecer, reintentos
require_once __DIR__ . '/lib/reportes.php';      // reporte de evaluaciones (filtros y paginación)
require_once __DIR__ . '/lib/catalogo.php';      // cursos, carreras, roles, configuración, solicitudes
require_once __DIR__ . '/lib/seguridad.php';     // log de actividad y rate limiting
require_once __DIR__ . '/lib/certificados.php';  // códigos y verificación de certificados
require_once __DIR__ . '/lib/sistema.php';       // diagnóstico de salud
