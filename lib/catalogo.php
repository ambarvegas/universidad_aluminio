<?php
/**
 * lib/catalogo.php — Catálogo académico y configuración
 * Cursos, carreras, roles, imágenes, configuración general y solicitudes.
 * Se carga desde db_mysql.php.
 */

/**
 * Valor SQL de un mensaje de retroalimentación de pregunta (retroCorrecta / retroIncorrecta):
 * texto recortado y escapado, o NULL si viene vacío.
 */
function db_retro_sql(mysqli $conn, array $preg, string $campo): string {
    $texto = trim((string)($preg[$campo] ?? ''));
    if ($texto === '') return 'NULL';
    return "'" . $conn->real_escape_string(mb_substr($texto, 0, 2000)) . "'";
}

/**
 * Si la imagen viene como cadena data:image/... en Base64,
 * la extrae, la guarda como archivo JPG optimizado en uploads/ y retorna la ruta relativa.
 * Si ya es una ruta relativa o URL, la retorna sin modificar.
 */
function db_guardar_imagen_si_base64(string $imagen, string $prefix = 'img'): string {
    if (strpos($imagen, 'data:image/') !== 0) {
        return $imagen;
    }

    $uploadDir = APP_ROOT . '/uploads/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $comaPos = strpos($imagen, ',');
    if ($comaPos === false) return $imagen;

    $base64Data = substr($imagen, $comaPos + 1);
    $binaryData = base64_decode($base64Data);
    if (!$binaryData) return $imagen;

    $cleanPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
    $fileName = "portada_{$cleanPrefix}_" . time() . ".jpg";
    $filePath = $uploadDir . $fileName;
    $relPath  = "uploads/{$fileName}";

    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($binaryData);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $maxWidth = 1200;
            if ($w > $maxWidth) {
                $newH = (int)round($h * $maxWidth / $w);
                $newW = $maxWidth;
            } else {
                $newW = $w;
                $newH = $h;
            }
            $dst = imagecreatetruecolor($newW, $newH);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            if (imagejpeg($dst, $filePath, 85)) {
                imagedestroy($dst);
                return $relPath;
            }
            imagedestroy($dst);
        }
    }

    if (file_put_contents($filePath, $binaryData) !== false) {
        return $relPath;
    }

    return $imagen;
}

/**
 * Inserta o actualiza un curso y sincroniza sus módulos, lecciones y preguntas
 * en las tablas relacionales normalizadas.
 */
function db_upsert_curso(mysqli $conn, array $c): void {
    $id             = trim((string)($c['id']          ?? ''));
    $titulo         = trim((string)($c['titulo']       ?? ''));
    $descripcion    = trim((string)($c['descripcion']  ?? ''));
    $tipo           = trim((string)($c['tipo']         ?? 'especializado'));
    $imagen         = db_guardar_imagen_si_base64($c['imagen'] ?? '', $id);
    $prel           = !empty($c['prelacion']) ? trim((string)$c['prelacion']) : null;
    $enConstruccion = !empty($c['enConstruccion']) ? 1 : 0;
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `cursos` (id, titulo, descripcion, tipo, imagen, prelacion, en_construccion) VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), descripcion=VALUES(descripcion), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), en_construccion=VALUES(en_construccion)"
    );
    $stmt->bind_param('ssssssi', $id, $titulo, $descripcion, $tipo, $imagen, $prel, $enConstruccion);
    $stmt->execute();

    // Reemplazar módulos, lecciones y preguntas del curso
    $safeId = $conn->real_escape_string($id);
    $conn->query("DELETE FROM `curso_modulos` WHERE curso_id = '$safeId'"); // cascada borra lecciones y preguntas

    $modOrd = 0;
    foreach (($c['modulos'] ?? []) as $mod) {
        $modTitulo  = $conn->real_escape_string(trim((string)($mod['titulo'] ?? '')));
        $modMaxInt  = (int)($mod['maxIntentos'] ?? $mod['max_intentos'] ?? 0);
        $evalTipo   = $conn->real_escape_string($mod['evaluacion']['tipo'] ?? 'fijo');
        $evalNum    = (int)($mod['evaluacion']['numPreguntas'] ?? 0);
        $evalMez    = !empty($mod['evaluacion']['mezclarOpciones']) ? 1 : 0;

        $conn->query("INSERT INTO `curso_modulos` (curso_id, orden, titulo, max_intentos, eval_tipo, eval_num_preguntas, eval_mezclar_opciones) VALUES ('$safeId', $modOrd, '$modTitulo', $modMaxInt, '$evalTipo', $evalNum, $evalMez)");
        $modId = (int)$conn->insert_id;
        $modOrd++;

        // Lecciones
        $lecOrd = 0;
        foreach (($mod['lecciones'] ?? []) as $lec) {
            $lTit  = $conn->real_escape_string(trim((string)($lec['titulo']   ?? '')));
            $lVid  = $conn->real_escape_string(trim((string)($lec['videoID']  ?? '')));
            $lCont = $conn->real_escape_string((string)($lec['contenido'] ?? ''));
            $lAdj  = !empty($lec['adjunto']) ? "'" . $conn->real_escape_string($lec['adjunto']) . "'" : 'NULL';
            $conn->query("INSERT INTO `curso_lecciones` (modulo_id, curso_id, orden, titulo, video_id, contenido, adjunto) VALUES ($modId, '$safeId', $lecOrd, '$lTit', '$lVid', '$lCont', $lAdj)");
            $lecOrd++;
        }

        // Preguntas de evaluación
        $preOrd   = 0;
        $preguntas = $mod['evaluacion']['preguntas'] ?? [];
        foreach ($preguntas as $preg) {
            $pEnun = $conn->real_escape_string((string)($preg['enunciado'] ?? ''));
            $pImg  = !empty($preg['imagen']) ? "'" . $conn->real_escape_string(db_guardar_imagen_si_base64($preg['imagen'], "pregunta_{$safeId}_{$modId}")) . "'" : 'NULL';
            $rawOpc = $preg['opciones'] ?? [];
            if (is_array($rawOpc)) {
                foreach ($rawOpc as $oKey => &$oVal) {
                    if (is_array($oVal) && !empty($oVal['imagen'])) {
                        $oVal['imagen'] = db_guardar_imagen_si_base64($oVal['imagen'], "opcion_{$safeId}_{$modId}_{$preOrd}_{$oKey}");
                    }
                }
                unset($oVal);
            }
            $pOpc  = $conn->real_escape_string(json_encode($rawOpc, JSON_UNESCAPED_UNICODE));
            $pCorr = (int)($preg['correcta'] ?? 0);
            $pRetC = db_retro_sql($conn, $preg, 'retroCorrecta');
            $pRetI = db_retro_sql($conn, $preg, 'retroIncorrecta');
            $conn->query("INSERT INTO `curso_preguntas` (modulo_id, curso_id, orden, enunciado, imagen, opciones, correcta, retro_correcta, retro_incorrecta) VALUES ($modId, '$safeId', $preOrd, '$pEnun', $pImg, '$pOpc', $pCorr, $pRetC, $pRetI)");
            $preOrd++;
        }
    }
}

/**
 * Elimina un curso por su ID.
 */
function db_delete_curso(mysqli $conn, string $id): void {
    $stmt = $conn->prepare("DELETE FROM `cursos` WHERE id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
}

/**
 * Inserta o actualiza una carrera y sincroniza carrera_cursos.
 */
function db_upsert_carrera(mysqli $conn, array $c): void {
    $id        = trim((string)($c['id']     ?? ''));
    $nombre    = trim((string)($c['nombre'] ?? ''));
    $rawCursos = $c['cursos'] ?? [];
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `carreras` (id, nombre) VALUES (?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)"
    );
    $stmt->bind_param('ss', $id, $nombre);
    $stmt->execute();

    // Actualizar tabla relacional carrera_cursos
    $safeId = $conn->real_escape_string($id);
    $conn->query("DELETE FROM `carrera_cursos` WHERE carrera_id = '$safeId'");
    $stmtCC = $conn->prepare("INSERT INTO `carrera_cursos` (carrera_id, curso_id, orden) VALUES (?,?,?)");
    $ord = 0;
    foreach ((array)$rawCursos as $cItem) {
        $cItemStr = trim((string)$cItem);
        if ($cItemStr) {
            $stmtCC->bind_param('ssi', $id, $cItemStr, $ord);
            $stmtCC->execute();
            $ord++;
        }
    }
}

/**
 * Elimina una carrera por su ID.
 */
function db_delete_carrera(mysqli $conn, string $id): void {
    $stmt = $conn->prepare("DELETE FROM `carreras` WHERE id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
}

/**
 * Inserta o actualiza un rol y sincroniza rol_permisos, rol_cursos, rol_carreras.
 */
function db_upsert_rol(mysqli $conn, array $r): void {
    $id      = trim((string)($r['id']     ?? ''));
    $nombre  = trim((string)($r['nombre'] ?? ''));
    $rawPerm = (array)($r['permisos']  ?? []);
    $rawCur  = (array)($r['cursos']    ?? []);
    $rawCar  = (array)($r['carreras']  ?? []);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `roles_config` (id, nombre) VALUES (?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)"
    );
    $stmt->bind_param('ss', $id, $nombre);
    $stmt->execute();

    // Actualizar tablas relacionales de roles
    $safeId = $conn->real_escape_string($id);
    $conn->query("DELETE FROM `rol_permisos` WHERE rol_id = '$safeId'");
    $conn->query("DELETE FROM `rol_cursos`   WHERE rol_id = '$safeId'");
    $conn->query("DELETE FROM `rol_carreras` WHERE rol_id = '$safeId'");

    $stmtP = $conn->prepare("INSERT IGNORE INTO `rol_permisos` (rol_id, permiso) VALUES (?,?)");
    foreach ($rawPerm as $p) {
        $pStr = trim((string)$p);
        if ($pStr) {
            $stmtP->bind_param('ss', $id, $pStr);
            $stmtP->execute();
        }
    }

    $stmtC = $conn->prepare("INSERT IGNORE INTO `rol_cursos` (rol_id, curso_id) VALUES (?,?)");
    foreach ($rawCur as $rc) {
        $rcStr = trim((string)$rc);
        if ($rcStr) {
            $stmtC->bind_param('ss', $id, $rcStr);
            $stmtC->execute();
        }
    }

    $stmtCar = $conn->prepare("INSERT IGNORE INTO `rol_carreras` (rol_id, carrera_id) VALUES (?,?)");
    foreach ($rawCar as $rca) {
        $rcaStr = trim((string)$rca);
        if ($rcaStr) {
            $stmtCar->bind_param('ss', $id, $rcaStr);
            $stmtCar->execute();
        }
    }
}

/**
 * Elimina un rol por su ID.
 */
function db_delete_rol(mysqli $conn, string $id): void {
    $stmt = $conn->prepare("DELETE FROM `roles_config` WHERE id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
}

/**
 * Guarda una clave de configuración.
 */
function db_upsert_config(mysqli $conn, string $clave, $valor): void {
    if ($clave === 'logo' && is_string($valor)) {
        $valor = db_guardar_imagen_si_base64($valor, 'logo_institucional');
    }
    $valorStr = is_string($valor) ? $valor : json_encode($valor);
    $stmt = $conn->prepare(
        "INSERT INTO `configuracion` (clave, valor) VALUES (?,?)
         ON DUPLICATE KEY UPDATE valor=VALUES(valor)"
    );
    $stmt->bind_param('ss', $clave, $valorStr);
    $stmt->execute();
}

/**
 * Guarda un lote de pares clave-valor de configuración dentro de una sola transacción.
 */
function db_upsert_config_batch(mysqli $conn, array $configs): void {
    $conn->begin_transaction();
    try {
        foreach ($configs as $clave => $valor) {
            $clave = trim((string)$clave);
            if ($clave === '') continue;
            db_upsert_config($conn, $clave, $valor);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Agrega una solicitud de registro.
 */
function db_add_solicitud_registro(mysqli $conn, array $s): void {
    $id    = $s['id']               ?? '';
    $nom   = $s['nombre']           ?? '';
    $cl    = $s['clave']            ?? '';
    $perf  = $s['perfilDeseado']    ?? '';
    $fecha = $s['fecha']            ?? date('d/m/Y');
    $auto  = $s['autoAssignCareerId'] ?? null;
    if (!$id) return;

    // Hashear clave de solicitud
    $cl = str_starts_with($cl, '$2y$') ? $cl : password_hash($cl, PASSWORD_BCRYPT);

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO `solicitudes_registro` (id, nombre, clave, perfil_deseado, fecha, auto_assign_career_id)
         VALUES (?,?,?,?,?,?)"
    );
    $stmt->bind_param('ssssss', $id, $nom, $cl, $perf, $fecha, $auto);
    $stmt->execute();
}

/**
 * Elimina una solicitud de registro por ID.
 */
function db_delete_solicitud_registro(mysqli $conn, string $id): void {
    $stmt = $conn->prepare("DELETE FROM `solicitudes_registro` WHERE id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
}

/**
 * Agrega una solicitud de acceso a curso.
 */
function db_add_solicitud_curso(mysqli $conn, array $s): void {
    $uid   = $s['userId']   ?? '';
    $unom  = $s['userName'] ?? '';
    $cid   = $s['cursoId']  ?? '';
    $fecha = $s['fecha']    ?? date('d/m/Y');

    // Evitar duplicados
    $check = $conn->prepare("SELECT COUNT(*) as cnt FROM `solicitudes_cursos` WHERE user_id = ? AND curso_id = ?");
    $check->bind_param('ss', $uid, $cid);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if ($row['cnt'] > 0) return;

    $stmt = $conn->prepare(
        "INSERT INTO `solicitudes_cursos` (user_id, user_name, curso_id, fecha) VALUES (?,?,?,?)"
    );
    $stmt->bind_param('ssss', $uid, $unom, $cid, $fecha);
    $stmt->execute();
}

/**
 * Elimina una solicitud de acceso a curso.
 */
function db_delete_solicitud_curso(mysqli $conn, string $userId, string $cursoId): void {
    $stmt = $conn->prepare("DELETE FROM `solicitudes_cursos` WHERE user_id = ? AND curso_id = ?");
    $stmt->bind_param('ss', $userId, $cursoId);
    $stmt->execute();
}
