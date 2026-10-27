<?php
/**
 * endpoints/progreso.php — Progreso y evaluaciones
 * Evaluación en servidor, guardado de progreso, restablecimiento y reintentos habilitados.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

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

        // Si el curso es de pruebas, solo administradores pueden evaluar
        $sChk = $conn->prepare("SELECT tipo FROM `cursos` WHERE id = ?");
        $sChk->bind_param('s', $cid);
        $sChk->execute();
        $rChk = $sChk->get_result()->fetch_assoc();
        if ($rChk && ($rChk['tipo'] ?? '') === 'pruebas' && !is_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Acceso restringido: Este curso es de pruebas y exclusivo para administradores']);
            break;
        }

        try {
            $resultado = db_evaluar_modulo($conn, $uid, $cid, $midx, $resp);
            // Disparadores de Notificaciones Institucionales por Correo
            require_once APP_ROOT . '/mailer.php';

            $sU = $conn->prepare("SELECT nombre, rol, email FROM `usuarios` WHERE id = ?");
            $sU->bind_param('s', $uid);
            $sU->execute();
            $rU = $sU->get_result()->fetch_assoc();
            $uName = $rU['nombre'] ?? $uid;
            $userEmailDb = trim($rU['email'] ?? '');

            $cTitulo = $resultado['cursoTitulo'] ?? $cid;
            $mTitulo = $resultado['moduloTitulo'] ?? ("Módulo " . ($midx + 1));
            $calif   = (int)($resultado['calificacion'] ?? 0);
            $intentos = (int)($resultado['numIntentos'] ?? 1);
            $maxInt  = (int)($resultado['maxIntentos'] ?? 0);

            // 1. Notificar al Admin cuando un usuario aprueba un módulo
            if (!empty($resultado['moduloRecienAprobado'])) {
                @notificarAdminModuloAprobado($conn, $uid, $uName, $cid, $cTitulo, $mTitulo, $midx, $calif, $intentos);
            }

            // 2. Notificar al Admin cuando un usuario agota sus intentos permitidos
            if (!empty($resultado['intentosAgotados'])) {
                @notificarAdminIntentosAgotados($conn, $uid, $uName, $cid, $cTitulo, $mTitulo, $midx, $intentos, $maxInt, $calif);
            }

            // 3. Notificar al Alumno y al Admin cuando completa un curso completo
            if (!empty($resultado['certificadoOtorgado'])) {
                $codCert = db_generar_codigo_certificado($uid, $cid, 'curso');
                $emailUser = ($userEmailDb && filter_var($userEmailDb, FILTER_VALIDATE_EMAIL)) ? $userEmailDb : (filter_var($uid, FILTER_VALIDATE_EMAIL) ? $uid : '');
                if ($emailUser) {
                    @notificarCertificadoEmitido($conn, $emailUser, $uName, $cTitulo, $codCert, $uid, $cid, 'curso');
                }
                @notificarAdminCursoCompletado($conn, $uid, $uName, $cid, $cTitulo, $codCert);
            }

            // 4. Notificar al Admin cuando completa todos los cursos de su rol o carrera
            if (!empty($resultado['rolOtorgado']) || !empty($resultado['carreraOtorgada'])) {
                $nombrePlan = $resultado['rolNombre'] ?? 'Plan de Formación del Rol';
                @notificarAdminRolCompletado($conn, $uid, $uName, $nombrePlan, $resultado['certificadosCurso'] ?? []);
            }
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
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
                $stmtCheck = $conn->prepare("SELECT modulo_num FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ?");
                $stmtCheck->bind_param('ss', $uid, $cid);
                $stmtCheck->execute();
                $resCheck = $stmtCheck->get_result();
                $dbModAprob = [];
                while ($resCheck && $rCheck = $resCheck->fetch_assoc()) {
                    $dbModAprob[] = (string)$rCheck['modulo_num'];
                }

                // Permitir aprobar módulos sin preguntas (solo lectura/video) si el frontend los envió.
                // El guardado es aditivo: solo se envían los módulos nuevos permitidos.
                $incomingMod = (array)($body['modulosAprobados'] ?? []);
                $allowedMod = [];
                $allowedMed = [];
                foreach ($incomingMod as $mNum) {
                    $mNumStr = (string)$mNum;
                    if (in_array($mNumStr, $dbModAprob, true)) continue;
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
                    'evaluaciones'         => [],
                    'intentos'             => [],
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
        } catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ RESTABLECER PROGRESO (atómico) ----------------------
    case 'restablecer_progreso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body    = jsonBody();
        $uid     = trim((string)($body['usuario_id'] ?? ''));
        $cid     = trim((string)($body['curso_id']   ?? ''));
        $modulos = isset($body['modulos']) && is_array($body['modulos']) ? $body['modulos'] : null;
        if (!$uid || !$cid) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requieren usuario_id y curso_id']);
            break;
        }
        try {
            $res = db_restablecer_progreso($conn, $uid, $cid, $modulos);
            db_log_activity($conn, $_SESSION['user_id'] ?? 'admin', 'RESTABLECER_PROGRESO', "Usuario: $uid, Curso: $cid", $_SERVER['REMOTE_ADDR'] ?? '');
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al restablecer progreso: ' . $e->getMessage()]);
        }
        break;

    // ------ HABILITAR REINTENTO DE EVALUACIÓN REPROBADA ------------
    // Otorga intentos adicionales sin borrar el historial de reprobaciones.
    case 'habilitar_reintento':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid  = trim((string)($body['usuario_id'] ?? ''));
        $cid  = trim((string)($body['curso_id']   ?? ''));
        $mIdx = isset($body['modulo_idx']) && is_numeric($body['modulo_idx']) ? (int)$body['modulo_idx'] : -1;
        if (!$uid || !$cid || $mIdx < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requieren usuario_id, curso_id y modulo_idx']);
            break;
        }
        try {
            $adminId = (string)($_SESSION['user_id'] ?? 'admin');
            $res = db_habilitar_reintento(
                $conn, $uid, $cid, $mIdx,
                (int)($body['intentos'] ?? 1),
                !empty($body['repasar_lecciones']),
                (string)($body['motivo'] ?? ''),
                $adminId
            );
            db_log_activity($conn, $adminId, 'HABILITAR_REINTENTO', "Usuario: $uid, Curso: $cid, Módulo: $mIdx, Intentos: {$res['intentosExtra']}", $_SERVER['REMOTE_ADDR'] ?? '');
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al habilitar reintento: ' . $e->getMessage()]);
        }
        break;
}
