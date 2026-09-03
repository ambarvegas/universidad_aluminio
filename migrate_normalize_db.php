<?php
/**
 * migrate_normalize_db.php
 * Script de migración y normalización de base de datos MySQL — Universidad del Aluminio
 *
 * Normaliza columnas JSON a tablas relacionales con PK, FK e Índices:
 * - carrera_cursos
 * - rol_permisos
 * - rol_cursos
 * - rol_carreras
 * - usuario_lecciones_completadas
 * - usuario_modulos_aprobados
 * - usuario_medallas
 * - usuario_evaluaciones
 * - usuario_intentos
 */

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(0);

require_once __DIR__ . '/db_mysql.php';

$isCli = (php_sapi_name() === 'cli');

function out(string $msg, string $type = 'info'): void {
    global $isCli;
    if ($isCli) {
        $prefix = match($type) {
            'ok'   => '[OK] ',
            'err'  => '[ERROR] ',
            'warn' => '[WARN] ',
            default => '[INFO] '
        };
        echo $prefix . $msg . PHP_EOL;
    } else {
        echo "<p class=\"$type\">" . htmlspecialchars($msg) . "</p>\n";
        flush();
    }
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Normalización MySQL</title>
    <style>body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:2rem;}
    .ok{color:#4ade80;} .err{color:#f87171;} .warn{color:#facc15;} .info{color:#94a3b8;}</style></head><body>
    <h1>🔧 Normalización Relacional MySQL — Universidad del Aluminio</h1>';
}

try {
    $conn = db_connect();
    out("Conectado exitosamente a MySQL (" . MYSQL_HOST . " / " . MYSQL_DB . ")", 'ok');

    out("Paso 1: Creando nuevas tablas relacionales normalizadas...", 'info');

    $createTableStatements = [
        // 1. Relación Carrera - Cursos
        "CREATE TABLE IF NOT EXISTS `carrera_cursos` (
            `carrera_id` VARCHAR(100) NOT NULL,
            `curso_id`   VARCHAR(100) NOT NULL,
            `orden`      INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`carrera_id`, `curso_id`),
            INDEX `idx_cc_curso` (`curso_id`),
            FOREIGN KEY (`carrera_id`) REFERENCES `carreras`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 2. Relación Rol - Permisos
        "CREATE TABLE IF NOT EXISTS `rol_permisos` (
            `rol_id`  VARCHAR(100) NOT NULL,
            `permiso` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `permiso`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 3. Relación Rol - Cursos asignados por defecto
        "CREATE TABLE IF NOT EXISTS `rol_cursos` (
            `rol_id`   VARCHAR(100) NOT NULL,
            `curso_id` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `curso_id`),
            INDEX `idx_rc_curso` (`curso_id`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 4. Relación Rol - Carreras asignadas por defecto
        "CREATE TABLE IF NOT EXISTS `rol_carreras` (
            `rol_id`     VARCHAR(100) NOT NULL,
            `carrera_id` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`rol_id`, `carrera_id`),
            INDEX `idx_rc_carrera` (`carrera_id`),
            FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 5. Progreso de Usuario: Lecciones completadas
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

        // 6. Progreso de Usuario: Módulos aprobados
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

        // 7. Progreso de Usuario: Medallas obtenidas
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

        // 8. Progreso de Usuario: Evaluaciones rendidas
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

        // 9. Progreso de Usuario: Intentos por módulo
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
    ];

    foreach ($createTableStatements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException("Error creando tabla: " . $conn->error . " en SQL: " . substr($sql, 0, 80));
        }
    }
    out("Tablas relacionales creadas/verificadas con éxito.", 'ok');

    out("Paso 2: Iniciando migración de datos existentes...", 'info');
    $conn->begin_transaction();

    // 2.1 Migrar carreras.cursos -> carrera_cursos
    out("  → Migrando carreras...", 'info');
    $resCar = $conn->query("SELECT id, cursos FROM `carreras`");
    $carreraCursosRows = [];
    while ($row = $resCar->fetch_assoc()) {
        $cId = $row['id'];
        $cursos = json_decode($row['cursos'] ?? '[]', true) ?: [];
        $orden = 0;
        foreach ($cursos as $cursoId) {
            $cursoIdStr = trim((string)$cursoId);
            if ($cursoIdStr) {
                $carreraCursosRows[] = [$cId, $cursoIdStr, $orden++];
            }
        }
    }
    if (!empty($carreraCursosRows)) {
        db_bulk_insert($conn, 'carrera_cursos', ['carrera_id', 'curso_id', 'orden'], $carreraCursosRows, 100, '', true);
    }
    out("    ✅ Carrera_cursos migrados: " . count($carreraCursosRows) . " relaciones.", 'ok');

    // 2.2 Migrar roles_config (permisos, cursos, carreras)
    out("  → Migrando roles_config...", 'info');
    $resRoles = $conn->query("SELECT id, permisos, cursos, carreras FROM `roles_config`");
    $rolPermisosRows = [];
    $rolCursosRows = [];
    $rolCarrerasRows = [];
    while ($row = $resRoles->fetch_assoc()) {
        $rId = $row['id'];
        $permisos = json_decode($row['permisos'] ?? '[]', true) ?: [];
        foreach ($permisos as $p) {
            $pStr = trim((string)$p);
            if ($pStr) $rolPermisosRows[] = [$rId, $pStr];
        }

        $cursos = json_decode($row['cursos'] ?? '[]', true) ?: [];
        foreach ($cursos as $c) {
            $cStr = trim((string)$c);
            if ($cStr) $rolCursosRows[] = [$rId, $cStr];
        }

        $carreras = json_decode($row['carreras'] ?? '[]', true) ?: [];
        foreach ($carreras as $ca) {
            $caStr = trim((string)$ca);
            if ($caStr) $rolCarrerasRows[] = [$rId, $caStr];
        }
    }
    if (!empty($rolPermisosRows)) {
        db_bulk_insert($conn, 'rol_permisos', ['rol_id', 'permiso'], $rolPermisosRows, 100, '', true);
    }
    if (!empty($rolCursosRows)) {
        db_bulk_insert($conn, 'rol_cursos', ['rol_id', 'curso_id'], $rolCursosRows, 100, '', true);
    }
    if (!empty($rolCarrerasRows)) {
        db_bulk_insert($conn, 'rol_carreras', ['rol_id', 'carrera_id'], $rolCarrerasRows, 100, '', true);
    }
    out("    ✅ Roles migrados: " . count($rolPermisosRows) . " permisos, " . count($rolCursosRows) . " cursos, " . count($rolCarrerasRows) . " carreras.", 'ok');

    // 2.3 Migrar usuario_progreso
    out("  → Migrando usuario_progreso a tablas desagregadas...", 'info');
    $resProg = $conn->query("SELECT usuario_id, curso_id, lecciones_completadas, modulos_aprobados, medallas, evaluaciones, intentos FROM `usuario_progreso`");

    $lecRows = [];
    $modRows = [];
    $medRows = [];
    $evalRows = [];
    $intRows = [];

    $countProg = 0;
    while ($row = $resProg->fetch_assoc()) {
        $countProg++;
        $uId = $row['usuario_id'];
        $cId = $row['curso_id'];

        // Lecciones completadas
        $lecs = json_decode($row['lecciones_completadas'] ?? '[]', true) ?: [];
        foreach ($lecs as $lec) {
            $lecCode = trim((string)$lec);
            if ($lecCode !== '') {
                $lecRows[] = [$uId, $cId, $lecCode];
            }
        }

        // Módulos aprobados
        $mods = json_decode($row['modulos_aprobados'] ?? '[]', true) ?: [];
        foreach ($mods as $m) {
            $mNum = trim((string)$m);
            if ($mNum !== '') {
                $modRows[] = [$uId, $cId, $mNum];
            }
        }

        // Medallas
        $meds = json_decode($row['medallas'] ?? '[]', true) ?: [];
        foreach ($meds as $med) {
            $medNum = trim((string)$med);
            if ($medNum !== '') {
                $medRows[] = [$uId, $cId, $medNum];
            }
        }

        // Evaluaciones
        $evals = json_decode($row['evaluaciones'] ?? '{}', true) ?: [];
        if (is_array($evals)) {
            foreach ($evals as $mNum => $eData) {
                $mNumStr = trim((string)$mNum);
                if ($mNumStr !== '' && is_array($eData)) {
                    $calif = floatval($eData['calificacion'] ?? $eData['nota'] ?? 0);
                    $aprob = !empty($eData['aprobado']) ? 1 : 0;
                    $manual = !empty($eData['marcadoManual']) ? 1 : 0;
                    $fecha = $eData['fecha'] ?? null;
                    $evalRows[] = [$uId, $cId, $mNumStr, $calif, $aprob, $manual, $fecha];
                }
            }
        }

        // Intentos
        $ints = json_decode($row['intentos'] ?? '{}', true) ?: [];
        if (is_array($ints)) {
            foreach ($ints as $mNum => $intVal) {
                $mNumStr = trim((string)$mNum);
                $intNum = intval($intVal);
                if ($mNumStr !== '' && $intNum > 0) {
                    $intRows[] = [$uId, $cId, $mNumStr, $intNum];
                }
            }
        }
    }

    out("    Procesados $countProg registros base de usuario_progreso.");

    if (!empty($lecRows)) {
        db_bulk_insert($conn, 'usuario_lecciones_completadas', ['usuario_id', 'curso_id', 'leccion_codigo'], $lecRows, 200, '', true);
        out("    ✅ Lecciones completadas insertadas: " . count($lecRows) . " filas", 'ok');
    }
    if (!empty($modRows)) {
        db_bulk_insert($conn, 'usuario_modulos_aprobados', ['usuario_id', 'curso_id', 'modulo_num'], $modRows, 200, '', true);
        out("    ✅ Módulos aprobados insertados: " . count($modRows) . " filas", 'ok');
    }
    if (!empty($medRows)) {
        db_bulk_insert($conn, 'usuario_medallas', ['usuario_id', 'curso_id', 'medalla_num'], $medRows, 200, '', true);
        out("    ✅ Medallas insertadas: " . count($medRows) . " filas", 'ok');
    }
    if (!empty($evalRows)) {
        db_bulk_insert($conn, 'usuario_evaluaciones', ['usuario_id', 'curso_id', 'modulo_num', 'calificacion', 'aprobado', 'marcado_manual', 'fecha'], $evalRows, 100,
            "ON DUPLICATE KEY UPDATE calificacion=VALUES(calificacion), aprobado=VALUES(aprobado), marcado_manual=VALUES(marcado_manual), fecha=VALUES(fecha)");
        out("    ✅ Evaluaciones insertadas: " . count($evalRows) . " filas", 'ok');
    }
    if (!empty($intRows)) {
        db_bulk_insert($conn, 'usuario_intentos', ['usuario_id', 'curso_id', 'modulo_num', 'intentos'], $intRows, 100,
            "ON DUPLICATE KEY UPDATE intentos=VALUES(intentos)");
        out("    ✅ Intentos insertados: " . count($intRows) . " filas", 'ok');
    }

    $conn->commit();
    out("Paso 3: Transacción completada con éxito.", 'ok');

    out("Paso 4: Verificación de conteos en nuevas tablas...", 'info');
    $normalizedTables = [
        'carrera_cursos',
        'rol_permisos',
        'rol_cursos',
        'rol_carreras',
        'usuario_lecciones_completadas',
        'usuario_modulos_aprobados',
        'usuario_medallas',
        'usuario_evaluaciones',
        'usuario_intentos',
    ];

    foreach ($normalizedTables as $tbl) {
        $cnt = $conn->query("SELECT COUNT(*) as c FROM `$tbl`")->fetch_assoc()['c'];
        out("   📊 $tbl: $cnt registros", 'ok');
    }

    out("🎉 Normalización completada con éxito. Todos los datos han sido migrados y verificados.", 'ok');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    out("❌ Error: " . $e->getMessage(), 'err');
}

if (!$isCli) {
    echo '</body></html>';
}
