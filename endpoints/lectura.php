<?php
/**
 * endpoints/lectura.php — Lectura de datos
 * Catálogo del campus, detalle de curso, base completa filtrada por rol y lecturas individuales.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

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
            if (($curso['tipo'] ?? '') === 'pruebas' && !is_admin()) {
                http_response_code(403); echo json_encode(['error' => 'Acceso restringido: Este curso es de pruebas y exclusivo para administradores']); break;
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
                    // Admin: recibe todos los datos (sin claves). Con ligero=1, sin el progreso
                    // de cada usuario, que se pide aparte con progreso_usuarios.
                    $conProgreso = empty($_GET['ligero']);
                    echo json_encode(db_read_safe($conn, $conProgreso), JSON_UNESCAPED_UNICODE);
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
            require_admin(); // Solo admins pueden restaurar o importar el DB completo (backup)
            $body = jsonBody();
            if (empty($body)) { http_response_code(400); echo json_encode(['error' => 'Datos invalidos o vacios']); break; }
            foreach (['usuarios','cursos','carreras','rolesConfig','solicitudesRegistro','solicitudesCursos'] as $k) {
                if (!isset($body[$k]) || !is_array($body[$k])) {
                    http_response_code(400); echo json_encode(['error' => "Propiedad faltante: '$k'"]); $conn->close(); exit;
                }
            }
            try {
                db_log_activity($conn, $_SESSION['user_id'] ?? 'admin', 'IMPORT_DB_BACKUP', 'Restauración completa de base de datos JSON', $_SERVER['REMOTE_ADDR'] ?? '');
                db_write_all($conn, $body);
                echo json_encode(['message' => 'Base de datos restaurada y sincronizada en MySQL']);
            }
            catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
            break;
        }
        http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break;

    // ------ PROGRESO BAJO DEMANDA (admin) ------------------------
    // GET: progreso de todos los usuarios. POST {ids: [...]}: solo esos usuarios.
    // Respuesta: { progreso: { usuario_id: { curso_id: {...} } } }
    case 'progreso_usuarios':
        require_admin();
        try {
            if ($method === 'GET') {
                $progreso = db_leer_progreso($conn);
            } elseif ($method === 'POST') {
                $ids = jsonBody()['ids'] ?? null;
                if (!is_array($ids) || count($ids) === 0 || count($ids) > 200) {
                    http_response_code(400); echo json_encode(['error' => 'Se requiere ids (1 a 200 usuarios)']); break;
                }
                $progreso = [];
                foreach (array_unique(array_map('strval', $ids)) as $id) {
                    $progreso += db_leer_progreso($conn, $id);
                }
            } else {
                http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break;
            }
            echo json_encode(['progreso' => (object)$progreso], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
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
        try {
            $d = db_read_safe($conn);
            $cfg = is_admin() ? $d['configuracion'] : db_config_publica($d['configuracion']);
            echo json_encode(['configuracion' => $cfg], JSON_UNESCAPED_UNICODE);
        }
        catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;
}
