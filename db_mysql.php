<?php
/**
 * db_mysql.php
 * Módulo de conexión y acceso a datos — MySQL
 * Universidad del Aluminio
 */

// ============================================================
// CONFIGURACIÓN DE CONEXIÓN
// ============================================================

define('MYSQL_HOST', '185.2.168.16');
define('MYSQL_USER', 'alufletes_admin');
define('MYSQL_PASS', '2fjy68PjsK7u2Hi');
define('MYSQL_DB',   'alufletes_universidad');
define('MYSQL_PORT', 3306);
define('MYSQL_CHARSET', 'utf8mb4');

/**
 * Retorna una conexión mysqli activa.
 * Lanza una excepción si no puede conectarse.
 */
function db_connect(): mysqli {
    $conn = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB, MYSQL_PORT);
    if ($conn->connect_error) {
        throw new RuntimeException('Error de conexión MySQL: ' . $conn->connect_error);
    }
    $conn->set_charset(MYSQL_CHARSET);
    return $conn;
}

// ============================================================
// CREACIÓN DE TABLAS (DDL)
// ============================================================

/**
 * Crea todas las tablas necesarias si no existen.
 * Seguro para ejecutar múltiples veces (IF NOT EXISTS).
 */
function db_create_tables(mysqli $conn): void {
    $statements = [

        // Usuarios
        "CREATE TABLE IF NOT EXISTS `usuarios` (
            `id`      VARCHAR(50)  NOT NULL,
            `nombre`  VARCHAR(255) NOT NULL DEFAULT '',
            `clave`   VARCHAR(255) NOT NULL DEFAULT '12345',
            `rol`     VARCHAR(100) NOT NULL DEFAULT 'participante',
            `estado`  VARCHAR(50)  NOT NULL DEFAULT 'activo',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Cursos asignados directamente a un usuario
        "CREATE TABLE IF NOT EXISTS `usuario_asignados` (
            `usuario_id` VARCHAR(50)  NOT NULL,
            `curso_id`   VARCHAR(100) NOT NULL,
            PRIMARY KEY (`usuario_id`, `curso_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Carreras asignadas a un usuario
        "CREATE TABLE IF NOT EXISTS `usuario_carreras_asignadas` (
            `usuario_id` VARCHAR(50)  NOT NULL,
            `carrera_id` VARCHAR(100) NOT NULL,
            `estado`     VARCHAR(50)  NOT NULL DEFAULT 'Incompleta',
            PRIMARY KEY (`usuario_id`, `carrera_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso por curso de cada usuario (datos JSON para flexibilidad)
        "CREATE TABLE IF NOT EXISTS `usuario_progreso` (
            `usuario_id`            VARCHAR(50)  NOT NULL,
            `curso_id`              VARCHAR(100) NOT NULL,
            `lecciones_completadas` JSON,
            `modulos_aprobados`     JSON,
            `medallas`              JSON,
            `evaluaciones`          JSON,
            `intentos`              JSON,
            PRIMARY KEY (`usuario_id`, `curso_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Certificados de cursos
        "CREATE TABLE IF NOT EXISTS `usuario_certificados_curso` (
            `usuario_id` VARCHAR(50)  NOT NULL,
            `curso_id`   VARCHAR(100) NOT NULL,
            PRIMARY KEY (`usuario_id`, `curso_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Certificados de carreras
        "CREATE TABLE IF NOT EXISTS `usuario_certificados_carrera` (
            `usuario_id` VARCHAR(50)  NOT NULL,
            `carrera_id` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`usuario_id`, `carrera_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Cursos (imagen y módulos se guardan como texto/JSON por compatibilidad)
        "CREATE TABLE IF NOT EXISTS `cursos` (
            `id`        VARCHAR(100) NOT NULL,
            `titulo`    VARCHAR(255) NOT NULL DEFAULT '',
            `tipo`      VARCHAR(50)  NOT NULL DEFAULT 'especializado',
            `imagen`    LONGTEXT,
            `prelacion` VARCHAR(100) DEFAULT NULL,
            `modulos`   LONGTEXT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Carreras
        "CREATE TABLE IF NOT EXISTS `carreras` (
            `id`     VARCHAR(100) NOT NULL,
            `nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `cursos` JSON,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Configuración de roles
        "CREATE TABLE IF NOT EXISTS `roles_config` (
            `id`       VARCHAR(100) NOT NULL,
            `nombre`   VARCHAR(255) NOT NULL DEFAULT '',
            `permisos` JSON,
            `cursos`   JSON,
            `carreras` JSON,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Solicitudes de registro (nuevos usuarios)
        "CREATE TABLE IF NOT EXISTS `solicitudes_registro` (
            `id`                   VARCHAR(50)  NOT NULL,
            `nombre`               VARCHAR(255) NOT NULL DEFAULT '',
            `clave`                VARCHAR(255) NOT NULL DEFAULT '',
            `perfil_deseado`       VARCHAR(100) NOT NULL DEFAULT '',
            `fecha`                VARCHAR(50)  NOT NULL DEFAULT '',
            `auto_assign_career_id` VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Solicitudes de acceso a cursos
        "CREATE TABLE IF NOT EXISTS `solicitudes_cursos` (
            `id`        INT          NOT NULL AUTO_INCREMENT,
            `user_id`   VARCHAR(50)  NOT NULL,
            `user_name` VARCHAR(255) NOT NULL DEFAULT '',
            `curso_id`  VARCHAR(100) NOT NULL,
            `fecha`     VARCHAR(50)  NOT NULL DEFAULT '',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Configuración general (clave-valor)
        "CREATE TABLE IF NOT EXISTS `configuracion` (
            `clave` VARCHAR(100) NOT NULL,
            `valor` MEDIUMTEXT,
            PRIMARY KEY (`clave`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Log de actividad y auditoría
        "CREATE TABLE IF NOT EXISTS `activity_log` (
            `id`         INT          NOT NULL AUTO_INCREMENT,
            `usuario_id` VARCHAR(50)  NOT NULL DEFAULT '',
            `accion`     VARCHAR(100) NOT NULL DEFAULT '',
            `detalle`    TEXT,
            `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
            `fecha`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX (`usuario_id`),
            INDEX (`fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Rate limiting de intentos de login
        "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `ip`              VARCHAR(45) NOT NULL,
            `intentos`        INT         NOT NULL DEFAULT 0,
            `bloqueado_hasta` DATETIME DEFAULT NULL,
            `ultima_vez`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Error creando tabla: ' . $conn->error . ' — SQL: ' . substr($sql, 0, 80));
        }
    }
}

// ============================================================
// LECTURA — Reconstruye el objeto DB completo (compatibilidad)
// ============================================================

/**
 * Lee todos los datos de MySQL y los retorna como un array
 * con la misma estructura que tenía db.json.
 */
function db_read_all(mysqli $conn): array {
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
    $res = $conn->query("SELECT id, nombre, clave, rol, estado FROM `usuarios`");
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

    // Progreso
    $res = $conn->query("SELECT usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso`");
    while ($row = $res->fetch_assoc()) {
        $uid = $row['usuario_id'];
        $cid = $row['curso_id'];
        if (!isset($usuariosMap[$uid])) continue;

        $evals = json_decode($row['evaluaciones'] ?? '{}', true);
        if (!is_array($evals)) $evals = [];
        $ints = json_decode($row['intentos'] ?? '{}', true);
        if (!is_array($ints)) $ints = [];

        $usuariosMap[$uid]['progreso'][$cid] = [
            'leccionesCompletadas' => json_decode($row['lecciones_completadas'] ?? '[]', true) ?? [],
            'modulosAprobados'     => json_decode($row['modulos_aprobados']     ?? '[]', true) ?? [],
            'medallas'             => json_decode($row['medallas']              ?? '[]', true) ?? [],
            'evaluaciones'         => empty($evals) ? (object)[] : $evals,
            'intentos'             => empty($ints) ? (object)[] : $ints,
        ];
    }

    // Format empty progreso as object for JSON
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

    // --- Cursos ---
    $res = $conn->query("SELECT id, titulo, tipo, imagen, prelacion, modulos FROM `cursos`");
    while ($row = $res->fetch_assoc()) {
        $row['modulos'] = json_decode($row['modulos'] ?? '[]', true) ?? [];
        if (!$row['prelacion']) unset($row['prelacion']);
        $db['cursos'][] = $row;
    }

    // --- Carreras ---
    $res = $conn->query("SELECT id, nombre, cursos FROM `carreras`");
    while ($row = $res->fetch_assoc()) {
        $row['cursos'] = json_decode($row['cursos'] ?? '[]', true) ?? [];
        $db['carreras'][] = $row;
    }

    // --- Roles Config ---
    $res = $conn->query("SELECT id, nombre, permisos, cursos, carreras FROM `roles_config`");
    while ($row = $res->fetch_assoc()) {
        $row['permisos']  = json_decode($row['permisos']  ?? '[]', true) ?? [];
        $row['cursos']    = json_decode($row['cursos']    ?? '[]', true) ?? [];
        $row['carreras']  = json_decode($row['carreras']  ?? '[]', true) ?? [];
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

// ============================================================
// ESCRITURA — Guarda el objeto DB completo (compatibilidad)
// ============================================================

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
    $conn->begin_transaction();

    try {
        // Desactivar FK checks temporalmente para truncar sin orden
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Limpiar tablas dependientes
        $conn->query("TRUNCATE TABLE `usuario_asignados`");
        $conn->query("TRUNCATE TABLE `usuario_carreras_asignadas`");
        $conn->query("TRUNCATE TABLE `usuario_progreso`");
        $conn->query("TRUNCATE TABLE `usuario_certificados_curso`");
        $conn->query("TRUNCATE TABLE `usuario_certificados_carrera`");
        $conn->query("TRUNCATE TABLE `solicitudes_registro`");
        $conn->query("TRUNCATE TABLE `solicitudes_cursos`");

        // --- Recopilar arrays de filas para Bulk Insert ---
        $existingHashes = [];
        $resH = $conn->query("SELECT id, clave FROM `usuarios`");
        if ($resH) {
            while ($r = $resH->fetch_assoc()) {
                $existingHashes[$r['id']] = $r['clave'];
            }
        }

        $usuariosRows          = [];
        $asignadosRows         = [];
        $carrerasAsignadasRows = [];
        $progresoRows          = [];
        $certCursoRows         = [];
        $certCarreraRows       = [];

        foreach (($data['usuarios'] ?? []) as $u) {
            $id       = trim((string)($u['id'] ?? ''));
            $nombre   = trim((string)($u['nombre'] ?? ''));
            $rawClave = trim((string)($u['clave'] ?? ''));
            $rol      = trim((string)($u['rol'] ?? 'participante'));
            $estado   = trim((string)($u['estado'] ?? 'activo'));
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

            $usuariosRows[] = [$id, $nombre, $clave, $rol, $estado];

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

            // Progreso
            $progreso = $u['progreso'] ?? [];
            if (is_object($progreso)) $progreso = (array)$progreso;
            foreach ($progreso as $cId => $prog) {
                if (is_array($prog)) {
                    $lec  = json_encode($prog['leccionesCompletadas'] ?? []);
                    $mod  = json_encode($prog['modulosAprobados']     ?? []);
                    $med  = json_encode($prog['medallas']             ?? []);
                    $eval = json_encode($prog['evaluaciones']         ?? (object)[]);
                    $int  = json_encode($prog['intentos']             ?? (object)[]);
                } else {
                    $lec = $mod = $med = '[]';
                    $eval = $int = '{}';
                }
                $cIdStr = (string)$cId;
                $progresoRows[] = [$id, $cIdStr, $lec, $mod, $med, $eval, $int];
            }

            // Certificados curso (deduplicar)
            $certCursoUnique = array_unique(array_filter((array)($u['certificadosCurso'] ?? [])));
            foreach ($certCursoUnique as $cId) {
                if ($cId) $certCursoRows[] = [$id, (string)$cId];
            }

            // Certificados carrera (deduplicar)
            $certCarUnique = array_unique(array_filter((array)($u['certificadosCarrera'] ?? [])));
            foreach ($certCarUnique as $carId) {
                if ($carId) $certCarreraRows[] = [$id, (string)$carId];
            }
        }

        // Ejecutar Bulk Inserts de Usuarios y sus relaciones
        db_bulk_insert($conn, 'usuarios', ['id', 'nombre', 'clave', 'rol', 'estado'], $usuariosRows, 100,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado)");

        db_bulk_insert($conn, 'usuario_asignados', ['usuario_id', 'curso_id'], $asignadosRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_carreras_asignadas', ['usuario_id', 'carrera_id', 'estado'], $carrerasAsignadasRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_progreso', ['usuario_id', 'curso_id', 'lecciones_completadas', 'modulos_aprobados', 'medallas', 'evaluaciones', 'intentos'], $progresoRows, 100,
            "ON DUPLICATE KEY UPDATE lecciones_completadas=VALUES(lecciones_completadas), modulos_aprobados=VALUES(modulos_aprobados), medallas=VALUES(medallas), evaluaciones=VALUES(evaluaciones), intentos=VALUES(intentos)");
        db_bulk_insert($conn, 'usuario_certificados_curso', ['usuario_id', 'curso_id'], $certCursoRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_certificados_carrera', ['usuario_id', 'carrera_id'], $certCarreraRows, 200, '', true);

        // --- Cursos ---
        $cursosRows = [];
        foreach (($data['cursos'] ?? []) as $c) {
            $cId      = $c['id']       ?? '';
            $titulo   = $c['titulo']   ?? '';
            $tipo     = $c['tipo']     ?? 'especializado';
            $imagen   = $c['imagen']   ?? '';
            $prel     = $c['prelacion'] ?? null;
            $modulos  = json_encode($c['modulos'] ?? []);
            if (!$cId) continue;
            $cursosRows[] = [$cId, $titulo, $tipo, $imagen, $prel, $modulos];
        }
        db_bulk_insert($conn, 'cursos', ['id', 'titulo', 'tipo', 'imagen', 'prelacion', 'modulos'], $cursosRows, 50,
            "ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), modulos=VALUES(modulos)");

        // Eliminar cursos que ya no existen
        $idsActuales = array_filter(array_column($data['cursos'] ?? [], 'id'));
        if (!empty($idsActuales)) {
            $escapedIds = array_map(function($id) use ($conn) { return "'" . $conn->real_escape_string($id) . "'"; }, $idsActuales);
            $conn->query("DELETE FROM `cursos` WHERE id NOT IN (" . implode(',', $escapedIds) . ")");
        } else {
            $conn->query("DELETE FROM `cursos`");
        }

        // --- Carreras ---
        $carrerasRows = [];
        foreach (($data['carreras'] ?? []) as $c) {
            $cId    = $c['id']     ?? '';
            $nombre = $c['nombre'] ?? '';
            $curs   = json_encode($c['cursos'] ?? []);
            if (!$cId) continue;
            $carrerasRows[] = [$cId, $nombre, $curs];
        }
        db_bulk_insert($conn, 'carreras', ['id', 'nombre', 'cursos'], $carrerasRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), cursos=VALUES(cursos)");

        $idsCarreras = array_filter(array_column($data['carreras'] ?? [], 'id'));
        if (!empty($idsCarreras)) {
            $escapedCarIds = array_map(function($id) use ($conn) { return "'" . $conn->real_escape_string($id) . "'"; }, $idsCarreras);
            $conn->query("DELETE FROM `carreras` WHERE id NOT IN (" . implode(',', $escapedCarIds) . ")");
        } else {
            $conn->query("DELETE FROM `carreras`");
        }

        // --- Roles Config ---
        $rolesRows = [];
        foreach (($data['rolesConfig'] ?? []) as $r) {
            $rId      = $r['id']       ?? '';
            $nombre   = $r['nombre']   ?? '';
            $permisos = json_encode($r['permisos']  ?? []);
            $cursos   = json_encode($r['cursos']    ?? []);
            $carreras = json_encode($r['carreras']  ?? []);
            if (!$rId) continue;
            $rolesRows[] = [$rId, $nombre, $permisos, $cursos, $carreras];
        }
        db_bulk_insert($conn, 'roles_config', ['id', 'nombre', 'permisos', 'cursos', 'carreras'], $rolesRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), permisos=VALUES(permisos), cursos=VALUES(cursos), carreras=VALUES(carreras)");

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

// ============================================================
// SEGURIDAD — Contraseñas
// ============================================================

/**
 * Verifica login server-side. Retorna datos del usuario (sin clave) o null.
 * Soporta claves en texto plano (legadas) y bcrypt.
 */
function db_verify_login(mysqli $conn, string $id, string $clave): ?array {
    $stmt = $conn->prepare("SELECT id, nombre, clave, rol, estado FROM `usuarios` WHERE id = ? AND estado = 'activo'");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;

    $hash = $row['clave'];
    $valid = false;

    // Verificar bcrypt primero
    if (str_starts_with($hash, '$2y$')) {
        $valid = password_verify($clave, $hash);
    } else {
        // Clave legada en texto plano
        $valid = ($clave === $hash);
        if ($valid) {
            $newHash = password_hash($clave, PASSWORD_BCRYPT);
            $upd = $conn->prepare("UPDATE `usuarios` SET clave = ? WHERE id = ?");
            $upd->bind_param('ss', $newHash, $id);
            $upd->execute();
        }
    }

    if (!$valid) return null;

    // Cargar directamente las relaciones del usuario para máxima velocidad
    $usuario = [
        'id'                  => $row['id'],
        'nombre'              => $row['nombre'],
        'rol'                 => $row['rol'],
        'estado'              => $row['estado'],
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

    // Progreso
    $stmtProg = $conn->prepare("SELECT curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ?");
    $stmtProg->bind_param('s', $id);
    $stmtProg->execute();
    $resProg = $stmtProg->get_result();
    while ($r = $resProg->fetch_assoc()) {
        $cid = $r['curso_id'];
        $evals = json_decode($r['evaluaciones'] ?? '{}', true) ?: [];
        $ints = json_decode($r['intentos'] ?? '{}', true) ?: [];
        $usuario['progreso'][$cid] = [
            'leccionesCompletadas' => json_decode($r['lecciones_completadas'] ?? '[]', true) ?: [],
            'modulosAprobados'     => json_decode($r['modulos_aprobados'] ?? '[]', true) ?: [],
            'medallas'             => json_decode($r['medallas'] ?? '[]', true) ?: [],
            'evaluaciones'         => empty($evals) ? (object)[] : $evals,
            'intentos'             => empty($ints) ? (object)[] : $ints,
        ];
    }
    if (empty($usuario['progreso'])) $usuario['progreso'] = (object)[];

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
 * Modifica db_read_all para que NO incluya claves en la respuesta.
 * Llama a db_read_all y limpia las claves.
 */
function db_read_safe(mysqli $conn): array {
    $data = db_read_all($conn);
    foreach ($data['usuarios'] as &$u) {
        unset($u['clave']);
    }
    return $data;
}

// ============================================================
// ESCRITURAS GRANULARES — Por entidad individual
// ============================================================

/**
 * Inserta o actualiza un usuario completo (con todas sus relaciones).
 */
function db_upsert_usuario(mysqli $conn, array $u): void {
    $conn->begin_transaction();
    try {
        $id     = $u['id']     ?? '';
        $nombre = $u['nombre'] ?? '';
        $rol    = $u['rol']    ?? 'participante';
        $estado = $u['estado'] ?? 'activo';
        if (!$id) { $conn->rollback(); return; }

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
            "INSERT INTO `usuarios` (id, nombre, clave, rol, estado) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado)"
        );
        $stmt->bind_param('sssss', $id, $nombre, $nuevaClave, $rol, $estado);
        $stmt->execute();

        // Limpiar y reescribir relaciones
        $conn->query("DELETE FROM `usuario_asignados`           WHERE usuario_id = '$id'");
        $conn->query("DELETE FROM `usuario_carreras_asignadas`  WHERE usuario_id = '$id'");
        $conn->query("DELETE FROM `usuario_progreso`            WHERE usuario_id = '$id'");
        $conn->query("DELETE FROM `usuario_certificados_curso`  WHERE usuario_id = '$id'");
        $conn->query("DELETE FROM `usuario_certificados_carrera` WHERE usuario_id = '$id'");

        $stmtA = $conn->prepare("INSERT IGNORE INTO `usuario_asignados` (usuario_id, curso_id) VALUES (?,?)");
        foreach (($u['asignados'] ?? []) as $cId) {
            $stmtA->bind_param('ss', $id, $cId);
            $stmtA->execute();
        }

        $stmtCA = $conn->prepare("INSERT INTO `usuario_carreras_asignadas` (usuario_id, carrera_id, estado) VALUES (?,?,?)");
        foreach (($u['carrerasAsignadas'] ?? []) as $ca) {
            $caId = is_array($ca) ? ($ca['id'] ?? '') : $ca;
            $caEst = is_array($ca) ? ($ca['estado'] ?? 'Incompleta') : 'Incompleta';
            if (!$caId) continue;
            $stmtCA->bind_param('sss', $id, $caId, $caEst);
            $stmtCA->execute();
        }

        $stmtP = $conn->prepare(
            "INSERT INTO `usuario_progreso` (usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos)
             VALUES (?,?,?,?,?,?,?)"
        );
        $prog = $u['progreso'] ?? [];
        if (is_object($prog)) $prog = (array)$prog;
        foreach ($prog as $cId => $p) {
            $lec  = json_encode($p['leccionesCompletadas'] ?? []);
            $mod  = json_encode($p['modulosAprobados']     ?? []);
            $med  = json_encode($p['medallas']             ?? []);
            $eval = json_encode($p['evaluaciones']         ?? (object)[]);
            $int  = json_encode($p['intentos']             ?? (object)[]);
            $cStr = (string)$cId;
            $stmtP->bind_param('sssssss', $id, $cStr, $lec, $mod, $med, $eval, $int);
            $stmtP->execute();
        }

        $stmtCC = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
        foreach (($u['certificadosCurso'] ?? []) as $cId) {
            $stmtCC->bind_param('ss', $id, $cId);
            $stmtCC->execute();
        }

        $stmtCCar = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_carrera` (usuario_id, carrera_id) VALUES (?,?)");
        foreach (($u['certificadosCarrera'] ?? []) as $carId) {
            $stmtCCar->bind_param('ss', $id, $carId);
            $stmtCCar->execute();
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
        $conn->query("DELETE FROM `usuario_progreso`            WHERE usuario_id = '$safeId'");
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
 * Guarda solo el progreso de un usuario en un curso específico.
 * El endpoint más llamado — payload mínimo.
 */
function db_upsert_progreso(mysqli $conn, string $userId, string $cursoId, array $prog): void {
    $lec  = json_encode($prog['leccionesCompletadas'] ?? []);
    $mod  = json_encode($prog['modulosAprobados']     ?? []);
    $med  = json_encode($prog['medallas']             ?? []);
    $eval = json_encode($prog['evaluaciones']         ?? (object)[]);
    $int  = json_encode($prog['intentos']             ?? (object)[]);

    $stmt = $conn->prepare(
        "INSERT INTO `usuario_progreso` (usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           lecciones_completadas = VALUES(lecciones_completadas),
           modulos_aprobados     = VALUES(modulos_aprobados),
           medallas              = VALUES(medallas),
           evaluaciones          = VALUES(evaluaciones),
           intentos              = VALUES(intentos)"
    );
    $stmt->bind_param('sssssss', $userId, $cursoId, $lec, $mod, $med, $eval, $int);
    $stmt->execute();
}

/**
 * Inserta o actualiza un curso.
 */
function db_upsert_curso(mysqli $conn, array $c): void {
    $id      = $c['id']       ?? '';
    $titulo  = $c['titulo']   ?? '';
    $tipo    = $c['tipo']     ?? 'especializado';
    $imagen  = $c['imagen']   ?? '';
    $prel    = $c['prelacion'] ?? null;
    $modulos = json_encode($c['modulos'] ?? []);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `cursos` (id, titulo, tipo, imagen, prelacion, modulos) VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), modulos=VALUES(modulos)"
    );
    $stmt->bind_param('ssssss', $id, $titulo, $tipo, $imagen, $prel, $modulos);
    $stmt->execute();
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
 * Inserta o actualiza una carrera.
 */
function db_upsert_carrera(mysqli $conn, array $c): void {
    $id     = $c['id']     ?? '';
    $nombre = $c['nombre'] ?? '';
    $cursos = json_encode($c['cursos'] ?? []);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `carreras` (id, nombre, cursos) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), cursos=VALUES(cursos)"
    );
    $stmt->bind_param('sss', $id, $nombre, $cursos);
    $stmt->execute();
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
 * Inserta o actualiza un rol.
 */
function db_upsert_rol(mysqli $conn, array $r): void {
    $id       = $r['id']       ?? '';
    $nombre   = $r['nombre']   ?? '';
    $permisos = json_encode($r['permisos']  ?? []);
    $cursos   = json_encode($r['cursos']    ?? []);
    $carreras = json_encode($r['carreras']  ?? []);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `roles_config` (id, nombre, permisos, cursos, carreras) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), permisos=VALUES(permisos), cursos=VALUES(cursos), carreras=VALUES(carreras)"
    );
    $stmt->bind_param('sssss', $id, $nombre, $permisos, $cursos, $carreras);
    $stmt->execute();
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
    $valorStr = is_string($valor) ? $valor : json_encode($valor);
    $stmt = $conn->prepare(
        "INSERT INTO `configuracion` (clave, valor) VALUES (?,?)
         ON DUPLICATE KEY UPDATE valor=VALUES(valor)"
    );
    $stmt->bind_param('ss', $clave, $valorStr);
    $stmt->execute();
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

// ============================================================
// HARDENING — Log de actividad
// ============================================================

/**
 * Crea la tabla de log si no existe.
 */
function db_create_activity_log_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `activity_log` (
        `id`         INT          NOT NULL AUTO_INCREMENT,
        `usuario_id` VARCHAR(50)  NOT NULL DEFAULT '',
        `accion`     VARCHAR(100) NOT NULL DEFAULT '',
        `detalle`    TEXT,
        `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
        `fecha`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX (`usuario_id`),
        INDEX (`fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Registra una acción en el log de actividad.
 */
function db_log_activity(mysqli $conn, string $userId, string $accion, string $detalle = '', string $ip = ''): void {
    if (!$ip) $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare(
        "INSERT INTO `activity_log` (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)"
    );
    $stmt->bind_param('ssss', $userId, $accion, $detalle, $ip);
    $stmt->execute();
}

// ============================================================
// RATE LIMITING — Protección de login
// ============================================================

/**
 * Crea la tabla de rate limiting si no existe.
 */
function db_create_rate_limit_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `login_attempts` (
        `ip`         VARCHAR(45) NOT NULL,
        `intentos`   INT         NOT NULL DEFAULT 0,
        `bloqueado_hasta` DATETIME DEFAULT NULL,
        `ultima_vez` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`ip`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Verifica si una IP está bloqueada por demasiados intentos.
 * Retorna true si está bloqueada.
 */
function db_is_rate_limited(mysqli $conn, string $ip): bool {
    $stmt = $conn->prepare("SELECT bloqueado_hasta FROM `login_attempts` WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return false;
    if ($row['bloqueado_hasta'] && new DateTime($row['bloqueado_hasta']) > new DateTime()) {
        return true;
    }
    return false;
}

/**
 * Registra un intento fallido. Bloquea la IP por 15 min si supera 5 intentos.
 */
function db_record_failed_login(mysqli $conn, string $ip): void {
    $conn->query("INSERT INTO `login_attempts` (ip, intentos) VALUES ('$ip', 1)
                  ON DUPLICATE KEY UPDATE intentos = intentos + 1, bloqueado_hasta =
                  IF(intentos + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), bloqueado_hasta)");
}

/**
 * Limpia el contador de intentos tras un login exitoso.
 */
function db_clear_login_attempts(mysqli $conn, string $ip): void {
    $stmt = $conn->prepare("DELETE FROM `login_attempts` WHERE ip = ?");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
}
