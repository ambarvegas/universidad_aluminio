<?php
/**
 * api.php - Universidad del Aluminio
 * API REST con:
 *  - Sesiones PHP server-side (autenticación persistente)
 *  - Filtrado de datos por rol (admin = todo, participante = solo sus datos)
 *  - Endpoints granulares protegidos con require_session / require_admin
 *  - Rate limiting en login
 *  - Log de actividad
 *  - Headers de seguridad HTTP
 */

// ============================================================
// SESIÓN + HEADERS DE SEGURIDAD + CORS
// ============================================================
// Inactividad máxima del servidor antes de descartar la sesión. El cliente envía un
// latido periódico (js/api.js) para que la sesión no expire mientras la pestaña está abierta,
// p. ej. viendo un video largo sin hacer otras peticiones.
const SESSION_IDLE_SECONDS = 8 * 3600;

if (session_status() === PHP_SESSION_NONE) {
    // Directorio propio: en hosting compartido el /tmp común lo limpian otras apps con el
    // gc_maxlifetime por defecto (24 min), lo que cerraría sesiones aunque aquí sea mayor.
    $sessionDir = __DIR__ . '/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
        @file_put_contents($sessionDir . '/.htaccess', "Require all denied\n");
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_SECONDS);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,             // Cookie de sesión (dura hasta cerrar el browser)
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,          // No accesible desde JS
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    if (extension_loaded('zlib') && !in_array('ob_gzhandler', ob_list_handlers())) {
        ob_start('ob_gzhandler');
    }
    // CORS solo para el endpoint que consume la app Alu-Cultura (autenticado con token
    // Bearer / X-Api-Key, sin cookies). El resto de la API solo la usan páginas del mismo origen.
    if (($_GET['action'] ?? '') === 'kpi_humildad') {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key");
    }
    header("Content-Type: application/json; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Sin Cache en respuestas autenticadas
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_mysql.php';
require_once __DIR__ . '/firebase_auth.php';

try {
    $conn = db_connect();
} catch (Throwable $e) {
    // El detalle (host, usuario) queda en el log del servidor, no en la respuesta pública
    error_log('[api.php] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'No se pudo conectar a la base de datos. Intenta de nuevo en unos minutos.']);
    exit;
}

// ============================================================
// HELPERS
// ============================================================

function jsonBody(): array {
    $json = file_get_contents('php://input');
    if (empty($json)) return [];
    $decoded = json_decode($json, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

/**
 * Verifica que haya sesión activa. Si no, responde 401 y termina.
 */
function require_session(): void {
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'No autenticado. Inicia sesión.', 'code' => 'UNAUTHENTICATED']);
        exit;
    }
}

/**
 * Verifica que el usuario autenticado sea admin o supervisor.
 * Si no, responde 403 y termina.
 */
function require_admin(): void {
    require_session();
    $adminRoles = ['admin', 'supervisor'];
    if (!in_array($_SESSION['user_rol'] ?? '', $adminRoles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado. Se requiere rol de administrador.', 'code' => 'FORBIDDEN']);
        exit;
    }
}

/**
 * Devuelve true si el usuario en sesión es admin/supervisor.
 */
function is_admin(): bool {
    return in_array($_SESSION['user_rol'] ?? '', ['admin', 'supervisor'], true);
}

/**
 * Clave para integraciones servidor a servidor (dashboard Alu-Cultura).
 * Se lee de la variable de entorno KPI_API_KEY o, en hosting compartido, de
 * config.local.php (no versionado; ver config.local.example.php).
 * Sin clave configurada, el endpoint queda deshabilitado.
 */
function kpi_api_key(): string {
    return (string)app_secret('KPI_API_KEY', 'kpi_api_key', '');
}

/**
 * Token de "Authorization: Bearer <token>" de la petición, o null si no viene.
 * En CGI/FastCGI llega vía la regla de .htaccess (HTTP_AUTHORIZATION o
 * REDIRECT_HTTP_AUTHORIZATION); en mod_php, vía apache_request_headers().
 */
function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach ((array)apache_request_headers() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) { $h = (string)$v; break; }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim((string)$h), $m) ? $m[1] : null;
}

// ============================================================
// ROUTER — cada acción se atiende en endpoints/<área>.php
// ============================================================

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'db';

const API_RUTAS = [
    // auth
    'login'                      => 'auth',
    'logout'                     => 'auth',
    'me'                         => 'auth',
    'cambiar_clave'              => 'auth',
    'guardar_perfil'             => 'auth',
    'hash_passwords'             => 'auth',
    'solicitar_recuperacion'     => 'auth',
    'verificar_token_acceso'     => 'auth',
    'ejecutar_recuperacion'      => 'auth',
    'generar_link_invitacion'    => 'auth',
    // lectura
    'catalogo'                   => 'lectura',
    'curso'                      => 'lectura',
    'db'                         => 'lectura',
    'usuarios'                   => 'lectura',
    'cursos'                     => 'lectura',
    'carreras'                   => 'lectura',
    'config'                     => 'lectura',
    'progreso_usuarios'          => 'lectura',
    // usuarios
    'usuarios_paginados'         => 'usuarios',
    'guardar_usuario'            => 'usuarios',
    'eliminar_usuario'           => 'usuarios',
    'notificar_usuario'          => 'usuarios',
    // catalogo
    'upload_image'               => 'catalogo',
    'guardar_curso'              => 'catalogo',
    'eliminar_curso'             => 'catalogo',
    'guardar_carrera'            => 'catalogo',
    'eliminar_carrera'           => 'catalogo',
    'guardar_rol'                => 'catalogo',
    'eliminar_rol'               => 'catalogo',
    'guardar_config'             => 'catalogo',
    'guardar_config_batch'       => 'catalogo',
    // progreso
    'evaluar_modulo'             => 'progreso',
    'guardar_progreso'           => 'progreso',
    'restablecer_progreso'       => 'progreso',
    'habilitar_reintento'        => 'progreso',
    // solicitudes
    'solicitar_registro'         => 'solicitudes',
    'solicitar_acceso_curso'     => 'solicitudes',
    'eliminar_solicitud_registro' => 'solicitudes',
    'eliminar_solicitud_curso'   => 'solicitudes',
    // reportes
    'reporte_evaluaciones'       => 'reportes',
    'kpi_humildad'               => 'reportes',
    // certificados
    'verificar_certificado'      => 'certificados',
    'enviar_certificado_email'   => 'certificados',
    // sistema
    'health'                     => 'sistema',
    'test_email'                 => 'sistema',
    'trigger_backup'             => 'sistema',
    'lista_respaldos'            => 'sistema',
    'ping'                       => 'sistema',
];

if (isset(API_RUTAS[$action])) {
    require APP_ROOT . '/endpoints/' . API_RUTAS[$action] . '.php';
} else {
    http_response_code(404);
    echo json_encode(['error' => "Accion desconocida: '$action'"]);
}

$conn->close();

