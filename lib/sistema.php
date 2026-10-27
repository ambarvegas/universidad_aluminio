<?php
/**
 * lib/sistema.php — Diagnóstico del sistema
 * Estado de salud del servidor y utilidades.
 * Se carga desde db_mysql.php.
 */

/**
 * Diagnóstico de Salud Integral del Sistema (Base de Datos, Servidor, PHP, Correo y Almacenamiento).
 */
function db_get_health_status(mysqli $conn): array {
    $warnings = [];
    $errors   = [];

    // 1. Diagnóstico de MySQL
    $tPingStart = microtime(true);
    $resPing = $conn->query("SELECT 1");
    $tPingEnd = microtime(true);
    $latenciaMs = round(($tPingEnd - $tPingStart) * 1000, 2);

    $dbName = MYSQL_DB;
    $dbHost = MYSQL_HOST;
    $serverInfo = $conn->server_info;

    // Conteo de tablas y tamaño total
    $tablasCount = 0;
    $filasTotal  = 0;
    $tamanoDbBytes = 0;

    $resTables = $conn->query("SELECT table_name, table_rows, data_length, index_length 
                               FROM information_schema.TABLES 
                               WHERE table_schema = '{$dbName}'");
    if ($resTables) {
        while ($r = $resTables->fetch_assoc()) {
            $tablasCount++;
            $filasTotal += (int)($r['table_rows'] ?? 0);
            $tamanoDbBytes += ((int)($r['data_length'] ?? 0) + (int)($r['index_length'] ?? 0));
        }
    }

    if ($latenciaMs > 500) {
        $warnings[] = "Latencia MySQL elevada ({$latenciaMs} ms). Verifique la conectividad de red.";
    }

    // Errores registrados en las últimas 24 h (PHP y navegador)
    try {
        $errores24h = db_errores_ultimas_24h($conn);
        if ($errores24h > 0) {
            $warnings[] = "Se registraron $errores24h error(es) distinto(s) en las últimas 24 h. Revise la sección «Errores recientes».";
        }
    } catch (Throwable $e) {
        $errores24h = null;
    }

    // Versión del esquema (migraciones aplicadas / disponibles)
    try {
        $esquema = db_estado_migraciones($conn);
        if ($esquema['pendientes']) {
            $warnings[] = 'Migraciones de base de datos pendientes: ' . implode(', ', $esquema['pendientes'])
                        . '. Revise el log de errores del servidor ([migraciones]).';
        }
    } catch (Throwable $e) {
        $esquema = ['version' => 0, 'disponible' => 0, 'pendientes' => []];
        $warnings[] = 'No se pudo consultar el estado de las migraciones: ' . $e->getMessage();
    }

    // 2. Diagnóstico de Espacio en Disco y Almacenamiento
    $diskFree = @disk_free_space(APP_ROOT);
    $diskTotal = @disk_total_space(APP_ROOT);
    $diskFreeFormatted = $diskFree !== false ? db_format_bytes((int)$diskFree) : 'N/D';
    $diskTotalFormatted = $diskTotal !== false ? db_format_bytes((int)$diskTotal) : 'N/D';
    $diskUsagePercent = ($diskTotal && $diskTotal > 0 && $diskFree !== false) ? round((1 - ($diskFree / $diskTotal)) * 100, 1) : null;

    if ($diskUsagePercent !== null && $diskUsagePercent > 90) {
        $warnings[] = "Espacio en disco bajo ({$diskUsagePercent}% utilizado).";
    }

    // Diagnóstico de /uploads/
    $uploadsDir = APP_ROOT . '/uploads/';
    $uploadsCount = 0;
    $uploadsBytes = 0;
    if (is_dir($uploadsDir)) {
        foreach (new DirectoryIterator($uploadsDir) as $f) {
            if ($f->isFile() && $f->getFilename() !== '.htaccess') {
                $uploadsCount++;
                $uploadsBytes += $f->getSize();
            }
        }
    }

    // Diagnóstico de /backups/
    $backupsDir = APP_ROOT . '/backups/';
    $backupsCount = 0;
    $backupsBytes = 0;
    $ultimoBackupFecha = null;
    $ultimoBackupNombre = null;
    if (is_dir($backupsDir)) {
        $backupFiles = glob($backupsDir . "respaldo_unialuminio_*.*");
        if ($backupFiles) {
            $backupsCount = count($backupFiles);
            usort($backupFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $ultimoBackupFecha = date('Y-m-d H:i:s', filemtime($backupFiles[0]));
            $ultimoBackupNombre = basename($backupFiles[0]);
            foreach ($backupFiles as $bf) {
                $backupsBytes += filesize($bf);
            }
        }
    }

    if ($backupsCount === 0) {
        $warnings[] = "Aún no se han generado respaldos automatizados en /backups/.";
    }

    // 3. Configuración de Correo Institucional (SMTP)
    $cfgMail = [];
    $resCfg = $conn->query("SELECT clave, valor FROM `configuracion` WHERE clave LIKE 'smtp_%' OR clave IN ('email_remitente', 'email_admin')");
    if ($resCfg) {
        while ($r = $resCfg->fetch_assoc()) {
            $cfgMail[$r['clave']] = $r['valor'];
        }
    }

    $smtpHost = $cfgMail['smtp_host'] ?? (getenv('SMTP_HOST') ?: '');
    $smtpUser = $cfgMail['smtp_user'] ?? (getenv('SMTP_USER') ?: '');
    $emailAdmin = $cfgMail['email_admin'] ?? '';
    $smtpConfigurado = !empty($smtpHost) && !empty($smtpUser);

    if (!$smtpConfigurado) {
        $warnings[] = "Servidor SMTP no configurado. Las notificaciones se enviarán vía mail() de PHP como fallback.";
    }
    if (empty($emailAdmin)) {
        $warnings[] = "Correo de administración no configurado. No se recibirán alertas de nuevas solicitudes.";
    }

    // 4. Entorno PHP y Extensiones
    $extensiones = [
        'mysqli'    => extension_loaded('mysqli'),
        'zlib'      => extension_loaded('zlib'),
        'zip'       => extension_loaded('zip'),
        'gd'        => extension_loaded('gd'),
        'mbstring'  => extension_loaded('mbstring'),
        'openssl'   => extension_loaded('openssl'),
        'opcache'   => extension_loaded('Zend OPcache') && ini_get('opcache.enable'),
    ];

    foreach (['mysqli', 'mbstring', 'openssl'] as $extCritica) {
        if (!$extensiones[$extCritica]) {
            $errors[] = "Extensión crítica de PHP no disponible: $extCritica";
        }
    }

    // Estado General
    $status = 'healthy';
    if (!empty($errors)) {
        $status = 'critical';
    } elseif (!empty($warnings)) {
        $status = 'warning';
    }

    return [
        'status'         => $status,
        'timestamp'      => date('c'),
        'database'       => [
            'connected'       => true,
            'name'            => $dbName,
            'host'            => $dbHost,
            'server_version'  => $serverInfo,
            'latencia_ms'     => $latenciaMs,
            'tablas_total'    => $tablasCount,
            'filas_estimadas' => $filasTotal,
            'tamano_bytes'    => $tamanoDbBytes,
            'tamano_formato'  => db_format_bytes($tamanoDbBytes),
            'esquema'         => $esquema,
            'errores_24h'     => $errores24h,
        ],
        'storage'        => [
            'disk_free_bytes'    => $diskFree !== false ? (int)$diskFree : null,
            'disk_free_formato'  => $diskFreeFormatted,
            'disk_total_bytes'   => $diskTotal !== false ? (int)$diskTotal : null,
            'disk_total_formato' => $diskTotalFormatted,
            'disk_usage_percent' => $diskUsagePercent,
            'uploads'            => [
                'archivos_total' => $uploadsCount,
                'tamano_bytes'   => $uploadsBytes,
                'tamano_formato' => db_format_bytes($uploadsBytes),
            ],
            'backups'            => [
                'archivos_total' => $backupsCount,
                'tamano_bytes'   => $backupsBytes,
                'tamano_formato' => db_format_bytes($backupsBytes),
                'ultimo_backup'  => $ultimoBackupFecha,
                'ultimo_archivo' => $ultimoBackupNombre,
            ],
        ],
        'php'            => [
            'version'             => phpversion(),
            'sapi'                => php_sapi_name(),
            'memory_limit'        => ini_get('memory_limit'),
            'max_execution_time'  => (int)ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size'       => ini_get('post_max_size'),
            'extensiones'         => $extensiones,
        ],
        'mailer'         => [
            'smtp_configurado' => $smtpConfigurado,
            'smtp_host'        => $smtpHost ?: 'No configurado (fallback mail())',
            'smtp_port'        => (int)($cfgMail['smtp_port'] ?? 587),
            'smtp_secure'      => $cfgMail['smtp_secure'] ?? 'tls',
            'email_remitente'  => $cfgMail['email_remitente'] ?? '',
            'email_admin'      => $emailAdmin ?: 'No configurado',
        ],
        'warnings'       => $warnings,
        'errors'         => $errors,
    ];
}

/**
 * Helper para formateo de bytes legible en db_mysql.
 */
function db_format_bytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
