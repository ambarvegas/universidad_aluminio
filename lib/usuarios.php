<?php
/**
 * lib/usuarios.php — Usuarios y autenticación
 * Login, contraseñas, perfil, alta/baja, paginación y tokens de acceso.
 * Se carga desde db_mysql.php.
 */

/**
 * Verifica login server-side. Retorna datos del usuario (sin clave) o null.
 * Soporta claves en texto plano (legadas) y bcrypt.
 */
function db_verify_login(mysqli $conn, string $id, string $clave): ?array {
    $stmt = $conn->prepare("SELECT id, nombre, clave, rol, estado, telefono, email, fecha_nacimiento FROM `usuarios` WHERE id = ? AND estado = 'activo'");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;

    $hash = $row['clave'];
    $valid = false;

    // 1. Probar password_verify directamente (cubre $2y$, $2a$, $2b$, Argon2, etc.)
    if (password_verify($clave, $hash)) {
        $valid = true;
    } elseif ($clave === $hash || $hash === '' || $hash === null) {
        // Clave legada en texto plano
        $valid = true;
        $newHash = password_hash($clave, PASSWORD_BCRYPT);
        $upd = $conn->prepare("UPDATE `usuarios` SET clave = ? WHERE id = ?");
        $upd->bind_param('ss', $newHash, $id);
        $upd->execute();
    } elseif (md5($clave) === $hash || sha1($clave) === $hash) {
        // Hashes legados MD5 o SHA1
        $valid = true;
        $newHash = password_hash($clave, PASSWORD_BCRYPT);
        $upd = $conn->prepare("UPDATE `usuarios` SET clave = ? WHERE id = ?");
        $upd->bind_param('ss', $newHash, $id);
        $upd->execute();
    }

    if (!$valid) return null;

    // Cargar directamente las relaciones del usuario para máxima velocidad
    $usuario = [
        'id'                  => $row['id'],
        'nombre'              => $row['nombre'],
        'rol'                 => $row['rol'],
        'estado'              => $row['estado'],
        'telefono'            => $row['telefono'] ?? '',
        'email'               => $row['email'] ?? '',
        'fecha_nacimiento'    => $row['fecha_nacimiento'] ?? '',
        'asignados'           => [],
        'carrerasAsignadas'   => [],
        'progreso'            => [],
        'certificadosCurso'   => [],
        'certificadosCarrera' => [],
    ];

    // Asignados directos
    $stmtAsig = $conn->prepare("SELECT curso_id FROM `usuario_asignados` WHERE usuario_id = ?");
    $stmtAsig->bind_param('s', $id);
    $stmtAsig->execute();
    $resAsig = $stmtAsig->get_result();
    while ($r = $resAsig->fetch_assoc()) $usuario['asignados'][] = $r['curso_id'];

    // Carreras asignadas
    $stmtCar = $conn->prepare("SELECT carrera_id, estado FROM `usuario_carreras_asignadas` WHERE usuario_id = ?");
    $stmtCar->bind_param('s', $id);
    $stmtCar->execute();
    $resCar = $stmtCar->get_result();
    while ($r = $resCar->fetch_assoc()) $usuario['carrerasAsignadas'][] = ['id' => $r['carrera_id'], 'estado' => $r['estado']];

    // Progreso de cursos (tablas normalizadas + reintentos habilitados)
    $usuario['progreso'] = db_leer_progreso($conn, $id)[$id] ?? (object)[];

    // Certificados Curso
    $stmtCC = $conn->prepare("SELECT curso_id FROM `usuario_certificados_curso` WHERE usuario_id = ?");
    $stmtCC->bind_param('s', $id);
    $stmtCC->execute();
    $resCC = $stmtCC->get_result();
    while ($r = $resCC->fetch_assoc()) $usuario['certificadosCurso'][] = $r['curso_id'];

    // Certificados Carrera
    $stmtCarC = $conn->prepare("SELECT carrera_id FROM `usuario_certificados_carrera` WHERE usuario_id = ?");
    $stmtCarC->bind_param('s', $id);
    $stmtCarC->execute();
    $resCarC = $stmtCarC->get_result();
    while ($r = $resCarC->fetch_assoc()) $usuario['certificadosCarrera'][] = $r['carrera_id'];

    return $usuario;
}

/**
 * Hashea todas las contraseñas en texto plano con bcrypt.
 * Retorna el número de contraseñas migradas.
 */
function db_hash_all_passwords(mysqli $conn): int {
    $res = $conn->query("SELECT id, clave FROM `usuarios`");
    $migrated = 0;
    while ($row = $res->fetch_assoc()) {
        if (!str_starts_with($row['clave'], '$2y$')) {
            $hash = password_hash($row['clave'], PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE `usuarios` SET clave = ? WHERE id = ?");
            $stmt->bind_param('ss', $hash, $row['id']);
            $stmt->execute();
            $migrated++;
        }
    }
    return $migrated;
}

/**
 * Inserta o actualiza un usuario completo (con todas sus relaciones).
 */
function db_upsert_usuario(mysqli $conn, array $u): void {
    $conn->begin_transaction();
    try {
        $id       = trim((string)($u['id']     ?? ''));
        $nombre   = trim((string)($u['nombre'] ?? ''));
        $rol      = trim((string)($u['rol']    ?? 'participante'));
        $estado   = trim((string)($u['estado'] ?? 'activo'));
        $telefono = trim((string)($u['telefono'] ?? ''));
        $email    = trim((string)($u['email']    ?? ''));
        $fechaNac = trim((string)($u['fecha_nacimiento'] ?? $u['fechaNacimiento'] ?? ''));
        if (!$id) { $conn->rollback(); return; }

        $safeId = $conn->real_escape_string($id);

        // Manejar clave — si viene como texto plano, hashear
        $claveActual = null;
        $resC = $conn->prepare("SELECT clave FROM `usuarios` WHERE id = ?");
        $resC->bind_param('s', $id);
        $resC->execute();
        $rowC = $resC->get_result()->fetch_assoc();
        if ($rowC) {
            $claveActual = $rowC['clave'];
        }

        $rawClave = trim((string)($u['clave'] ?? ''));
        if (!empty($rawClave)) {
            if (str_starts_with($rawClave, '$2y$')) {
                $nuevaClave = $rawClave;
            } else {
                $nuevaClave = password_hash($rawClave, PASSWORD_BCRYPT);
            }
        } else {
            $nuevaClave = $claveActual ?? password_hash('12345', PASSWORD_BCRYPT);
        }

        $stmt = $conn->prepare(
            "INSERT INTO `usuarios` (id, nombre, clave, rol, estado, telefono, email, fecha_nacimiento) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado), telefono=VALUES(telefono), email=VALUES(email), fecha_nacimiento=VALUES(fecha_nacimiento)"
        );
        $stmt->bind_param('ssssssss', $id, $nombre, $nuevaClave, $rol, $estado, $telefono, $email, $fechaNac);
        $stmt->execute();

        // Limpiar y reescribir solo relaciones de asignación directa de cursos y carreras
        $conn->query("DELETE FROM `usuario_asignados`           WHERE usuario_id = '$safeId'");
        $conn->query("DELETE FROM `usuario_carreras_asignadas`  WHERE usuario_id = '$safeId'");

        $stmtA = $conn->prepare("INSERT IGNORE INTO `usuario_asignados` (usuario_id, curso_id) VALUES (?,?)");
        foreach (($u['asignados'] ?? []) as $cId) {
            $cIdStr = (string)$cId;
            if ($cIdStr) {
                $stmtA->bind_param('ss', $id, $cIdStr);
                $stmtA->execute();
            }
        }

        $stmtCA = $conn->prepare("INSERT INTO `usuario_carreras_asignadas` (usuario_id, carrera_id, estado) VALUES (?,?,?)");
        foreach (($u['carrerasAsignadas'] ?? []) as $ca) {
            $caId = is_array($ca) ? ($ca['id'] ?? '') : $ca;
            $caEst = is_array($ca) ? ($ca['estado'] ?? 'Incompleta') : 'Incompleta';
            if (!$caId) continue;
            $stmtCA->bind_param('sss', $id, $caId, $caEst);
            $stmtCA->execute();
        }

        // Fusión de progreso (NO borra progreso previo existente en MySQL)
        $prog = $u['progreso'] ?? [];
        if (is_object($prog)) $prog = (array)$prog;
        if (!empty($prog)) {
            foreach ($prog as $cId => $p) {
                if (!empty($p) && is_array($p)) {
                    db_upsert_progreso($conn, $id, (string)$cId, $p);
                }
            }
        }

        // Certificados (preserva y agrega si no existen)
        $stmtCC = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
        foreach (($u['certificadosCurso'] ?? []) as $cId) {
            $cIdStr = (string)$cId;
            if ($cIdStr) {
                $stmtCC->bind_param('ss', $id, $cIdStr);
                $stmtCC->execute();
            }
        }

        $stmtCCar = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_carrera` (usuario_id, carrera_id) VALUES (?,?)");
        foreach (($u['certificadosCarrera'] ?? []) as $carId) {
            $carIdStr = (string)$carId;
            if ($carIdStr) {
                $stmtCCar->bind_param('ss', $id, $carIdStr);
                $stmtCCar->execute();
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Elimina un usuario y todos sus datos relacionados.
 */
function db_delete_usuario(mysqli $conn, string $id): void {
    $conn->begin_transaction();
    try {
        $safeId = $conn->real_escape_string($id);
        $conn->query("DELETE FROM `usuario_asignados`           WHERE usuario_id = '$safeId'");
        $conn->query("DELETE FROM `usuario_carreras_asignadas`  WHERE usuario_id = '$safeId'");
        $conn->query("DELETE FROM `usuario_certificados_curso`  WHERE usuario_id = '$safeId'");
        $conn->query("DELETE FROM `usuario_certificados_carrera` WHERE usuario_id = '$safeId'");
        $conn->query("DELETE FROM `solicitudes_cursos`          WHERE user_id = '$safeId'");
        $conn->query("DELETE FROM `solicitudes_registro`        WHERE id = '$safeId'");

        $stmt = $conn->prepare("DELETE FROM `usuarios` WHERE id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Cambia la contraseña de un usuario validando su contraseña actual.
 * Guarda la nueva clave siempre con bcrypt.
 */
function db_cambiar_clave(mysqli $conn, string $userId, string $claveActual, string $claveNueva): array {
    $claveActual = trim($claveActual);
    $claveNueva  = trim($claveNueva);

    if (strlen($claveNueva) < 4) {
        throw new InvalidArgumentException('La nueva contraseña debe tener al menos 4 caracteres.');
    }

    $stmt = $conn->prepare("SELECT id, clave FROM `usuarios` WHERE id = ?");
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('Usuario no encontrado.');
    }

    $hash = $row['clave'];
    $valid = false;
    if (str_starts_with($hash, '$2y$')) {
        $valid = password_verify($claveActual, $hash);
    } else {
        $valid = ($claveActual === $hash);
    }

    if (!$valid) {
        throw new InvalidArgumentException('La contraseña actual ingresada es incorrecta.');
    }

    $nuevoHash = password_hash($claveNueva, PASSWORD_BCRYPT);
    $stmtUpd = $conn->prepare("UPDATE `usuarios` SET clave = ? WHERE id = ?");
    $stmtUpd->bind_param('ss', $nuevoHash, $userId);
    $stmtUpd->execute();

    db_log_activity($conn, $userId, 'CAMBIO_CLAVE', 'Contraseña actualizada por el colaborador');

    return ['success' => true, 'message' => 'Contraseña actualizada exitosamente.'];
}

/**
 * Consulta paginada y filtrada de usuarios para el panel de administración.
 * Realiza una consulta eficiente con LIMIT/OFFSET y devuelve los metadatos de paginación.
 */
function db_read_usuarios_paginados(mysqli $conn, int $page = 1, int $limit = 25, string $search = '', string $rol = '', string $estado = ''): array {
    $page = max(1, $page);
    $limit = max(5, min(100, $limit));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];
    $types = '';

    if ($search !== '') {
        $searchWild = '%' . $search . '%';
        $where[] = "(id LIKE ? OR nombre LIKE ? OR email LIKE ? OR telefono LIKE ?)";
        $params[] = $searchWild;
        $params[] = $searchWild;
        $params[] = $searchWild;
        $params[] = $searchWild;
        $types .= 'ssss';
    }

    if ($rol !== '') {
        $where[] = "rol = ?";
        $params[] = $rol;
        $types .= 's';
    }

    if ($estado !== '') {
        $where[] = "estado = ?";
        $params[] = $estado;
        $types .= 's';
    }

    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    // 1. Contar total de registros coincidentes
    $countSql = "SELECT COUNT(*) as total FROM `usuarios` $whereSql";
    $stmtCount = $conn->prepare($countSql);
    if (!empty($params)) {
        $stmtCount->bind_param($types, ...$params);
    }
    $stmtCount->execute();
    $totalRows = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);

    // 2. Obtener usuarios de la página actual
    $sql = "SELECT id, nombre, rol, estado, telefono, email, fecha_nacimiento FROM `usuarios` $whereSql ORDER BY nombre ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $paramsWithLimit = $params;
    $paramsWithLimit[] = $limit;
    $paramsWithLimit[] = $offset;
    $typesWithLimit = $types . 'ii';
    $stmt->bind_param($typesWithLimit, ...$paramsWithLimit);
    $stmt->execute();
    $res = $stmt->get_result();

    $usuariosMap = [];
    $userIds = [];
    while ($row = $res->fetch_assoc()) {
        $row['asignados']           = [];
        $row['carrerasAsignadas']   = [];
        $row['progreso']            = (object)[];
        $row['certificadosCurso']   = [];
        $row['certificadosCarrera'] = [];
        $usuariosMap[$row['id']] = $row;
        $userIds[] = $row['id'];
    }

    // 3. Si hay usuarios en la página, enriquecer con asignaciones y certificados
    if (!empty($userIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        $inTypes = str_repeat('s', count($userIds));

        // Carreras asignadas
        $stmtCar = $conn->prepare("SELECT usuario_id, carrera_id, estado FROM `usuario_carreras_asignadas` WHERE usuario_id IN ($inPlaceholders)");
        $stmtCar->bind_param($inTypes, ...$userIds);
        $stmtCar->execute();
        $resCar = $stmtCar->get_result();
        while ($r = $resCar->fetch_assoc()) {
            if (isset($usuariosMap[$r['usuario_id']])) {
                $usuariosMap[$r['usuario_id']]['carrerasAsignadas'][] = [
                    'id'     => $r['carrera_id'],
                    'estado' => $r['estado']
                ];
            }
        }

        // Cursos asignados directamente
        $stmtAsig = $conn->prepare("SELECT usuario_id, curso_id FROM `usuario_asignados` WHERE usuario_id IN ($inPlaceholders)");
        $stmtAsig->bind_param($inTypes, ...$userIds);
        $stmtAsig->execute();
        $resAsig = $stmtAsig->get_result();
        while ($r = $resAsig->fetch_assoc()) {
            if (isset($usuariosMap[$r['usuario_id']])) {
                $usuariosMap[$r['usuario_id']]['asignados'][] = $r['curso_id'];
            }
        }

        // Certificados de curso
        $stmtCert = $conn->prepare("SELECT usuario_id, curso_id FROM `usuario_certificados_curso` WHERE usuario_id IN ($inPlaceholders)");
        $stmtCert->bind_param($inTypes, ...$userIds);
        $stmtCert->execute();
        $resCert = $stmtCert->get_result();
        while ($r = $resCert->fetch_assoc()) {
            if (isset($usuariosMap[$r['usuario_id']])) {
                $usuariosMap[$r['usuario_id']]['certificadosCurso'][] = $r['curso_id'];
            }
        }

        // Certificados de carrera
        $stmtCertCar = $conn->prepare("SELECT usuario_id, carrera_id FROM `usuario_certificados_carrera` WHERE usuario_id IN ($inPlaceholders)");
        $stmtCertCar->bind_param($inTypes, ...$userIds);
        $stmtCertCar->execute();
        $resCertCar = $stmtCertCar->get_result();
        while ($r = $resCertCar->fetch_assoc()) {
            if (isset($usuariosMap[$r['usuario_id']])) {
                $usuariosMap[$r['usuario_id']]['certificadosCarrera'][] = $r['carrera_id'];
            }
        }
    }

    return [
        'usuarios'   => array_values($usuariosMap),
        'total'      => $totalRows,
        'page'       => $page,
        'limit'      => $limit,
        'totalPages' => (int)ceil($totalRows / $limit) ?: 1
    ];
}

/**
 * Permite a un colaborador actualizar sus datos de perfil (teléfono, correo, fecha de nacimiento y nombre).
 */
function db_actualizar_perfil(mysqli $conn, string $userId, array $datos): array {
    $userId = trim($userId);
    if (!$userId) throw new InvalidArgumentException("ID de usuario requerido.");

    $telefono = trim((string)($datos['telefono'] ?? ''));
    $email    = trim((string)($datos['email'] ?? ''));
    $fechaNac = trim((string)($datos['fecha_nacimiento'] ?? $datos['fechaNacimiento'] ?? ''));
    $nombre   = isset($datos['nombre']) ? trim((string)$datos['nombre']) : null;

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("El formato del correo electrónico es inválido.");
    }

    if ($nombre !== null && $nombre !== '') {
        $stmt = $conn->prepare("UPDATE `usuarios` SET nombre = ?, telefono = ?, email = ?, fecha_nacimiento = ? WHERE id = ?");
        $stmt->bind_param('sssss', $nombre, $telefono, $email, $fechaNac, $userId);
    } else {
        $stmt = $conn->prepare("UPDATE `usuarios` SET telefono = ?, email = ?, fecha_nacimiento = ? WHERE id = ?");
        $stmt->bind_param('ssss', $telefono, $email, $fechaNac, $userId);
    }
    $stmt->execute();

    $stmtGet = $conn->prepare("SELECT id, nombre, rol, estado, telefono, email, fecha_nacimiento FROM `usuarios` WHERE id = ?");
    $stmtGet->bind_param('s', $userId);
    $stmtGet->execute();
    $row = $stmtGet->get_result()->fetch_assoc();

    return $row ?: [
        'id'               => $userId,
        'nombre'           => $nombre ?: $userId,
        'telefono'         => $telefono,
        'email'            => $email,
        'fecha_nacimiento' => $fechaNac
    ];
}

/**
 * Genera un token criptográfico seguro de acceso/restablecimiento/invitación para un usuario.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param string $usuarioId C.I. del usuario
 * @param string $tipo Tipo de token ('reset' o 'invitacion')
 * @param int $horasValidez Duración de validez en horas
 * @return string Token generado (64 caracteres hex)
 */
function db_crear_token_acceso(mysqli $conn, string $usuarioId, string $tipo = 'reset', int $horasValidez = 48): string {
    $usuarioId = trim($usuarioId);
    if (!$usuarioId) throw new InvalidArgumentException("ID de usuario requerido.");

    // Validar existencia del usuario
    $stmtU = $conn->prepare("SELECT id FROM `usuarios` WHERE id = ?");
    $stmtU->bind_param('s', $usuarioId);
    $stmtU->execute();
    if (!$stmtU->get_result()->fetch_assoc()) {
        throw new InvalidArgumentException("Usuario con cédula $usuarioId no encontrado.");
    }

    // Invalidar tokens previos no utilizados del mismo tipo
    $stmtInv = $conn->prepare("UPDATE `tokens_acceso` SET usado = 1 WHERE usuario_id = ? AND tipo = ? AND usado = 0");
    $stmtInv->bind_param('ss', $usuarioId, $tipo);
    $stmtInv->execute();

    // Generar token criptográfico
    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', time() + ($horasValidez * 3600));

    $stmtIns = $conn->prepare("INSERT INTO `tokens_acceso` (token, usuario_id, tipo, expira, usado, creado_en) VALUES (?, ?, ?, ?, 0, NOW())");
    $stmtIns->bind_param('ssss', $token, $usuarioId, $tipo, $expira);
    $stmtIns->execute();

    return $token;
}

/**
 * Valida un token de acceso y retorna los datos del usuario asociado si es válido.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param string $token Token de 64 caracteres
 * @return array Estado de validez y detalles del usuario
 */
function db_validar_token_acceso(mysqli $conn, string $token): array {
    $token = trim($token);
    if (!$token || strlen($token) !== 64) {
        return ['valid' => false, 'error' => 'El enlace o token de acceso no es válido.'];
    }

    $stmt = $conn->prepare("SELECT t.token, t.usuario_id, t.tipo, t.expira, t.usado, u.nombre, u.email, u.rol, u.estado 
                            FROM `tokens_acceso` t 
                            JOIN `usuarios` u ON u.id = t.usuario_id 
                            WHERE t.token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return ['valid' => false, 'error' => 'El enlace de acceso no existe o no es válido.'];
    }

    if ((int)$row['usado'] === 1) {
        return ['valid' => false, 'error' => 'Este enlace ya ha sido utilizado anteriormente. Solicita uno nuevo.'];
    }

    if (strtotime($row['expira']) < time()) {
        return ['valid' => false, 'error' => 'Este enlace de acceso ha expirado. Por favor, solicita uno nuevo.'];
    }

    return [
        'valid'      => true,
        'token'      => $token,
        'usuario_id' => $row['usuario_id'],
        'tipo'       => $row['tipo'],
        'expira'     => $row['expira'],
        'nombre'     => $row['nombre'],
        'email'      => $row['email'],
        'rol'        => $row['rol'],
        'estado'     => $row['estado'],
    ];
}

/**
 * Consume el token y actualiza la contraseña del usuario (bcrypt).
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param string $token Token de 64 caracteres
 * @param string $nuevaClave Nueva contraseña en texto plano
 * @return array Resultado de la operación
 */
function db_consumir_token_acceso(mysqli $conn, string $token, string $nuevaClave): array {
    $val = db_validar_token_acceso($conn, $token);
    if (!$val['valid']) {
        throw new InvalidArgumentException($val['error']);
    }

    $nuevaClave = trim($nuevaClave);
    if (strlen($nuevaClave) < 4) {
        throw new InvalidArgumentException("La contraseña debe tener al menos 4 caracteres.");
    }

    $userId = $val['usuario_id'];
    $hash = password_hash($nuevaClave, PASSWORD_BCRYPT);

    // Actualizar contraseña y activar usuario si estaba inactivo/pendiente
    $stmtUpU = $conn->prepare("UPDATE `usuarios` SET clave = ?, estado = 'activo' WHERE id = ?");
    $stmtUpU->bind_param('ss', $hash, $userId);
    $stmtUpU->execute();

    // Marcar token como consumido
    $stmtUpT = $conn->prepare("UPDATE `tokens_acceso` SET usado = 1, usado_en = NOW() WHERE token = ?");
    $stmtUpT->bind_param('s', $token);
    $stmtUpT->execute();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    db_log_activity($conn, $userId, 'RESTABLECER_CLAVE_TOKEN', "Tipo: {$val['tipo']}", $ip);

    return [
        'success'    => true,
        'usuario_id' => $userId,
        'nombre'     => $val['nombre'],
        'tipo'       => $val['tipo'],
        'message'    => 'Contraseña actualizada correctamente.'
    ];
}
