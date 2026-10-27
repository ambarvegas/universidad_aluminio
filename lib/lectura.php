<?php
/**
 * lib/lectura.php — Lectura de datos
 * Progreso y carga completa (panel admin). Lo que lee el campus está en lib/campus.php.
 * Se carga desde db_mysql.php.
 */

/**
 * Progreso de cursos desde las tablas normalizadas (única fuente de verdad).
 * Retorna [usuario_id => [curso_id => progreso]] con el formato que consume el frontend:
 * leccionesCompletadas, modulosAprobados, medallas, evaluaciones, intentos, más
 * intentosExtra/habilitaciones cuando existen reintentos habilitados.
 * Con $userId se limita a ese usuario.
 */
function db_leer_progreso(mysqli $conn, ?string $userId = null): array {
    $vacio = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => [], 'intentos' => []];
    $prog = [];

    $consultar = function (string $sql) use ($conn, $userId) {
        if ($userId === null) return $conn->query($sql);
        $stmt = $conn->prepare($sql . ' WHERE usuario_id = ?');
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        return $stmt->get_result();
    };

    $listas = [
        'leccionesCompletadas' => "SELECT usuario_id, curso_id, leccion_codigo AS v FROM `usuario_lecciones_completadas`",
        'modulosAprobados'     => "SELECT usuario_id, curso_id, modulo_num AS v FROM `usuario_modulos_aprobados`",
        'medallas'             => "SELECT usuario_id, curso_id, medalla_num AS v FROM `usuario_medallas`",
    ];
    foreach ($listas as $campo => $sql) {
        $res = $consultar($sql);
        while ($res && $r = $res->fetch_assoc()) {
            $prog[$r['usuario_id']][$r['curso_id']] ??= $vacio;
            $prog[$r['usuario_id']][$r['curso_id']][$campo][] = $r['v'];
        }
    }

    $res = $consultar("SELECT usuario_id, curso_id, modulo_num, calificacion, aprobado, marcado_manual, fecha FROM `usuario_evaluaciones`");
    while ($res && $r = $res->fetch_assoc()) {
        $prog[$r['usuario_id']][$r['curso_id']] ??= $vacio;
        $eItem = ['calificacion' => (float)$r['calificacion'], 'aprobado' => (bool)$r['aprobado']];
        if (!empty($r['marcado_manual'])) $eItem['marcadoManual'] = true;
        if (!empty($r['fecha'])) $eItem['fecha'] = $r['fecha'];
        $prog[$r['usuario_id']][$r['curso_id']]['evaluaciones'][$r['modulo_num']] = $eItem;
    }

    $res = $consultar("SELECT usuario_id, curso_id, modulo_num, intentos FROM `usuario_intentos`");
    while ($res && $r = $res->fetch_assoc()) {
        $prog[$r['usuario_id']][$r['curso_id']] ??= $vacio;
        $prog[$r['usuario_id']][$r['curso_id']]['intentos'][$r['modulo_num']] = (int)$r['intentos'];
    }

    // Mapas vacíos como objeto JSON ({}), no como arreglo ([])
    foreach ($prog as &$cursosUsuario) {
        foreach ($cursosUsuario as &$p) {
            $p['evaluaciones'] = (object)$p['evaluaciones'];
            $p['intentos']     = (object)$p['intentos'];
        }
        unset($p);
    }
    unset($cursosUsuario);

    // Reintentos habilitados por administración (intentos extra + historial para reportes)
    foreach (db_habilitaciones_map($conn, $userId) as $uid => $habUsuario) {
        if (isset($prog[$uid])) {
            $prog[$uid] = db_habilitaciones_aplicar_a_progreso($prog[$uid], $habUsuario);
        }
    }

    return $prog;
}

/**
 * Lee todos los datos de MySQL y los retorna como un array
 * con la misma estructura que tenía db.json (compatibilidad con frontend).
 * Lee directamente de las tablas relacionales normalizadas.
 */
function db_read_all(mysqli $conn, bool $conProgreso = true): array {
    $db = [
        'usuarios'            => [],
        'cursos'              => [],
        'carreras'            => [],
        'rolesConfig'         => [],
        'solicitudesRegistro' => [],
        'solicitudesCursos'   => [],
        'configuracion'       => ['nombreInstitucion' => 'Universidad del Aluminio', 'logo' => '', 'minAprobacion' => 70],
    ];

    // --- Usuarios ---
    $res = $conn->query("SELECT id, nombre, clave, rol, estado, telefono, email, fecha_nacimiento FROM `usuarios`");
    $usuariosMap = [];
    while ($row = $res->fetch_assoc()) {
        $row['asignados']              = [];
        $row['carrerasAsignadas']      = [];
        $row['progreso']               = [];
        $row['certificadosCurso']      = [];
        $row['certificadosCarrera']    = [];
        $usuariosMap[$row['id']] = $row;
    }

    // Asignados directos
    $res = $conn->query("SELECT usuario_id, curso_id FROM `usuario_asignados`");
    while ($row = $res->fetch_assoc()) {
        if (isset($usuariosMap[$row['usuario_id']])) {
            $usuariosMap[$row['usuario_id']]['asignados'][] = $row['curso_id'];
        }
    }

    // Carreras asignadas
    $res = $conn->query("SELECT usuario_id, carrera_id, estado FROM `usuario_carreras_asignadas`");
    while ($row = $res->fetch_assoc()) {
        if (isset($usuariosMap[$row['usuario_id']])) {
            $usuariosMap[$row['usuario_id']]['carrerasAsignadas'][] = [
                'id'     => $row['carrera_id'],
                'estado' => $row['estado'],
            ];
        }
    }

    // Progreso de cursos (tablas normalizadas + reintentos habilitados). Sin progreso, el
    // cliente lo pide después por usuario (acción progreso_usuarios).
    if ($conProgreso) {
        foreach (db_leer_progreso($conn) as $uid => $progUsuario) {
            if (isset($usuariosMap[$uid])) {
                $usuariosMap[$uid]['progreso'] = $progUsuario;
            }
        }
    } else {
        $db['progresoIncluido'] = false;
    }

    // Formatear progreso vacío como objeto para JSON
    foreach ($usuariosMap as &$uRef) {
        if (empty($uRef['progreso'])) {
            $uRef['progreso'] = (object)[];
        }
    }
    unset($uRef);

    // Certificados de cursos
    $res = $conn->query("SELECT usuario_id, curso_id FROM `usuario_certificados_curso`");
    while ($row = $res->fetch_assoc()) {
        if (isset($usuariosMap[$row['usuario_id']])) {
            $usuariosMap[$row['usuario_id']]['certificadosCurso'][] = $row['curso_id'];
        }
    }

    // Certificados de carreras
    $res = $conn->query("SELECT usuario_id, carrera_id FROM `usuario_certificados_carrera`");
    while ($row = $res->fetch_assoc()) {
        if (isset($usuariosMap[$row['usuario_id']])) {
            $usuariosMap[$row['usuario_id']]['certificadosCarrera'][] = $row['carrera_id'];
        }
    }

    $db['usuarios'] = array_values($usuariosMap);

    // --- Cursos (desde tablas relacionales normalizadas) ---
    // Pre-cargar módulos
    $cursosModulosMap = [];
    $moduloToCursoMap = [];
    $resCM = $conn->query("SELECT id, curso_id, orden, titulo, max_intentos, eval_tipo, eval_num_preguntas, eval_mezclar_opciones FROM `curso_modulos` ORDER BY curso_id, orden ASC");
    if ($resCM) {
        while ($r = $resCM->fetch_assoc()) {
            $mid = (int)$r['id'];
            $cid = $r['curso_id'];
            $moduloToCursoMap[$mid] = $cid;
            $cursosModulosMap[$cid][$mid] = [
                '_orden'      => (int)$r['orden'],
                'titulo'      => $r['titulo'],
                'maxIntentos' => (int)($r['max_intentos'] ?? 0),
                'lecciones'   => [],
                'evaluacion'  => [
                    'tipo'            => $r['eval_tipo'] ?? 'fijo',
                    'numPreguntas'    => (int)($r['eval_num_preguntas'] ?? 0),
                    'mezclarOpciones' => !empty($r['eval_mezclar_opciones']),
                    'preguntas'       => [],
                ],
            ];
        }
    }

    // Pre-cargar lecciones
    $resCL = $conn->query("SELECT modulo_id, orden, titulo, video_id, contenido, adjunto FROM `curso_lecciones` ORDER BY modulo_id, orden ASC");
    if ($resCL) {
        while ($r = $resCL->fetch_assoc()) {
            $mid = (int)$r['modulo_id'];
            $cid = $moduloToCursoMap[$mid] ?? null;
            if ($cid !== null && isset($cursosModulosMap[$cid][$mid])) {
                $lec = ['titulo' => $r['titulo'], 'videoID' => $r['video_id'] ?? ''];
                if (!empty($r['contenido'])) $lec['contenido'] = $r['contenido'];
                if (!empty($r['adjunto']))   $lec['adjunto']   = $r['adjunto'];
                $cursosModulosMap[$cid][$mid]['lecciones'][] = $lec;
            }
        }
    }

    // Pre-cargar preguntas
    $resCP = $conn->query("SELECT id, modulo_id, orden, enunciado, imagen, opciones, correcta, retro_correcta, retro_incorrecta FROM `curso_preguntas` ORDER BY modulo_id, orden ASC");
    if ($resCP) {
        while ($r = $resCP->fetch_assoc()) {
            $mid = (int)$r['modulo_id'];
            $cid = $moduloToCursoMap[$mid] ?? null;
            if ($cid !== null && isset($cursosModulosMap[$cid][$mid])) {
                $cursosModulosMap[$cid][$mid]['evaluacion']['preguntas'][] = [
                    'id'        => (int)$r['id'],
                    'enunciado' => $r['enunciado'],
                    'imagen'    => $r['imagen'] ?? '',
                    'opciones'  => json_decode($r['opciones'] ?? '[]', true) ?? [],
                    'correcta'  => (int)$r['correcta'],
                    'retroCorrecta'   => $r['retro_correcta'] ?? '',
                    'retroIncorrecta' => $r['retro_incorrecta'] ?? '',
                ];
            }
        }
    }

    // Leer cursos y armar el objeto final
    $resCursos = $conn->query("SELECT id, titulo, descripcion, tipo, imagen, prelacion, en_construccion FROM `cursos`");
    if ($resCursos) {
        while ($row = $resCursos->fetch_assoc()) {
            $cid = $row['id'];
            // Reconstruir array modulos en orden
            $modsRaw = $cursosModulosMap[$cid] ?? [];
            uasort($modsRaw, fn($a, $b) => ($a['_orden'] ?? 0) <=> ($b['_orden'] ?? 0));
            $modulosArr = [];
            foreach ($modsRaw as $mod) {
                unset($mod['_orden']);
                $modulosArr[] = $mod;
            }
            $row['modulos']       = $modulosArr;
            $row['enConstruccion'] = !empty($row['en_construccion']);
            $row['descripcion']    = $row['descripcion'] ?? '';
            if (!$row['prelacion']) unset($row['prelacion']);
            unset($row['en_construccion']);
            $db['cursos'][] = $row;
        }
    }

    // --- Carreras (desde carrera_cursos normalizado) ---
    $carrerasCursosMap = [];
    $resCC = $conn->query("SELECT carrera_id, curso_id FROM `carrera_cursos` ORDER BY orden ASC");
    if ($resCC) {
        while ($r = $resCC->fetch_assoc()) {
            $carrerasCursosMap[$r['carrera_id']][] = $r['curso_id'];
        }
    }

    $res = $conn->query("SELECT id, nombre FROM `carreras`");
    while ($row = $res->fetch_assoc()) {
        $row['cursos'] = $carrerasCursosMap[$row['id']] ?? [];
        $db['carreras'][] = $row;
    }

    // --- Roles Config (con rol_permisos, rol_cursos, rol_carreras normalizados) ---
    $rolPermisosMap = [];
    $resRP = $conn->query("SELECT rol_id, permiso FROM `rol_permisos`");
    if ($resRP) {
        while ($r = $resRP->fetch_assoc()) $rolPermisosMap[$r['rol_id']][] = $r['permiso'];
    }

    $rolCursosMap = [];
    $resRC = $conn->query("SELECT rol_id, curso_id FROM `rol_cursos`");
    if ($resRC) {
        while ($r = $resRC->fetch_assoc()) $rolCursosMap[$r['rol_id']][] = $r['curso_id'];
    }

    $rolCarrerasMap = [];
    $resRCar = $conn->query("SELECT rol_id, carrera_id FROM `rol_carreras`");
    if ($resRCar) {
        while ($r = $resRCar->fetch_assoc()) $rolCarrerasMap[$r['rol_id']][] = $r['carrera_id'];
    }

    $res = $conn->query("SELECT id, nombre FROM `roles_config`");
    while ($row = $res->fetch_assoc()) {
        $rId = $row['id'];
        $row['permisos']  = $rolPermisosMap[$rId]  ?? [];
        $row['cursos']    = $rolCursosMap[$rId]    ?? [];
        $row['carreras']  = $rolCarrerasMap[$rId]  ?? [];
        $db['rolesConfig'][] = $row;
    }

    // --- Solicitudes de Registro ---
    $res = $conn->query("SELECT id, nombre, clave, perfil_deseado AS perfilDeseado, fecha, auto_assign_career_id AS autoAssignCareerId FROM `solicitudes_registro`");
    while ($row = $res->fetch_assoc()) {
        if (!$row['autoAssignCareerId']) unset($row['autoAssignCareerId']);
        $db['solicitudesRegistro'][] = $row;
    }

    // --- Solicitudes de Cursos ---
    $res = $conn->query("SELECT user_id AS userId, user_name AS userName, curso_id AS cursoId, fecha FROM `solicitudes_cursos`");
    while ($row = $res->fetch_assoc()) {
        $db['solicitudesCursos'][] = $row;
    }

    // --- Configuración ---
    $res = $conn->query("SELECT clave, valor FROM `configuracion`");
    while ($row = $res->fetch_assoc()) {
        $clave = $row['clave'];
        $valor = $row['valor'];
        // Intentar decodificar como JSON (por si es número o booleano)
        $decoded = json_decode($valor, true);
        $db['configuracion'][$clave] = ($decoded !== null) ? $decoded : $valor;
    }

    return $db;
}

/**
 * Modifica db_read_all para que NO incluya claves en la respuesta.
 * Llama a db_read_all y limpia las claves.
 */
/**
 * Configuración apta para usuarios no administradores: excluye credenciales SMTP y
 * datos de contacto internos que solo necesita el panel de administración.
 */
function db_config_publica($config): array {
    $config = is_array($config) ? $config : (array)$config;
    foreach (array_keys($config) as $clave) {
        if (str_starts_with((string)$clave, 'smtp_') || $clave === 'email_admin') {
            unset($config[$clave]);
        }
    }
    return $config;
}

function db_read_safe(mysqli $conn, bool $conProgreso = true): array {
    $data = db_read_all($conn, $conProgreso);
    foreach ($data['usuarios'] as &$u) {
        unset($u['clave']);
    }
    return $data;
}

