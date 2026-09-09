<?php
/**
 * migrate_normalize_cursos.php
 * Normalización relacional de cursos.modulos y limpieza de columnas JSON redundantes.
 *
 * Acciones:
 *  1. Crea tablas: curso_modulos, curso_lecciones, curso_preguntas
 *  2. Migra cursos.modulos (JSON) → las 3 tablas relacionales
 *  3. Elimina columnas JSON redundantes:
 *       - cursos.modulos
 *       - carreras.cursos
 *       - roles_config.permisos / .cursos / .carreras
 *
 * Idempotente: puede ejecutarse múltiples veces sin duplicar datos.
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
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Normalizacion Cursos</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 2rem; }
        h1   { color: #38bdf8; }
        .ok   { color: #4ade80; }
        .err  { color: #f87171; }
        .warn { color: #facc15; }
        .info { color: #94a3b8; }
    </style></head><body>
    <h1>Normalizacion Relacional - Modulos de Cursos</h1>';
}

try {
    $conn = db_connect();
    out("Conectado a MySQL (" . MYSQL_HOST . " / " . MYSQL_DB . ")", 'ok');

    // =========================================================
    // PASO 1: Crear tablas nuevas
    // =========================================================
    out("Paso 1: Creando tablas relacionales...", 'info');

    $createStatements = [
        "CREATE TABLE IF NOT EXISTS `curso_modulos` (
            `id`       INT          NOT NULL AUTO_INCREMENT,
            `curso_id` VARCHAR(100) NOT NULL,
            `orden`    INT          NOT NULL DEFAULT 0,
            `titulo`   VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            INDEX `idx_cm_curso` (`curso_id`),
            FOREIGN KEY (`curso_id`) REFERENCES `cursos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

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
    ];

    foreach ($createStatements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException("Error creando tabla: " . $conn->error);
        }
    }
    out("  Tablas curso_modulos, curso_lecciones, curso_preguntas creadas/verificadas.", 'ok');

    // =========================================================
    // PASO 2: Verificar que cursos aun tiene columna modulos
    // =========================================================
    $hasModulos = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'modulos'");
    if (!$hasModulos || $hasModulos->num_rows === 0) {
        out("Paso 2: La columna cursos.modulos ya fue eliminada. Saltando migracion de datos.", 'warn');
    } else {
        out("Paso 2: Migrando datos de cursos.modulos a tablas relacionales...", 'info');

        $resCursos = $conn->query("SELECT id, modulos FROM `cursos` WHERE modulos IS NOT NULL AND modulos != '' AND modulos != '[]'");
        $totalModulos   = 0;
        $totalLecciones = 0;
        $totalPreguntas = 0;
        $totalCursos    = 0;

        $conn->begin_transaction();

        while ($curso = $resCursos->fetch_assoc()) {
            $cid     = $curso['id'];
            $safeCid = $conn->real_escape_string($cid);
            $modulos = json_decode($curso['modulos'], true);
            if (!is_array($modulos) || empty($modulos)) continue;

            // Verificar si ya tiene modulos migrados
            $existing = $conn->query("SELECT COUNT(*) as c FROM `curso_modulos` WHERE curso_id = '$safeCid'")->fetch_assoc();
            if ((int)$existing['c'] > 0) {
                out("  Curso '$cid' ya tiene modulos migrados. Omitiendo.", 'warn');
                continue;
            }

            $totalCursos++;
            $modOrd = 0;
            foreach ($modulos as $mod) {
                $modTitulo = $conn->real_escape_string(trim((string)($mod['titulo'] ?? '')));
                $conn->query("INSERT INTO `curso_modulos` (curso_id, orden, titulo) VALUES ('$safeCid', $modOrd, '$modTitulo')");
                $modId = (int)$conn->insert_id;
                $modOrd++;
                $totalModulos++;

                // Lecciones
                $lecOrd = 0;
                foreach (($mod['lecciones'] ?? []) as $lec) {
                    $lTit  = $conn->real_escape_string(trim((string)($lec['titulo']   ?? '')));
                    $lVid  = $conn->real_escape_string(trim((string)($lec['videoID']  ?? '')));
                    $lCont = $conn->real_escape_string((string)($lec['contenido'] ?? ''));
                    $lAdj  = !empty($lec['adjunto']) ? "'" . $conn->real_escape_string($lec['adjunto']) . "'" : 'NULL';
                    $conn->query("INSERT INTO `curso_lecciones` (modulo_id, curso_id, orden, titulo, video_id, contenido, adjunto) VALUES ($modId, '$safeCid', $lecOrd, '$lTit', '$lVid', '$lCont', $lAdj)");
                    $lecOrd++;
                    $totalLecciones++;
                }

                // Preguntas
                $preOrd    = 0;
                $preguntas = $mod['evaluacion']['preguntas'] ?? [];
                foreach ($preguntas as $preg) {
                    $pEnun = $conn->real_escape_string((string)($preg['enunciado'] ?? ''));
                    $pOpc  = $conn->real_escape_string(json_encode($preg['opciones'] ?? []));
                    $pCorr = (int)($preg['correcta'] ?? 0);
                    $conn->query("INSERT INTO `curso_preguntas` (modulo_id, curso_id, orden, enunciado, opciones, correcta) VALUES ($modId, '$safeCid', $preOrd, '$pEnun', '$pOpc', $pCorr)");
                    $preOrd++;
                    $totalPreguntas++;
                }
            }
        }

        $conn->commit();
        out("  Migracion completada:", 'ok');
        out("     Cursos procesados:  $totalCursos", 'ok');
        out("     Modulos insertados: $totalModulos", 'ok');
        out("     Lecciones:          $totalLecciones", 'ok');
        out("     Preguntas:          $totalPreguntas", 'ok');
    }

    // =========================================================
    // PASO 3: Verificar conteos en tablas relacionales
    // =========================================================
    out("Paso 3: Verificando conteos...", 'info');
    foreach (['curso_modulos', 'curso_lecciones', 'curso_preguntas'] as $tbl) {
        $cnt = $conn->query("SELECT COUNT(*) as c FROM `$tbl`")->fetch_assoc()['c'];
        out("   $tbl: $cnt registros", 'ok');
    }

    // =========================================================
    // PASO 4: Eliminar columnas JSON redundantes
    // =========================================================
    out("Paso 4: Eliminando columnas JSON redundantes...", 'info');

    // cursos.modulos
    $col = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'modulos'");
    if ($col && $col->num_rows > 0) {
        if ($conn->query("ALTER TABLE `cursos` DROP COLUMN `modulos`")) {
            out("  cursos.modulos eliminada.", 'ok');
        } else {
            out("  Error eliminando cursos.modulos: " . $conn->error, 'err');
        }
    } else {
        out("  cursos.modulos ya estaba eliminada.", 'info');
    }

    // carreras.cursos
    $col = $conn->query("SHOW COLUMNS FROM `carreras` LIKE 'cursos'");
    if ($col && $col->num_rows > 0) {
        if ($conn->query("ALTER TABLE `carreras` DROP COLUMN `cursos`")) {
            out("  carreras.cursos eliminada.", 'ok');
        } else {
            out("  Error eliminando carreras.cursos: " . $conn->error, 'err');
        }
    } else {
        out("  carreras.cursos ya estaba eliminada.", 'info');
    }

    // roles_config.permisos / .cursos / .carreras
    foreach (['permisos', 'cursos', 'carreras'] as $colName) {
        $col = $conn->query("SHOW COLUMNS FROM `roles_config` LIKE '$colName'");
        if ($col && $col->num_rows > 0) {
            if ($conn->query("ALTER TABLE `roles_config` DROP COLUMN `$colName`")) {
                out("  roles_config.$colName eliminada.", 'ok');
            } else {
                out("  Error eliminando roles_config.$colName: " . $conn->error, 'err');
            }
        } else {
            out("  roles_config.$colName ya estaba eliminada.", 'info');
        }
    }

    out("", 'info');
    out("Normalizacion completada exitosamente. La base de datos esta ahora 100% relacional.", 'ok');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    out("Error fatal: " . $e->getMessage(), 'err');
}

if (!$isCli) {
    echo '</body></html>';
}
