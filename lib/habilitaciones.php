<?php
/**
 * lib/habilitaciones.php — Reintentos habilitados
 * Intentos adicionales que un administrador otorga sobre evaluaciones reprobadas
 * (tabla usuario_habilitaciones) y su aplicación al progreso.
 * Se carga desde db_mysql.php.
 */

/**
 * Devuelve las habilitaciones agrupadas: [usuario][curso] => ['intentosExtra' => [mod => n], 'habilitaciones' => [mod => [...]]].
 * Si se indica $userId, solo las de ese usuario.
 */
function db_habilitaciones_map(mysqli $conn, ?string $userId = null): array {
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
