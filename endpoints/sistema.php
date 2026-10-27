<?php
/**
 * endpoints/sistema.php — Sistema
 * Diagnóstico, prueba de correo, respaldos, latido de sesión y registro de errores.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

    // ------ ESTADO DE SALUD DEL SISTEMA (DIAGNÓSTICO INTEGRAL) ---
    case 'health':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try {
            $health = db_get_health_status($conn);
            echo json_encode($health, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al obtener estado de salud: ' . $e->getMessage()]);
        }
        break;

    // ------ PRUEBA DE SERVIDOR SMTP / CORREO ---------------------
    case 'test_email':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $destinatario = trim((string)($body['email'] ?? ''));
        if (!$destinatario || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
            $destinatario = ($res && $r = $res->fetch_assoc()) ? trim((string)$r['valor']) : '';
        }
        if (!$destinatario || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere una dirección de correo válida para la prueba (o configure el email_admin en configuración).']);
            break;
        }

        require_once APP_ROOT . '/mailer.php';
        try {
            $mailer = obtenerMailerInstance($conn);
            $horaActual = date('d/m/Y H:i:s');
            $cuerpoHtml = "<p>Hola Administrador,</p>
            <p>Este es un correo de prueba emitido desde el <strong>Panel de Administración de la Universidad del Aluminio LMS</strong>.</p>
            <div style=\"background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin:16px 0;\">
                <p style=\"margin:0 0 6px;\"><strong>Fecha y Hora:</strong> {$horaActual}</p>
                <p style=\"margin:0 0 6px;\"><strong>Destinatario de Prueba:</strong> <code style=\"color:#0284c7; font-weight:bold;\">{$destinatario}</code></p>
                <p style=\"margin:0;\"><strong>Estado del Servicio SMTP:</strong> <span style=\"color:#10b981; font-weight:bold;\">&#10004; Operativo</span></p>
            </div>
            <p>Si has recibido este mensaje, las notificaciones automáticas para aprobaciones de cuentas, certificados oficiales y alertas de solicitudes están correctamente enlazadas.</p>";

            $enviado = $mailer->send($destinatario, "Prueba de Configuración de Correo — Universidad del Aluminio", renderHtmlEmailTemplate("Diagnóstico de Correo Institucional", $cuerpoHtml));

            if ($enviado) {
                db_log_activity($conn, $_SESSION['user_id'] ?? 'admin', 'TEST_EMAIL', "Correo de prueba enviado a $destinatario", $_SERVER['REMOTE_ADDR'] ?? '');
                echo json_encode(['success' => true, 'message' => "Correo de prueba enviado exitosamente a $destinatario."]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "No se pudo entregar el correo a $destinatario. Verifique los parámetros SMTP o la conectividad del servidor."]);
            }
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Excepción durante el envío: ' . $e->getMessage()]);
        }
        break;

    // ------ EJECUTAR RESPALDO MANUAL COMPLETO --------------------
    case 'trigger_backup':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        require_once APP_ROOT . '/tools/cron_backup.php';
        try {
            $resultado = ejecutarRespaldo($conn);
            db_log_activity($conn, $_SESSION['user_id'] ?? 'admin', 'BACKUP_MANUAL', "Generado respaldo: " . $resultado['archivo'], $_SERVER['REMOTE_ADDR'] ?? '');
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al generar respaldo: ' . $e->getMessage()]);
        }
        break;

    // ------ LISTADO DE RESPALDOS GENERADOS -----------------------
    case 'lista_respaldos':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $backupDir = APP_ROOT . '/backups/';
        $lista = [];
        if (is_dir($backupDir)) {
            $archivos = glob($backupDir . "respaldo_unialuminio_*.*");
            if ($archivos) {
                usort($archivos, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                foreach ($archivos as $arch) {
                    $bytes = filesize($arch);
                    $lista[] = [
                        'archivo'      => basename($arch),
                        'fecha'        => date('Y-m-d H:i:s', filemtime($arch)),
                        'tamano_bytes' => $bytes,
                        'tamano'       => db_format_bytes($bytes),
                        'tipo'         => pathinfo($arch, PATHINFO_EXTENSION) === 'zip' ? 'ZIP (Base de Datos + Uploads)' : 'SQL Plano'
                    ];
                }
            }
        }
        echo json_encode(['respaldos' => $lista], JSON_UNESCAPED_UNICODE);
        break;

    // ------ HEALTH CHECK (PING BASICO) ---------------------------
    // Latido de sesión: session_start() ya renovó la marca de actividad de la sesión.
    case 'ping':
        echo json_encode([
            'status'       => 'ok',
            'time'         => date('c'),
            'authenticated'=> !empty($_SESSION['user_id']),
            'user_id'      => $_SESSION['user_id'] ?? null,
            'user_rol'     => $_SESSION['user_rol'] ?? null,
        ]);
        break;

    // ------ REGISTRO DE ERRORES DEL NAVEGADOR -------------------
    // Sin sesión obligatoria (también se registran errores de login.php); límite por IP en
    // db_registrar_error(). Siempre responde 204: el cliente no debe reaccionar a esto.
    case 'registrar_error':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        try {
            db_registrar_error($conn, [
                'origen'     => 'js',
                'mensaje'    => $body['mensaje'] ?? '',
                'detalle'    => $body['detalle'] ?? '',
                'ubicacion'  => errores_ruta((string)($body['url'] ?? '')),
                'usuario_id' => $_SESSION['user_id'] ?? '',
                'navegador'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'version'    => $body['version'] ?? '',
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (Throwable $e) {
            error_log('[errores] no se pudo registrar un error del navegador: ' . $e->getMessage());
        }
        http_response_code(204);
        break;

    // ------ ERRORES RECIENTES (DIAGNÓSTICO) ----------------------
    case 'errores_recientes':
        require_admin();
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        try {
            echo json_encode(db_errores_recientes($conn, (int)($_GET['limit'] ?? 50)), JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al consultar los errores: ' . $e->getMessage()]);
        }
        break;

    // ------ MARCAR ERRORES COMO REVISADOS ------------------------
    // Body: { ids: [1,2,...] } para unos concretos, o {} para todos.
    case 'errores_limpiar':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : null;
        try {
            $borrados = db_errores_limpiar($conn, $ids);
            db_log_activity($conn, $_SESSION['user_id'], 'ERRORES_REVISADOS', $ids === null ? 'todos' : implode(',', array_map('intval', $ids)));
            echo json_encode(['borrados' => $borrados]);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al limpiar los errores: ' . $e->getMessage()]);
        }
        break;
}
