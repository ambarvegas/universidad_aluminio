<?php
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Content-Type: application/json");
}

$db_file = __DIR__ . '/db.json';
$bak_file = __DIR__ . '/db.json.bak';
$tmp_file = __DIR__ . '/db.json.tmp';
$lock_file = __DIR__ . '/db.lock';
$backups_dir = __DIR__ . '/backups';

$initial_structure = [
    "usuarios" => [],
    "cursos" => [],
    "carreras" => [],
    "rolesConfig" => [],
    "solicitudesRegistro" => [],
    "solicitudesCursos" => [],
    "configuracion" => ["nombreInstitucion" => "Universidad del Aluminio", "logo" => "", "minAprobacion" => 70]
];

/**
 * Valida si el payload de datos JSON posee la estructura requerida.
 */
if (!function_exists('validate_db_structure')) {
    function validate_db_structure($data) {
        if (!is_array($data)) return false;
        $required_keys = ['usuarios', 'cursos', 'carreras', 'rolesConfig', 'solicitudesRegistro', 'solicitudesCursos'];
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) {
                return false;
            }
        }
        return true;
    }
}

/**
 * Lee la base de datos de manera segura con auto-recuperación desde backup.
 */
if (!function_exists('read_db_safe')) {
    function read_db_safe($db_file, $bak_file, $lock_file, $initial_structure) {
        $lock_fp = fopen($lock_file, 'c+');
        if ($lock_fp) flock($lock_fp, LOCK_SH);

        $content = null;
        if (file_exists($db_file)) {
            $raw = @file_get_contents($db_file);
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && validate_db_structure($decoded)) {
                $content = $raw;
            }
        }

        // Auto-recuperación si db.json falta o está corrupto
        if ($content === null && file_exists($bak_file)) {
            $raw = @file_get_contents($bak_file);
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && validate_db_structure($decoded)) {
                $content = $raw;
                // Restaurar db.json desde la copia de respaldo válida
                @file_put_contents($db_file, $content);
            }
        }

        if ($lock_fp) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        if ($content !== null) {
            return $content;
        }

        return json_encode($initial_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Escribe en la base de datos de forma atómica, con bloqueos y backups.
 */
if (!function_exists('write_db_safe')) {
    function write_db_safe($db_file, $bak_file, $tmp_file, $lock_file, $backups_dir, $json_raw, $decoded_data) {
        // 1. Obtener bloqueo exclusivo
        $lock_fp = fopen($lock_file, 'c+');
        if (!$lock_fp || !flock($lock_fp, LOCK_EX)) {
            if ($lock_fp) fclose($lock_fp);
            return false;
        }

        try {
            // 2. Crear respaldo db.json.bak si db.json existe y es válido
            if (file_exists($db_file)) {
                $current_raw = @file_get_contents($db_file);
                $current_decoded = json_decode($current_raw, true);
                if (json_last_error() === JSON_ERROR_NONE && validate_db_structure($current_decoded)) {
                    @copy($db_file, $bak_file);

                    // Crear respaldo rotatorio opcional en la carpeta backups/
                    if (!is_dir($backups_dir)) {
                        @mkdir($backups_dir, 0755, true);
                    }
                    if (is_dir($backups_dir)) {
                        $timestamp = date('Ymd_His');
                        @copy($db_file, $backups_dir . "/db_{$timestamp}.json");
                        // Mantener únicamente los últimos 20 backups
                        $backups = glob($backups_dir . '/db_*.json');
                        if (is_array($backups) && count($backups) > 20) {
                            usort($backups, function($a, $b) { return filemtime($a) - filemtime($b); });
                            while (count($backups) > 20) {
                                $old_file = array_shift($backups);
                                @unlink($old_file);
                            }
                        }
                    }
                }
            }

            // 3. Escribir al archivo temporal .tmp
            $formatted_json = json_encode($decoded_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $written = file_put_contents($tmp_file, $formatted_json);
            if ($written === false) {
                flock($lock_fp, LOCK_UN);
                fclose($lock_fp);
                return false;
            }

            // 4. Renombrado atómico
            $renamed = rename($tmp_file, $db_file);

            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);

            return $renamed;
        } catch (\Throwable $e) {
            if (file_exists($tmp_file)) @unlink($tmp_file);
            if ($lock_fp) {
                flock($lock_fp, LOCK_UN);
                fclose($lock_fp);
            }
            return false;
        }
    }
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'GET') {
    echo read_db_safe($db_file, $bak_file, $lock_file, $initial_structure);
} elseif ($method === 'POST') {
    $json = file_get_contents('php://input');

    if (!empty($json)) {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(["error" => "El JSON enviado no es valido: " . json_last_error_msg()]);
            exit;
        }

        if (!validate_db_structure($decoded)) {
            http_response_code(400);
            echo json_encode(["error" => "El objeto JSON no contiene las propiedades requeridas (usuarios, cursos, carreras, etc.)"]);
            exit;
        }

        if (write_db_safe($db_file, $bak_file, $tmp_file, $lock_file, $backups_dir, $json, $decoded)) {
            echo json_encode(["message" => "Base de datos guardada correctamente"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Error al escribir de forma segura en la base de datos"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "No se recibieron datos"]);
    }
} elseif ($method === 'OPTIONS') {
    http_response_code(200);
} else {
    http_response_code(405);
    echo json_encode(["error" => "Metodo no permitido"]);
}