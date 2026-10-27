<?php
/**
 * endpoints/auth.php — Autenticación y cuenta
 * Login, sesión, contraseña, perfil, recuperación de acceso e invitaciones.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

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
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al cambiar contraseña: ' . $e->getMessage()]);
        }
        break;

    // ------ ACTUALIZAR DATOS DE PERFIL (TELÉFONO, CORREO, FECHA NAC.) -------
    case 'guardar_perfil':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = $_SESSION['user_id'];
        try {
            $updated = db_actualizar_perfil($conn, $uid, $body);
            echo json_encode([
                'success' => true,
                'message' => 'Tus datos de perfil han sido actualizados correctamente.',
                'usuario' => $updated
            ], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al actualizar datos de perfil: ' . $e->getMessage()]);
        }
        break;

    // ------ MIGRACION CONTRASENAS --------------------------------
    case 'hash_passwords':
        require_admin();
        if (($_GET['key'] ?? '') !== 'HASH2026') { http_response_code(403); echo json_encode(['error' => 'Acceso denegado']); break; }
        try { $n = db_hash_all_passwords($conn); echo json_encode(['message' => "Hasheadas: $n contrasenas"]); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ RECUPERACION DE CONTRASEÑA (PÚBLICO) ----------------
    case 'solicitar_recuperacion':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $identificador = trim((string)($body['identificador'] ?? ''));
        if (!$identificador) {
            http_response_code(400);
            echo json_encode(['error' => 'Por favor ingresa tu cédula o correo electrónico.']);
            break;
        }

        // Buscar usuario por cédula o correo
        $stmtU = $conn->prepare("SELECT id, nombre, email, estado FROM `usuarios` WHERE id = ? OR (email != '' AND LOWER(email) = LOWER(?))");
        $stmtU->bind_param('ss', $identificador, $identificador);
        $stmtU->execute();
        $resU = $stmtU->get_result();
        $uRow = $resU->fetch_assoc();

        if (!$uRow) {
            http_response_code(404);
            echo json_encode(['error' => 'No se encontró ningún colaborador con la cédula o correo proporcionado.']);
            break;
        }

        $emailUser = trim($uRow['email'] ?? '');
        if (!$emailUser || !filter_var($emailUser, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Tu cuenta no tiene un correo electrónico registrado en el sistema. Por favor, solicita a un administrador que te configure tu correo o te genere un enlace de acceso directo.',
                'code'  => 'NO_EMAIL_CONFIGURED',
                'usuario_id' => $uRow['id'],
                'nombre' => $uRow['nombre']
            ]);
            break;
        }

        try {
            $token = db_crear_token_acceso($conn, $uRow['id'], 'reset', 2);
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $link = rtrim($baseUrl, '/') . '/recuperar.php?token=' . $token;

            require_once APP_ROOT . '/mailer.php';
            $enviado = @notificarRecuperacionClave($conn, $emailUser, $uRow['nombre'], $uRow['id'], $link);

            // Ofuscar correo para feedback seguro
            $partes = explode('@', $emailUser);
            $nombreCorreo = $partes[0];
            $dominio = $partes[1] ?? '';
            $longitud = strlen($nombreCorreo);
            $ofuscado = ($longitud <= 2) ? $nombreCorreo . '***@' . $dominio : substr($nombreCorreo, 0, 2) . str_repeat('*', max(3, $longitud - 3)) . substr($nombreCorreo, -1) . '@' . $dominio;

            echo json_encode([
                'success'        => true,
                'email_enviado'  => $enviado,
                'email_ofuscado' => $ofuscado,
                'message'        => "Hemos enviado un enlace seguro para restablecer tu contraseña al correo $ofuscado. El enlace es válido por 2 horas."
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al procesar la solicitud de recuperación: ' . $e->getMessage()]);
        }
        break;

    // ------ VERIFICAR TOKEN DE ACCESO / RECUPERACIÓN (PÚBLICO) ---
    case 'verificar_token_acceso':
        $token = trim((string)($_GET['token'] ?? jsonBody()['token'] ?? ''));
        if (!$token) {
            http_response_code(400);
            echo json_encode(['valid' => false, 'error' => 'Token no proporcionado.']);
            break;
        }
        try {
            $info = db_validar_token_acceso($conn, $token);
            if (!$info['valid']) {
                http_response_code(400);
            }
            echo json_encode($info, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['valid' => false, 'error' => 'Error al validar el enlace: ' . $e->getMessage()]);
        }
        break;

    // ------ EJECUTAR RESTABLECIMIENTO / DEFINIR NUEVA CLAVE (PÚBLICO)
    case 'ejecutar_recuperacion':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $token = trim((string)($body['token'] ?? ''));
        $claveNueva = trim((string)($body['clave_nueva'] ?? $body['claveNueva'] ?? ''));

        if (!$token || !$claveNueva) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requieren el token y la nueva contraseña.']);
            break;
        }

        try {
            $resultado = db_consumir_token_acceso($conn, $token, $claveNueva);
            echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al restablecer la contraseña: ' . $e->getMessage()]);
        }
        break;

    // ------ GENERAR LINK DE INVITACIÓN / ACCESO AUTOMÁTICO (ADMIN) -
    case 'generar_link_invitacion':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $uid = trim((string)($body['usuario_id'] ?? $body['id'] ?? ''));
        $tipo = trim((string)($body['tipo'] ?? 'invitacion'));
        $horas = isset($body['horas_validez']) ? (int)$body['horas_validez'] : 48;
        if ($horas <= 0) $horas = 48;

        if (!$uid) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere el identificador de usuario (cédula).']);
            break;
        }

        $stmtU = $conn->prepare("SELECT id, nombre, email, rol, estado FROM `usuarios` WHERE id = ?");
        $stmtU->bind_param('s', $uid);
        $stmtU->execute();
        $uRow = $stmtU->get_result()->fetch_assoc();

        if (!$uRow) {
            http_response_code(404);
            echo json_encode(['error' => "Usuario con cédula $uid no encontrado."]);
            break;
        }

        try {
            $token = db_crear_token_acceso($conn, $uid, $tipo, $horas);
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $link = rtrim($baseUrl, '/') . '/recuperar.php?token=' . $token;

            $userEmail = trim($uRow['email'] ?? '');
            $emailEnviado = false;

            // Envío automático al correo registrado del usuario
            if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                require_once APP_ROOT . '/mailer.php';
                $emailEnviado = @notificarInvitacionAcceso($conn, $userEmail, $uRow['nombre'], $uid, $link, $tipo);
            }

            $expiraFormatted = date('d/m/Y H:i', time() + ($horas * 3600));

            echo json_encode([
                'success'       => true,
                'link'          => $link,
                'token'         => $token,
                'expira'        => $expiraFormatted,
                'tipo'          => $tipo,
                'usuario'       => [
                    'id'     => $uRow['id'],
                    'nombre' => $uRow['nombre'],
                    'email'  => $userEmail,
                    'rol'    => $uRow['rol']
                ],
                'email_enviado' => $emailEnviado,
                'mensaje'       => ($emailEnviado) 
                    ? "Enlace generado y enviado exitosamente al correo registrado ({$userEmail})."
                    : ($userEmail ? "Enlace generado. No se pudo enviar el correo automático por configuración SMTP del servidor, pero puedes copiar el enlace directamente." : "Enlace generado. El usuario no tiene correo registrado en su perfil, puedes copiar el enlace para enviárselo directamente.")
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al generar enlace de acceso: ' . $e->getMessage()]);
        }
        break;
}
