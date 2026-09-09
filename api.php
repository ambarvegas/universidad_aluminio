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
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,             // Cookie de sesión (dura hasta cerrar el browser)
        'path'     => '/',
        'secure'   => false,         // Cambiar a true en producción HTTPS
        'httponly' => true,          // No accesible desde JS
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    if (extension_loaded('zlib') && !in_array('ob_gzhandler', ob_list_handlers())) {
        ob_start('ob_gzhandler');
    }
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
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

try {
    $conn = db_connect();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'No se pudo conectar a la base de datos: ' . $e->getMessage()]);
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


// ============================================================
// ROUTER
// ============================================================

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'db';

switch ($action) {

    // ------ SUBIDA DE IMAGENES (multipart/form-data) -------------
    case 'upload_image':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                http_response_code(500); echo json_encode(['error' => 'No se pudo crear el directorio de uploads']); break;
            }
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? 'sin archivo';
            http_response_code(400); echo json_encode(['error' => "Error de subida: $err"]); break;
        }

        $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['file']['tmp_name']);

        if (!in_array($mime, $allowedMime)) {
            http_response_code(400); echo json_encode(['error' => 'Tipo de archivo no permitido: ' . $mime]); break;
        }

        $type    = trim($_POST['type'] ?? 'portada');
        $entidad = trim($_POST['id'] ?? 'gen');
        $entidad = preg_replace('/[^a-zA-Z0-9_\-]/', '', $entidad);
        $ext     = 'jpg';
        $nombre  = $type . '_' . $entidad . '_' . time() . '.' . $ext;
        $destino = $uploadDir . $nombre;

        $maxWidth = ($type === 'logo') ? 400 : 1200;
        $quality  = 82;

        switch ($mime) {
            case 'image/jpeg': case 'image/jpg': $src = imagecreatefromjpeg($_FILES['file']['tmp_name']); break;
            case 'image/png':  $src = imagecreatefrompng($_FILES['file']['tmp_name']); break;
            case 'image/gif':  $src = imagecreatefromgif($_FILES['file']['tmp_name']); break;
            case 'image/webp': $src = imagecreatefromwebp($_FILES['file']['tmp_name']); break;
            default: $src = null;
        }

        if (!$src) { http_response_code(500); echo json_encode(['error' => 'No se pudo procesar la imagen']); break; }

        $w = imagesx($src); $h = imagesy($src);
        if ($w > $maxWidth) { $h = (int)round($h * $maxWidth / $w); $w = $maxWidth; }

        $dst = imagecreatetruecolor($w, $h);
        imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);

        if (!imagejpeg($dst, $destino, $quality)) {
            imagedestroy($dst);
            http_response_code(500); echo json_encode(['error' => 'Error al guardar la imagen']); break;
        }
        imagedestroy($dst);

        $prevFile = trim($_POST['prev'] ?? '');
        if ($prevFile && strpos($prevFile, 'uploads/') === 0) {
            $prevPath = __DIR__ . '/' . $prevFile;
            if (is_file($prevPath)) @unlink($prevPath);
        }

        $url = 'uploads/' . $nombre;
        echo json_encode(['url' => $url, 'message' => 'Imagen subida correctamente']);
        break;

    // ------ CATALOGO LIGERO (carga inicial ultra-rápida) ----------
    case 'catalogo':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        require_session();
        try {
            $data = db_read_catalogo($conn, $_SESSION['user_id'], $_SESSION['user_rol']);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ------ DETALLE DE CURSO BAJO DEMANDA -------------------------
    case 'curso':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        require_session();
        $cursoId = trim($_GET['id'] ?? '');
        if (!$cursoId) { http_response_code(400); echo json_encode(['error' => 'Se requiere el parametro id']); break; }
        try {
            $curso = db_read_curso_detalle($conn, $cursoId, is_admin());
            if (!$curso) {
                http_response_code(404); echo json_encode(['error' => 'Curso no encontrado']); break;
            }
            echo json_encode(['curso' => $curso], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ------ DB COMPLETA (con filtrado por rol) -------------------
    case 'db':
        if ($method === 'GET') {
            require_session();
            try {
                if (is_admin()) {
                    // Admin: recibe todos los datos (sin claves)
                    echo json_encode(db_read_safe($conn), JSON_UNESCAPED_UNICODE);
                } else {
                    // Participante: solo sus datos
                    $data = db_read_for_participant($conn, $_SESSION['user_id'], $_SESSION['user_rol']);
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                }
            } catch (Throwable $e) {
                http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
            }
            break;
        }
        if ($method === 'POST') {
            require_admin(); // Solo admins pueden guardar el DB completo
            $body = jsonBody();
            if (empty($body)) { http_response_code(400); echo json_encode(['error' => 'Datos invalidos o vacios']); break; }
            foreach (['usuarios','cursos','carreras','rolesConfig','solicitudesRegistro','solicitudesCursos'] as $k) {
                if (!isset($body[$k]) || !is_array($body[$k])) {
                    http_response_code(400); echo json_encode(['error' => "Propiedad faltante: '$k'"]); $conn->close(); exit;
                }
            }
            try { db_write_all($conn, $body); echo json_encode(['message' => 'Guardado en MySQL']); }
            catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
            break;
        }
        http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break;


    // ------ LOGIN SERVER-SIDE ------------------------------------
    case 'login':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body  = jsonBody();
        $id    = trim((string)($body['id']    ?? ''));
        $clave = trim((string)($body['clave'] ?? ''));
        if (!$id || !$clave) { http_response_code(400); echo json_encode(['error' => 'Se requieren id y clave']); break; }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (db_is_rate_limited($conn, $ip)) {
            http_response_code(429);
            echo json_encode(['error' => 'Demasiados intentos fallidos. Espera 15 minutos.']);
            break;
        }
        $usuario = db_verify_login($conn, $id, $clave);
        if (!$usuario) {
            db_record_failed_login($conn, $ip);
            db_log_activity($conn, $id, 'LOGIN_FALLIDO', "IP: $ip", $ip);
            http_response_code(401);
            echo json_encode(['error' => 'Cedula o clave incorrecta']);
            break;
        }
        db_clear_login_attempts($conn, $ip);
        db_log_activity($conn, $id, 'LOGIN_EXITOSO', '', $ip);

        // Crear sesión PHP server-side
        session_regenerate_id(true); // Prevenir session fixation
        $_SESSION['user_id']  = $usuario['id'];
        $_SESSION['user_rol'] = $usuario['rol'];
        $_SESSION['login_at'] = time();

        echo json_encode(['usuario' => $usuario], JSON_UNESCAPED_UNICODE);
        break;

    // ------ LOGOUT -----------------------------------------------
    case 'logout':
        if (!empty($_SESSION['user_id'])) {
            db_log_activity($conn, $_SESSION['user_id'], 'LOGOUT', '', $_SERVER['REMOTE_ADDR'] ?? '');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        echo json_encode(['message' => 'Sesion cerrada']);
        break;

    // ------ SESION ACTUAL (para verificar desde JS) ---------------
    case 'me':
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'No autenticado', 'code' => 'UNAUTHENTICATED']);
            break;
        }
        echo json_encode([
            'user_id'  => $_SESSION['user_id'],
            'user_rol' => $_SESSION['user_rol'],
            'login_at' => $_SESSION['login_at'] ?? null,
        ]);
        break;

    // ------ CAMBIO AUTONOMO DE CONTRASEÑA ------------------------
    case 'cambiar_clave':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $claveActual = (string)($body['claveActual'] ?? '');
        $claveNueva  = (string)($body['claveNueva']  ?? '');

        if (!$claveActual || !$claveNueva) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requieren la contraseña actual y la nueva contraseña.']);
            break;
        }

        try {
            $resultado = db_cambiar_clave($conn, $_SESSION['user_id'], $claveActual, $claveNueva);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al cambiar contraseña: ' . $e->getMessage()]);
        }
        break;

    // ------ MIGRACION CONTRASENAS --------------------------------
    case 'hash_passwords':
        require_admin();
        if (($_GET['key'] ?? '') !== 'HASH2026') { http_response_code(403); echo json_encode(['error' => 'Acceso denegado']); break; }
        try { $n = db_hash_all_passwords($conn); echo json_encode(['message' => "Hasheadas: $n contrasenas"]); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ SOLICITUD DE REGISTRO --------------------------------
    case 'solicitar_registro':
        // Público: no requiere sesión (para que puedan registrarse)
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $sol = [
            'id'               => trim($body['id']    ?? ''),
            'nombre'           => trim($body['nombre'] ?? ''),
            'clave'            => trim($body['clave']  ?? ''),
            'perfilDeseado'    => trim($body['perfilDeseado'] ?? 'participante'),
            'fecha'            => date('d/m/Y'),
            'autoAssignCareerId' => $body['autoAssignCareerId'] ?? null,
        ];
        if (!$sol['id'] || !$sol['nombre'] || !$sol['clave']) {
            http_response_code(400); echo json_encode(['error' => 'Campos requeridos: id, nombre, clave']); break;
        }
        try { db_add_solicitud_registro($conn, $sol); echo json_encode(['message' => 'Solicitud enviada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ USUARIOS ---------------------------------------------
    case 'guardar_usuario':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_usuario($conn, $body); echo json_encode(['message' => 'Usuario guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_usuario':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        if ($id === '25482938') { http_response_code(403); echo json_encode(['error' => 'No se puede eliminar al admin principal']); break; }
        try { db_delete_usuario($conn, $id); echo json_encode(['message' => 'Usuario eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CURSOS -----------------------------------------------
    case 'guardar_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_curso($conn, $body); echo json_encode(['message' => 'Curso guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_curso($conn, $id); echo json_encode(['message' => 'Curso eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CARRERAS ---------------------------------------------
    case 'guardar_carrera':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_carrera($conn, $body); echo json_encode(['message' => 'Carrera guardada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_carrera':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_carrera($conn, $id); echo json_encode(['message' => 'Carrera eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ ROLES ------------------------------------------------
    case 'guardar_rol':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_rol($conn, $body); echo json_encode(['message' => 'Rol guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_rol':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_rol($conn, $id); echo json_encode(['message' => 'Rol eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ EVALUACION SEGURA DE MODULO EN EL SERVIDOR -----------
    case 'evaluar_modulo':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid  = $_SESSION['user_id'];
        $cid  = trim($body['curso_id'] ?? '');
        $midx = isset($body['modulo_idx']) ? (int)$body['modulo_idx'] : -1;
        $resp = $body['respuestas'] ?? [];

        if (!$cid || $midx < 0 || !is_array($resp)) {
            http_response_code(400);
            echo json_encode(['error' => 'Parámetros inválidos. Se requieren curso_id, modulo_idx y respuestas (array).']);
            break;
        }

        try {
            $resultado = db_evaluar_modulo($conn, $uid, $cid, $midx, $resp);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al evaluar módulo: ' . $e->getMessage()]);
        }
        break;

    // ------ PROGRESO (el endpoint mas llamado) -------------------
    case 'guardar_progreso':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid  = trim($body['usuario_id'] ?? '');
        $cid  = trim($body['curso_id']   ?? '');
        if (!$uid || !$cid) { http_response_code(400); echo json_encode(['error' => 'Se requieren usuario_id y curso_id']); break; }

        // Solo puede guardar su propio progreso (a menos que sea admin)
        if (!is_admin() && $uid !== $_SESSION['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'No puedes guardar el progreso de otro usuario']);
            break;
        }

        try {
            if (!is_admin()) {
                // Participante: solo puede marcar lecciones completadas libremente.
                // Evaluaciones, intentos y módulos con examen solo se actualizan vía 'evaluar_modulo'.
                $stmtCheck = $conn->prepare("SELECT modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ? AND curso_id = ?");
                $stmtCheck->bind_param('ss', $uid, $cid);
                $stmtCheck->execute();
                $resCheck = $stmtCheck->get_result();
                $dbModAprob = [];
                $dbMedallas = [];
                $dbEvals    = [];
                $dbIntentos = [];
                if ($resCheck && $rCheck = $resCheck->fetch_assoc()) {
                    $dbModAprob = json_decode($rCheck['modulos_aprobados'] ?? '[]', true) ?? [];
                    $dbMedallas = json_decode($rCheck['medallas'] ?? '[]', true) ?? [];
                    $dbEvals    = json_decode($rCheck['evaluaciones'] ?? '{}', true) ?? [];
                    $dbIntentos = json_decode($rCheck['intentos'] ?? '{}', true) ?? [];
                }

                // Permitir aprobar módulos sin preguntas (solo lectura/video) si el frontend los envió
                $incomingMod = (array)($body['modulosAprobados'] ?? []);
                $allowedMod = $dbModAprob;
                $allowedMed = $dbMedallas;
                foreach ($incomingMod as $mNum) {
                    $mNumStr = (string)$mNum;
                    if (in_array($mNumStr, $allowedMod, true)) continue;
                    $mIdxInt = (int)$mNum;
                    $sQ = $conn->prepare("SELECT COUNT(p.id) as num_q FROM `curso_modulos` m LEFT JOIN `curso_preguntas` p ON p.modulo_id = m.id WHERE m.curso_id = ? AND m.orden = ?");
                    $sQ->bind_param('si', $cid, $mIdxInt);
                    $sQ->execute();
                    $rQ = $sQ->get_result()->fetch_assoc();
                    if ($rQ && (int)$rQ['num_q'] === 0) {
                        $allowedMod[] = $mNumStr;
                        $allowedMed[] = $mNumStr;
                    }
                }

                $prog = [
                    'leccionesCompletadas' => $body['leccionesCompletadas'] ?? [],
                    'modulosAprobados'     => array_values(array_unique($allowedMod)),
                    'medallas'             => array_values(array_unique($allowedMed)),
                    'evaluaciones'         => (object)$dbEvals,
                    'intentos'             => (object)$dbIntentos,
                ];
                db_upsert_progreso($conn, $uid, $cid, $prog);
            } else {
                // Admin: puede guardar todo tal como viene
                $prog = [
                    'leccionesCompletadas' => $body['leccionesCompletadas'] ?? [],
                    'modulosAprobados'     => $body['modulosAprobados']     ?? [],
                    'medallas'             => $body['medallas']             ?? [],
                    'evaluaciones'         => $body['evaluaciones']         ?? (object)[],
                    'intentos'             => $body['intentos']             ?? (object)[],
                ];
                db_upsert_progreso($conn, $uid, $cid, $prog);
                if (!empty($body['certificadosCurso']) && is_array($body['certificadosCurso'])) {
                    foreach ($body['certificadosCurso'] as $certId) {
                        $s = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
                        $s->bind_param('ss', $uid, $certId); $s->execute();
                    }
                }
            }
            echo json_encode(['message' => 'Progreso guardado']);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ SOLICITUDES ------------------------------------------
    case 'solicitar_acceso_curso':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        try { db_add_solicitud_curso($conn, $body); echo json_encode(['message' => 'Solicitud enviada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_registro':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_solicitud_registro($conn, $id); echo json_encode(['message' => 'Solicitud eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = trim($body['usuario_id'] ?? ''); $cid = trim($body['curso_id'] ?? '');
        if (!$uid || !$cid) { http_response_code(400); echo json_encode(['error' => 'Se requieren usuario_id y curso_id']); break; }
        try { db_delete_solicitud_curso($conn, $uid, $cid); echo json_encode(['message' => 'Solicitud de curso eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CONFIGURACION ----------------------------------------
    case 'guardar_config':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $clave = trim($body['clave'] ?? '');
        if (!$clave || !array_key_exists('valor', $body)) {
            http_response_code(400); echo json_encode(['error' => 'Se requieren clave y valor']); break;
        }
        try { db_upsert_config($conn, $clave, $body['valor']); echo json_encode(['message' => 'Configuracion guardada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ LECTURA INDIVIDUAL -----------------------------------
    case 'usuarios':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['usuarios' => $d['usuarios']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'cursos':
        require_session();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['cursos' => $d['cursos']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'carreras':
        require_session();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['carreras' => $d['carreras']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'config':
        require_session();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['configuracion' => $d['configuracion']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ REPORTE DIRECTO DE EVALUACIONES ----------------------
    case 'reporte_evaluaciones':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try {
            $sql = "SELECT
                        e.usuario_id,
                        u.nombre AS usuario_nombre,
                        u.rol AS usuario_rol,
                        e.curso_id,
                        c.titulo AS curso_titulo,
                        e.modulo_num,
                        e.calificacion,
                        e.aprobado,
                        e.marcado_manual,
                        e.fecha,
                        COALESCE(i.intentos, 1) AS intentos
                    FROM `usuario_evaluaciones` e
                    JOIN `usuarios` u ON u.id = e.usuario_id
                    JOIN `cursos` c ON c.id = e.curso_id
                    LEFT JOIN `usuario_intentos` i ON (i.usuario_id = e.usuario_id AND i.curso_id = e.curso_id AND i.modulo_num = e.modulo_num)
                    ORDER BY e.calificacion DESC, e.fecha DESC";
            $res = $conn->query($sql);
            $evaluaciones = [];
            while ($row = $res->fetch_assoc()) {
                $row['calificacion']   = (float)$row['calificacion'];
                $row['aprobado']       = (bool)$row['aprobado'];
                $row['marcado_manual'] = (bool)$row['marcado_manual'];
                $row['intentos']       = (int)$row['intentos'];
                $evaluaciones[] = $row;
            }
            echo json_encode(['evaluaciones' => $evaluaciones], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ------ VERIFICACION PUBLICA DE CERTIFICADO / DIPLOMA ---------
    case 'verificar_certificado':
        $codigo = trim($_GET['codigo'] ?? $_POST['codigo'] ?? '');
        if (!$codigo) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere el parámetro codigo', 'valido' => false]);
            break;
        }
        $resultado = db_verificar_certificado($conn, $codigo);
        if ($resultado) {
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode([
                'valido'  => false,
                'codigo'  => $codigo,
                'mensaje' => 'El código de verificación no corresponde a ningún certificado oficial o ha sido revocado.'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;

    // ------ HEALTH CHECK -----------------------------------------
    case 'ping':
        echo json_encode([
            'status'       => 'ok',
            'db'           => MYSQL_DB,
            'host'         => MYSQL_HOST,
            'time'         => date('c'),
            'authenticated'=> !empty($_SESSION['user_id']),
            'user_id'      => $_SESSION['user_id'] ?? null,
            'user_rol'     => $_SESSION['user_rol'] ?? null,
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => "Accion desconocida: '$action'"]);
        break;
}

$conn->close();
