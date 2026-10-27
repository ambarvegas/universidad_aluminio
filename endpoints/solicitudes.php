<?php
/**
 * endpoints/solicitudes.php — Solicitudes
 * Solicitudes de registro y de acceso a cursos.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

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
        try { 
            db_add_solicitud_registro($conn, $sol); 
            require_once APP_ROOT . '/mailer.php';
            @notificarAdminNuevaSolicitud($conn, 'Registro de Nuevo Usuario', $sol['nombre'], $sol['id']);
            echo json_encode(['message' => 'Solicitud enviada']); 
        }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ SOLICITUDES ------------------------------------------
    case 'solicitar_acceso_curso':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $targetCid = trim($body['cursoId'] ?? $body['curso_id'] ?? '');
        if ($targetCid) {
            $sCur = $conn->prepare("SELECT tipo FROM `cursos` WHERE id = ?");
            $sCur->bind_param('s', $targetCid);
            $sCur->execute();
            $rCur = $sCur->get_result()->fetch_assoc();
            if ($rCur && ($rCur['tipo'] ?? '') === 'pruebas') {
                http_response_code(403);
                echo json_encode(['error' => 'No es posible solicitar acceso a cursos en modalidad de prueba.']);
                break;
            }
        }
        try { 
            db_add_solicitud_curso($conn, $body); 
            require_once APP_ROOT . '/mailer.php';
            @notificarAdminNuevaSolicitud($conn, 'Acceso a Curso Especializado', $body['userName'] ?? $body['user_name'] ?? $_SESSION['user_id'], $body['cursoId'] ?? $body['curso_id'] ?? '');
            echo json_encode(['message' => 'Solicitud enviada']); 
        }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_registro':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_solicitud_registro($conn, $id); echo json_encode(['message' => 'Solicitud eliminada']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_solicitud_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = trim($body['usuario_id'] ?? ''); $cid = trim($body['curso_id'] ?? '');
        if (!$uid || !$cid) { http_response_code(400); echo json_encode(['error' => 'Se requieren usuario_id y curso_id']); break; }
        try { db_delete_solicitud_curso($conn, $uid, $cid); echo json_encode(['message' => 'Solicitud de curso eliminada']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;
}
