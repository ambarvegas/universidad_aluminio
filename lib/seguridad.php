<?php
/**
 * lib/seguridad.php — Seguridad
 * Registro de actividad y límite de intentos de login.
 * Se carga desde db_mysql.php.
 */

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
