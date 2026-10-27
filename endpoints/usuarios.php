<?php
/**
 * endpoints/usuarios.php — Gestión de usuarios
 * Listado paginado, alta/edición, eliminación y notificaciones a usuarios.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

    // ------ USUARIOS ---------------------------------------------
    case 'usuarios_paginados':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $page   = isset($_GET['page'])   ? (int)$_GET['page']   : 1;
        $limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 25;
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $rol    = isset($_GET['rol'])    ? trim((string)$_GET['rol'])    : '';
        $estado = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';

        try {
            $data = db_read_usuarios_paginados($conn, $page, $limit, $search, $rol, $estado);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al consultar usuarios: ' . $e->getMessage()]);
        }
        break;

    case 'guardar_usuario':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_usuario($conn, $body); echo json_encode(['message' => 'Usuario guardado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_usuario':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        if ($id === '25482938') { http_response_code(403); echo json_encode(['error' => 'No se puede eliminar al admin principal']); break; }
        try { db_delete_usuario($conn, $id); echo json_encode(['message' => 'Usuario eliminado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ NOTIFICAR USUARIO (MANUAL O APROBACION) --------------
    case 'notificar_usuario':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $email  = trim((string)($body['email'] ?? ''));
        $nombre = trim((string)($body['nombre'] ?? 'Usuario'));
        $id     = trim((string)($body['id'] ?? ''));
        $clave  = trim((string)($body['clave'] ?? ''));
        $tipo   = trim((string)($body['tipo'] ?? 'aprobacion'));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere un correo electrónico válido del destinatario.']);
            break;
        }

        require_once APP_ROOT . '/mailer.php';
        try {
            if ($tipo === 'aprobacion') {
                $enviado = notificarCuentaAprobada($conn, $email, $nombre, $id, $clave);
            } else {
                $asunto = trim((string)($body['asunto'] ?? 'Notificación Oficial — Universidad del Aluminio'));
                $mensaje = trim((string)($body['mensaje'] ?? ''));
                $mailer = obtenerMailerInstance($conn);
                $enviado = $mailer->send($email, $asunto, renderHtmlEmailTemplate($asunto, "<p>" . nl2br(htmlspecialchars($mensaje)) . "</p>"));
            }

            if ($enviado) {
                echo json_encode(['success' => true, 'message' => "Notificación enviada a $email"]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "No se pudo entregar el correo a $email"]);
            }
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al enviar notificación: ' . $e->getMessage()]);
        }
        break;
}
