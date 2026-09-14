<?php
/**
 * cron_backup.php — Universidad del Aluminio
 * Generador automatizado de respaldos para MySQL y archivos /uploads/
 *
 * Puede ejecutarse:
 *  1. Desde CLI / Cron: php tools/cron_backup.php
 *  2. Desde la API interna (Admin): ejecutarRespaldo($conn)
 *
 * Características:
 *  - Volcado SQL nativo puro en PHP (sin requerir binarios externos como mysqldump).
 *  - Empaquetado comprimido ZIP con la base de datos SQL y todos los archivos de /uploads/.
 *  - Protección de seguridad en la carpeta /backups/ (.htaccess + index.html).
 *  - Política de rotación y retención automática (conserva las últimas 7 copias).
 */

require_once __DIR__ . '/../db_mysql.php';

/**
 * Función principal que ejecuta el proceso completo de respaldo.
 *
 * @param mysqli|null $conn Conexión opcional. Si no se pasa, se crea una automáticamente.
 * @param int $retencionMax Cantidad máxima de respaldos a conservar (por defecto 7).
 * @return array Metadatos del respaldo generado.
 */
function ejecutarRespaldo(?mysqli $conn = null, int $retencionMax = 7): array {
    $tInicio = microtime(true);
    $cerrarConnAlFinal = false;

    if (!$conn) {
        $conn = db_connect();
        $cerrarConnAlFinal = true;
    }

    $backupDir = dirname(__DIR__) . '/backups/';
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            throw new RuntimeException("No se pudo crear el directorio de respaldos: $backupDir");
        }
    }

    // Asegurar protección de la carpeta de respaldos
    $htaccessPath = $backupDir . '.htaccess';
    if (!file_exists($htaccessPath)) {
        file_put_contents($htaccessPath, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $indexHtmlPath = $backupDir . 'index.html';
    if (!file_exists($indexHtmlPath)) {
        file_put_contents($indexHtmlPath, "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Acceso Denegado</h1></body></html>");
    }

    $fechaStamp = date('Y-m-d_His');
    $nombreBase = "respaldo_unialuminio_{$fechaStamp}";
    $sqlFilePath = $backupDir . "{$nombreBase}.sql";
    $zipFilePath = $backupDir . "{$nombreBase}.zip";

    // ------------------------------------------------------------
    // 1. GENERAR VOLCADO SQL PURO
    // ------------------------------------------------------------
    $sqlHandle = fopen($sqlFilePath, 'w');
    if (!$sqlHandle) {
        throw new RuntimeException("No se pudo crear el archivo temporal de volcado SQL.");
    }

    $dbName = MYSQL_DB;
    $header  = "-- ============================================================\n";
    $header .= "-- UNIVERSIDAD DEL ALUMINIO — RESPALDO AUTOMATIZADO DE BASE DE DATOS\n";
    $header .= "-- Base de Datos: {$dbName}\n";
    $header .= "-- Fecha y Hora:  " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- Servidor:      " . MYSQL_HOST . "\n";
    $header .= "-- ============================================================\n\n";
    $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $header .= "SET time_zone = \"+00:00\";\n";
    $header .= "SET NAMES utf8mb4;\n\n";
    fwrite($sqlHandle, $header);

    // Obtener lista de tablas
    $tablas = [];
    $resTablas = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    if ($resTablas) {
        while ($row = $resTablas->fetch_row()) {
            $tablas[] = $row[0];
        }
    }

    $totalFilasRespaldadas = 0;

    foreach ($tablas as $tabla) {
        fwrite($sqlHandle, "-- ------------------------------------------------------------\n");
        fwrite($sqlHandle, "-- Estructura de la tabla `{$tabla}`\n");
        fwrite($sqlHandle, "-- ------------------------------------------------------------\n");
        fwrite($sqlHandle, "DROP TABLE IF EXISTS `{$tabla}`;\n");

        $resCreate = $conn->query("SHOW CREATE TABLE `{$tabla}`");
        if ($resCreate && $rowCreate = $resCreate->fetch_row()) {
            fwrite($sqlHandle, $rowCreate[1] . ";\n\n");
        }

        // Volcar datos
        $resCount = $conn->query("SELECT COUNT(*) FROM `{$tabla}`");
        $rowCount = ($resCount && $rc = $resCount->fetch_row()) ? (int)$rc[0] : 0;

        if ($rowCount > 0) {
            fwrite($sqlHandle, "-- Volcado de datos para la tabla `{$tabla}` ({$rowCount} registros)\n");
            $offset = 0;
            $limit = 200;

            while ($offset < $rowCount) {
                $resData = $conn->query("SELECT * FROM `{$tabla}` LIMIT {$limit} OFFSET {$offset}");
                if ($resData && $resData->num_rows > 0) {
                    $insertPrefix = "INSERT INTO `{$tabla}` VALUES \n";
                    $valuesArr = [];

                    while ($row = $resData->fetch_row()) {
                        $totalFilasRespaldadas++;
                        $escapedValues = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $escapedValues[] = "NULL";
                            } else {
                                $escapedValues[] = "'" . $conn->real_escape_string($val) . "'";
                            }
                        }
                        $valuesArr[] = "(" . implode(', ', $escapedValues) . ")";
                    }

                    fwrite($sqlHandle, $insertPrefix . implode(",\n", $valuesArr) . ";\n");
                }
                $offset += $limit;
            }
            fwrite($sqlHandle, "\n");
        }
    }

    fwrite($sqlHandle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($sqlHandle, "-- Fin del respaldo\n");
    fclose($sqlHandle);

    // ------------------------------------------------------------
    // 2. EMPAQUETAR EN ZIP (SQL + CARPETA /uploads/)
    // ------------------------------------------------------------
    $archivoFinal = $sqlFilePath;
    $esZip = false;
    $archivosUploads = 0;

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Agregar dump SQL en la raíz del ZIP
            $zip->addFile($sqlFilePath, "database_{$dbName}.sql");

            // Agregar archivos de uploads
            $uploadsDir = dirname(__DIR__) . '/uploads/';
            if (is_dir($uploadsDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    $itemPath = $item->getRealPath();
                    $relPath = 'uploads/' . ltrim(substr($itemPath, strlen(realpath($uploadsDir))), '/\\');
                    $relPath = str_replace('\\', '/', $relPath);

                    if ($item->isDir()) {
                        $zip->addEmptyDir($relPath);
                    } else if ($item->isFile()) {
                        $zip->addFile($itemPath, $relPath);
                        $archivosUploads++;
                    }
                }
            }

            // Metadatos informativos del respaldo
            $infoManifest = [
                'sistema'          => 'Universidad del Aluminio LMS',
                'fecha_generacion' => date('Y-m-d H:i:s'),
                'base_datos'       => $dbName,
                'tablas_total'     => count($tablas),
                'filas_total'      => $totalFilasRespaldadas,
                'archivos_uploads' => $archivosUploads,
                'php_version'      => phpversion(),
            ];
            $zip->addFromString('manifest.json', json_encode($infoManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $zip->close();
            $esZip = true;
            $archivoFinal = $zipFilePath;

            // Eliminar el SQL suelto para no duplicar espacio
            @unlink($sqlFilePath);
        }
    }

    // ------------------------------------------------------------
    // 3. POLÍTICA DE ROTACIÓN Y RETENCIÓN (conservar los últimos N)
    // ------------------------------------------------------------
    $eliminados = 0;
    $archivosRespaldos = glob($backupDir . "respaldo_unialuminio_*.*");
    if ($archivosRespaldos) {
        // Ordenar por fecha de modificación descendente (más nuevos primero)
        usort($archivosRespaldos, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Eliminar los que excedan la retención máxima
        for ($i = $retencionMax; $i < count($archivosRespaldos); $i++) {
            if (is_file($archivosRespaldos[$i])) {
                @unlink($archivosRespaldos[$i]);
                $eliminados++;
            }
        }
    }

    $tFin = microtime(true);
    $duracion = round($tFin - $tInicio, 2);
    $tamanoBytes = is_file($archivoFinal) ? filesize($archivoFinal) : 0;
    $tamanoFormateado = formatBytes($tamanoBytes);

    if ($cerrarConnAlFinal && $conn) {
        $conn->close();
    }

    // Registrar en el log de actividad
    if (!$cerrarConnAlFinal && $conn) {
        @db_log_activity($conn, 'SISTEMA_CRON', 'BACKUP_AUTOMATICO', "Respaldo: " . basename($archivoFinal) . " ($tamanoFormateado)", '127.0.0.1');
    }

    return [
        'success'             => true,
        'archivo'             => basename($archivoFinal),
        'ruta_relativa'       => 'backups/' . basename($archivoFinal),
        'formato'             => $esZip ? 'ZIP (SQL + Uploads)' : 'SQL Plano',
        'tamano'              => $tamanoFormateado,
        'tamano_bytes'        => $tamanoBytes,
        'tablas_respaldadas'  => count($tablas),
        'filas_respaldadas'   => $totalFilasRespaldadas,
        'archivos_uploads'    => $archivosUploads,
        'fecha'               => date('Y-m-d H:i:s'),
        'duracion_segundos'   => $duracion,
        'eliminados_rotacion' => $eliminados,
    ];
}

/**
 * Helper para formatear bytes a formato legible (KB, MB, GB).
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ------------------------------------------------------------
// EJECUCIÓN DIRECTA DESDE CLI
// ------------------------------------------------------------
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    echo "====================================================\n";
    echo " Universidad del Aluminio — Generador de Respaldos \n";
    echo "====================================================\n";
    echo "Iniciando respaldo automatizado...\n";

    try {
        $resultado = ejecutarRespaldo();
        echo " [OK] Respaldo completado exitosamente.\n";
        echo " Archivo:      {$resultado['archivo']}\n";
        echo " Formato:      {$resultado['formato']}\n";
        echo " Tamaño:       {$resultado['tamano']}\n";
        echo " Tablas:       {$resultado['tablas_respaldadas']}\n";
        echo " Registros:    {$resultado['filas_respaldadas']}\n";
        echo " Imgs Uploads: {$resultado['archivos_uploads']}\n";
        echo " Duración:     {$resultado['duracion_segundos']}s\n";
        echo " Rotación:     {$resultado['eliminados_rotacion']} archivos antiguos eliminados\n";
        exit(0);
    } catch (Throwable $e) {
        echo " [ERROR] Falló la creación del respaldo: " . $e->getMessage() . "\n";
        exit(1);
    }
}
