<?php
/**
 * lib/migraciones.php — Migraciones del esquema de base de datos
 *
 * Cada cambio de estructura es un archivo numerado en migraciones/ que se aplica una sola vez,
 * en orden, y queda registrado en la tabla `schema_migraciones`:
 *   - NNNN_descripcion.sql  sentencias SQL; cada una termina en ';' al final de la línea.
 *   - NNNN_descripcion.php  devuelve function (mysqli $conn): void, para cambios condicionales.
 * Si la cabecera del archivo incluye la marca "@destructiva" (borra tablas, columnas o datos),
 * antes de aplicarla se genera un respaldo completo; si el respaldo falla, no se aplica.
 *
 * api.php llama a db_migrar_si_hace_falta() en cada petición: tras un "Update from Remote"
 * la primera petición aplica lo pendiente. Uso manual: php tools/migrar.php
 */

const MIGRACIONES_DIR = APP_ROOT . '/migraciones';
const MIGRACIONES_LOCK = 'unialuminio_migraciones';

/**
 * Lista las migraciones de la carpeta, ordenadas por número.
 * @return array<int, array{version:int, nombre:string, ruta:string, destructiva:bool}>
 */
function migraciones_disponibles(string $dir = MIGRACIONES_DIR): array {
    $lista = [];
    foreach (glob($dir . '/*.{sql,php}', GLOB_BRACE) ?: [] as $ruta) {
        if (!preg_match('/^(\d{4})_[\w-]+\.(sql|php)$/', basename($ruta), $m)) continue;
        $version = (int)$m[1];
        if (isset($lista[$version])) {
            throw new RuntimeException("Hay dos migraciones con el número {$m[1]}");
        }
        $cabecera = (string)file_get_contents($ruta, false, null, 0, 2048);
        $lista[$version] = [
            'version'     => $version,
            'nombre'      => basename($ruta),
            'ruta'        => $ruta,
            'destructiva' => strpos($cabecera, '@destructiva') !== false,
        ];
    }
    ksort($lista);
    return $lista;
}

function migraciones_asegurar_tabla(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS `schema_migraciones` (
        `version`     INT          NOT NULL,
        `nombre`      VARCHAR(255) NOT NULL,
        `aplicada_en` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `duracion_ms` INT          NOT NULL DEFAULT 0,
        PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** @return array<int, string> versión => nombre de las migraciones ya aplicadas */
function migraciones_aplicadas(mysqli $conn): array {
    migraciones_asegurar_tabla($conn);
    $aplicadas = [];
    $res = $conn->query('SELECT `version`, `nombre` FROM `schema_migraciones`');
    while ($fila = $res->fetch_assoc()) {
        $aplicadas[(int)$fila['version']] = $fila['nombre'];
    }
    return $aplicadas;
}

/** Ejecuta un archivo .sql sentencia por sentencia. */
function migraciones_ejecutar_sql(mysqli $conn, string $ruta): void {
    $sql = (string)file_get_contents($ruta);
    // Quitar comentarios de línea completa y separar por ';' al final de línea
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
        $sentencia = trim($sentencia);
        if ($sentencia === '') continue;
        if (!$conn->query($sentencia)) {
            throw new RuntimeException($conn->error . ' — SQL: ' . substr(preg_replace('/\s+/', ' ', $sentencia), 0, 120));
        }
    }
}

/**
 * Aplica en orden las migraciones pendientes. Seguro ante peticiones simultáneas (GET_LOCK).
 * Se detiene en la primera que falle: esa y las siguientes quedan pendientes para el próximo intento.
 *
 * @param callable|null $respaldar Genera el respaldo previo a una migración destructiva
 *                                 (por defecto ejecutarRespaldo() de tools/cron_backup.php).
 * @return string[] Nombres de las migraciones aplicadas en esta llamada.
 */
function db_migrar(mysqli $conn, string $dir = MIGRACIONES_DIR, ?callable $respaldar = null): array {
    $disponibles = migraciones_disponibles($dir);

    $lock = $conn->query("SELECT GET_LOCK('" . MIGRACIONES_LOCK . "', 60)")->fetch_row()[0] ?? 0;
    if ((int)$lock !== 1) {
        throw new RuntimeException('Otra petición está aplicando las migraciones; intenta de nuevo en unos segundos.');
    }

    $aplicadasAhora = [];
    try {
        // Releer dentro del bloqueo: otra petición pudo aplicarlas mientras esperábamos
        $aplicadas = migraciones_aplicadas($conn);
        $pendientes = array_diff_key($disponibles, $aplicadas);
        $respaldoHecho = false;

        foreach ($pendientes as $m) {
            if ($m['destructiva'] && !$respaldoHecho) {
                if ($respaldar === null) {
                    require_once APP_ROOT . '/tools/cron_backup.php';
                    $respaldar = fn(mysqli $c) => ejecutarRespaldo($c);
                }
                // Si el respaldo falla, la excepción detiene la migración antes de tocar nada
                $respaldar($conn);
                $respaldoHecho = true;
            }

            $inicio = microtime(true);
            try {
                if (str_ends_with($m['ruta'], '.php')) {
                    $migracion = require $m['ruta'];
                    if (!is_callable($migracion)) {
                        throw new RuntimeException('el archivo debe devolver function (mysqli $conn): void');
                    }
                    $migracion($conn);
                } else {
                    migraciones_ejecutar_sql($conn, $m['ruta']);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Migración {$m['nombre']} falló: " . $e->getMessage(), 0, $e);
            }

            $ms = (int)round((microtime(true) - $inicio) * 1000);
            $stmt = $conn->prepare('INSERT INTO `schema_migraciones` (`version`, `nombre`, `duracion_ms`) VALUES (?, ?, ?)');
            $stmt->bind_param('isi', $m['version'], $m['nombre'], $ms);
            $stmt->execute();
            $stmt->close();
            $aplicadasAhora[] = $m['nombre'];
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('" . MIGRACIONES_LOCK . "')");
    }
    return $aplicadasAhora;
}

/**
 * Versión más alta disponible y aplicada, y lista de pendientes (para el diagnóstico).
 * @return array{version:int, disponible:int, pendientes:string[]}
 */
function db_estado_migraciones(mysqli $conn, string $dir = MIGRACIONES_DIR): array {
    $disponibles = migraciones_disponibles($dir);
    $aplicadas = migraciones_aplicadas($conn);
    return [
        'version'    => $aplicadas ? max(array_keys($aplicadas)) : 0,
        'disponible' => $disponibles ? max(array_keys($disponibles)) : 0,
        'pendientes' => array_values(array_column(array_diff_key($disponibles, $aplicadas), 'nombre')),
    ];
}

/**
 * Comprobación barata para cada petición: solo consulta la base cuando cambian los archivos
 * de migraciones desde la última comprobación de esta sesión.
 */
function db_migrar_si_hace_falta(mysqli $conn): void {
    $firma = implode(',', array_column(migraciones_disponibles(), 'nombre'));
    if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['_esquema'] ?? null) === $firma) {
        return;
    }
    $aplicadas = db_migrar($conn);
    if ($aplicadas) {
        error_log('[migraciones] aplicadas: ' . implode(', ', $aplicadas));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_esquema'] = $firma;
    }
}
