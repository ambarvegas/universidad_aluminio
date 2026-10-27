<?php
/**
 * lib/errores.php — Registro de errores (PHP y JavaScript)
 * Guarda los errores agrupados por huella en `errores_log` para consultarlos desde el
 * diagnóstico del panel. Registrar un error nunca debe provocar otro: todo va protegido.
 * Se carga desde db_mysql.php.
 */

const ERRORES_MAX_POR_IP = 20;        // errores nuevos aceptados por IP en la ventana
const ERRORES_VENTANA_MIN = 10;
const ERRORES_DIAS_RETENCION = 30;
const ERRORES_MAX_FILAS = 2000;

/** Recorta a $max caracteres (UTF-8) quitando caracteres de control. */
function errores_texto($valor, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$valor) ?? '';
    return mb_substr($s, 0, $max);
}

/** Ruta sin parámetros ni fragmento: la URL puede llevar tokens (recuperar.php?token=...). */
function errores_ruta(string $url): string {
    $ruta = parse_url($url, PHP_URL_PATH);
    return is_string($ruta) && str_starts_with($ruta, '/') ? errores_texto($ruta, 255) : '';
}

/**
 * Registra (o suma una repetición de) un error.
 * @param array $e origen ('js'|'php'), mensaje, detalle, ubicacion, usuario_id, navegador, version, ip
 * @return bool false si se descartó por el límite de la IP
 */
function db_registrar_error(mysqli $conn, array $e): bool {
    $origen    = in_array($e['origen'] ?? '', ['js', 'php'], true) ? $e['origen'] : 'js';
    $mensaje   = errores_texto($e['mensaje'] ?? '', 500);
    $detalle   = errores_texto($e['detalle'] ?? '', 4000);
    $ubicacion = errores_texto($e['ubicacion'] ?? '', 255);
    $usuario   = errores_texto($e['usuario_id'] ?? '', 50);
    $navegador = errores_texto($e['navegador'] ?? '', 255);
    $version   = errores_texto($e['version'] ?? '', 50);
    $ip        = errores_texto($e['ip'] ?? '', 45);
    if ($mensaje === '') $mensaje = '(sin mensaje)';

    // La huella agrupa el mismo error aunque cambie el usuario o la línea del stack
    $primeraLinea = strtok($detalle, "\n") ?: '';
    $huella = sha1($origen . '|' . $mensaje . '|' . $ubicacion . '|' . $primeraLinea);

    $existe = $conn->prepare('SELECT 1 FROM `errores_log` WHERE huella = ?');
    $existe->bind_param('s', $huella);
    $existe->execute();
    $esNuevo = $existe->get_result()->num_rows === 0;
    $existe->close();

    if ($esNuevo && $ip !== '') {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM `errores_log`
                                WHERE ip = ? AND primera_vez > NOW() - INTERVAL ' . ERRORES_VENTANA_MIN . ' MINUTE');
        $stmt->bind_param('s', $ip);
        $stmt->execute();
        $recientes = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        if ($recientes >= ERRORES_MAX_POR_IP) return false;
    }

    $stmt = $conn->prepare('INSERT INTO `errores_log`
            (huella, origen, mensaje, detalle, ubicacion, usuario_id, navegador, version, ip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE veces = veces + 1, ultima_vez = NOW(),
            usuario_id = VALUES(usuario_id), navegador = VALUES(navegador),
            version = VALUES(version), ip = VALUES(ip)');
    $stmt->bind_param('sssssssss', $huella, $origen, $mensaje, $detalle, $ubicacion, $usuario, $navegador, $version, $ip);
    $stmt->execute();
    $stmt->close();

    if ($esNuevo && random_int(1, 50) === 1) {
        db_errores_purgar($conn);
    }
    return true;
}

/** Borra los errores antiguos y, si aun así sobran, los menos recientes. */
function db_errores_purgar(mysqli $conn): void {
    $conn->query('DELETE FROM `errores_log` WHERE ultima_vez < NOW() - INTERVAL ' . ERRORES_DIAS_RETENCION . ' DAY');
    $total = (int)$conn->query('SELECT COUNT(*) FROM `errores_log`')->fetch_row()[0];
    if ($total > ERRORES_MAX_FILAS) {
        $sobran = $total - ERRORES_MAX_FILAS;
        $conn->query("DELETE FROM `errores_log` ORDER BY ultima_vez ASC LIMIT $sobran");
    }
}

/**
 * Registra una excepción capturada en la API. Nunca lanza.
 * @param array $contexto ubicacion (acción), usuario_id, ip
 */
function db_registrar_excepcion(mysqli $conn, Throwable $e, array $contexto = []): void {
    try {
        $archivo = str_replace('\\', '/', $e->getFile());
        $raiz = str_replace('\\', '/', APP_ROOT) . '/';
        $archivo = str_starts_with($archivo, $raiz) ? substr($archivo, strlen($raiz)) : basename($archivo);
        db_registrar_error($conn, [
            'origen'     => 'php',
            'mensaje'    => get_class($e) . ': ' . $e->getMessage(),
            'detalle'    => "$archivo:{$e->getLine()}\n" . str_replace(str_replace('/', DIRECTORY_SEPARATOR, $raiz), '', $e->getTraceAsString()),
            'ubicacion'  => $contexto['ubicacion'] ?? '',
            'usuario_id' => $contexto['usuario_id'] ?? '',
            'ip'         => '',   // los errores del servidor no cuentan para el límite por IP
        ]);
    } catch (Throwable $ignorado) {
        error_log('[errores] no se pudo registrar: ' . $ignorado->getMessage());
    }
}

/**
 * Errores para el panel, del más reciente al más antiguo.
 * @return array{errores: array, total: int, ultimas24h: int}
 */
function db_errores_recientes(mysqli $conn, int $limite = 50): array {
    $limite = max(1, min(200, $limite));
    $res = $conn->query("SELECT id, origen, mensaje, detalle, ubicacion, usuario_id, navegador, version, veces,
                                primera_vez, ultima_vez
                         FROM `errores_log` ORDER BY ultima_vez DESC, id DESC LIMIT $limite");
    $errores = [];
    while ($f = $res->fetch_assoc()) {
        $f['id'] = (int)$f['id'];
        $f['veces'] = (int)$f['veces'];
        $errores[] = $f;
    }
    return [
        'errores'    => $errores,
        'total'      => (int)$conn->query('SELECT COUNT(*) FROM `errores_log`')->fetch_row()[0],
        'ultimas24h' => db_errores_ultimas_24h($conn),
    ];
}

function db_errores_ultimas_24h(mysqli $conn): int {
    return (int)$conn->query('SELECT COUNT(*) FROM `errores_log` WHERE ultima_vez > NOW() - INTERVAL 1 DAY')->fetch_row()[0];
}

/** Marca como revisados (borra) los errores indicados, o todos si $ids es null. */
function db_errores_limpiar(mysqli $conn, ?array $ids = null): int {
    if ($ids === null) {
        $conn->query('DELETE FROM `errores_log`');
        return $conn->affected_rows;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return 0;
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("DELETE FROM `errores_log` WHERE id IN ($marcas)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    return $stmt->affected_rows;
}
