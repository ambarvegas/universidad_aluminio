<?php
/**
 * lib/certificados.php — Certificados
 * Códigos de certificado y verificación pública.
 * Se carga desde db_mysql.php.
 */

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
