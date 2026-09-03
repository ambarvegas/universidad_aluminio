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
    $conn = mysqli_init();
    if (!$conn) {
        throw new RuntimeException('Error al inicializar mysqli');
    }
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (!@$conn->real_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB, MYSQL_PORT)) {
        throw new RuntimeException('Error de conexión MySQL: ' . mysqli_connect_error());
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
            `id`              VARCHAR(100) NOT NULL,
            `titulo`          VARCHAR(255) NOT NULL DEFAULT '',
            `descripcion`     MEDIUMTEXT,
            `tipo`            VARCHAR(50)  NOT NULL DEFAULT 'especializado',
            `imagen`          LONGTEXT,
            `prelacion`       VARCHAR(100) DEFAULT NULL,
            `modulos`         LONGTEXT,
            `en_construccion` TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Carreras
        "CREATE TABLE IF NOT EXISTS `carreras` (
            `id`     VARCHAR(100) NOT NULL,
            `nombre` VARCHAR(255) NOT NULL DEFAULT '',
            `cursos` JSON,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Carreras - Cursos (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `carrera_cursos` (
            `carrera_id` VARCHAR(100) NOT NULL,
            `curso_id`   VARCHAR(100) NOT NULL,
            `orden`      INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`carrera_id`, `curso_id`),
            INDEX `idx_cc_curso` (`curso_id`),
            FOREIGN KEY (`carrera_id`) REFERENCES `carreras`(`id`) ON DELETE CASCADE
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

        // Roles - Permisos (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `rol_permisos` (
            `rol_id`  VARCHAR(100) NOT NULL,
            `permiso` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `permiso`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Roles - Cursos asignados (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `rol_cursos` (
            `rol_id`   VARCHAR(100) NOT NULL,
            `curso_id` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `curso_id`),
            INDEX `idx_rc_curso` (`curso_id`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Roles - Carreras asignadas (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `rol_carreras` (
            `rol_id`     VARCHAR(100) NOT NULL,
            `carrera_id` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `carrera_id`),
            INDEX `idx_rc_carrera` (`carrera_id`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso de Usuario: Lecciones completadas (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `usuario_lecciones_completadas` (
            `usuario_id`       VARCHAR(50)  NOT NULL,
            `curso_id`         VARCHAR(100) NOT NULL,
            `leccion_codigo`   VARCHAR(50)  NOT NULL,
            `fecha_completado` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`usuario_id`, `curso_id`, `leccion_codigo`),
            INDEX `idx_ulc_curso` (`curso_id`),
            INDEX `idx_ulc_user` (`usuario_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso de Usuario: Módulos aprobados (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `usuario_modulos_aprobados` (
            `usuario_id`     VARCHAR(50)  NOT NULL,
            `curso_id`       VARCHAR(100) NOT NULL,
            `modulo_num`     VARCHAR(50)  NOT NULL,
            `fecha_aprobado` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
            INDEX `idx_uma_curso` (`curso_id`),
            INDEX `idx_uma_user` (`usuario_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso de Usuario: Medallas obtenidas (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `usuario_medallas` (
            `usuario_id`     VARCHAR(50)  NOT NULL,
            `curso_id`       VARCHAR(100) NOT NULL,
            `medalla_num`    VARCHAR(50)  NOT NULL,
            `fecha_obtenida` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`usuario_id`, `curso_id`, `medalla_num`),
            INDEX `idx_um_curso` (`curso_id`),
            INDEX `idx_um_user` (`usuario_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso de Usuario: Evaluaciones rendidas (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `usuario_evaluaciones` (
            `usuario_id`      VARCHAR(50)    NOT NULL,
            `curso_id`        VARCHAR(100)   NOT NULL,
            `modulo_num`      VARCHAR(50)    NOT NULL,
            `calificacion`    DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
            `aprobado`        TINYINT(1)     NOT NULL DEFAULT 1,
            `marcado_manual`  TINYINT(1)     NOT NULL DEFAULT 0,
            `fecha`           VARCHAR(50)    DEFAULT NULL,
            PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
            INDEX `idx_ue_curso` (`curso_id`),
            INDEX `idx_ue_user` (`usuario_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Progreso de Usuario: Intentos por módulo (Relación normalizada)
        "CREATE TABLE IF NOT EXISTS `usuario_intentos` (
            `usuario_id` VARCHAR(50)  NOT NULL,
            `curso_id`   VARCHAR(100) NOT NULL,
            `modulo_num` VARCHAR(50)  NOT NULL,
            `intentos`   INT          NOT NULL DEFAULT 1,
            PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
            INDEX `idx_ui_curso` (`curso_id`),
            INDEX `idx_ui_user` (`usuario_id`),
            FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
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

    // Migración automática para base de datos existente
    $colCheck = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'en_construccion'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `cursos` ADD COLUMN `en_construccion` TINYINT(1) NOT NULL DEFAULT 0");
    }

    $colDescCheck = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'descripcion'");
    if ($colDescCheck && $colDescCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `cursos` ADD COLUMN `descripcion` MEDIUMTEXT AFTER `titulo`");
    }
}

// ============================================================
// LECTURA — Reconstruye el objeto DB completo (compatibilidad)
// ============================================================

/**
 * Lee todos los datos de MySQL y los retorna como un array
 * con la misma estructura que tenía db.json (compatibilidad con frontend).
 * Lee directamente de las tablas relacionales normalizadas.
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

    // --- Progreso desde tablas relacionales normalizadas ---
    $normProgreso = [];

    // Lecciones completadas
    $resLec = $conn->query("SELECT usuario_id, curso_id, leccion_codigo FROM `usuario_lecciones_completadas`");
    if ($resLec) {
        while ($r = $resLec->fetch_assoc()) {
            $u = $r['usuario_id']; $c = $r['curso_id'];
            if (!isset($normProgreso[$u][$c])) {
                $normProgreso[$u][$c] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
            }
            $normProgreso[$u][$c]['leccionesCompletadas'][] = $r['leccion_codigo'];
        }
    }

    // Módulos aprobados
    $resMod = $conn->query("SELECT usuario_id, curso_id, modulo_num FROM `usuario_modulos_aprobados`");
    if ($resMod) {
        while ($r = $resMod->fetch_assoc()) {
            $u = $r['usuario_id']; $c = $r['curso_id'];
            if (!isset($normProgreso[$u][$c])) {
                $normProgreso[$u][$c] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
            }
            $normProgreso[$u][$c]['modulosAprobados'][] = $r['modulo_num'];
        }
    }

    // Medallas
    $resMed = $conn->query("SELECT usuario_id, curso_id, medalla_num FROM `usuario_medallas`");
    if ($resMed) {
        while ($r = $resMed->fetch_assoc()) {
            $u = $r['usuario_id']; $c = $r['curso_id'];
            if (!isset($normProgreso[$u][$c])) {
                $normProgreso[$u][$c] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
            }
            $normProgreso[$u][$c]['medallas'][] = $r['medalla_num'];
        }
    }

    // Evaluaciones
    $resEval = $conn->query("SELECT usuario_id, curso_id, modulo_num, calificacion, aprobado, marcado_manual, fecha FROM `usuario_evaluaciones`");
    if ($resEval) {
        while ($r = $resEval->fetch_assoc()) {
            $u = $r['usuario_id']; $c = $r['curso_id'];
            if (!isset($normProgreso[$u][$c])) {
                $normProgreso[$u][$c] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => [], 'intentos' => (object)[]];
            }
            if (is_object($normProgreso[$u][$c]['evaluaciones'])) {
                $normProgreso[$u][$c]['evaluaciones'] = (array)$normProgreso[$u][$c]['evaluaciones'];
            }
            $eItem = [
                'calificacion' => (float)$r['calificacion'],
                'aprobado'     => (bool)$r['aprobado']
            ];
            if (!empty($r['marcado_manual'])) $eItem['marcadoManual'] = true;
            if (!empty($r['fecha'])) $eItem['fecha'] = $r['fecha'];

            $normProgreso[$u][$c]['evaluaciones'][$r['modulo_num']] = $eItem;
        }
    }

    // Intentos
    $resInt = $conn->query("SELECT usuario_id, curso_id, modulo_num, intentos FROM `usuario_intentos`");
    if ($resInt) {
        while ($r = $resInt->fetch_assoc()) {
            $u = $r['usuario_id']; $c = $r['curso_id'];
            if (!isset($normProgreso[$u][$c])) {
                $normProgreso[$u][$c] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => []];
            }
            if (is_object($normProgreso[$u][$c]['intentos'])) {
                $normProgreso[$u][$c]['intentos'] = (array)$normProgreso[$u][$c]['intentos'];
            }
            $normProgreso[$u][$c]['intentos'][$r['modulo_num']] = (int)$r['intentos'];
        }
    }

    // Asignar progreso normalizado a usuarios
    foreach ($normProgreso as $uId => $cMap) {
        if (isset($usuariosMap[$uId])) {
            foreach ($cMap as $cId => $pData) {
                if (empty($pData['evaluaciones'])) $pData['evaluaciones'] = (object)[];
                if (empty($pData['intentos'])) $pData['intentos'] = (object)[];
                $usuariosMap[$uId]['progreso'][$cId] = $pData;
            }
        }
    }

    // Fallback: Si algún usuario no tiene progreso en tablas normalizadas pero sí en usuario_progreso legacy
    $resLeg = $conn->query("SELECT usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso`");
    if ($resLeg) {
        while ($row = $resLeg->fetch_assoc()) {
            $uid = $row['usuario_id'];
            $cid = $row['curso_id'];
            if (!isset($usuariosMap[$uid])) continue;
            if (!isset($usuariosMap[$uid]['progreso'][$cid])) {
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
        }
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

    // --- Cursos ---
    $res = $conn->query("SELECT * FROM `cursos`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['modulos'] = json_decode($row['modulos'] ?? '[]', true) ?? [];
            if (!$row['prelacion']) unset($row['prelacion']);
            $row['enConstruccion'] = !empty($row['en_construccion']) ? true : false;
            $row['descripcion'] = $row['descripcion'] ?? '';
            $db['cursos'][] = $row;
        }
    }

    // --- Carreras (con carrera_cursos normalizado) ---
    $carrerasCursosMap = [];
    $resCC = $conn->query("SELECT carrera_id, curso_id FROM `carrera_cursos` ORDER BY orden ASC");
    if ($resCC) {
        while ($r = $resCC->fetch_assoc()) {
            $carrerasCursosMap[$r['carrera_id']][] = $r['curso_id'];
        }
    }

    $res = $conn->query("SELECT id, nombre, cursos FROM `carreras`");
    while ($row = $res->fetch_assoc()) {
        if (isset($carrerasCursosMap[$row['id']])) {
            $row['cursos'] = $carrerasCursosMap[$row['id']];
        } else {
            $row['cursos'] = json_decode($row['cursos'] ?? '[]', true) ?? [];
        }
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

    $res = $conn->query("SELECT id, nombre, permisos, cursos, carreras FROM `roles_config`");
    while ($row = $res->fetch_assoc()) {
        $rId = $row['id'];
        $row['permisos']  = $rolPermisosMap[$rId] ?? (json_decode($row['permisos'] ?? '[]', true) ?? []);
        $row['cursos']    = $rolCursosMap[$rId]   ?? (json_decode($row['cursos']   ?? '[]', true) ?? []);
        $row['carreras']  = $rolCarrerasMap[$rId] ?? (json_decode($row['carreras'] ?? '[]', true) ?? []);
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

        // --- Recopilar hashes, progreso y certificados existentes para no sobrescribir ni borrar datos ---
        $existingHashes = [];
        $resH = $conn->query("SELECT id, clave FROM `usuarios`");
        if ($resH) {
            while ($r = $resH->fetch_assoc()) {
                $existingHashes[$r['id']] = $r['clave'];
            }
        }

        $existingDbProgreso = [];
        $resP = $conn->query("SELECT usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso`");
        if ($resP) {
            while ($r = $resP->fetch_assoc()) {
                $existingDbProgreso[$r['usuario_id']][$r['curso_id']] = $r;
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
        $conn->query("TRUNCATE TABLE `usuario_progreso`");
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
        $progresoRows          = [];
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

                $dbProg = $existingDbProgreso[$id][$cIdStr] ?? null;

                $dbLec  = $dbProg ? (json_decode($dbProg['lecciones_completadas'] ?? '[]', true) ?? []) : [];
                $dbMod  = $dbProg ? (json_decode($dbProg['modulos_aprobados']     ?? '[]', true) ?? []) : [];
                $dbMed  = $dbProg ? (json_decode($dbProg['medallas']              ?? '[]', true) ?? []) : [];
                $dbEval = $dbProg ? (json_decode($dbProg['evaluaciones']          ?? '{}', true) ?? []) : [];
                $dbInt  = $dbProg ? (json_decode($dbProg['intentos']              ?? '{}', true) ?? []) : [];

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
                        $sDb = is_array($eDb) ? (int)($eDb['nota'] ?? 0) : 0;
                        $sPay = is_array($ePay) ? (int)($ePay['nota'] ?? 0) : 0;
                        $mergedEval[$ek] = ($sDb >= $sPay) ? $eDb : $ePay;
                    }
                }

                $mergedInt  = array_replace($dbInt, $payInt);

                $lec  = json_encode($mergedLec);
                $mod  = json_encode($mergedMod);
                $med  = json_encode($mergedMed);
                $eval = empty($mergedEval) ? '{}' : json_encode((object)$mergedEval, JSON_FORCE_OBJECT);
                $int  = empty($mergedInt)  ? '{}' : json_encode((object)$mergedInt,  JSON_FORCE_OBJECT);

                $progresoRows[] = [$id, $cIdStr, $lec, $mod, $med, $eval, $int];

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
        db_bulk_insert($conn, 'usuarios', ['id', 'nombre', 'clave', 'rol', 'estado'], $usuariosRows, 100,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado)");

        db_bulk_insert($conn, 'usuario_asignados', ['usuario_id', 'curso_id'], $asignadosRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_carreras_asignadas', ['usuario_id', 'carrera_id', 'estado'], $carrerasAsignadasRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_progreso', ['usuario_id', 'curso_id', 'lecciones_completadas', 'modulos_aprobados', 'medallas', 'evaluaciones', 'intentos'], $progresoRows, 100,
            "ON DUPLICATE KEY UPDATE lecciones_completadas=VALUES(lecciones_completadas), modulos_aprobados=VALUES(modulos_aprobados), medallas=VALUES(medallas), evaluaciones=VALUES(evaluaciones), intentos=VALUES(intentos)");
        db_bulk_insert($conn, 'usuario_lecciones_completadas', ['usuario_id', 'curso_id', 'leccion_codigo'], $normLecRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_modulos_aprobados', ['usuario_id', 'curso_id', 'modulo_num'], $normModRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_medallas', ['usuario_id', 'curso_id', 'medalla_num'], $normMedRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_evaluaciones', ['usuario_id', 'curso_id', 'modulo_num', 'calificacion', 'aprobado', 'marcado_manual', 'fecha'], $normEvalRows, 100,
            "ON DUPLICATE KEY UPDATE calificacion=VALUES(calificacion), aprobado=VALUES(aprobado), marcado_manual=VALUES(marcado_manual), fecha=VALUES(fecha)");
        db_bulk_insert($conn, 'usuario_intentos', ['usuario_id', 'curso_id', 'modulo_num', 'intentos'], $normIntRows, 100,
            "ON DUPLICATE KEY UPDATE intentos=VALUES(intentos)");

        db_bulk_insert($conn, 'usuario_certificados_curso', ['usuario_id', 'curso_id'], $certCursoRows, 200, '', true);
        db_bulk_insert($conn, 'usuario_certificados_carrera', ['usuario_id', 'carrera_id'], $certCarreraRows, 200, '', true);

        // --- Cursos ---
        $cursosRows = [];
        foreach (($data['cursos'] ?? []) as $c) {
            $cId            = $c['id']             ?? '';
            $titulo         = $c['titulo']         ?? '';
            $descripcion    = $c['descripcion']    ?? '';
            $tipo           = $c['tipo']           ?? 'especializado';
            $imagen         = $c['imagen']         ?? '';
            $prel           = $c['prelacion']      ?? null;
            $modulos        = json_encode($c['modulos'] ?? []);
            $enConstruccion = !empty($c['enConstruccion']) ? 1 : 0;
            if (!$cId) continue;
            $cursosRows[] = [$cId, $titulo, $descripcion, $tipo, $imagen, $prel, $modulos, $enConstruccion];
        }
        db_bulk_insert($conn, 'cursos', ['id', 'titulo', 'descripcion', 'tipo', 'imagen', 'prelacion', 'modulos', 'en_construccion'], $cursosRows, 50,
            "ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), descripcion=VALUES(descripcion), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), modulos=VALUES(modulos), en_construccion=VALUES(en_construccion)");

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
        $carreraCursosRows = [];
        foreach (($data['carreras'] ?? []) as $c) {
            $cId    = $c['id']     ?? '';
            $nombre = $c['nombre'] ?? '';
            $rawCurs = $c['cursos'] ?? [];
            $curs   = json_encode($rawCurs);
            if (!$cId) continue;
            $carrerasRows[] = [$cId, $nombre, $curs];

            $ord = 0;
            foreach ((array)$rawCurs as $cItem) {
                $cItemStr = trim((string)$cItem);
                if ($cItemStr) {
                    $carreraCursosRows[] = [$cId, $cItemStr, $ord++];
                }
            }
        }
        db_bulk_insert($conn, 'carreras', ['id', 'nombre', 'cursos'], $carrerasRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), cursos=VALUES(cursos)");
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

        // --- Roles Config ---
        $rolesRows = [];
        $rolPermisosRows = [];
        $rolCursosRows = [];
        $rolCarrerasRows = [];
        foreach (($data['rolesConfig'] ?? []) as $r) {
            $rId      = $r['id']       ?? '';
            $nombre   = $r['nombre']   ?? '';
            $rawPerm  = (array)($r['permisos']  ?? []);
            $rawCur   = (array)($r['cursos']    ?? []);
            $rawCar   = (array)($r['carreras']  ?? []);
            $permisos = json_encode($rawPerm);
            $cursos   = json_encode($rawCur);
            $carreras = json_encode($rawCar);
            if (!$rId) continue;
            $rolesRows[] = [$rId, $nombre, $permisos, $cursos, $carreras];

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
        db_bulk_insert($conn, 'roles_config', ['id', 'nombre', 'permisos', 'cursos', 'carreras'], $rolesRows, 50,
            "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), permisos=VALUES(permisos), cursos=VALUES(cursos), carreras=VALUES(carreras)");
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

    // Progreso desde tablas normalizadas
    $uProg = [];

    // Lecciones
    $stmtUL = $conn->prepare("SELECT curso_id, leccion_codigo FROM `usuario_lecciones_completadas` WHERE usuario_id = ?");
    $stmtUL->bind_param('s', $id);
    $stmtUL->execute();
    $resUL = $stmtUL->get_result();
    while ($r = $resUL->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($uProg[$cid])) $uProg[$cid] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
        $uProg[$cid]['leccionesCompletadas'][] = $r['leccion_codigo'];
    }

    // Módulos
    $stmtUM = $conn->prepare("SELECT curso_id, modulo_num FROM `usuario_modulos_aprobados` WHERE usuario_id = ?");
    $stmtUM->bind_param('s', $id);
    $stmtUM->execute();
    $resUM = $stmtUM->get_result();
    while ($r = $resUM->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($uProg[$cid])) $uProg[$cid] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
        $uProg[$cid]['modulosAprobados'][] = $r['modulo_num'];
    }

    // Medallas
    $stmtUMed = $conn->prepare("SELECT curso_id, medalla_num FROM `usuario_medallas` WHERE usuario_id = ?");
    $stmtUMed->bind_param('s', $id);
    $stmtUMed->execute();
    $resUMed = $stmtUMed->get_result();
    while ($r = $resUMed->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($uProg[$cid])) $uProg[$cid] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => (object)[]];
        $uProg[$cid]['medallas'][] = $r['medalla_num'];
    }

    // Evaluaciones
    $stmtUE = $conn->prepare("SELECT curso_id, modulo_num, calificacion, aprobado, marcado_manual, fecha FROM `usuario_evaluaciones` WHERE usuario_id = ?");
    $stmtUE->bind_param('s', $id);
    $stmtUE->execute();
    $resUE = $stmtUE->get_result();
    while ($r = $resUE->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($uProg[$cid])) $uProg[$cid] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => [], 'intentos' => (object)[]];
        if (is_object($uProg[$cid]['evaluaciones'])) $uProg[$cid]['evaluaciones'] = (array)$uProg[$cid]['evaluaciones'];
        $eItem = ['calificacion' => (float)$r['calificacion'], 'aprobado' => (bool)$r['aprobado']];
        if (!empty($r['marcado_manual'])) $eItem['marcadoManual'] = true;
        if (!empty($r['fecha'])) $eItem['fecha'] = $r['fecha'];
        $uProg[$cid]['evaluaciones'][$r['modulo_num']] = $eItem;
    }

    // Intentos
    $stmtUI = $conn->prepare("SELECT curso_id, modulo_num, intentos FROM `usuario_intentos` WHERE usuario_id = ?");
    $stmtUI->bind_param('s', $id);
    $stmtUI->execute();
    $resUI = $stmtUI->get_result();
    while ($r = $resUI->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($uProg[$cid])) $uProg[$cid] = ['leccionesCompletadas' => [], 'modulosAprobados' => [], 'medallas' => [], 'evaluaciones' => (object)[], 'intentos' => []];
        if (is_object($uProg[$cid]['intentos'])) $uProg[$cid]['intentos'] = (array)$uProg[$cid]['intentos'];
        $uProg[$cid]['intentos'][$r['modulo_num']] = (int)$r['intentos'];
    }

    foreach ($uProg as $cid => &$pData) {
        if (empty($pData['evaluaciones'])) $pData['evaluaciones'] = (object)[];
        if (empty($pData['intentos'])) $pData['intentos'] = (object)[];
        $usuario['progreso'][$cid] = $pData;
    }
    unset($pData);

    // Fallback usuario_progreso legacy
    $stmtProg = $conn->prepare("SELECT curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ?");
    $stmtProg->bind_param('s', $id);
    $stmtProg->execute();
    $resProg = $stmtProg->get_result();
    while ($r = $resProg->fetch_assoc()) {
        $cid = $r['curso_id'];
        if (!isset($usuario['progreso'][$cid])) {
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
        $id     = trim((string)($u['id']     ?? ''));
        $nombre = trim((string)($u['nombre'] ?? ''));
        $rol    = trim((string)($u['rol']    ?? 'participante'));
        $estado = trim((string)($u['estado'] ?? 'activo'));
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
            "INSERT INTO `usuarios` (id, nombre, clave, rol, estado) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), clave=VALUES(clave), rol=VALUES(rol), estado=VALUES(estado)"
        );
        $stmt->bind_param('sssss', $id, $nombre, $nuevaClave, $rol, $estado);
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
 * Actualiza la tabla legacy usuario_progreso y las tablas relacionales normalizadas.
 */
function db_upsert_progreso(mysqli $conn, string $userId, string $cursoId, array $prog): void {
    // 1. Obtener datos existentes en la BD para este usuario y curso
    $stmtSel = $conn->prepare("SELECT lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ? AND curso_id = ?");
    $stmtSel->bind_param('ss', $userId, $cursoId);
    $stmtSel->execute();
    $resSel = $stmtSel->get_result();

    $existingLec  = [];
    $existingMod  = [];
    $existingMed  = [];
    $existingEval = [];
    $existingInt  = [];

    if ($resSel && $rowSel = $resSel->fetch_assoc()) {
        $existingLec  = json_decode($rowSel['lecciones_completadas'] ?? '[]', true) ?? [];
        $existingMod  = json_decode($rowSel['modulos_aprobados']     ?? '[]', true) ?? [];
        $existingMed  = json_decode($rowSel['medallas']              ?? '[]', true) ?? [];
        $existingEval = json_decode($rowSel['evaluaciones']         ?? '{}', true) ?? [];
        $existingInt  = json_decode($rowSel['intentos']             ?? '{}', true) ?? [];
    }

    // 2. Fusionar lecciones, módulos y medallas (sin duplicados)
    $newLec = array_values(array_unique(array_merge($existingLec, (array)($prog['leccionesCompletadas'] ?? []))));
    $newMod = array_values(array_unique(array_merge($existingMod, (array)($prog['modulosAprobados']     ?? []))));
    $newMed = array_values(array_unique(array_merge($existingMed, (array)($prog['medallas']             ?? []))));

    // 3. Fusionar evaluaciones e intentos de forma segura
    $incomingEval = is_array($prog['evaluaciones'] ?? null) ? $prog['evaluaciones'] : (is_object($prog['evaluaciones'] ?? null) ? (array)$prog['evaluaciones'] : []);
    $incomingInt  = is_array($prog['intentos'] ?? null)     ? $prog['intentos']     : (is_object($prog['intentos'] ?? null)     ? (array)$prog['intentos']     : []);

    $newEval = array_replace($existingEval, $incomingEval);
    $newInt  = array_replace($existingInt, $incomingInt);

    $lec  = json_encode($newLec);
    $mod  = json_encode($newMod);
    $med  = json_encode($newMed);
    $eval = empty($newEval) ? '{}' : json_encode((object)$newEval, JSON_FORCE_OBJECT);
    $int  = empty($newInt)  ? '{}' : json_encode((object)$newInt,  JSON_FORCE_OBJECT);

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

    // Actualizar tablas relacionales normalizadas
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
            $fecha   = $eVal['fecha'] ?? null;
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
 * Inserta o actualiza un curso.
 */
function db_upsert_curso(mysqli $conn, array $c): void {
    $id             = trim((string)($c['id']             ?? ''));
    $titulo         = trim((string)($c['titulo']         ?? ''));
    $descripcion    = trim((string)($c['descripcion']    ?? ''));
    $tipo           = trim((string)($c['tipo']           ?? 'especializado'));
    $imagen         = $c['imagen']         ?? '';
    $prel           = !empty($c['prelacion']) ? trim((string)$c['prelacion']) : null;
    $modulos        = json_encode($c['modulos'] ?? []);
    $enConstruccion = !empty($c['enConstruccion']) ? 1 : 0;
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `cursos` (id, titulo, descripcion, tipo, imagen, prelacion, modulos, en_construccion) VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE titulo=VALUES(titulo), descripcion=VALUES(descripcion), tipo=VALUES(tipo), imagen=VALUES(imagen), prelacion=VALUES(prelacion), modulos=VALUES(modulos), en_construccion=VALUES(en_construccion)"
    );
    $stmt->bind_param('sssssssi', $id, $titulo, $descripcion, $tipo, $imagen, $prel, $modulos, $enConstruccion);
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
 * Inserta o actualiza una carrera y sincroniza carrera_cursos.
 */
function db_upsert_carrera(mysqli $conn, array $c): void {
    $id        = trim((string)($c['id']     ?? ''));
    $nombre    = trim((string)($c['nombre'] ?? ''));
    $rawCursos = $c['cursos'] ?? [];
    $cursos    = json_encode($rawCursos);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `carreras` (id, nombre, cursos) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), cursos=VALUES(cursos)"
    );
    $stmt->bind_param('sss', $id, $nombre, $cursos);
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
    $id       = trim((string)($r['id']       ?? ''));
    $nombre   = trim((string)($r['nombre']   ?? ''));
    $rawPerm  = (array)($r['permisos']  ?? []);
    $rawCur   = (array)($r['cursos']    ?? []);
    $rawCar   = (array)($r['carreras']  ?? []);
    $permisos = json_encode($rawPerm);
    $cursos   = json_encode($rawCur);
    $carreras = json_encode($rawCar);
    if (!$id) return;

    $stmt = $conn->prepare(
        "INSERT INTO `roles_config` (id, nombre, permisos, cursos, carreras) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), permisos=VALUES(permisos), cursos=VALUES(cursos), carreras=VALUES(carreras)"
    );
    $stmt->bind_param('sssss', $id, $nombre, $permisos, $cursos, $carreras);
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
