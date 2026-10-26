<?php
/**
 * endpoints/reportes.php — Reportes e integraciones
 * Reporte de evaluaciones (paginado) y KPI para el dashboard Alu-Cultura.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

    // ------ REPORTE DE EVALUACIONES (paginado en el servidor) ----
    // GET ?search=&usuario=&curso=&estado=&orden=&modo=agrupada|plana&page=&limit=&todo=1
    // Ver db_reporte_evaluaciones() en lib/reportes.php para el formato de respuesta.
    case 'reporte_evaluaciones':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try {
            $filtros = array_intersect_key($_GET, array_flip(['search', 'usuario', 'curso', 'estado', 'orden', 'modo', 'page', 'limit', 'todo']));
            echo json_encode(db_reporte_evaluaciones($conn, $filtros), JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ------ KPI HUMILDAD PARA ALU-CULTURA --------------------------
    // a) App:      GET api.php?action=kpi_humildad
    //              Header: Authorization: Bearer <ID token de Firebase (Opening Checklist)>
    //              La cédula sale del perfil users/{uid}.ci. Si users/{uid}.role es
    //              admin o superadmin, &ci=<cédula> consulta a otra persona (modo
    //              "Ver como" de la app); para el resto el parámetro ci se ignora.
    // b) Servidor: GET api.php?action=kpi_humildad&ci=25482938   Header: X-Api-Key: <clave>
    //              (ruta heredada, se retirará junto con el servidor de Alu-Cultura)
    // Cursos asignados = asignados directamente + cursos del rol (igual que
    // db_read_for_participant), sin cursos en construcción. Completado = certificado.
    case 'kpi_humildad':
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $idToken = bearer_token();
        if ($idToken !== null) {
            $fb = firebase_config();
            try {
                $claims = verificar_id_token($idToken, $fb['firebase_project_id'], $fb['firebase_emulador']);
            } catch (Throwable $e) {
                http_response_code(401);
                echo json_encode(['error' => 'Sesión inválida o expirada'], JSON_UNESCAPED_UNICODE);
                break;
            }
            try {
                $perfil = perfil_usuario_firebase($idToken, $fb['firebase_project_id'], $claims['sub'], $fb['firestore_emulador_host']);
            } catch (Throwable $e) {
                http_response_code(502);
                echo json_encode(['error' => 'No se pudo leer tu perfil'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $ciSolicitada = isset($_GET['ci']) && is_string($_GET['ci']) ? $_GET['ci'] : null;
            $ci = ci_objetivo($perfil, $ciSolicitada);
            if ($ci === null) {
                http_response_code(403);
                echo json_encode(['error' => 'Tu usuario no tiene cédula registrada en Opening Checklist'], JSON_UNESCAPED_UNICODE);
                break;
            }
        } else {
            $kpiKey = kpi_api_key();
            if ($kpiKey === '' || !hash_equals($kpiKey, (string)($_SERVER['HTTP_X_API_KEY'] ?? ''))) {
                http_response_code(401);
                echo json_encode(['error' => 'API key invalida']);
                break;
            }
            $ci = preg_replace('/[^0-9]/', '', (string)($_GET['ci'] ?? ''));
            if ($ci === '') { http_response_code(400); echo json_encode(['error' => 'ci requerido']); break; }
        }
        try {
            $stmt = $conn->prepare(
                "SELECT u.nombre, u.rol, rc.nombre AS rol_nombre
                 FROM `usuarios` u LEFT JOIN `roles_config` rc ON rc.id = u.rol
                 WHERE u.id = ? AND u.estado = 'activo'"
            );
            $stmt->bind_param('s', $ci);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            if (!$u) { http_response_code(404); echo json_encode(['error' => 'Usuario no encontrado']); break; }

            $stmt = $conn->prepare(
                "SELECT
                    COUNT(*)                       AS asignados,
                    SUM(cert.curso_id IS NOT NULL) AS completados,
                    SUM(cert.curso_id IS NULL AND EXISTS (
                        SELECT 1 FROM `usuario_lecciones_completadas` l
                        WHERE l.usuario_id = ? AND l.curso_id = a.curso_id
                    )) AS en_progreso
                 FROM (
                    SELECT curso_id FROM `usuario_asignados` WHERE usuario_id = ?
                    UNION
                    SELECT curso_id FROM `rol_cursos` WHERE rol_id = ?
                 ) a
                 JOIN `cursos` c ON c.id = a.curso_id AND c.en_construccion = 0
                 LEFT JOIN `usuario_certificados_curso` cert ON cert.usuario_id = ? AND cert.curso_id = a.curso_id"
            );
            // Orden de los ? en el texto SQL: EXISTS (en_progreso), asignados, rol, certificados
            $stmt->bind_param('ssss', $ci, $ci, $u['rol'], $ci);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();

            echo json_encode([
                'nombre'      => $u['nombre'],
                'rol'         => $u['rol'],
                'rol_nombre'  => $u['rol_nombre'] ?: $u['rol'],
                'asignados'   => (int)($r['asignados']   ?? 0),
                'completados' => (int)($r['completados'] ?? 0),
                'en_progreso' => (int)($r['en_progreso'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al calcular KPI']);
        }
        break;
}
