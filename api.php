<?php
/**
 * api.php - Universidad del Aluminio
 * API REST completa con:
 *  - Autenticacion server-side (bcrypt) con rate limiting
 *  - Endpoints granulares por entidad
 *  - Log de actividad
 *  - Headers de seguridad HTTP
 *  - Compatibilidad total con frontend existente (GET/POST sin ?action)
 */

// ============================================================
// HEADERS DE SEGURIDAD + CORS
// ============================================================
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Content-Type: application/json; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
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

function jsonBody(): array {
    $json = file_get_contents('php://input');
    if (empty($json)) return [];
    $decoded = json_decode($json, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'db';

switch ($action) {

    // ------ SUBIDA DE IMAGENES (multipart/form-data) -------------
    case 'upload_image':
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

        $type    = trim($_POST['type'] ?? 'portada');   // logo | portada
        $entidad = trim($_POST['id'] ?? 'gen');
        $entidad = preg_replace('/[^a-zA-Z0-9_\-]/', '', $entidad);
        $ext     = 'jpg';
        $nombre  = $type . '_' . $entidad . '_' . time() . '.' . $ext;
        $destino = $uploadDir . $nombre;

        // Comprimir con GD
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
        // Preservar fondo blanco para PNG transparentes
        imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);

        if (!imagejpeg($dst, $destino, $quality)) {
            imagedestroy($dst);
            http_response_code(500); echo json_encode(['error' => 'Error al guardar la imagen']); break;
        }
        imagedestroy($dst);

        // Eliminar imagen anterior del mismo tipo/entidad (limpieza automática)
        $prevFile = trim($_POST['prev'] ?? '');
        if ($prevFile && strpos($prevFile, 'uploads/') === 0) {
            $prevPath = __DIR__ . '/' . $prevFile;
            if (is_file($prevPath)) @unlink($prevPath);
        }

        $url = 'uploads/' . $nombre;
        echo json_encode(['url' => $url, 'message' => 'Imagen subida correctamente']);
        break;

    // ------ COMPATIBILIDAD TOTAL: GET/POST sin ?action ----------
    case 'db':
        if ($method === 'GET') {
            try { echo json_encode(db_read_safe($conn), JSON_UNESCAPED_UNICODE); }
            catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
            break;
        }
        if ($method === 'POST') {
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
        echo json_encode(['usuario' => $usuario], JSON_UNESCAPED_UNICODE);
        break;

    // ------ MIGRACION CONTRASENAS --------------------------------
    case 'hash_passwords':
        if (($_GET['key'] ?? '') !== 'HASH2026') { http_response_code(403); echo json_encode(['error' => 'Acceso denegado']); break; }
        try { $n = db_hash_all_passwords($conn); echo json_encode(['message' => "Hasheadas: $n contrasenas"]); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ SOLICITUD DE REGISTRO --------------------------------
    case 'solicitar_registro':
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
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_usuario($conn, $body); echo json_encode(['message' => 'Usuario guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_usuario':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        if ($id === '25482938') { http_response_code(403); echo json_encode(['error' => 'No se puede eliminar al admin principal']); break; }
        try { db_delete_usuario($conn, $id); echo json_encode(['message' => 'Usuario eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CURSOS -----------------------------------------------
    case 'guardar_curso':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_curso($conn, $body); echo json_encode(['message' => 'Curso guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_curso':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_curso($conn, $id); echo json_encode(['message' => 'Curso eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CARRERAS ---------------------------------------------
    case 'guardar_carrera':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_carrera($conn, $body); echo json_encode(['message' => 'Carrera guardada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_carrera':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_carrera($conn, $id); echo json_encode(['message' => 'Carrera eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ ROLES ------------------------------------------------
    case 'guardar_rol':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_rol($conn, $body); echo json_encode(['message' => 'Rol guardado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_rol':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_rol($conn, $id); echo json_encode(['message' => 'Rol eliminado']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ PROGRESO (el endpoint mas llamado) -------------------
    case 'guardar_progreso':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = trim($body['usuario_id'] ?? '');
        $cid = trim($body['curso_id']   ?? '');
        if (!$uid || !$cid) { http_response_code(400); echo json_encode(['error' => 'Se requieren usuario_id y curso_id']); break; }
        $prog = [
            'leccionesCompletadas' => $body['leccionesCompletadas'] ?? [],
            'modulosAprobados'     => $body['modulosAprobados']     ?? [],
            'medallas'             => $body['medallas']             ?? [],
            'evaluaciones'         => $body['evaluaciones']         ?? (object)[],
            'intentos'             => $body['intentos']             ?? (object)[],
        ];
        try {
            db_upsert_progreso($conn, $uid, $cid, $prog);
            if (!empty($body['certificadosCurso']) && is_array($body['certificadosCurso'])) {
                foreach ($body['certificadosCurso'] as $certId) {
                    $s = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
                    $s->bind_param('ss', $uid, $certId); $s->execute();
                }
            }
            echo json_encode(['message' => 'Progreso guardado']);
        } catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ SOLICITUDES ------------------------------------------
    case 'solicitar_acceso_curso':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        try { db_add_solicitud_curso($conn, $body); echo json_encode(['message' => 'Solicitud enviada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_registro':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_solicitud_registro($conn, $id); echo json_encode(['message' => 'Solicitud eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_curso':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = trim($body['usuario_id'] ?? ''); $cid = trim($body['curso_id'] ?? '');
        if (!$uid || !$cid) { http_response_code(400); echo json_encode(['error' => 'Se requieren usuario_id y curso_id']); break; }
        try { db_delete_solicitud_curso($conn, $uid, $cid); echo json_encode(['message' => 'Solicitud de curso eliminada']); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CONFIGURACION ----------------------------------------
    case 'guardar_config':
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
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['usuarios' => $d['usuarios']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'cursos':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['cursos' => $d['cursos']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'carreras':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['carreras' => $d['carreras']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'config':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try { $d = db_read_safe($conn); echo json_encode(['configuracion' => $d['configuracion']], JSON_UNESCAPED_UNICODE); }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ HEALTH CHECK -----------------------------------------
    case 'ping':
        echo json_encode(['status' => 'ok', 'db' => MYSQL_DB, 'host' => MYSQL_HOST, 'time' => date('c')]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => "Accion desconocida: '$action'"]);
        break;
}

$conn->close();
