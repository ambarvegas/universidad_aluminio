<?php
/**
 * verificar.php — Portal de Verificación Pública de Certificados y Diplomas
 * Universidad del Aluminio
 *
 * Permite a cualquier supervisor, cliente, empleador o entidad verificar
 * la autenticidad y validez de un certificado o diploma oficial escaneando
 * su código QR o introduciendo el código de registro.
 */

require_once __DIR__ . '/db_mysql.php';

$codigo = trim($_GET['codigo'] ?? $_POST['codigo'] ?? '');
$format = trim($_GET['format'] ?? '');

$resultado = null;
$buscado   = false;
$errorDb   = null;

if ($codigo !== '') {
    $buscado = true;
    try {
        $conn = db_connect();
        $resultado = db_verificar_certificado($conn, $codigo);
        $conn->close();
    } catch (Throwable $e) {
        $errorDb = $e->getMessage();
    }
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if ($resultado) {
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            'valido'  => false,
            'codigo'  => $codigo,
            'mensaje' => 'Certificado no encontrado o inválido.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Certificados Oficiales — Universidad del Aluminio</title>
    <link rel="icon" type="image/png" href="icon-192.png">
    
    <!-- Fuentes e Iconos -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css">

    <style>
        body {
            background: linear-gradient(135deg, #091a2c 0%, #0f2b48 50%, #173b61 100%);
            min-height: 100vh;
            color: #f8fafc;
            display: flex;
            flex-direction: column;
        }
        .verification-container {
            max-width: 780px;
            margin: 40px auto;
            padding: 0 15px;
            flex: 1;
        }
        .verification-card {
            background: #ffffff;
            color: #0f172a;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        .verification-header {
            background: linear-gradient(135deg, #0f2b48 0%, #1e3a8a 100%);
            padding: 28px 32px;
            color: #ffffff;
            text-align: center;
            position: relative;
        }
        .verification-header::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #d4af37 0%, #f59e0b 50%, #d4af37 100%);
        }
        .badge-status-valid {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            border: 1.5px solid #10b981;
            padding: 8px 18px;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
        }
        .badge-status-invalid {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border: 1.5px solid #ef4444;
            padding: 8px 18px;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 0.95rem;
        }
        .cert-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }
        .cert-field {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
        }
        .cert-field-label {
            font-size: 0.775rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }
        .cert-field-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f2b48;
        }
        .seal-watermark {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #d4af37 0%, #b8860b 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 2.2rem;
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.35);
            margin: 0 auto 16px;
        }
        .search-box-wrap {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .code-pill {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            background: #e2e8f0;
            color: #0f2b48;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <nav class="navbar navbar-dark py-3" style="background: rgba(9, 26, 44, 0.85); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,0.08);">
        <div class="container d-flex justify-content-between align-items-center">
            <a class="navbar-brand d-flex align-items-center gap-2 m-0" href="index.html">
                <i class="bi bi-shield-check text-warning fs-4"></i>
                <span class="fw-bold">Universidad del Aluminio</span>
                <span class="badge bg-white bg-opacity-10 text-white-50 ms-2 d-none d-sm-inline">Padrón de Verificación</span>
            </a>
            <a href="index.html" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Ir al Campus
            </a>
        </div>
    </nav>

    <!-- Contenedor Principal -->
    <div class="verification-container">

        <!-- Buscador manual -->
        <div class="search-box-wrap">
            <h5 class="fw-bold text-white mb-2"><i class="bi bi-search me-2"></i>Validación de Documentos Académicos</h5>
            <p class="text-white-50 small mb-3">Ingresa el código alfanumérico que aparece en el margen inferior o código QR del certificado para validar su autenticidad.</p>
            <form action="verificar.php" method="GET" class="row g-2 align-items-center">
                <div class="col-sm-9">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-0 text-muted"><i class="bi bi-qr-code"></i></span>
                        <input type="text" name="codigo" class="form-control form-control-lg border-0" 
                               placeholder="Ej: ALU-CUR-B5E275112A o ALU-CAR-..." 
                               value="<?= htmlspecialchars($codigo) ?>" required autocomplete="off" style="text-transform: uppercase;">
                    </div>
                </div>
                <div class="col-sm-3">
                    <button type="submit" class="btn btn-warning btn-lg w-100 fw-bold">
                        <i class="bi bi-check-circle-fill me-1"></i>Verificar
                    </button>
                </div>
            </form>
        </div>

        <?php if ($buscado): ?>
            <?php if ($resultado && !empty($resultado['valido'])): ?>
                <!-- TARJETA: CERTIFICADO VÁLIDO -->
                <div class="verification-card">
                    <div class="verification-header">
                        <div class="seal-watermark">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                        <div class="badge-status-valid mb-2">
                            <i class="bi bi-check-circle-fill"></i> DOCUMENTO OFICIAL AUTÉNTICO
                        </div>
                        <h3 class="fw-bold mb-1 text-white"><?= htmlspecialchars($resultado['tipo_label']) ?></h3>
                        <p class="text-white-50 small mb-0">Emitido y respaldado institucionalmente por la Universidad del Aluminio</p>
                    </div>

                    <div class="p-4 p-md-5">
                        <div class="alert alert-success border-0 bg-success bg-opacity-10 d-flex align-items-center gap-3 mb-4 p-3 rounded-3">
                            <i class="bi bi-shield-fill-check fs-2 text-success"></i>
                            <div>
                                <div class="fw-bold text-success">Certificación Vigente y Registrada en el Padrón Oficial</div>
                                <small class="text-muted">Los registros académicos de la Universidad del Aluminio confirman que el titular completó y aprobó satisfactoriamente las competencias evaluadas.</small>
                            </div>
                        </div>

                        <div class="cert-info-grid">
                            <div class="cert-field">
                                <div class="cert-field-label"><i class="bi bi-person-fill me-1"></i>Colaborador / Egresado</div>
                                <div class="cert-field-value"><?= htmlspecialchars($resultado['usuario_nombre']) ?></div>
                            </div>

                            <div class="cert-field">
                                <div class="cert-field-label"><i class="bi bi-card-heading me-1"></i>Documento de Identidad</div>
                                <div class="cert-field-value"><?= htmlspecialchars($resultado['usuario_id']) ?></div>
                            </div>

                            <div class="cert-field" style="grid-column: 1 / -1;">
                                <div class="cert-field-label"><i class="bi bi-award-fill me-1"></i>Programa Acreditado</div>
                                <div class="cert-field-value text-primary fs-5"><?= htmlspecialchars($resultado['programa_nombre']) ?></div>
                                <?php if (!empty($resultado['programa_desc'])): ?>
                                    <small class="text-muted d-block mt-1"><?= htmlspecialchars(substr($resultado['programa_desc'], 0, 150)) . (strlen($resultado['programa_desc']) > 150 ? '...' : '') ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="cert-field">
                                <div class="cert-field-label"><i class="bi bi-calendar-check me-1"></i>Fecha de Certificación</div>
                                <div class="cert-field-value"><?= htmlspecialchars($resultado['fecha_emision']) ?></div>
                            </div>

                            <div class="cert-field">
                                <div class="cert-field-label"><i class="bi bi-hash me-1"></i>Código Único de Registro</div>
                                <div class="cert-field-value">
                                    <span class="code-pill"><?= htmlspecialchars($resultado['codigo']) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-top text-center">
                            <small class="text-muted d-block mb-3">
                                <i class="bi bi-info-circle me-1"></i>Para soporte o consultas adicionales de auditoría técnica, contactar a la Rectoría de la Universidad del Aluminio.
                            </small>
                            <a href="index.html" class="btn btn-primary px-4 py-2">
                                <i class="bi bi-arrow-left me-1"></i>Volver al Campus
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- TARJETA: CERTIFICADO NO ENCONTRADO -->
                <div class="verification-card text-center p-5">
                    <div class="badge-status-invalid mb-3">
                        <i class="bi bi-exclamation-triangle-fill"></i> NO ENCONTRADO O INVÁLIDO
                    </div>
                    <h3 class="fw-bold text-danger mb-2">Código de Verificación No Registrado</h3>
                    <p class="text-muted max-w-500 mx-auto mb-4">
                        El código <strong class="code-pill"><?= htmlspecialchars($codigo) ?></strong> no coincide con ningún certificado, diploma o credencial oficial expedido por la Universidad del Aluminio, o ha sido revocado.
                    </p>

                    <div class="alert alert-warning border-0 bg-warning bg-opacity-10 d-inline-block text-start p-3 rounded-3 max-w-500 mb-4">
                        <div class="fw-bold text-dark mb-1"><i class="bi bi-lightbulb-fill text-warning me-2"></i>Recomendaciones:</div>
                        <ul class="mb-0 text-muted small ps-3">
                            <li>Verifica que el código haya sido escrito exactamente como figura en el documento (incluyendo mayúsculas y guiones).</li>
                            <li>Si escaneaste un código QR deteriorado, prueba escribir los caracteres manualmente.</li>
                            <li>Comunícate con el administrador o colaborador para solicitar una copia actualizada.</li>
                        </ul>
                    </div>

                    <div>
                        <a href="verificar.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-repeat me-1"></i>Probar con otro código
                        </a>
                        <a href="index.html" class="btn btn-primary">
                            <i class="bi bi-house-door-fill me-1"></i>Ir al Campus Virtual
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- PANTALLA INICIAL: INSTRUCCIONES -->
            <div class="verification-card p-5 text-center">
                <div class="seal-watermark mb-3" style="width: 70px; height: 70px; font-size: 1.8rem;">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h3 class="fw-bold text-primary mb-2">Sistema de Autenticación Académica</h3>
                <p class="text-muted mb-4 mx-auto" style="max-width: 540px;">
                    Todos los certificados y diplomas emitidos por la <strong>Universidad del Aluminio</strong> cuentan con firma digital, código alfanumérico único y código QR inviolable para garantizar la competencia técnica de nuestros egresados.
                </p>
                <div class="row g-3 text-start mt-2">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light border h-100">
                            <div class="text-primary fs-3 mb-2"><i class="bi bi-qr-code-scan"></i></div>
                            <h6 class="fw-bold mb-1">Escaneo QR</h6>
                            <small class="text-muted">Apunta la cámara de tu teléfono al código QR del diploma para validarlo directamente.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light border h-100">
                            <div class="text-primary fs-3 mb-2"><i class="bi bi-hash"></i></div>
                            <h6 class="fw-bold mb-1">Código Único</h6>
                            <small class="text-muted">Introduce el código alfanumérico en el buscador superior para verificar el registro en el padrón.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light border h-100">
                            <div class="text-primary fs-3 mb-2"><i class="bi bi-patch-check-fill text-success"></i></div>
                            <h6 class="fw-bold mb-1">100% Oficial</h6>
                            <small class="text-muted">Comprueba el nombre del alumno, curso o carrera técnica aprobada y fecha de expedición.</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Footer -->
    <footer class="py-3 text-center text-white-50 small border-top" style="border-color: rgba(255,255,255,0.08) !important; background: rgba(9, 26, 44, 0.95);">
        © 2026 Universidad del Aluminio • Padrón de Certificaciones Oficiales • Todos los derechos reservados.
    </footer>

</body>
</html>
