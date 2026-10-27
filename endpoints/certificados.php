<?php
/**
 * endpoints/certificados.php — Certificados
 * Verificación pública y envío de certificados por correo.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

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

    // ------ ENVIAR CERTIFICADO OFICIAL EN PDF POR CORREO --------
    case 'enviar_certificado_email':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $targetUid = trim((string)($body['usuario_id'] ?? $_SESSION['user_id']));
        $itemId    = trim((string)($body['item_id'] ?? $body['curso_id'] ?? ''));
        $tipo      = trim((string)($body['tipo'] ?? 'curso'));
        $emailDest = trim((string)($body['email'] ?? ''));

        // Solo un administrador puede enviar certificados de otros usuarios
        if (!is_admin() && $targetUid !== $_SESSION['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'No tienes permiso para solicitar certificados de otro usuario.']);
            break;
        }

        if (!$itemId) {
            http_response_code(400);
            echo json_encode(['error' => 'Se requiere el identificador del curso o carrera.']);
            break;
        }

        // Obtener datos del usuario
        $sU = $conn->prepare("SELECT id, nombre, email FROM `usuarios` WHERE id = ?");
        $sU->bind_param('s', $targetUid);
        $sU->execute();
        $rU = $sU->get_result()->fetch_assoc();
        if (!$rU) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuario no encontrado.']);
            break;
        }

        $userName  = $rU['nombre'];
        $userEmail = $emailDest ?: trim($rU['email'] ?? '');
        if (!$userEmail || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'El usuario no posee un correo electrónico válido registrado.']);
            break;
        }

        // Obtener título del programa
        $tituloPrograma = '';
        if ($tipo === 'carrera') {
            $sC = $conn->prepare("SELECT nombre FROM `carreras` WHERE id = ?");
            $sC->bind_param('s', $itemId);
            $sC->execute();
            $rC = $sC->get_result()->fetch_assoc();
            $tituloPrograma = $rC['nombre'] ?? $itemId;
        } else {
            $sCur = $conn->prepare("SELECT titulo FROM `cursos` WHERE id = ?");
            $sCur->bind_param('s', $itemId);
            $sCur->execute();
            $rCur = $sCur->get_result()->fetch_assoc();
            $tituloPrograma = $rCur['titulo'] ?? $itemId;
        }

        $codCert = db_generar_codigo_certificado($targetUid, $itemId, $tipo);

        require_once APP_ROOT . '/mailer.php';
        try {
            $enviado = notificarCertificadoEmitido($conn, $userEmail, $userName, $tituloPrograma, $codCert, $targetUid, $itemId, $tipo);
            if ($enviado) {
                echo json_encode(['success' => true, 'message' => "Certificado en PDF enviado satisfactoriamente a $userEmail"]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "No se pudo entregar el correo con el certificado a $userEmail"]);
            }
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500);
            echo json_encode(['error' => 'Error al procesar el envío del certificado: ' . $e->getMessage()]);
        }
        break;
}
