<?php
/**
 * lib/progreso.php — Progreso y evaluaciones
 * Guardado de progreso, evaluación en servidor, restablecimiento y reintentos habilitados.
 * Se carga desde db_mysql.php.
 */

/**
 * Guarda el progreso de un usuario en un curso en las tablas normalizadas.
 * Es aditivo: lecciones, módulos y medallas se suman a los existentes (INSERT IGNORE);
 * evaluaciones e intentos recibidos se insertan o actualizan por módulo.
 */
function db_upsert_progreso(mysqli $conn, string $userId, string $cursoId, array $prog): void {
    $newLec = array_values(array_unique((array)($prog['leccionesCompletadas'] ?? [])));
    $newMod = array_values(array_unique((array)($prog['modulosAprobados']     ?? [])));
    $newMed = array_values(array_unique((array)($prog['medallas']             ?? [])));
    $newEval = is_array($prog['evaluaciones'] ?? null) ? $prog['evaluaciones'] : (is_object($prog['evaluaciones'] ?? null) ? (array)$prog['evaluaciones'] : []);
    $newInt  = is_array($prog['intentos'] ?? null)     ? $prog['intentos']     : (is_object($prog['intentos'] ?? null)     ? (array)$prog['intentos']     : []);

    // Conservar la fecha original de evaluaciones que llegan sin fecha
    $stmtFecha = $conn->prepare("SELECT fecha FROM `usuario_evaluaciones` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    foreach ($newEval as $mNum => &$eItem) {
        if (is_object($eItem)) $eItem = (array)$eItem;
        if (is_array($eItem) && empty($eItem['fecha'])) {
            $mNumStr = (string)$mNum;
            $stmtFecha->bind_param('sss', $userId, $cursoId, $mNumStr);
            $stmtFecha->execute();
            $fRow = $stmtFecha->get_result()->fetch_assoc();
            $eItem['fecha'] = !empty($fRow['fecha']) ? $fRow['fecha'] : date('c');
        }
    }
    unset($eItem);

    $stmtLec = $conn->prepare("INSERT IGNORE INTO `usuario_lecciones_completadas` (usuario_id, curso_id, leccion_codigo) VALUES (?,?,?)");
    foreach ($newLec as $lCode) {
        $lCodeStr = (string)$lCode;
        if ($lCodeStr !== '') {
            $stmtLec->bind_param('sss', $userId, $cursoId, $lCodeStr);
            $stmtLec->execute();
        }
    }

    $stmtMod = $conn->prepare("INSERT IGNORE INTO `usuario_modulos_aprobados` (usuario_id, curso_id, modulo_num) VALUES (?,?,?)");
    foreach ($newMod as $mNum) {
        $mNumStr = (string)$mNum;
        if ($mNumStr !== '') {
            $stmtMod->bind_param('sss', $userId, $cursoId, $mNumStr);
            $stmtMod->execute();
        }
    }

    $stmtMed = $conn->prepare("INSERT IGNORE INTO `usuario_medallas` (usuario_id, curso_id, medalla_num) VALUES (?,?,?)");
    foreach ($newMed as $medNum) {
        $medNumStr = (string)$medNum;
        if ($medNumStr !== '') {
            $stmtMed->bind_param('sss', $userId, $cursoId, $medNumStr);
            $stmtMed->execute();
        }
    }

    $stmtEv = $conn->prepare(
        "INSERT INTO `usuario_evaluaciones` (usuario_id, curso_id, modulo_num, calificacion, aprobado, marcado_manual, fecha)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE calificacion=VALUES(calificacion), aprobado=VALUES(aprobado), marcado_manual=VALUES(marcado_manual), fecha=VALUES(fecha)"
    );
    foreach ($newEval as $mNum => $eVal) {
        if (is_array($eVal)) {
            $mNumStr = (string)$mNum;
            $calif   = floatval($eVal['calificacion'] ?? $eVal['nota'] ?? 0);
            $aprob   = !empty($eVal['aprobado']) ? 1 : 0;
            $manual  = !empty($eVal['marcadoManual']) ? 1 : 0;
            $fecha   = !empty($eVal['fecha']) ? $eVal['fecha'] : date('Y-m-d H:i:s');
            $stmtEv->bind_param('sssdiis', $userId, $cursoId, $mNumStr, $calif, $aprob, $manual, $fecha);
            $stmtEv->execute();
        }
    }

    $stmtIn = $conn->prepare(
        "INSERT INTO `usuario_intentos` (usuario_id, curso_id, modulo_num, intentos)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE intentos=VALUES(intentos)"
    );
    foreach ($newInt as $mNum => $intVal) {
        $mNumStr = (string)$mNum;
        $intNum  = intval($intVal);
        if ($intNum > 0) {
            $stmtIn->bind_param('sssi', $userId, $cursoId, $mNumStr, $intNum);
            $stmtIn->execute();
        }
    }
}

/**
 * Evalúa las respuestas de un módulo de forma segura en el servidor.
 * Calcula calificación, intentos, aprobación y certificados, y persiste directamente en MySQL.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param string $userId ID del usuario (cédula)
 * @param string $cursoId ID del curso
 * @param int $moduloIdx Índice orden del módulo (0, 1, 2...)
 * @param array $respuestas Arreglo de opciones seleccionadas por el alumno [idxPregunta => opcionElegida]
 * @return array Resultado detallado de la evaluación y nuevo estado del progreso
 */
function db_evaluar_modulo(mysqli $conn, string $userId, string $cursoId, int $moduloIdx, array $respuestas): array {
    // 1. Validar existencia del usuario
    $stmtU = $conn->prepare("SELECT id, rol FROM `usuarios` WHERE id = ?");
    $stmtU->bind_param('s', $userId);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    if (!$resU || !($uRow = $resU->fetch_assoc())) {
        throw new InvalidArgumentException("Usuario no encontrado: $userId");
    }

    // 2. Buscar el módulo por curso_id y orden
    $stmtM = $conn->prepare("SELECT id, orden, titulo, max_intentos, eval_tipo, eval_num_preguntas, eval_mezclar_opciones FROM `curso_modulos` WHERE curso_id = ? AND orden = ?");
    $stmtM->bind_param('si', $cursoId, $moduloIdx);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    if (!$resM || !($mRow = $resM->fetch_assoc())) {
        throw new InvalidArgumentException("Módulo no encontrado en curso $cursoId con orden $moduloIdx");
    }
    $moduloId = (int)$mRow['id'];
    $moduloTitulo = $mRow['titulo'];
    $maxIntentos = (int)($mRow['max_intentos'] ?? 0);
    $mNumStr = (string)$moduloIdx;

    // Verificar si ya está aprobado
    $stmtYaApr = $conn->prepare("SELECT 1 FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtYaApr->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtYaApr->execute();
    $yaAprobado = ($stmtYaApr->get_result()->num_rows > 0);

    // Verificar si ya tenía certificado emitido de este curso
    $stmtYaCert = $conn->prepare("SELECT 1 FROM `usuario_certificados_curso` WHERE usuario_id = ? AND curso_id = ?");
    $stmtYaCert->bind_param('ss', $userId, $cursoId);
    $stmtYaCert->execute();
    $yaCertificado = ($stmtYaCert->get_result()->num_rows > 0);

    // Obtener intentos previos
    $stmtIntSel = $conn->prepare("SELECT intentos FROM `usuario_intentos` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtIntSel->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtIntSel->execute();
    $resInt = $stmtIntSel->get_result();
    $prevIntentos = 0;
    if ($resInt && $rInt = $resInt->fetch_assoc()) {
        $prevIntentos = (int)$rInt['intentos'];
    }

    // Límite efectivo = máximo del módulo + reintentos habilitados por un administrador
    $maxIntentosBase = $maxIntentos;
    $intentosExtra = $maxIntentos > 0 ? db_intentos_extra($conn, $userId, $cursoId, $mNumStr) : 0;
    $maxIntentos += $intentosExtra;

    if (!$yaAprobado && $maxIntentos > 0 && $prevIntentos >= $maxIntentos) {
        throw new InvalidArgumentException("Has alcanzado el límite máximo de $maxIntentos intentos permitidos para esta evaluación. Contacta a un administrador para habilitar un nuevo intento.");
    }

    // 3. Obtener el banco de preguntas del módulo en orden
    $stmtQ = $conn->prepare("SELECT id, orden, enunciado, imagen, opciones, correcta FROM `curso_preguntas` WHERE modulo_id = ? ORDER BY orden ASC");
    $stmtQ->bind_param('i', $moduloId);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    $bancoPreguntasById = [];
    $bancoPreguntasList = [];
    while ($qRow = $resQ->fetch_assoc()) {
        $qId = (int)$qRow['id'];
        $bancoPreguntasById[$qId] = $qRow;
        $bancoPreguntasList[] = $qRow;
    }
    if (empty($bancoPreguntasList)) {
        throw new InvalidArgumentException("El módulo \"$moduloTitulo\" no posee preguntas de evaluación configuradas.");
    }

    // 4. Identificar y calificar preguntas respondidas
    $preguntasAEvaluar = [];

    // Detectar si respuestas viene como array de objetos [{ pregunta_id: 123, opcion: 0 }, ...]
    $esListaObjetos = false;
    foreach ($respuestas as $item) {
        if (is_array($item) && (isset($item['pregunta_id']) || isset($item['id']))) {
            $esListaObjetos = true;
            break;
        }
    }

    if ($esListaObjetos) {
        foreach ($respuestas as $item) {
            if (!is_array($item)) continue;
            $pId = (int)($item['pregunta_id'] ?? $item['id'] ?? 0);
            $sel = isset($item['opcion']) ? (int)$item['opcion'] : (isset($item['opcion_seleccionada']) ? (int)$item['opcion_seleccionada'] : -1);
            if (isset($bancoPreguntasById[$pId])) {
                $preguntasAEvaluar[] = [
                    'pregunta'     => $bancoPreguntasById[$pId],
                    'userSelected' => $sel
                ];
            }
        }
    } else {
        // Formato mapa o indexado (retrocompatibilidad)
        foreach ($respuestas as $key => $val) {
            $sel = (int)$val;
            if (is_numeric($key) && isset($bancoPreguntasById[(int)$key])) {
                $preguntasAEvaluar[] = [
                    'pregunta'     => $bancoPreguntasById[(int)$key],
                    'userSelected' => $sel
                ];
            } elseif (is_numeric($key) && isset($bancoPreguntasList[(int)$key])) {
                $preguntasAEvaluar[] = [
                    'pregunta'     => $bancoPreguntasList[(int)$key],
                    'userSelected' => $sel
                ];
            }
        }
    }

    if (empty($preguntasAEvaluar)) {
        foreach ($bancoPreguntasList as $idx => $p) {
            $sel = isset($respuestas[$idx]) ? (int)$respuestas[$idx] : -1;
            $preguntasAEvaluar[] = ['pregunta' => $p, 'userSelected' => $sel];
        }
    }

    $total = count($preguntasAEvaluar);
    $aciertos = 0;
    $preguntasDetalle = [];

    foreach ($preguntasAEvaluar as $item) {
        $p = $item['pregunta'];
        $userSelected = $item['userSelected'];
        $correcta = (int)$p['correcta'];
        $esCorrecta = ($userSelected === $correcta);
        if ($esCorrecta) {
            $aciertos++;
        }

        $opciones = is_string($p['opciones']) ? json_decode($p['opciones'], true) : $p['opciones'];
        if (!is_array($opciones)) $opciones = [];

        $preguntasDetalle[] = [
            'id'           => (int)$p['id'],
            'orden'        => (int)$p['orden'],
            'enunciado'    => $p['enunciado'],
            'imagen'       => $p['imagen'] ?? '',
            'opciones'     => $opciones,
            'seleccionada' => $userSelected,
            'correcta'     => $correcta,
            'esCorrecta'   => $esCorrecta,
        ];
    }

    $calificacion = (int)round(($aciertos / $total) * 100);

    // Obtener minAprobacion de configuracion (default 75)
    $minAprobacion = 75;
    $resCfg = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'minAprobacion'");
    if ($resCfg && $rCfg = $resCfg->fetch_assoc()) {
        $v = (int)$rCfg['valor'];
        if ($v > 0) $minAprobacion = $v;
    }

    $aprobado = ($calificacion >= $minAprobacion);
    $fechaIso = date('c');

    // 5. Incrementar número de intentos
    $numIntentos = $prevIntentos + 1;
    $stmtIntUp = $conn->prepare("INSERT INTO `usuario_intentos` (usuario_id, curso_id, modulo_num, intentos) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE intentos = VALUES(intentos)");
    $stmtIntUp->bind_param('sssi', $userId, $cursoId, $mNumStr, $numIntentos);
    $stmtIntUp->execute();

    // 6. Registrar evaluación en usuario_evaluaciones
    $aprobadoInt = $aprobado ? 1 : 0;
    $califFloat = (float)$calificacion;
    $stmtEv = $conn->prepare("INSERT INTO `usuario_evaluaciones` (usuario_id, curso_id, modulo_num, calificacion, aprobado, marcado_manual, fecha)
        VALUES (?,?,?,?,?,0,?)
        ON DUPLICATE KEY UPDATE calificacion = VALUES(calificacion), aprobado = VALUES(aprobado), fecha = VALUES(fecha)");
    $stmtEv->bind_param('sssdis', $userId, $cursoId, $mNumStr, $califFloat, $aprobadoInt, $fechaIso);
    $stmtEv->execute();

    // 7. Si aprobó, registrar módulo aprobado, medalla y verificar certificación
    $certificadoOtorgado = false;
    if ($aprobado) {
        $stmtMod = $conn->prepare("INSERT IGNORE INTO `usuario_modulos_aprobados` (usuario_id, curso_id, modulo_num) VALUES (?,?,?)");
        $stmtMod->bind_param('sss', $userId, $cursoId, $mNumStr);
        $stmtMod->execute();

        $stmtMed = $conn->prepare("INSERT IGNORE INTO `usuario_medallas` (usuario_id, curso_id, medalla_num) VALUES (?,?,?)");
        $stmtMed->bind_param('sss', $userId, $cursoId, $mNumStr);
        $stmtMed->execute();

        // Verificar si completó todos los módulos con evaluación de este curso
        $stmtTot = $conn->prepare("SELECT COUNT(DISTINCT m.id) as total_eval FROM `curso_modulos` m JOIN `curso_preguntas` p ON p.modulo_id = m.id WHERE m.curso_id = ?");
        $stmtTot->bind_param('s', $cursoId);
        $stmtTot->execute();
        $resTot = $stmtTot->get_result();
        $totalEval = ($resTot && $rTot = $resTot->fetch_assoc()) ? (int)$rTot['total_eval'] : 0;

        if ($totalEval === 0) {
            $stmtTotM = $conn->prepare("SELECT COUNT(*) as total_m FROM `curso_modulos` WHERE curso_id = ?");
            $stmtTotM->bind_param('s', $cursoId);
            $stmtTotM->execute();
            $resTotM = $stmtTotM->get_result();
            $totalEval = ($resTotM && $rTotM = $resTotM->fetch_assoc()) ? (int)$rTotM['total_m'] : 0;
        }

        $stmtApr = $conn->prepare("SELECT COUNT(DISTINCT modulo_num) as aprobados FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ?");
        $stmtApr->bind_param('ss', $userId, $cursoId);
        $stmtApr->execute();
        $resApr = $stmtApr->get_result();
        $aprobados = ($resApr && $rApr = $resApr->fetch_assoc()) ? (int)$rApr['aprobados'] : 0;

        if ($totalEval > 0 && $aprobados >= $totalEval) {
            $stmtCert = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
            $stmtCert->bind_param('ss', $userId, $cursoId);
            $stmtCert->execute();
            if ($stmtCert->affected_rows > 0 || !$yaCertificado) {
                $certificadoOtorgado = true;
            }
        }
    }

    // 9. Verificar carreras completadas
    $stmtUC = $conn->prepare("SELECT curso_id FROM `usuario_certificados_curso` WHERE usuario_id = ?");
    $stmtUC->bind_param('s', $userId);
    $stmtUC->execute();
    $resUC = $stmtUC->get_result();
    $userCertsCurso = [];
    while ($rUC = $resUC->fetch_assoc()) {
        $userCertsCurso[] = $rUC['curso_id'];
    }

    $stmtCars = $conn->prepare("SELECT carrera_id FROM `usuario_carreras_asignadas` WHERE usuario_id = ?");
    $stmtCars->bind_param('s', $userId);
    $stmtCars->execute();
    $resCars = $stmtCars->get_result();
    $carreraOtorgada = false;
    while ($carRow = $resCars->fetch_assoc()) {
        $carId = $carRow['carrera_id'];
        $stmtCC = $conn->prepare("SELECT curso_id FROM `carrera_cursos` WHERE carrera_id = ?");
        $stmtCC->bind_param('s', $carId);
        $stmtCC->execute();
        $resCC = $stmtCC->get_result();
        $reqCursos = [];
        while ($ccRow = $resCC->fetch_assoc()) {
            $reqCursos[] = $ccRow['curso_id'];
        }
        if (!empty($reqCursos)) {
            $completa = true;
            foreach ($reqCursos as $rcId) {
                if (!in_array($rcId, $userCertsCurso, true)) {
                    $completa = false;
                    break;
                }
            }
            if ($completa) {
                $stmtUpCar = $conn->prepare("UPDATE `usuario_carreras_asignadas` SET estado = 'Completada' WHERE usuario_id = ? AND carrera_id = ?");
                $stmtUpCar->bind_param('ss', $userId, $carId);
                $stmtUpCar->execute();

                $stmtInsCertCar = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_carrera` (usuario_id, carrera_id) VALUES (?,?)");
                $stmtInsCertCar->bind_param('ss', $userId, $carId);
                $stmtInsCertCar->execute();
                if ($stmtInsCertCar->affected_rows > 0) {
                    $carreraOtorgada = true;
                }
            }
        }
    }

    // Certificados de carrera del usuario
    $stmtUCarr = $conn->prepare("SELECT carrera_id FROM `usuario_certificados_carrera` WHERE usuario_id = ?");
    $stmtUCarr->bind_param('s', $userId);
    $stmtUCarr->execute();
    $resUCarr = $stmtUCarr->get_result();
    $userCertsCarrera = [];
    while ($rUCarr = $resUCarr->fetch_assoc()) {
        $userCertsCarrera[] = $rUCarr['carrera_id'];
    }

    // Obtener rol del usuario y verificar si completó todos los cursos asignados a su rol
    $userRol = $uRow['rol'] ?? 'participante';
    $rolNombre = $userRol;
    $stmtRNom = $conn->prepare("SELECT nombre FROM `roles_config` WHERE id = ?");
    $stmtRNom->bind_param('s', $userRol);
    $stmtRNom->execute();
    $resRNom = $stmtRNom->get_result();
    if ($resRNom && $rNom = $resRNom->fetch_assoc()) {
        $rolNombre = $rNom['nombre'];
    }

    $stmtRC = $conn->prepare("SELECT curso_id FROM `rol_cursos` WHERE rol_id = ?");
    $stmtRC->bind_param('s', $userRol);
    $stmtRC->execute();
    $resRC = $stmtRC->get_result();
    $cursosRol = [];
    while ($rRC = $resRC->fetch_assoc()) {
        $cursosRol[] = $rRC['curso_id'];
    }

    $rolOtorgado = false;
    if (!empty($cursosRol) && $certificadoOtorgado) {
        $todosRolAprobados = true;
        foreach ($cursosRol as $cRolId) {
            if (!in_array($cRolId, $userCertsCurso, true)) {
                $todosRolAprobados = false;
                break;
            }
        }
        if ($todosRolAprobados) {
            $rolOtorgado = true;
        }
    }

    // Título del curso
    $stmtCTit = $conn->prepare("SELECT titulo FROM `cursos` WHERE id = ?");
    $stmtCTit->bind_param('s', $cursoId);
    $stmtCTit->execute();
    $resCTit = $stmtCTit->get_result();
    $cursoTitulo = ($resCTit && $rCTit = $resCTit->fetch_assoc()) ? $rCTit['titulo'] : $cursoId;

    $moduloRecienAprobado = (!$yaAprobado && $aprobado);
    $bloqueadoRecien = (!$yaAprobado && $maxIntentos > 0 && !$aprobado && $numIntentos >= $maxIntentos);

    return [
        'success'              => true,
        'calificacion'         => $calificacion,
        'aprobado'             => $aprobado,
        'moduloRecienAprobado' => $moduloRecienAprobado,
        'moduloTitulo'         => $moduloTitulo,
        'cursoTitulo'          => $cursoTitulo,
        'aciertos'             => $aciertos,
        'total'                => $total,
        'minAprobacion'        => $minAprobacion,
        'maxIntentos'          => $maxIntentos,
        'maxIntentosBase'      => $maxIntentosBase,
        'intentosExtra'        => $intentosExtra,
        'numIntentos'          => $numIntentos,
        'bloqueado'            => ($maxIntentos > 0 && !$aprobado && $numIntentos >= $maxIntentos),
        'intentosAgotados'     => $bloqueadoRecien,
        'certificadoOtorgado'  => $certificadoOtorgado,
        'carreraOtorgada'      => $carreraOtorgada,
        'rolOtorgado'          => $rolOtorgado,
        'rolNombre'            => $rolNombre,
        'preguntasDetalle'     => $preguntasDetalle,
        'progreso'             => db_leer_progreso($conn, $userId)[$userId][$cursoId] ?? [
            'leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [],
            'evaluaciones' => (object)[], 'intentos' => (object)[],
        ],
        'certificadosCurso'    => $userCertsCurso,
        'certificadosCarrera'  => $userCertsCarrera,
    ];
}

/**
 * Restablece el avance de un usuario en un curso o en módulos específicos de manera atómica.
 * Limpia tanto las tablas relacionales normalizadas como el espejo JSON.
 *
 * @param mysqli $conn Conexión a la base de datos
 * @param string $userId Cédula del usuario
 * @param string $cursoId ID del curso
 * @param array|null $modulos Índices de módulos a restablecer (null o vacío = curso completo)
 * @return array Estado resultante
 */
function db_restablecer_progreso(mysqli $conn, string $userId, string $cursoId, ?array $modulos = null): array {
    // Fuera de la transacción: un CREATE TABLE haría commit implícito
    db_habilitaciones_ensure_table($conn);
    $conn->begin_transaction();
    try {
        $userId = trim($userId);
        $cursoId = trim($cursoId);
        if (!$userId || !$cursoId) {
            throw new InvalidArgumentException("Se requieren userId y cursoId válidos");
        }

        $esCompleto = ($modulos === null || empty($modulos));

        if ($esCompleto) {
            // 1. Limpieza total de tablas relacionales para este usuario y curso
            $stmtLec = $conn->prepare("DELETE FROM `usuario_lecciones_completadas` WHERE usuario_id = ? AND curso_id = ?");
            $stmtLec->bind_param('ss', $userId, $cursoId);
            $stmtLec->execute();

            $stmtMod = $conn->prepare("DELETE FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ?");
            $stmtMod->bind_param('ss', $userId, $cursoId);
            $stmtMod->execute();

            $stmtMed = $conn->prepare("DELETE FROM `usuario_medallas` WHERE usuario_id = ? AND curso_id = ?");
            $stmtMed->bind_param('ss', $userId, $cursoId);
            $stmtMed->execute();

            $stmtEv = $conn->prepare("DELETE FROM `usuario_evaluaciones` WHERE usuario_id = ? AND curso_id = ?");
            $stmtEv->bind_param('ss', $userId, $cursoId);
            $stmtEv->execute();

            $stmtIn = $conn->prepare("DELETE FROM `usuario_intentos` WHERE usuario_id = ? AND curso_id = ?");
            $stmtIn->bind_param('ss', $userId, $cursoId);
            $stmtIn->execute();

            // Los reintentos habilitados dejan de sumar al límite, pero se conservan en el historial
            $stmtHab = $conn->prepare("UPDATE `usuario_habilitaciones` SET activo = 0 WHERE usuario_id = ? AND curso_id = ?");
            $stmtHab->bind_param('ss', $userId, $cursoId);
            $stmtHab->execute();

            $stmtCert = $conn->prepare("DELETE FROM `usuario_certificados_curso` WHERE usuario_id = ? AND curso_id = ?");
            $stmtCert->bind_param('ss', $userId, $cursoId);
            $stmtCert->execute();
        } else {
            // 2. Limpieza selectiva por módulos
            foreach ($modulos as $mVal) {
                $mNumStr = (string)$mVal;

                // Eliminar de tablas relacionales
                $sMod = $conn->prepare("DELETE FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
                $sMod->bind_param('sss', $userId, $cursoId, $mNumStr);
                $sMod->execute();

                $sMed = $conn->prepare("DELETE FROM `usuario_medallas` WHERE usuario_id = ? AND curso_id = ? AND medalla_num = ?");
                $sMed->bind_param('sss', $userId, $cursoId, $mNumStr);
                $sMed->execute();

                $sEv = $conn->prepare("DELETE FROM `usuario_evaluaciones` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
                $sEv->bind_param('sss', $userId, $cursoId, $mNumStr);
                $sEv->execute();

                $sIn = $conn->prepare("DELETE FROM `usuario_intentos` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
                $sIn->bind_param('sss', $userId, $cursoId, $mNumStr);
                $sIn->execute();

                $sHab = $conn->prepare("UPDATE `usuario_habilitaciones` SET activo = 0 WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
                $sHab->bind_param('sss', $userId, $cursoId, $mNumStr);
                $sHab->execute();

                // Eliminar lecciones con prefijo $mNumStr-
                $prefix = $mNumStr . '-%';
                $sLec = $conn->prepare("DELETE FROM `usuario_lecciones_completadas` WHERE usuario_id = ? AND curso_id = ? AND leccion_codigo LIKE ?");
                $sLec->bind_param('sss', $userId, $cursoId, $prefix);
                $sLec->execute();
            }

            // Si ya no quedan módulos aprobados, revocar certificado de curso si existía
            $sRest = $conn->prepare("SELECT 1 FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ? LIMIT 1");
            $sRest->bind_param('ss', $userId, $cursoId);
            $sRest->execute();
            if ($sRest->get_result()->num_rows === 0) {
                $sCert = $conn->prepare("DELETE FROM `usuario_certificados_curso` WHERE usuario_id = ? AND curso_id = ?");
                $sCert->bind_param('ss', $userId, $cursoId);
                $sCert->execute();
            }
        }

        // 3. Recalcular estado de carreras asignadas
        $stmtUC = $conn->prepare("SELECT curso_id FROM `usuario_certificados_curso` WHERE usuario_id = ?");
        $stmtUC->bind_param('s', $userId);
        $stmtUC->execute();
        $resUC = $stmtUC->get_result();
        $userCertsCurso = [];
        while ($rUC = $resUC->fetch_assoc()) {
            $userCertsCurso[] = $rUC['curso_id'];
        }

        $stmtCars = $conn->prepare("SELECT carrera_id FROM `usuario_carreras_asignadas` WHERE usuario_id = ?");
        $stmtCars->bind_param('s', $userId);
        $stmtCars->execute();
        $resCars = $stmtCars->get_result();
        while ($carRow = $resCars->fetch_assoc()) {
            $carId = $carRow['carrera_id'];
            $stmtCC = $conn->prepare("SELECT curso_id FROM `carrera_cursos` WHERE carrera_id = ?");
            $stmtCC->bind_param('s', $carId);
            $stmtCC->execute();
            $resCC = $stmtCC->get_result();
            $reqCursos = [];
            while ($ccRow = $resCC->fetch_assoc()) {
                $reqCursos[] = $ccRow['curso_id'];
            }
            if (!empty($reqCursos)) {
                $completa = true;
                foreach ($reqCursos as $rcId) {
                    if (!in_array($rcId, $userCertsCurso, true)) {
                        $completa = false;
                        break;
                    }
                }
                $nuevoEstado = $completa ? 'Completada' : 'Incompleta';
                $stmtUpCar = $conn->prepare("UPDATE `usuario_carreras_asignadas` SET estado = ? WHERE usuario_id = ? AND carrera_id = ?");
                $stmtUpCar->bind_param('sss', $nuevoEstado, $userId, $carId);
                $stmtUpCar->execute();

                if (!$completa) {
                    $stmtDelCertCar = $conn->prepare("DELETE FROM `usuario_certificados_carrera` WHERE usuario_id = ? AND carrera_id = ?");
                    $stmtDelCertCar->bind_param('ss', $userId, $carId);
                    $stmtDelCertCar->execute();
                }
            }
        }

        $conn->commit();
        return ['success' => true, 'message' => 'Avance restablecido correctamente'];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Garantiza que exista la tabla de habilitaciones (bases desplegadas antes de esta función).
 * Se ejecuta una sola vez por petición.
 */
function db_habilitaciones_ensure_table(mysqli $conn): void {
    static $verificada = false;
    if ($verificada) return;
    if (!$conn->query(DB_SQL_USUARIO_HABILITACIONES)) {
        throw new RuntimeException('Error creando tabla usuario_habilitaciones: ' . $conn->error);
    }
    $verificada = true;
}

/**
 * Devuelve las habilitaciones agrupadas: [usuario][curso] => ['intentosExtra' => [mod => n], 'habilitaciones' => [mod => [...]]].
 * Si se indica $userId, solo las de ese usuario.
 */
function db_habilitaciones_map(mysqli $conn, ?string $userId = null): array {
    db_habilitaciones_ensure_table($conn);
    $sql = "SELECT id, usuario_id, curso_id, modulo_num, intentos_otorgados, intentos_previos, calificacion_previa,
                   repasar_lecciones, motivo, admin_id, fecha, activo
            FROM `usuario_habilitaciones`";
    if ($userId !== null) {
        $stmt = $conn->prepare($sql . " WHERE usuario_id = ? ORDER BY fecha ASC, id ASC");
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql . " ORDER BY fecha ASC, id ASC");
    }

    $map = [];
    while ($res && $r = $res->fetch_assoc()) {
        $u = $r['usuario_id']; $c = $r['curso_id']; $m = (string)$r['modulo_num'];
        if (!isset($map[$u][$c])) $map[$u][$c] = ['intentosExtra' => [], 'habilitaciones' => []];
        $activo = (bool)$r['activo'];
        if ($activo) {
            $map[$u][$c]['intentosExtra'][$m] = ($map[$u][$c]['intentosExtra'][$m] ?? 0) + (int)$r['intentos_otorgados'];
        }
        $map[$u][$c]['habilitaciones'][$m][] = [
            'id'                 => (int)$r['id'],
            'intentosOtorgados'  => (int)$r['intentos_otorgados'],
            'intentosPrevios'    => (int)$r['intentos_previos'],
            'calificacionPrevia' => $r['calificacion_previa'] !== null ? (float)$r['calificacion_previa'] : null,
            'repasarLecciones'   => (bool)$r['repasar_lecciones'],
            'motivo'             => $r['motivo'],
            'adminId'            => $r['admin_id'],
            'fecha'              => $r['fecha'],
            'activo'             => $activo,
        ];
    }
    return $map;
}

/**
 * Inserta intentosExtra/habilitaciones en el progreso de un usuario (array curso => progreso).
 */
function db_habilitaciones_aplicar_a_progreso($progreso, array $mapUsuario) {
    if (empty($mapUsuario)) return $progreso;
    $progreso = is_object($progreso) ? (array)$progreso : (array)$progreso;
    foreach ($mapUsuario as $cid => $info) {
        if (!isset($progreso[$cid])) continue;
        $progreso[$cid]['intentosExtra']  = empty($info['intentosExtra']) ? (object)[] : (object)$info['intentosExtra'];
        $progreso[$cid]['habilitaciones'] = empty($info['habilitaciones']) ? (object)[] : (object)$info['habilitaciones'];
    }
    return empty($progreso) ? (object)[] : $progreso;
}

/**
 * Intentos adicionales vigentes otorgados a un usuario para un módulo.
 */
function db_intentos_extra(mysqli $conn, string $userId, string $cursoId, string $mNumStr): int {
    db_habilitaciones_ensure_table($conn);
    $stmt = $conn->prepare("SELECT COALESCE(SUM(intentos_otorgados), 0) AS extra FROM `usuario_habilitaciones`
                            WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ? AND activo = 1");
    $stmt->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['extra'] ?? 0);
}

/**
 * Habilita un nuevo intento de evaluación sobre un módulo reprobado SIN borrar los intentos
 * ni la calificación registrada: el historial de reprobaciones se conserva para los reportes.
 * Opcionalmente obliga a repasar las lecciones del módulo antes de volver a evaluar.
 */
function db_habilitar_reintento(mysqli $conn, string $userId, string $cursoId, int $moduloIdx, int $intentosOtorgados, bool $repasarLecciones, string $motivo, string $adminId): array {
    db_habilitaciones_ensure_table($conn);
    $userId  = trim($userId);
    $cursoId = trim($cursoId);
    $mNumStr = (string)$moduloIdx;
    $intentosOtorgados = max(1, min(10, $intentosOtorgados));
    $motivo = mb_substr(trim($motivo), 0, 500);

    $stmtM = $conn->prepare("SELECT max_intentos FROM `curso_modulos` WHERE curso_id = ? AND orden = ?");
    $stmtM->bind_param('si', $cursoId, $moduloIdx);
    $stmtM->execute();
    $mRow = $stmtM->get_result()->fetch_assoc();
    if (!$mRow) {
        throw new InvalidArgumentException("Módulo no encontrado en curso $cursoId con orden $moduloIdx");
    }
    $maxIntentos = (int)($mRow['max_intentos'] ?? 0);

    $stmtApr = $conn->prepare("SELECT 1 FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtApr->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtApr->execute();
    if ($stmtApr->get_result()->num_rows > 0) {
        throw new InvalidArgumentException('El módulo ya está aprobado; no requiere habilitar un reintento.');
    }

    $stmtInt = $conn->prepare("SELECT intentos FROM `usuario_intentos` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtInt->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtInt->execute();
    $intRow = $stmtInt->get_result()->fetch_assoc();
    $intentosPrevios = (int)($intRow['intentos'] ?? 0);
    if ($intentosPrevios < 1) {
        throw new InvalidArgumentException('El colaborador no registra intentos reprobados en este módulo.');
    }

    $stmtEv = $conn->prepare("SELECT calificacion FROM `usuario_evaluaciones` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtEv->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtEv->execute();
    $evRow = $stmtEv->get_result()->fetch_assoc();
    $califPrevia = $evRow ? (float)$evRow['calificacion'] : null;

    $conn->begin_transaction();
    try {
        $repasarInt = $repasarLecciones ? 1 : 0;
        $stmtIns = $conn->prepare("INSERT INTO `usuario_habilitaciones`
            (usuario_id, curso_id, modulo_num, intentos_otorgados, intentos_previos, calificacion_previa, repasar_lecciones, motivo, admin_id)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $stmtIns->bind_param('sssiidiss', $userId, $cursoId, $mNumStr, $intentosOtorgados, $intentosPrevios, $califPrevia, $repasarInt, $motivo, $adminId);
        $stmtIns->execute();
        $habId = (int)$conn->insert_id;

        if ($repasarLecciones) {
            // Las lecciones del módulo vuelven a quedar pendientes
            $prefix = $mNumStr . '-%';
            $sLec = $conn->prepare("DELETE FROM `usuario_lecciones_completadas` WHERE usuario_id = ? AND curso_id = ? AND leccion_codigo LIKE ?");
            $sLec->bind_param('sss', $userId, $cursoId, $prefix);
            $sLec->execute();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $extra = db_intentos_extra($conn, $userId, $cursoId, $mNumStr);
    return [
        'success'             => true,
        'habilitacionId'      => $habId,
        'intentos'            => $intentosPrevios,
        'intentosExtra'       => $extra,
        'maxIntentos'         => $maxIntentos,
        'maxIntentosEfectivo' => $maxIntentos > 0 ? $maxIntentos + $extra : 0,
        'repasarLecciones'    => $repasarLecciones,
    ];
}
