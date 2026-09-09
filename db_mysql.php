<?php
/**
 * db_mysql.php
 * Módulo de conexión y acceso a datos — MySQL
 * Universidad del Aluminio
 */

// ============================================================
// CONFIGURACIÓN DE CONEXIÓN
// ============================================================

define('MYSQL_HOST', getenv('DB_HOST') ?: '185.2.168.16');
define('MYSQL_USER', getenv('DB_USER') ?: 'alufletes_admin');
define('MYSQL_PASS', getenv('DB_PASS') ?: '2fjy68PjsK7u2Hi');
define('MYSQL_DB',   getenv('DB_NAME') ?: 'alufletes_universidad');
define('MYSQL_PORT', (int)(getenv('DB_PORT') ?: 3306));
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

        // Cursos (imagen en LONGTEXT por base64, módulos normalizados en tablas relacionales)
        "CREATE TABLE IF NOT EXISTS `cursos` (
            `id`              VARCHAR(100) NOT NULL,
            `titulo`          VARCHAR(255) NOT NULL DEFAULT '',
            `descripcion`     MEDIUMTEXT,
            `tipo`            VARCHAR(50)  NOT NULL DEFAULT 'especializado',
            `imagen`          LONGTEXT,
            `prelacion`       VARCHAR(100) DEFAULT NULL,
            `en_construccion` TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Módulos de un curso (relación normalizada)
        "CREATE TABLE IF NOT EXISTS `curso_modulos` (
            `id`       INT          NOT NULL AUTO_INCREMENT,
            `curso_id` VARCHAR(100) NOT NULL,
            `orden`    INT          NOT NULL DEFAULT 0,
            `titulo`   VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            INDEX `idx_cm_curso` (`curso_id`),
            FOREIGN KEY (`curso_id`) REFERENCES `cursos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Lecciones de un módulo (relación normalizada)
        "CREATE TABLE IF NOT EXISTS `curso_lecciones` (
            `id`        INT          NOT NULL AUTO_INCREMENT,
            `modulo_id` INT          NOT NULL,
            `curso_id`  VARCHAR(100) NOT NULL,
            `orden`     INT          NOT NULL DEFAULT 0,
            `titulo`    VARCHAR(500) NOT NULL DEFAULT '',
            `video_id`  VARCHAR(200) DEFAULT NULL,
            `contenido` MEDIUMTEXT,
            `adjunto`   TEXT         DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_cl_modulo` (`modulo_id`),
            INDEX `idx_cl_curso`  (`curso_id`),
            FOREIGN KEY (`modulo_id`) REFERENCES `curso_modulos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Preguntas de evaluación de un módulo (relación normalizada)
        "CREATE TABLE IF NOT EXISTS `curso_preguntas` (
            `id`        INT          NOT NULL AUTO_INCREMENT,
            `modulo_id` INT          NOT NULL,
            `curso_id`  VARCHAR(100) NOT NULL,
            `orden`     INT          NOT NULL DEFAULT 0,
            `enunciado` TEXT         NOT NULL,
            `opciones`  JSON         NOT NULL,
            `correcta`  TINYINT      NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_cp_modulo` (`modulo_id`),
            INDEX `idx_cp_curso`  (`curso_id`),
            FOREIGN KEY (`modulo_id`) REFERENCES `curso_modulos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Carreras (sin columna cursos JSON — normalizado en carrera_cursos)
        "CREATE TABLE IF NOT EXISTS `carreras` (
            `id`     VARCHAR(100) NOT NULL,
            `nombre` VARCHAR(255) NOT NULL DEFAULT '',
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

        // Configuración de roles (sin columnas JSON — normalizadas en rol_permisos, rol_cursos, rol_carreras)
        "CREATE TABLE IF NOT EXISTS `roles_config` (
            `id`       VARCHAR(100) NOT NULL,
            `nombre`   VARCHAR(255) NOT NULL DEFAULT '',
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
    // Agregar en_construccion si no existe
    $colCheck = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'en_construccion'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `cursos` ADD COLUMN `en_construccion` TINYINT(1) NOT NULL DEFAULT 0");
    }

    // Agregar descripcion si no existe
    $colDescCheck = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'descripcion'");
    if ($colDescCheck && $colDescCheck->num_rows === 0) {
        $conn->query("ALTER TABLE `cursos` ADD COLUMN `descripcion` MEDIUMTEXT AFTER `titulo`");
    }

    // Eliminar columna modulos JSON de cursos si aún existe (reemplazada por tablas relacionales)
    $colModulos = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'modulos'");
    if ($colModulos && $colModulos->num_rows > 0) {
        $conn->query("ALTER TABLE `cursos` DROP COLUMN `modulos`");
    }

    // Eliminar columna cursos JSON de carreras si aún existe
    $colCarCursos = $conn->query("SHOW COLUMNS FROM `carreras` LIKE 'cursos'");
    if ($colCarCursos && $colCarCursos->num_rows > 0) {
        $conn->query("ALTER TABLE `carreras` DROP COLUMN `cursos`");
    }

    // Eliminar columnas JSON de roles_config si aún existen
    foreach (['permisos', 'cursos', 'carreras'] as $colName) {
        $colRol = $conn->query("SHOW COLUMNS FROM `roles_config` LIKE '$colName'");
        if ($colRol && $colRol->num_rows > 0) {
            $conn->query("ALTER TABLE `roles_config` DROP COLUMN `$colName`");
        }
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

    // Auto-normalización estricta: Si un módulo está APROBADO por el usuario pero carece de evaluación,
    // cargar dicha evaluación faltante con 100%. Si el usuario tiene módulos por cursar (no aprobados),
    // dejarlos sin calificación. Si ya tiene una evaluación cargada, preservarla intacta (INSERT IGNORE).
    $conn->query("
        INSERT IGNORE INTO `usuario_evaluaciones` (`usuario_id`, `curso_id`, `modulo_num`, `calificacion`, `aprobado`, `marcado_manual`, `fecha`)
        SELECT uma.usuario_id, uma.curso_id, uma.modulo_num, 100.00, 1, 1, NOW()
        FROM `usuario_modulos_aprobados` uma
    ");
    $conn->query("
        INSERT IGNORE INTO `usuario_intentos` (`usuario_id`, `curso_id`, `modulo_num`, `intentos`)
        SELECT ue.usuario_id, ue.curso_id, ue.modulo_num, 1
        FROM `usuario_evaluaciones` ue
    ");

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
                $pData['evaluaciones'] = empty($pData['evaluaciones']) ? (object)[] : (object)$pData['evaluaciones'];
                $pData['intentos']     = empty($pData['intentos'])     ? (object)[] : (object)$pData['intentos'];
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

    // --- Cursos (desde tablas relacionales normalizadas) ---
    // Pre-cargar módulos
    $cursosModulosMap = [];
    $moduloToCursoMap = [];
    $resCM = $conn->query("SELECT id, curso_id, orden, titulo FROM `curso_modulos` ORDER BY curso_id, orden ASC");
    if ($resCM) {
        while ($r = $resCM->fetch_assoc()) {
            $mid = (int)$r['id'];
            $cid = $r['curso_id'];
            $moduloToCursoMap[$mid] = $cid;
            $cursosModulosMap[$cid][$mid] = [
                '_orden'     => (int)$r['orden'],
                'titulo'     => $r['titulo'],
                'lecciones'  => [],
                'evaluacion' => ['preguntas' => []],
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
    $resCP = $conn->query("SELECT modulo_id, orden, enunciado, opciones, correcta FROM `curso_preguntas` ORDER BY modulo_id, orden ASC");
    if ($resCP) {
        while ($r = $resCP->fetch_assoc()) {
            $mid = (int)$r['modulo_id'];
            $cid = $moduloToCursoMap[$mid] ?? null;
            if ($cid !== null && isset($cursosModulosMap[$cid][$mid])) {
                $cursosModulosMap[$cid][$mid]['evaluacion']['preguntas'][] = [
                    'enunciado' => $r['enunciado'],
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
                $modTitulo = $conn->real_escape_string(trim((string)($mod['titulo'] ?? '')));
                $conn->query("INSERT INTO `curso_modulos` (curso_id, orden, titulo) VALUES ('$safeCId', $modOrd, '$modTitulo')");
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
                    $pOpc  = $conn->real_escape_string(json_encode($preg['opciones'] ?? []));
                    $pCorr = (int)($preg['correcta'] ?? 0);
                    $conn->query("INSERT INTO `curso_preguntas` (modulo_id, curso_id, orden, enunciado, opciones, correcta) VALUES ($modId, '$safeCId', $preOrd, '$pEnun', '$pOpc', $pCorr)");
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
        'configuracion'       => $full['configuracion'],
    ];
}

/**
 * Retorna el catálogo ligero para la vista principal / dashboard del estudiante.
 * Omite las lecciones detalladas y el banco de preguntas, reduciendo el payload en un 95%.
 */
function db_read_catalogo(mysqli $conn, string $userId, string $userRol): array {
    // 1. Obtener usuario autenticado
    $stmtU = $conn->prepare("SELECT id, nombre, rol, estado FROM `usuarios` WHERE id = ?");
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

        // Progreso
        $stmtPr = $conn->prepare("SELECT curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ?");
        $stmtPr->bind_param('s', $userId);
        $stmtPr->execute();
        $rPr = $stmtPr->get_result();
        $userProg = [];
        while ($row = $rPr->fetch_assoc()) {
            $cid = $row['curso_id'];
            $evals = json_decode($row['evaluaciones'] ?? '{}', true);
            $ints  = json_decode($row['intentos'] ?? '{}', true);
            $userProg[$cid] = [
                'leccionesCompletadas' => json_decode($row['lecciones_completadas'] ?? '[]', true) ?? [],
                'modulosAprobados'     => json_decode($row['modulos_aprobados'] ?? '[]', true) ?? [],
                'medallas'             => json_decode($row['medallas'] ?? '[]', true) ?? [],
                'evaluaciones'         => is_array($evals) ? $evals : (object)[],
                'intentos'             => is_array($ints) ? $ints : (object)[],
            ];
        }
        $miUsuario['progreso'] = empty($userProg) ? (object)[] : $userProg;

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
    $sqlMod = "SELECT cm.id, cm.curso_id, cm.orden, cm.titulo,
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
                'id'              => (int)$mr['id'],
                '_orden'          => (int)$mr['orden'],
                'titulo'          => $mr['titulo'],
                'totalLecciones'  => (int)$mr['total_lecciones'],
                'tieneEvaluacion' => ((int)$mr['total_preguntas'] > 0),
                'lecciones'       => array_fill(0, (int)$mr['total_lecciones'], null),
            ];
        }
    }

    // 4. Cursos accesibles
    $resC = $conn->query("SELECT id, titulo, descripcion, tipo, imagen, prelacion, en_construccion FROM `cursos`");
    $cursosFiltrados = [];
    while ($row = $resC->fetch_assoc()) {
        $cid = $row['id'];
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
        'configuracion'       => $configuracion,
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

    // Módulos
    $stmtM = $conn->prepare("SELECT id, orden, titulo FROM `curso_modulos` WHERE curso_id = ? ORDER BY orden ASC");
    $stmtM->bind_param('s', $cursoId);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    $modulosMap = [];
    $modulosOrden = [];
    while ($mRow = $resM->fetch_assoc()) {
        $mid = (int)$mRow['id'];
        $modulosOrden[] = $mid;
        $modulosMap[$mid] = [
            'id'         => $mid,
            '_orden'     => (int)$mRow['orden'],
            'titulo'     => $mRow['titulo'],
            'lecciones'  => [],
            'evaluacion' => ['preguntas' => []],
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
    $stmtP = $conn->prepare("SELECT id, modulo_id, orden, enunciado, opciones, correcta FROM `curso_preguntas` WHERE curso_id = ? ORDER BY modulo_id, orden ASC");
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
    foreach ($newEval as $mNum => &$eItem) {
        if (is_array($eItem) && empty($eItem['fecha'])) {
            $eItem['fecha'] = !empty($existingEval[$mNum]['fecha']) ? $existingEval[$mNum]['fecha'] : date('c');
        }
    }
    unset($eItem);

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
    $stmtM = $conn->prepare("SELECT id, orden, titulo FROM `curso_modulos` WHERE curso_id = ? AND orden = ?");
    $stmtM->bind_param('si', $cursoId, $moduloIdx);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    if (!$resM || !($mRow = $resM->fetch_assoc())) {
        throw new InvalidArgumentException("Módulo no encontrado en curso $cursoId con orden $moduloIdx");
    }
    $moduloId = (int)$mRow['id'];
    $moduloTitulo = $mRow['titulo'];

    // 3. Obtener las preguntas del módulo en orden
    $stmtQ = $conn->prepare("SELECT id, orden, enunciado, opciones, correcta FROM `curso_preguntas` WHERE modulo_id = ? ORDER BY orden ASC");
    $stmtQ->bind_param('i', $moduloId);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    $preguntas = [];
    while ($qRow = $resQ->fetch_assoc()) {
        $preguntas[] = $qRow;
    }
    if (empty($preguntas)) {
        throw new InvalidArgumentException("El módulo \"$moduloTitulo\" no posee preguntas de evaluación configuradas.");
    }

    // 4. Comparar respuestas y calcular puntaje
    $total = count($preguntas);
    $aciertos = 0;
    $preguntasDetalle = [];

    foreach ($preguntas as $idx => $p) {
        $userSelected = isset($respuestas[$idx]) ? (int)$respuestas[$idx] : -1;
        $correcta = (int)$p['correcta'];
        $esCorrecta = ($userSelected === $correcta);
        if ($esCorrecta) {
            $aciertos++;
        }

        $opciones = is_string($p['opciones']) ? json_decode($p['opciones'], true) : $p['opciones'];
        if (!is_array($opciones)) $opciones = [];

        $preguntasDetalle[] = [
            'orden'        => (int)$p['orden'],
            'enunciado'    => $p['enunciado'],
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
    $mNumStr = (string)$moduloIdx;
    $fechaIso = date('c');

    // 5. Incrementar número de intentos
    $stmtIntSel = $conn->prepare("SELECT intentos FROM `usuario_intentos` WHERE usuario_id = ? AND curso_id = ? AND modulo_num = ?");
    $stmtIntSel->bind_param('sss', $userId, $cursoId, $mNumStr);
    $stmtIntSel->execute();
    $resInt = $stmtIntSel->get_result();
    $numIntentos = 1;
    if ($resInt && $rInt = $resInt->fetch_assoc()) {
        $numIntentos = ((int)$rInt['intentos']) + 1;
    }
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

        $stmtApr = $conn->prepare("SELECT COUNT(DISTINCT modulo_num) as aprobados FROM `usuario_modulos_aprobados` WHERE usuario_id = ? AND curso_id = ?");
        $stmtApr->bind_param('ss', $userId, $cursoId);
        $stmtApr->execute();
        $resApr = $stmtApr->get_result();
        $aprobados = ($resApr && $rApr = $resApr->fetch_assoc()) ? (int)$rApr['aprobados'] : 0;

        if ($totalEval > 0 && $aprobados >= $totalEval) {
            $stmtCert = $conn->prepare("INSERT IGNORE INTO `usuario_certificados_curso` (usuario_id, curso_id) VALUES (?,?)");
            $stmtCert->bind_param('ss', $userId, $cursoId);
            $stmtCert->execute();
            if ($stmtCert->affected_rows > 0) {
                $certificadoOtorgado = true;
            }
        }
    }

    // 8. Sincronizar espejo JSON usuario_progreso legacy
    $stmtProg = $conn->prepare("SELECT lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso` WHERE usuario_id = ? AND curso_id = ?");
    $stmtProg->bind_param('ss', $userId, $cursoId);
    $stmtProg->execute();
    $resProg = $stmtProg->get_result();
    $curProg = [
        'lecciones_completadas' => [],
        'modulos_aprobados'     => [],
        'medallas'              => [],
        'evaluaciones'          => [],
        'intentos'              => []
    ];
    if ($resProg && $pRow = $resProg->fetch_assoc()) {
        $curProg['lecciones_completadas'] = json_decode($pRow['lecciones_completadas'] ?? '[]', true) ?? [];
        $curProg['modulos_aprobados']     = json_decode($pRow['modulos_aprobados'] ?? '[]', true) ?? [];
        $curProg['medallas']              = json_decode($pRow['medallas'] ?? '[]', true) ?? [];
        $curProg['evaluaciones']          = json_decode($pRow['evaluaciones'] ?? '{}', true) ?? [];
        $curProg['intentos']              = json_decode($pRow['intentos'] ?? '{}', true) ?? [];
    }

    $curProg['intentos'][$mNumStr] = $numIntentos;
    $curProg['evaluaciones'][$mNumStr] = [
        'calificacion' => $calificacion,
        'aprobado'     => $aprobado,
        'fecha'        => $fechaIso
    ];
    if ($aprobado) {
        if (!in_array($mNumStr, $curProg['modulos_aprobados'], true)) {
            $curProg['modulos_aprobados'][] = $mNumStr;
        }
        if (!in_array($mNumStr, $curProg['medallas'], true)) {
            $curProg['medallas'][] = $mNumStr;
        }
    }

    $lecJson  = json_encode(array_values($curProg['lecciones_completadas']));
    $modJson  = json_encode(array_values($curProg['modulos_aprobados']));
    $medJson  = json_encode(array_values($curProg['medallas']));
    $evalJson = json_encode((object)$curProg['evaluaciones'], JSON_FORCE_OBJECT);
    $intJson  = json_encode((object)$curProg['intentos'], JSON_FORCE_OBJECT);

    $stmtUpProg = $conn->prepare("INSERT INTO `usuario_progreso` (usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            modulos_aprobados = VALUES(modulos_aprobados),
            medallas = VALUES(medallas),
            evaluaciones = VALUES(evaluaciones),
            intentos = VALUES(intentos)");
    $stmtUpProg->bind_param('sssssss', $userId, $cursoId, $lecJson, $modJson, $medJson, $evalJson, $intJson);
    $stmtUpProg->execute();

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

    return [
        'success'             => true,
        'calificacion'        => $calificacion,
        'aprobado'            => $aprobado,
        'aciertos'            => $aciertos,
        'total'               => $total,
        'minAprobacion'       => $minAprobacion,
        'numIntentos'         => $numIntentos,
        'certificadoOtorgado' => $certificadoOtorgado,
        'carreraOtorgada'     => $carreraOtorgada,
        'preguntasDetalle'    => $preguntasDetalle,
        'progreso'            => [
            'leccionesCompletadas' => array_values($curProg['lecciones_completadas']),
            'modulosAprobados'     => array_values($curProg['modulos_aprobados']),
            'medallas'             => array_values($curProg['medallas']),
            'evaluaciones'         => (object)$curProg['evaluaciones'],
            'intentos'             => (object)$curProg['intentos'],
        ],
        'certificadosCurso'   => $userCertsCurso,
        'certificadosCarrera' => $userCertsCarrera,
    ];
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

    $uploadDir = __DIR__ . '/uploads/';
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
        $modTitulo = $conn->real_escape_string(trim((string)($mod['titulo'] ?? '')));
        $conn->query("INSERT INTO `curso_modulos` (curso_id, orden, titulo) VALUES ('$safeId', $modOrd, '$modTitulo')");
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
            $pOpc  = $conn->real_escape_string(json_encode($preg['opciones'] ?? []));
            $pCorr = (int)($preg['correcta'] ?? 0);
            $conn->query("INSERT INTO `curso_preguntas` (modulo_id, curso_id, orden, enunciado, opciones, correcta) VALUES ($modId, '$safeId', $preOrd, '$pEnun', '$pOpc', $pCorr)");
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

// ============================================================
// CERTIFICADOS Y VERIFICACIÓN PÚBLICA
// ============================================================

if (!defined('CERT_SALT')) {
    define('CERT_SALT', 'ALU_CERT_SECRET_SALT_2026');
}

/**
 * Genera un código de verificación alfanumérico único y determinista.
 * Formato: ALU-CUR-XXXXXXXXXX (Curso) o ALU-CAR-XXXXXXXXXX (Carrera)
 */
function db_generar_codigo_certificado(string $userId, string $itemId, string $tipo = 'curso'): string {
    $tipo = strtolower(trim($tipo));
    $prefix = ($tipo === 'carrera') ? 'ALU-CAR' : 'ALU-CUR';
    $raw = CERT_SALT . '_' . $tipo . '_' . trim($userId) . '_' . trim($itemId);
    $hash = strtoupper(substr(hash('sha256', $raw), 0, 10));
    return "{$prefix}-{$hash}";
}

/**
 * Verifica la autenticidad de un código de certificado o diploma consultando la base de datos.
 * Retorna un array con los datos del titular y programa, o null si no es válido.
 */
function db_verificar_certificado(mysqli $conn, string $codigo): ?array {
    $codigo = strtoupper(trim($codigo));
    if (!preg_match('/^ALU-(CUR|CAR)-([A-F0-9]{10})$/', $codigo, $matches)) {
        return null;
    }

    $tipoStr = $matches[1];
    $tipo = ($tipoStr === 'CAR') ? 'carrera' : 'curso';

    if ($tipo === 'curso') {
        $sql = "SELECT c.usuario_id, c.curso_id, u.nombre AS usuario_nombre, cur.titulo AS programa_nombre,
                       cur.descripcion AS programa_desc, cur.tipo AS programa_tipo,
                       (SELECT fecha FROM `usuario_evaluaciones` WHERE usuario_id = c.usuario_id AND curso_id = c.curso_id ORDER BY fecha DESC LIMIT 1) AS fecha_eval
                FROM `usuario_certificados_curso` c
                JOIN `usuarios` u ON u.id = c.usuario_id
                JOIN `cursos` cur ON cur.id = c.curso_id";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $codeCalculado = db_generar_codigo_certificado($row['usuario_id'], $row['curso_id'], 'curso');
                if ($codeCalculado === $codigo) {
                    $fechaEmision = !empty($row['fecha_eval']) ? date('d/m/Y', strtotime($row['fecha_eval'])) : date('d/m/Y');
                    return [
                        'valido'          => true,
                        'codigo'          => $codeCalculado,
                        'tipo'            => 'curso',
                        'tipo_label'      => 'Certificación Técnica de Curso',
                        'usuario_id'      => $row['usuario_id'],
                        'usuario_nombre'  => $row['usuario_nombre'],
                        'programa_id'     => $row['curso_id'],
                        'programa_nombre' => $row['programa_nombre'],
                        'programa_desc'   => $row['programa_desc'] ?? '',
                        'fecha_emision'   => $fechaEmision,
                        'institucion'     => 'Universidad del Aluminio',
                        'estado'          => 'OFICIALMENTE REGISTRADO'
                    ];
                }
            }
        }
    } else {
        $sql = "SELECT c.usuario_id, c.carrera_id, u.nombre AS usuario_nombre, car.nombre AS programa_nombre
                FROM `usuario_certificados_carrera` c
                JOIN `usuarios` u ON u.id = c.usuario_id
                JOIN `carreras` car ON car.id = c.carrera_id";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $codeCalculado = db_generar_codigo_certificado($row['usuario_id'], $row['carrera_id'], 'carrera');
                if ($codeCalculado === $codigo) {
                    return [
                        'valido'          => true,
                        'codigo'          => $codeCalculado,
                        'tipo'            => 'carrera',
                        'tipo_label'      => 'Diploma de Graduación de Carrera Profesional',
                        'usuario_id'      => $row['usuario_id'],
                        'usuario_nombre'  => $row['usuario_nombre'],
                        'programa_id'     => $row['carrera_id'],
                        'programa_nombre' => $row['programa_nombre'],
                        'programa_desc'   => 'Programa Integral de Capacitación y Competencia Profesional',
                        'fecha_emision'   => date('d/m/Y'),
                        'institucion'     => 'Universidad del Aluminio',
                        'estado'          => 'OFICIALMENTE REGISTRADO'
                    ];
                }
            }
        }
    }

    return null;
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


