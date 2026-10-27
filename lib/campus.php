<?php
/**
 * lib/campus.php — Lectura de datos para el campus
 * Carga del participante, catálogo del campus y detalle de un curso.
 * Se carga desde db_mysql.php.
 */

/** Campos de una pregunta que solo ve el administrador (o el participante tras calificar). */
const DB_CAMPOS_PREGUNTA_PRIVADOS = ['correcta', 'retroCorrecta', 'retroIncorrecta'];

/**
 * Quita de las preguntas de una lista de cursos la respuesta correcta y la retroalimentación,
 * que delatarían la respuesta antes de enviar la evaluación.
 */
function db_ocultar_respuestas(array $cursos): array {
    foreach ($cursos as &$c) {
        foreach ($c['modulos'] ?? [] as $mi => $m) {
            foreach ($m['evaluacion']['preguntas'] ?? [] as $pi => $p) {
                foreach (DB_CAMPOS_PREGUNTA_PRIVADOS as $campo) {
                    unset($c['modulos'][$mi]['evaluacion']['preguntas'][$pi][$campo]);
                }
            }
        }
    }
    unset($c);
    return $cursos;
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

    // Los participantes no reciben la respuesta correcta ni la retroalimentación
    $cursosFiltrados = db_ocultar_respuestas($cursosFiltrados);

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
    $stmtP = $conn->prepare("SELECT id, modulo_id, orden, enunciado, imagen, opciones, correcta, retro_correcta, retro_incorrecta FROM `curso_preguntas` WHERE curso_id = ? ORDER BY modulo_id, orden ASC");
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
                $pItem['retroCorrecta'] = $pRow['retro_correcta'] ?? '';
                $pItem['retroIncorrecta'] = $pRow['retro_incorrecta'] ?? '';
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
