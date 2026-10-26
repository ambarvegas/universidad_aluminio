<?php
/**
 * lib/respaldo.php — Importación masiva / respaldos
 * Inserción por lotes y reemplazo completo de datos desde un respaldo.
 * Se carga desde db_mysql.php.
 */

/**
 * Recibe el array de datos (equivalente a db.json) y lo
 * persiste en MySQL, reemplazando todos los registros existentes.
 *
 * Estrategia: transacción única, truncar tablas dependientes y
 * reemplazar (INSERT OR REPLACE) las tablas independientes.
 */
/**
 * Helper para inserciones por lotes (multi-row INSERT).
 * Reduce cientos de llamadas de red individuales a 1 sola query.
 */
function db_bulk_insert(mysqli $conn, string $table, array $columns, array $rows, int $chunkSize = 100, string $onDuplicate = '', bool $ignore = false): void {
    if (empty($rows)) return;
    $colList = '`' . implode('`, `', $columns) . '`';
    $verb = $ignore ? 'INSERT IGNORE INTO' : 'INSERT INTO';
    $chunks = array_chunk($rows, $chunkSize);
    foreach ($chunks as $chunk) {
        $valuesArr = [];
        foreach ($chunk as $row) {
            $escaped = array_map(function($val) use ($conn) {
                if ($val === null) return 'NULL';
                return "'" . $conn->real_escape_string((string)$val) . "'";
            }, $row);
            $valuesArr[] = '(' . implode(', ', $escaped) . ')';
        }
        $sql = "$verb `$table` ($colList) VALUES " . implode(', ', $valuesArr);
        if ($onDuplicate) {
            $sql .= ' ' . $onDuplicate;
        }
        if (!$conn->query($sql)) {
            throw new RuntimeException("Error en bulk insert para '$table': " . $conn->error . " — SQL: " . substr($sql, 0, 150));
        }
    }
}

/**
 * Persiste el objeto DB completo en MySQL de manera ultra-optimizada.
 * Utiliza inserciones por lotes (bulk inserts) para evitar penalización de latencia de red.
 */
function db_write_all(mysqli $conn, array $data): void {
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');

    // Progreso actual (tablas normalizadas) para fusionarlo con el respaldo sin perder datos.
    // Se lee antes de la transacción: db_leer_progreso puede crear tablas (DDL = commit implícito).
    $existingDbProgreso = db_leer_progreso($conn);

    $conn->begin_transaction();

    try {
        // Desactivar FK checks temporalmente para truncar sin orden
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // --- Recopilar hashes, progreso y certificados existentes para no sobrescribir ni borrar datos ---
        $existingHashes = [];
        $resH = $conn->query("SELECT id, clave FROM `usuarios`");
        if ($resH) {
            while ($r = $resH->fetch_assoc()) {
                $existingHashes[$r['id']] = $r['clave'];
            }
        }

        $existingCertCurso = [];
        $resCC = $conn->query("SELECT usuario_id, curso_id FROM `usuario_certificados_curso`");
        if ($resCC) {
            while ($r = $resCC->fetch_assoc()) {
                $existingCertCurso[$r['usuario_id']][] = $r['curso_id'];
            }
        }

        $existingCertCarrera = [];
        $resCarC = $conn->query("SELECT usuario_id, carrera_id FROM `usuario_certificados_carrera`");
        if ($resCarC) {
            while ($r = $resCarC->fetch_assoc()) {
                $existingCertCarrera[$r['usuario_id']][] = $r['carrera_id'];
            }
        }

        // Limpiar tablas dependientes
        $conn->query("TRUNCATE TABLE `usuario_asignados`");
        $conn->query("TRUNCATE TABLE `usuario_carreras_asignadas`");
        $conn->query("TRUNCATE TABLE `usuario_lecciones_completadas`");
        $conn->query("TRUNCATE TABLE `usuario_modulos_aprobados`");
        $conn->query("TRUNCATE TABLE `usuario_medallas`");
        $conn->query("TRUNCATE TABLE `usuario_evaluaciones`");
        $conn->query("TRUNCATE TABLE `usuario_intentos`");
        $conn->query("TRUNCATE TABLE `usuario_certificados_curso`");
        $conn->query("TRUNCATE TABLE `usuario_certificados_carrera`");
        $conn->query("TRUNCATE TABLE `carrera_cursos`");
        $conn->query("TRUNCATE TABLE `rol_permisos`");
        $conn->query("TRUNCATE TABLE `rol_cursos`");
        $conn->query("TRUNCATE TABLE `rol_carreras`");
        $conn->query("TRUNCATE TABLE `solicitudes_registro`");
        $conn->query("TRUNCATE TABLE `solicitudes_cursos`");

        $usuariosRows          = [];
        $asignadosRows         = [];
        $carrerasAsignadasRows = [];
        $normLecRows           = [];
        $normModRows           = [];
        $normMedRows           = [];
        $normEvalRows          = [];
        $normIntRows           = [];
        $certCursoRows         = [];
        $certCarreraRows       = [];

        foreach (($data['usuarios'] ?? []) as $u) {
            $id       = trim((string)($u['id'] ?? ''));
            $nombre   = trim((string)($u['nombre'] ?? ''));
            $rawClave = trim((string)($u['clave'] ?? ''));
            $rol      = trim((string)($u['rol'] ?? 'participante'));
            $estado   = trim((string)($u['estado'] ?? 'activo'));
            $telefono = trim((string)($u['telefono'] ?? ''));
            $email    = trim((string)($u['email'] ?? ''));
            $fechaNac = trim((string)($u['fecha_nacimiento'] ?? $u['fechaNacimiento'] ?? ''));
            if (!$id) continue;

            if (!empty($rawClave)) {
                if (str_starts_with($rawClave, '$2y$')) {
                    $clave = $rawClave;
                } else {
                    $clave = password_hash($rawClave, PASSWORD_BCRYPT);
                }
            } else {
                $clave = $existingHashes[$id] ?? password_hash('12345', PASSWORD_BCRYPT);
            }

            $usuariosRows[] = [$id, $nombre, $clave, $rol, $estado, $telefono, $email, $fechaNac];

            // Asignados directos (deduplicar)
            $asignadosUnique = array_unique(array_filter((array)($u['asignados'] ?? [])));
            foreach ($asignadosUnique as $cId) {
                if ($cId) $asignadosRows[] = [$id, (string)$cId];
            }

            // Carreras asignadas (deduplicar por caId)
            $seenCar = [];
            foreach (($u['carrerasAsignadas'] ?? []) as $ca) {
                $caId     = is_array($ca) ? ($ca['id']     ?? '') : $ca;
                $caEstado = is_array($ca) ? ($ca['estado'] ?? 'Incompleta') : 'Incompleta';
                if ($caId && !isset($seenCar[$caId])) {
                    $seenCar[$caId] = true;
                    $carrerasAsignadasRows[] = [$id, (string)$caId, (string)$caEstado];
                }
            }

            // Progreso inteligente: fusionar payload con BD existente
            $progresoPayload = $u['progreso'] ?? [];
            if (is_object($progresoPayload)) $progresoPayload = (array)$progresoPayload;

            $allCourseIdsForUser = array_unique(array_merge(
                array_keys($progresoPayload),
                array_keys($existingDbProgreso[$id] ?? [])
            ));

            foreach ($allCourseIdsForUser as $cIdStr) {
                $cIdStr = (string)$cIdStr;
                $progPayload = $progresoPayload[$cIdStr] ?? [];
                if (is_object($progPayload)) $progPayload = (array)$progPayload;

                $dbProg = $existingDbProgreso[$id][$cIdStr] ?? [];

                $dbLec  = $dbProg['leccionesCompletadas'] ?? [];
                $dbMod  = $dbProg['modulosAprobados']     ?? [];
                $dbMed  = $dbProg['medallas']             ?? [];
                $dbEval = (array)($dbProg['evaluaciones'] ?? []);
                $dbInt  = (array)($dbProg['intentos']     ?? []);

                $payLec  = is_array($progPayload) ? ($progPayload['leccionesCompletadas'] ?? []) : [];
                $payMod  = is_array($progPayload) ? ($progPayload['modulosAprobados']     ?? []) : [];
                $payMed  = is_array($progPayload) ? ($progPayload['medallas']             ?? []) : [];
                $payEval = is_array($progPayload) ? (is_array($progPayload['evaluaciones'] ?? null) ? $progPayload['evaluaciones'] : (array)($progPayload['evaluaciones'] ?? [])) : [];
                $payInt  = is_array($progPayload) ? (is_array($progPayload['intentos'] ?? null)     ? $progPayload['intentos']     : (array)($progPayload['intentos'] ?? [])) : [];

                $mergedLec  = array_values(array_unique(array_merge($dbLec, $payLec)));
                $mergedMod  = array_values(array_unique(array_merge($dbMod, $payMod)));
                $mergedMed  = array_values(array_unique(array_merge($dbMed, $payMed)));

                // Fusión segura de evaluaciones preferir mayores notas
                $allEvalKeys = array_unique(array_merge(array_keys($dbEval), array_keys($payEval)));
                $mergedEval = [];
                foreach ($allEvalKeys as $ek) {
                    $eDb = $dbEval[$ek] ?? null;
                    $ePay = $payEval[$ek] ?? null;
                    if ($eDb !== null && $ePay === null) $mergedEval[$ek] = $eDb;
                    elseif ($eDb === null && $ePay !== null) $mergedEval[$ek] = $ePay;
                    else {
                        $sDb = is_array($eDb) ? (float)($eDb['calificacion'] ?? $eDb['nota'] ?? 0) : 0;
                        $sPay = is_array($ePay) ? (float)($ePay['calificacion'] ?? $ePay['nota'] ?? 0) : 0;
                        $mergedEval[$ek] = ($sDb >= $sPay) ? $eDb : $ePay;
                    }
                }

                $mergedInt  = array_replace($dbInt, $payInt);

                // Filas para tablas normalizadas
                foreach ($mergedLec as $lCode) {
                    if ($lCode !== '') $normLecRows[] = [$id, $cIdStr, (string)$lCode];
                }
                foreach ($mergedMod as $mNum) {
                    if ($mNum !== '') $normModRows[] = [$id, $cIdStr, (string)$mNum];
                }
                foreach ($mergedMed as $medNum) {
                    if ($medNum !== '') $normMedRows[] = [$id, $cIdStr, (string)$medNum];
                }
                foreach ($mergedEval as $mNum => $eVal) {
                    if (is_array($eVal)) {
                        $calif = floatval($eVal['calificacion'] ?? $eVal['nota'] ?? 0);
                        $aprob = !empty($eVal['aprobado']) ? 1 : 0;
                        $manual = !empty($eVal['marcadoManual']) ? 1 : 0;
                        $fech = $eVal['fecha'] ?? null;
                        $normEvalRows[] = [$id, $cIdStr, (string)$mNum, $calif, $aprob, $manual, $fech];
                    }
                }
                foreach ($mergedInt as $mNum => $intVal) {
                    $intNum = intval($intVal);
                    if ($intNum > 0) $normIntRows[] = [$id, $cIdStr, (string)$mNum, $intNum];
                }
            }

            // Certificados curso (fusionar BD + payload y deduplicar)
            $dbCerts = $existingCertCurso[$id] ?? [];
            $payCerts = (array)($u['certificadosCurso'] ?? []);
            $certCursoUnique = array_values(array_unique(array_filter(array_merge($dbCerts, $payCerts))));
            foreach ($certCursoUnique as $cId) {
                if ($cId) $certCursoRows[] = [$id, (string)$cId];
            }

            // Certificados carrera (fusionar BD + payload y deduplicar)
            $dbCarCerts = $existingCertCarrera[$id] ?? [];
            $payCarCerts = (array)($u['certificadosCarrera'] ?? []);
            $certCarUnique = array_values(array_unique(array_filter(array_merge($dbCarCerts, $payCarCerts))));
            foreach ($certCarUnique as $carId) {
                if ($carId) $certCarreraRows[] = [$id, (string)$carId];
            }
        }

        // Ejecutar Bulk Inserts de Usuarios y sus relaciones
        db_bulk_insert($conn, 'usuarios', ['id', 'nombre', 'clave', 'rol', 'estado', 'telefono', 'email', 'fecha_nacimiento'], $usuariosRows, 100,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado), telefono=VALUES(telefono), email=VALUES(email), fecha_nacimiento=VALUES(fecha_nacimiento)");

        db_bulk_insert($conn, 'usuario_asignados', ['usuario_id', 'curso_id'], $asignadosRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_carreras_asignadas', ['usuario_id', 'carrera_id', 'estado'], $carrerasAsignadasRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_lecciones_completadas', ['usuario_id', 'curso_id', 'leccion_codigo'], $normLecRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_modulos_aprobados', ['usuario_id', 'curso_id', 'modulo_num'], $normModRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_medallas', ['usuario_id', 'curso_id', 'medalla_num'], $normMedRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_evaluaciones', ['usuario_id', 'curso_id', 'modulo_num', 'calificacion', 'aprobado', 'marcado_manual', 'fecha'], $normEvalRows, 100,
            "ON DUPLICATE KEY UPDATE calificacion=VALUES(calificacion), aprobado=VALUES(aprobado), marcado_manual=VALUES(marcado_manual), fecha=VALUES(fecha)");
        db_bulk_insert($conn, 'usuario_intentos', ['usuario_id', 'curso_id', 'modulo_num', 'intentos'], $normIntRows, 100,
            "ON DUPLICATE KEY UPDATE intentos=VALUES(intentos)");

        db_bulk_insert($conn, 'usuario_certificados_curso', ['usuario_id', 'curso_id'], $certCursoRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_certificados_carrera', ['usuario_id', 'carrera_id'], $certCarreraRows, 200, '', true);

        // --- Cursos (con módulos en tablas relacionales) ---
        $cursosRows       = [];
        $cursoModulosRows = [];
        $cursoLeccionesData  = []; // arrays to bulk insert after getting module IDs
        $cursoPreguntasData  = [];

        foreach (($data['cursos'] ?? []) as $c) {
            $cId            = $c['id']          ?? '';
            $titulo         = $c['titulo']       ?? '';
            $descripcion    = $c['descripcion']  ?? '';
            $tipo           = $c['tipo']         ?? 'especializado';
            $imagen         = db_guardar_imagen_si_base64($c['imagen'] ?? '', $cId);
            $prel           = !empty($c['prelacion']) ? $c['prelacion'] : null;
            $enConstruccion = !empty($c['enConstruccion']) ? 1 : 0;
            if (!$cId) continue;
            $cursosRows[] = [$cId, $titulo, $descripcion, $tipo, $imagen, $prel, $enConstruccion];
        }

        db_bulk_insert($conn, 'cursos', ['id', 'titulo', 'descripcion', 'tipo', 'imagen', 'prelacion', 'en_construccion'], $cursosRows, 50,
            "ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), descripcion=VALUES(descripcion), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), en_construccion=VALUES(en_construccion)");

        // Eliminar cursos que ya no existen (cascada borra módulos/lecciones/preguntas automáticamente)
        $idsActuales = array_filter(array_column($data['cursos'] ?? [], 'id'));
        if (!empty($idsActuales)) {
            $escapedIds = array_map(function($id) use ($conn) { return "'" . $conn->real_escape_string($id) . "'"; }, $idsActuales);
            $conn->query("DELETE FROM `cursos` WHERE id NOT IN (" . implode(',', $escapedIds) . ")");
        } else {
            $conn->query("DELETE FROM `cursos`");
        }

        // Remplazar módulos, lecciones y preguntas de cada curso
        foreach (($data['cursos'] ?? []) as $c) {
            $cId = $c['id'] ?? '';
            if (!$cId) continue;
            $safeCId = $conn->real_escape_string($cId);

            // Borrar módulos existentes del curso (cascada borra lecciones y preguntas)
            $conn->query("DELETE FROM `curso_modulos` WHERE curso_id = '$safeCId'");

            $modOrd = 0;
            foreach (($c['modulos'] ?? []) as $mod) {
                $modTitulo  = $conn->real_escape_string(trim((string)($mod['titulo'] ?? '')));
                $modMaxInt  = (int)($mod['maxIntentos'] ?? $mod['max_intentos'] ?? 0);
                $evalTipo   = $conn->real_escape_string($mod['evaluacion']['tipo'] ?? 'fijo');
                $evalNum    = (int)($mod['evaluacion']['numPreguntas'] ?? 0);
                $evalMez    = !empty($mod['evaluacion']['mezclarOpciones']) ? 1 : 0;

                $conn->query("INSERT INTO `curso_modulos` (curso_id, orden, titulo, max_intentos, eval_tipo, eval_num_preguntas, eval_mezclar_opciones) VALUES ('$safeCId', $modOrd, '$modTitulo', $modMaxInt, '$evalTipo', $evalNum, $evalMez)");
                $modId = (int)$conn->insert_id;
                $modOrd++;

                // Lecciones
                $lecOrd = 0;
                foreach (($mod['lecciones'] ?? []) as $lec) {
                    $lTit  = $conn->real_escape_string(trim((string)($lec['titulo']   ?? '')));
                    $lVid  = $conn->real_escape_string(trim((string)($lec['videoID']  ?? '')));
                    $lCont = $conn->real_escape_string((string)($lec['contenido'] ?? ''));
                    $lAdj  = !empty($lec['adjunto']) ? "'" . $conn->real_escape_string($lec['adjunto']) . "'" : 'NULL';
                    $conn->query("INSERT INTO `curso_lecciones` (modulo_id, curso_id, orden, titulo, video_id, contenido, adjunto) VALUES ($modId, '$safeCId', $lecOrd, '$lTit', '$lVid', '$lCont', $lAdj)");
                    $lecOrd++;
                }

                // Preguntas de evaluación
                $preOrd = 0;
                $preguntas = $mod['evaluacion']['preguntas'] ?? [];
                foreach ($preguntas as $preg) {
                    $pEnun = $conn->real_escape_string((string)($preg['enunciado'] ?? ''));
                    $pImg  = !empty($preg['imagen']) ? "'" . $conn->real_escape_string(db_guardar_imagen_si_base64($preg['imagen'], "pregunta_{$safeCId}_{$modId}")) . "'" : 'NULL';
                    $rawOpc = $preg['opciones'] ?? [];
                    if (is_array($rawOpc)) {
                        foreach ($rawOpc as $oKey => &$oVal) {
                            if (is_array($oVal) && !empty($oVal['imagen'])) {
                                $oVal['imagen'] = db_guardar_imagen_si_base64($oVal['imagen'], "opcion_{$safeCId}_{$modId}_{$preOrd}_{$oKey}");
                            }
                        }
                        unset($oVal);
                    }
                    $pOpc  = $conn->real_escape_string(json_encode($rawOpc, JSON_UNESCAPED_UNICODE));
                    $pCorr = (int)($preg['correcta'] ?? 0);
                    $conn->query("INSERT INTO `curso_preguntas` (modulo_id, curso_id, orden, enunciado, imagen, opciones, correcta) VALUES ($modId, '$safeCId', $preOrd, '$pEnun', $pImg, '$pOpc', $pCorr)");
                    $preOrd++;
                }
            }
        }

        // --- Carreras (sin columna JSON cursos) ---
        $carrerasRows      = [];
        $carreraCursosRows = [];
        foreach (($data['carreras'] ?? []) as $c) {
            $cId     = $c['id']     ?? '';
            $nombre  = $c['nombre'] ?? '';
            $rawCurs = $c['cursos'] ?? [];
            if (!$cId) continue;
            $carrerasRows[] = [$cId, $nombre];

            $ord = 0;
            foreach ((array)$rawCurs as $cItem) {
                $cItemStr = trim((string)$cItem);
                if ($cItemStr) {
                    $carreraCursosRows[] = [$cId, $cItemStr, $ord++];
                }
            }
        }
        db_bulk_insert($conn, 'carreras', ['id', 'nombre'], $carrerasRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)");
        if (!empty($carreraCursosRows)) {
            db_bulk_insert($conn, 'carrera_cursos', ['carrera_id', 'curso_id', 'orden'], $carreraCursosRows, 100, '', true);
        }

        $idsCarreras = array_filter(array_column($data['carreras'] ?? [], 'id'));
        if (!empty($idsCarreras)) {
            $escapedCarIds = array_map(function($id) use ($conn) { return "'" . $conn->real_escape_string($id) . "'"; }, $idsCarreras);
            $conn->query("DELETE FROM `carreras` WHERE id NOT IN (" . implode(',', $escapedCarIds) . ")");
        } else {
            $conn->query("DELETE FROM `carreras`");
        }

        // --- Roles Config (sin columnas JSON permisos/cursos/carreras) ---
        $rolesRows       = [];
        $rolPermisosRows = [];
        $rolCursosRows   = [];
        $rolCarrerasRows = [];
        foreach (($data['rolesConfig'] ?? []) as $r) {
            $rId    = $r['id']     ?? '';
            $nombre = $r['nombre'] ?? '';
            $rawPerm = (array)($r['permisos']  ?? []);
            $rawCur  = (array)($r['cursos']    ?? []);
            $rawCar  = (array)($r['carreras']  ?? []);
            if (!$rId) continue;
            $rolesRows[] = [$rId, $nombre];

            foreach ($rawPerm as $p) {
                $pStr = trim((string)$p);
                if ($pStr) $rolPermisosRows[] = [$rId, $pStr];
            }
            foreach ($rawCur as $rc) {
                $rcStr = trim((string)$rc);
                if ($rcStr) $rolCursosRows[] = [$rId, $rcStr];
            }
            foreach ($rawCar as $rca) {
                $rcaStr = trim((string)$rca);
                if ($rcaStr) $rolCarrerasRows[] = [$rId, $rcaStr];
            }
        }
        db_bulk_insert($conn, 'roles_config', ['id', 'nombre'], $rolesRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)");
        if (!empty($rolPermisosRows)) {
            db_bulk_insert($conn, 'rol_permisos', ['rol_id', 'permiso'], $rolPermisosRows, 100, '', true);
        }
        if (!empty($rolCursosRows)) {
            db_bulk_insert($conn, 'rol_cursos', ['rol_id', 'curso_id'], $rolCursosRows, 100, '', true);
        }
        if (!empty($rolCarrerasRows)) {
            db_bulk_insert($conn, 'rol_carreras', ['rol_id', 'carrera_id'], $rolCarrerasRows, 100, '', true);
        }

        // --- Solicitudes de Registro ---
        $solRegRows = [];
        foreach (($data['solicitudesRegistro'] ?? []) as $s) {
            $sId   = $s['id']             ?? '';
            $snomb = $s['nombre']          ?? '';
            $scl   = $s['clave']           ?? '';
            $sperf = $s['perfilDeseado']   ?? '';
            $sfech = $s['fecha']           ?? '';
            $sauto = $s['autoAssignCareerId'] ?? null;
            if (!$sId) continue;
            if (!str_starts_with($scl, '$2y$')) $scl = password_hash($scl, PASSWORD_BCRYPT);
            $solRegRows[] = [$sId, $snomb, $scl, $sperf, $sfech, $sauto];
        }
        db_bulk_insert($conn, 'solicitudes_registro', ['id', 'nombre', 'clave', 'perfil_deseado', 'fecha', 'auto_assign_career_id'], $solRegRows, 50);

        // --- Solicitudes de Cursos ---
        $solCurRows = [];
        foreach (($data['solicitudesCursos'] ?? []) as $s) {
            $sUId  = $s['userId']   ?? '';
            $sUNom = $s['userName'] ?? '';
            $sCId  = $s['cursoId']  ?? '';
            $sFech = $s['fecha']    ?? '';
            if (!$sUId || !$sCId) continue;
            $solCurRows[] = [$sUId, $sUNom, $sCId, $sFech];
        }
        db_bulk_insert($conn, 'solicitudes_cursos', ['user_id', 'user_name', 'curso_id', 'fecha'], $solCurRows, 50);

        // --- Configuración ---
        $cfgRows = [];
        foreach (($data['configuracion'] ?? []) as $k => $v) {
            if ($k === 'logo' && is_string($v)) {
                $v = db_guardar_imagen_si_base64($v, 'logo_institucional');
            }
            $valor = is_string($v) ? $v : json_encode($v);
            $cfgRows[] = [$k, $valor];
        }
        db_bulk_insert($conn, 'configuracion', ['clave', 'valor'], $cfgRows, 50,
            "ON DUPLICATE KEY UPDATE valor=VALUES(valor)");

        // Reactivar FK checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->commit();

    } catch (Throwable $e) {
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        throw $e;
    }
}
