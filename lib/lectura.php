<?php
/**
 * lib/lectura.php — Lectura de datos
 * Progreso, carga completa (admin), carga del participante, catálogo del campus y detalle de curso.
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
    $resCP = $conn->query("SELECT id, modulo_id, orden, enunciado, imagen, opciones, correcta FROM `curso_preguntas` ORDER BY modulo_id, orden ASC");
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

/**
 * Construye la DB filtrada para un participante.
 * Solo recibe: sus propios datos + cursos y carreras accesibles + su rol config.
 * Sanitiza las evaluaciones removiendo 'correcta' para evitar trampas en el frontend.
 */
function db_read_for_participant(mysqli $conn, string $userId, string $userRol): array {
    $full = db_read_all($conn);

    // Solo su usuario (sin clave)
    $miUsuario = null;
    foreach ($full['usuarios'] as $u) {
        if ($u['id'] === $userId) {
            $miUsuario = $u;
            unset($miUsuario['clave']);
            break;
        }
    }

    // Cursos accesibles: los asignados directamente + los del rol
    $asignados = $miUsuario['asignados'] ?? [];
    $rolConfig  = null;
    foreach ($full['rolesConfig'] as $r) {
        if ($r['id'] === $userRol) {
            $rolConfig = $r;
            break;
        }
    }
    $cursosRol = $rolConfig ? ($rolConfig['cursos'] ?? []) : [];
    $cursosIds = array_unique(array_merge($asignados, $cursosRol));

    // Cursos del catálogo que puede ver
    $cursosFiltrados = [];
    foreach ($full['cursos'] as $c) {
        if (in_array($c['id'], $cursosIds, true) || ($rolConfig && ($rolConfig['permisos'][0] ?? '') === '*')) {
            $cursosFiltrados[] = $c;
        }
    }

    // Carreras accesibles por el rol
    $carrerasRol = $rolConfig ? ($rolConfig['carreras'] ?? []) : [];
    $carrerasFiltradas = [];
    foreach ($full['carreras'] as $c) {
        if (in_array($c['id'], $carrerasRol, true)) {
            $carrerasFiltradas[] = $c;
        }
    }

    // Sanitizar preguntas: los participantes no deben recibir el campo 'correcta'
    foreach ($cursosFiltrados as &$c) {
        if (!empty($c['modulos']) && is_array($c['modulos'])) {
            foreach ($c['modulos'] as &$m) {
                if (!empty($m['evaluacion']['preguntas']) && is_array($m['evaluacion']['preguntas'])) {
                    foreach ($m['evaluacion']['preguntas'] as &$p) {
                        unset($p['correcta']);
                    }
                    unset($p);
                }
            }
            unset($m);
        }
    }
    unset($c);

    return [
        'usuarios'            => $miUsuario ? [$miUsuario] : [],
        'cursos'              => $cursosFiltrados,
        'carreras'            => $carrerasFiltradas,
        'rolesConfig'         => $rolConfig ? [$rolConfig] : [],
        'solicitudesRegistro' => [],
        'solicitudesCursos'   => [],
        'configuracion'       => db_config_publica($full['configuracion']),
    ];
}

/**
 * Retorna el catálogo ligero para la vista principal / dashboard del estudiante.
 * Omite las lecciones detalladas y el banco de preguntas, reduciendo el payload en un 95%.
 */
function db_read_catalogo(mysqli $conn, string $userId, string $userRol): array {
    // 1. Obtener usuario autenticado
    $stmtU = $conn->prepare("SELECT id, nombre, rol, estado, telefono, email, fecha_nacimiento FROM `usuarios` WHERE id = ?");
    $stmtU->bind_param('s', $userId);
    $stmtU->execute();
    $uRes = $stmtU->get_result();
    $miUsuario = $uRes ? $uRes->fetch_assoc() : null;
    if ($miUsuario) {
        $miUsuario['asignados']           = [];
        $miUsuario['carrerasAsignadas']   = [];
        $miUsuario['progreso']            = (object)[];
        $miUsuario['certificadosCurso']   = [];
        $miUsuario['certificadosCarrera'] = [];

        // Asignados directos
        $stmtAs = $conn->prepare("SELECT curso_id FROM `usuario_asignados` WHERE usuario_id = ?");
        $stmtAs->bind_param('s', $userId);
        $stmtAs->execute();
        $rAs = $stmtAs->get_result();
        while ($row = $rAs->fetch_assoc()) {
            $miUsuario['asignados'][] = $row['curso_id'];
        }

        // Carreras asignadas
        $stmtCa = $conn->prepare("SELECT carrera_id, estado FROM `usuario_carreras_asignadas` WHERE usuario_id = ?");
        $stmtCa->bind_param('s', $userId);
        $stmtCa->execute();
        $rCa = $stmtCa->get_result();
        while ($row = $rCa->fetch_assoc()) {
            $miUsuario['carrerasAsignadas'][] = [
                'id'     => $row['carrera_id'],
                'estado' => $row['estado']
            ];
        }

        // Progreso de cursos (tablas normalizadas + reintentos habilitados)
        $miUsuario['progreso'] = db_leer_progreso($conn, $userId)[$userId] ?? (object)[];

        // Certificados
        $stmtCc = $conn->prepare("SELECT curso_id FROM `usuario_certificados_curso` WHERE usuario_id = ?");
        $stmtCc->bind_param('s', $userId);
        $stmtCc->execute();
        $rCc = $stmtCc->get_result();
        while ($row = $rCc->fetch_assoc()) {
            $miUsuario['certificadosCurso'][] = $row['curso_id'];
        }

        $stmtCca = $conn->prepare("SELECT carrera_id FROM `usuario_certificados_carrera` WHERE usuario_id = ?");
        $stmtCca->bind_param('s', $userId);
        $stmtCca->execute();
        $rCca = $stmtCca->get_result();
        while ($row = $rCca->fetch_assoc()) {
            $miUsuario['certificadosCarrera'][] = $row['carrera_id'];
        }
    }

    // 2. Roles config (desde tablas normalizadas roles_config)
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

    $resR = $conn->query("SELECT id, nombre FROM `roles_config`");
    $rolesConfig = [];
    $miRolConfig = null;
    if ($resR) {
        while ($row = $resR->fetch_assoc()) {
            $rId = $row['id'];
            $row['permisos'] = $rolPermisosMap[$rId] ?? [];
            $row['cursos']   = $rolCursosMap[$rId]   ?? [];
            $row['carreras'] = $rolCarrerasMap[$rId] ?? [];
            $rolesConfig[]   = $row;
            if ($rId === $userRol) {
                $miRolConfig = $row;
            }
        }
    }

    // Carreras permitidas (por rol + asignadas directamente al usuario)
    $carrerasRol = $miRolConfig ? ($miRolConfig['carreras'] ?? []) : [];
    $carrerasUsuario = [];
    foreach (($miUsuario['carrerasAsignadas'] ?? []) as $cu) {
        if (!empty($cu['id'])) $carrerasUsuario[] = $cu['id'];
    }
    $carrerasPermitidas = array_unique(array_merge($carrerasRol, $carrerasUsuario));

    // Mapeo carrera -> cursos
    $carrerasCursosMap = [];
    $resCC = $conn->query("SELECT carrera_id, curso_id FROM `carrera_cursos` ORDER BY carrera_id, orden ASC");
    if ($resCC) {
        while ($ccr = $resCC->fetch_assoc()) {
            $carrerasCursosMap[$ccr['carrera_id']][] = $ccr['curso_id'];
        }
    }

    $cursosDeCarreras = [];
    foreach ($carrerasPermitidas as $carId) {
        if (!empty($carrerasCursosMap[$carId])) {
            $cursosDeCarreras = array_merge($cursosDeCarreras, $carrerasCursosMap[$carId]);
        }
    }

    $asignados = $miUsuario['asignados'] ?? [];
    $cursosRol = $miRolConfig ? ($miRolConfig['cursos'] ?? []) : [];
    $cursosIds = array_unique(array_merge($asignados, $cursosRol, $cursosDeCarreras));
    $esSuperRol = ($miRolConfig && in_array('*', $miRolConfig['permisos'] ?? [], true));

    // 3. Módulos agregados (con conteo de lecciones y preguntas)
    $sqlMod = "SELECT cm.id, cm.curso_id, cm.orden, cm.titulo, cm.max_intentos, cm.eval_tipo, cm.eval_num_preguntas, cm.eval_mezclar_opciones,
                      COUNT(DISTINCT cl.id) as total_lecciones,
                      COUNT(DISTINCT cp.id) as total_preguntas
               FROM `curso_modulos` cm
               LEFT JOIN `curso_lecciones` cl ON cl.modulo_id = cm.id
               LEFT JOIN `curso_preguntas` cp ON cp.modulo_id = cm.id
               GROUP BY cm.id
               ORDER BY cm.curso_id, cm.orden ASC";
    $resMod = $conn->query($sqlMod);
    $modulosPorCurso = [];
    if ($resMod) {
        while ($mr = $resMod->fetch_assoc()) {
            $cid = $mr['curso_id'];
            $modulosPorCurso[$cid][] = [
                'id'                  => (int)$mr['id'],
                '_orden'              => (int)$mr['orden'],
                'titulo'              => $mr['titulo'],
                'maxIntentos'         => (int)($mr['max_intentos'] ?? 0),
                'evalTipo'            => $mr['eval_tipo'] ?? 'fijo',
                'evalNumPreguntas'    => (int)($mr['eval_num_preguntas'] ?? 0),
                'evalMezclarOpciones' => !empty($mr['eval_mezclar_opciones']),
                'totalLecciones'      => (int)$mr['total_lecciones'],
                'tieneEvaluacion'     => ((int)$mr['total_preguntas'] > 0),
                'totalPreguntas'      => (int)$mr['total_preguntas'],
                'lecciones'           => array_fill(0, (int)$mr['total_lecciones'], null),
            ];
        }
    }

    // 4. Cursos accesibles
    $resC = $conn->query("SELECT id, titulo, descripcion, tipo, imagen, prelacion, en_construccion FROM `cursos`");
    $cursosFiltrados = [];
    while ($row = $resC->fetch_assoc()) {
        $cid = $row['id'];
        $tipo = $row['tipo'] ?? 'especializado';
        // Cursos de tipo 'pruebas' son exclusivos para administradores
        if ($tipo === 'pruebas' && !$esSuperRol) {
            continue;
        }
        if ($esSuperRol || in_array($cid, $cursosIds, true)) {
            $mods = $modulosPorCurso[$cid] ?? [];
            $totalLec = 0;
            foreach ($mods as $m) {
                $totalLec += $m['totalLecciones'];
            }
            $cursosFiltrados[] = [
                'id'             => $row['id'],
                'titulo'         => $row['titulo'],
                'descripcion'    => $row['descripcion'],
                'tipo'           => $row['tipo'],
                'imagen'         => $row['imagen'],
                'prelacion'      => $row['prelacion'],
                'enConstruccion' => (bool)$row['en_construccion'],
                'totalModulos'   => count($mods),
                'totalLecciones' => $totalLec,
                'modulos'        => $mods,
                '_esResumen'     => true,
            ];
        }
    }

    // 5. Carreras
    $resCar = $conn->query("SELECT id, nombre FROM `carreras`");
    $carrerasFiltradas = [];
    if ($resCar) {
        while ($car = $resCar->fetch_assoc()) {
            $carId = $car['id'];
            if ($esSuperRol || in_array($carId, $carrerasPermitidas, true)) {
                $carrerasFiltradas[] = [
                    'id'     => $car['id'],
                    'nombre' => $car['nombre'],
                    'cursos' => $carrerasCursosMap[$carId] ?? []
                ];
            }
        }
    }

    // 6. Configuración
    $resCfg = $conn->query("SELECT clave, valor FROM `configuracion`");
    $configuracion = [];
    while ($row = $resCfg->fetch_assoc()) {
        $configuracion[$row['clave']] = $row['valor'];
    }

    // 7. Credenciales y Códigos de Verificación para el usuario
    if ($miUsuario) {
        $credenciales = [];
        foreach (($miUsuario['certificadosCurso'] ?? []) as $cid) {
            $credenciales[] = [
                'tipo'   => 'curso',
                'id'     => $cid,
                'codigo' => db_generar_codigo_certificado($userId, $cid, 'curso'),
            ];
        }
        foreach (($miUsuario['certificadosCarrera'] ?? []) as $carId) {
            $credenciales[] = [
                'tipo'   => 'carrera',
                'id'     => $carId,
                'codigo' => db_generar_codigo_certificado($userId, $carId, 'carrera'),
            ];
        }
        $miUsuario['credenciales'] = $credenciales;
    }

    return [
        'usuarios'            => $miUsuario ? [$miUsuario] : [],
        'cursos'              => $cursosFiltrados,
        'carreras'            => $carrerasFiltradas,
        'rolesConfig'         => $miRolConfig ? [$miRolConfig] : [],
        'solicitudesRegistro' => [],
        'solicitudesCursos'   => [],
        'configuracion'       => db_config_publica($configuracion),
    ];
}

/**
 * Lee un curso específico con todo su contenido (módulos, lecciones completas y preguntas).
 * Sanitiza las preguntas si $esAdmin es falso.
 */
function db_read_curso_detalle(mysqli $conn, string $cursoId, bool $esAdmin): ?array {
    $stmtC = $conn->prepare("SELECT id, titulo, descripcion, tipo, imagen, prelacion, en_construccion FROM `cursos` WHERE id = ?");
    $stmtC->bind_param('s', $cursoId);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    if (!$resC || !($cRow = $resC->fetch_assoc())) {
        return null;
    }

    // Si el curso es tipo 'pruebas' y el usuario no es admin, denegar acceso
    if (($cRow['tipo'] ?? '') === 'pruebas' && !$esAdmin) {
        return null;
    }

    // Módulos
    $stmtM = $conn->prepare("SELECT id, orden, titulo, max_intentos, eval_tipo, eval_num_preguntas, eval_mezclar_opciones FROM `curso_modulos` WHERE curso_id = ? ORDER BY orden ASC");
    $stmtM->bind_param('s', $cursoId);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    $modulosMap = [];
    $modulosOrden = [];
    while ($mRow = $resM->fetch_assoc()) {
        $mid = (int)$mRow['id'];
        $modulosOrden[] = $mid;
        $modulosMap[$mid] = [
            'id'          => $mid,
            '_orden'      => (int)$mRow['orden'],
            'titulo'      => $mRow['titulo'],
            'maxIntentos' => (int)($mRow['max_intentos'] ?? 0),
            'lecciones'   => [],
            'evaluacion'  => [
                'tipo'            => $mRow['eval_tipo'] ?? 'fijo',
                'numPreguntas'    => (int)($mRow['eval_num_preguntas'] ?? 0),
                'mezclarOpciones' => !empty($mRow['eval_mezclar_opciones']),
                'preguntas'       => [],
            ],
        ];
    }

    // Lecciones
    $stmtL = $conn->prepare("SELECT id, modulo_id, orden, titulo, video_id, contenido, adjunto FROM `curso_lecciones` WHERE curso_id = ? ORDER BY modulo_id, orden ASC");
    $stmtL->bind_param('s', $cursoId);
    $stmtL->execute();
    $resL = $stmtL->get_result();
    while ($lRow = $resL->fetch_assoc()) {
        $mid = (int)$lRow['modulo_id'];
        if (isset($modulosMap[$mid])) {
            $modulosMap[$mid]['lecciones'][] = [
                'id'        => (int)$lRow['id'],
                '_orden'    => (int)$lRow['orden'],
                'titulo'    => $lRow['titulo'],
                'videoID'   => $lRow['video_id'],
                'contenido' => $lRow['contenido'],
                'adjunto'   => $lRow['adjunto'],
            ];
        }
    }

    // Preguntas
    $stmtP = $conn->prepare("SELECT id, modulo_id, orden, enunciado, imagen, opciones, correcta FROM `curso_preguntas` WHERE curso_id = ? ORDER BY modulo_id, orden ASC");
    $stmtP->bind_param('s', $cursoId);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    while ($pRow = $resP->fetch_assoc()) {
        $mid = (int)$pRow['modulo_id'];
        if (isset($modulosMap[$mid])) {
            $pItem = [
                'id'        => (int)$pRow['id'],
                '_orden'    => (int)$pRow['orden'],
                'enunciado' => $pRow['enunciado'],
                'imagen'    => $pRow['imagen'] ?? '',
                'opciones'  => is_string($pRow['opciones']) ? json_decode($pRow['opciones'], true) : $pRow['opciones'],
            ];
            if ($esAdmin) {
                $pItem['correcta'] = (int)$pRow['correcta'];
            }
            $modulosMap[$mid]['evaluacion']['preguntas'][] = $pItem;
        }
    }

    $modulosList = [];
    foreach ($modulosOrden as $mid) {
        $modulosList[] = $modulosMap[$mid];
    }

    return [
        'id'             => $cRow['id'],
        'titulo'         => $cRow['titulo'],
        'descripcion'    => $cRow['descripcion'],
        'tipo'           => $cRow['tipo'],
        'imagen'         => $cRow['imagen'],
        'prelacion'      => $cRow['prelacion'],
        'enConstruccion' => (bool)$cRow['en_construccion'],
        'modulos'        => $modulosList,
        '_esResumen'     => false,
    ];
}
