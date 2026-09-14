<?php
/**
 * mailer.php — Universidad del Aluminio
 * Módulo centralizado de envío de correos electrónicos y notificaciones institucionales.
 *
 * Características:
 *  - Soporte para SMTP autenticado (SSL/TLS/STARTTLS) vía sockets nativos (sin dependencias externas).
 *  - Fallback automático a mail() de PHP si SMTP no está configurado.
 *  - Plantillas HTML institucionales responsivas con paleta oficial (#0f2b48 y #0284c7).
 *  - Manejo de cola segura y registro de eventos de notificación.
 */

// ============================================================
// 1. CLIENTE SMTP LIGERO BASADO EN SOCKETS
// ============================================================

class SmtpMailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $secure; // 'tls', 'ssl', 'none'
    private string $fromEmail;
    private string $fromName;
    private int $timeout;

    public function __construct(array $config = []) {
        $this->host      = $config['host']      ?? 'localhost';
        $this->port      = (int)($config['port'] ?? 587);
        $this->user      = $config['user']      ?? '';
        $this->pass      = $config['pass']      ?? '';
        $this->secure    = strtolower($config['secure'] ?? 'tls');
        $this->fromEmail = $config['from_email'] ?? ($config['user'] ?: 'rectoria@universidaddelaluminio.com');
        $this->fromName  = $config['from_name']  ?? 'Universidad del Aluminio';
        $this->timeout   = (int)($config['timeout'] ?? 10);
    }

    public function send(string $toEmail, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool {
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Dirección de correo inválida: $toEmail");
        }

        // Si no hay host SMTP configurado o es localhost sin usuario, intentar fallback a mail() nativo
        if (empty($this->user) && ($this->host === 'localhost' || empty($this->host))) {
            return $this->sendNativeMail($toEmail, $subject, $htmlBody, $textBody, $attachments);
        }

        $prefix = ($this->secure === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$socket) {
            // Fallback a mail() si el socket no abre
            error_log("[SmtpMailer] Fallo de conexión socket ($errstr). Intentando mail() nativo.");
            return $this->sendNativeMail($toEmail, $subject, $htmlBody, $textBody, $attachments);
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->readExpectedResponse($socket, '220');
            $this->sendCommand($socket, "EHLO " . gethostname(), '250');

            if ($this->secure === 'tls') {
                $this->sendCommand($socket, "STARTTLS", '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException("Fallo al negociar cifrado TLS");
                }
                $this->sendCommand($socket, "EHLO " . gethostname(), '250');
            }

            if (!empty($this->user)) {
                $this->sendCommand($socket, "AUTH LOGIN", '334');
                $this->sendCommand($socket, base64_encode($this->user), '334');
                $this->sendCommand($socket, base64_encode($this->pass), '235');
            }

            $this->sendCommand($socket, "MAIL FROM: <{$this->fromEmail}>", '250');
            $this->sendCommand($socket, "RCPT TO: <{$toEmail}>", '250');
            $this->sendCommand($socket, "DATA", '354');

            $hasAttachments = !empty($attachments);
            $plain = $textBody ?: strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

            $headers  = [];
            $headers[] = "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>";
            $headers[] = "To: <{$toEmail}>";
            $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "X-Mailer: UniAluminio LMS Mailer v2.0";
            $headers[] = "Date: " . date('r');

            $boundaryAlt = "----=_Part_Alt_" . md5(uniqid((string)time(), true));

            if ($hasAttachments) {
                $boundaryMixed = "----=_Part_Mixed_" . md5(uniqid((string)time() . '_mix', true));
                $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"";

                $body  = implode("\r\n", $headers) . "\r\n\r\n";

                // Subparte alternativa (Plain + HTML)
                $body .= "--{$boundaryMixed}\r\n";
                $body .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

                $body .= "--{$boundaryAlt}\r\n";
                $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($plain)) . "\r\n";

                $body .= "--{$boundaryAlt}\r\n";
                $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

                $body .= "--{$boundaryAlt}--\r\n";

                // Adjuntos
                foreach ($attachments as $att) {
                    $attFilename = $att['filename'] ?? 'Certificado.pdf';
                    $attMime     = $att['mime'] ?? 'application/pdf';
                    $attContent  = $att['content'] ?? '';

                    $safeFilename = basename($attFilename);
                    $encodedFilename = "=?UTF-8?B?" . base64_encode($safeFilename) . "?=";

                    $body .= "--{$boundaryMixed}\r\n";
                    $body .= "Content-Type: {$attMime}; name=\"{$encodedFilename}\"\r\n";
                    $body .= "Content-Transfer-Encoding: base64\r\n";
                    $body .= "Content-Disposition: attachment; filename=\"{$encodedFilename}\"\r\n\r\n";
                    $body .= chunk_split(base64_encode($attContent)) . "\r\n";
                }

                $body .= "--{$boundaryMixed}--\r\n";
            } else {
                $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"";

                $body  = implode("\r\n", $headers) . "\r\n\r\n";
                $body .= "--{$boundaryAlt}\r\n";
                $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($plain)) . "\r\n";

                $body .= "--{$boundaryAlt}\r\n";
                $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

                $body .= "--{$boundaryAlt}--\r\n";
            }

            $body .= "\r\n.";

            $this->sendCommand($socket, $body, '250');
            $this->sendCommand($socket, "QUIT", '221');
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            if (is_resource($socket)) fclose($socket);
            error_log("[SmtpMailer] Error SMTP: " . $e->getMessage() . " — Ejecutando fallback mail()");
            return $this->sendNativeMail($toEmail, $subject, $htmlBody, $textBody, $attachments);
        }
    }

    private function sendNativeMail(string $toEmail, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool {
        $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        $plain = $textBody ?: strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

        if (empty($attachments)) {
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
            $headers .= "Reply-To: {$this->fromEmail}\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            return @mail($toEmail, $encodedSubject, $htmlBody, $headers);
        }

        $boundaryMixed = "----=_Part_Mixed_" . md5(uniqid((string)time() . '_mix_nat', true));
        $boundaryAlt   = "----=_Part_Alt_" . md5(uniqid((string)time() . '_alt_nat', true));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $body  = "--{$boundaryMixed}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plain)) . "\r\n";

        $body .= "--{$boundaryAlt}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $body .= "--{$boundaryAlt}--\r\n";

        foreach ($attachments as $att) {
            $attFilename = $att['filename'] ?? 'Certificado.pdf';
            $attMime     = $att['mime'] ?? 'application/pdf';
            $attContent  = $att['content'] ?? '';

            $safeFilename = basename($attFilename);
            $encodedFilename = "=?UTF-8?B?" . base64_encode($safeFilename) . "?=";

            $body .= "--{$boundaryMixed}\r\n";
            $body .= "Content-Type: {$attMime}; name=\"{$encodedFilename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$encodedFilename}\"\r\n\r\n";
            $body .= chunk_split(base64_encode($attContent)) . "\r\n";
        }

        $body .= "--{$boundaryMixed}--\r\n";

        return @mail($toEmail, $encodedSubject, $body, $headers);
    }

    private function sendCommand($socket, string $cmd, string $expectedCode): string {
        fwrite($socket, $cmd . "\r\n");
        return $this->readExpectedResponse($socket, $expectedCode);
    }

    private function readExpectedResponse($socket, string $expectedCode): string {
        $response = "";
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Respuesta SMTP inesperada: [$code] " . trim($response));
        }
        return $response;
    }
}

// ============================================================
// 2. GENERADOR DE PLANTILLAS INSTITUCIONALES HTML
// ============================================================

function renderHtmlEmailTemplate(string $titulo, string $contenidoHtml, string $botonTexto = '', string $botonUrl = ''): string {
    $botonHtml = '';
    if ($botonTexto && $botonUrl) {
        $botonHtml = "
        <div style=\"margin: 28px 0; text-align: center;\">
            <a href=\"{$botonUrl}\" style=\"background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: 700; font-size: 15px; display: inline-block; box-shadow: 0 4px 12px rgba(2,132,199,0.3); letter-spacing: 0.3px;\">
                {$botonTexto} &rarr;
            </a>
        </div>";
    }

    $year = date('Y');

    return "<!DOCTYPE html>
<html lang=\"es\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$titulo}</title>
</head>
<body style=\"margin: 0; padding: 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; color: #0f172a;\">
    <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"background-color: #f1f5f9; padding: 30px 10px;\">
        <tr>
            <td align=\"center\">
                <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(15,43,72,0.08); border: 1px solid #e2e8f0;\">
                    <!-- Header Institucional -->
                    <tr>
                        <td style=\"background: linear-gradient(135deg, #0f2b48 0%, #1e3a8a 100%); padding: 32px 30px; text-align: center;\">
                            <div style=\"width: 48px; height: 48px; background: rgba(255,255,255,0.15); border-radius: 12px; margin: 0 auto 12px; line-height: 48px; font-size: 24px; color: #38bdf8; border: 1px solid rgba(255,255,255,0.25);\">
                                &#127891;
                            </div>
                            <h1 style=\"color: #ffffff; font-size: 22px; font-weight: 800; margin: 0; letter-spacing: -0.5px;\">Universidad del Aluminio</h1>
                            <p style=\"color: #94a3b8; font-size: 13px; margin: 4px 0 0; font-weight: 500;\">Plataforma Académica y Formación Técnica</p>
                        </td>
                    </tr>
                    <!-- Contenido Principal -->
                    <tr>
                        <td style=\"padding: 36px 32px; font-size: 15px; line-height: 1.65; color: #334155;\">
                            <h2 style=\"color: #0f2b48; font-size: 19px; font-weight: 700; margin-top: 0; margin-bottom: 16px;\">{$titulo}</h2>
                            {$contenidoHtml}
                            {$botonHtml}
                        </td>
                    </tr>
                    <!-- Footer Institucional -->
                    <tr>
                        <td style=\"background-color: #f8fafc; padding: 24px 30px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b;\">
                            <p style=\"margin: 0 0 6px;\">&copy; {$year} <strong>Universidad del Aluminio</strong> &bull; Rectoría Académica</p>
                            <p style=\"margin: 0; color: #94a3b8;\">Este es un mensaje automático del sistema LMS. Por favor, no respondas directamente a este correo.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
}

// ============================================================
// 3. HELPERS DE OBTENCIÓN DE CONFIGURACIÓN Y SERVICIO
// ============================================================

function obtenerMailerInstance(mysqli $conn): SmtpMailer {
    $cfg = [];
    $res = $conn->query("SELECT clave, valor FROM `configuracion` WHERE clave LIKE 'smtp_%' OR clave IN ('email_remitente', 'email_nombre', 'email_admin')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $k = $row['clave'];
            $v = $row['valor'];
            $cfg[$k] = $v;
        }
    }

    $smtpConfig = [
        'host'       => $cfg['smtp_host']       ?? (getenv('SMTP_HOST') ?: 'localhost'),
        'port'       => (int)($cfg['smtp_port'] ?? (getenv('SMTP_PORT') ?: 587)),
        'user'       => $cfg['smtp_user']       ?? (getenv('SMTP_USER') ?: ''),
        'pass'       => $cfg['smtp_pass']       ?? (getenv('SMTP_PASS') ?: ''),
        'secure'     => $cfg['smtp_secure']     ?? (getenv('SMTP_SECURE') ?: 'tls'),
        'from_email' => $cfg['email_remitente'] ?? ($cfg['smtp_user'] ?? 'no-reply@universidaddelaluminio.com'),
        'from_name'  => $cfg['email_nombre']    ?? 'Universidad del Aluminio',
    ];

    return new SmtpMailer($smtpConfig);
}

// ============================================================
// 4. DISPARADORES DE EVENTOS DE NOTIFICACIÓN
// ============================================================

/**
 * Notifica a un usuario que su cuenta ha sido aprobada.
 */
function notificarCuentaAprobada(mysqli $conn, string $email, string $nombre, string $id, string $clave = ''): bool {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $loginUrl = rtrim($baseUrl, '/') . '/login.php';

    $credencialesHtml = "<div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Cédula / Identificación:</strong> <code style=\"color: #0284c7; font-size: 15px; font-weight: bold;\">{$id}</code></p>";
    if ($clave) {
        $credencialesHtml .= "<p style=\"margin: 0;\"><strong>Contraseña temporal:</strong> <code style=\"color: #0f2b48; font-size: 15px;\">{$clave}</code></p>";
    }
    $credencialesHtml .= "</div>";

    $html = "<p>Estimado(a) <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
    <p>Nos complace informarte que tu solicitud de acceso a la <strong>Universidad del Aluminio</strong> ha sido <strong style=\"color: #10b981;\">aprobada satisfactoriamente</strong>.</p>
    <p>A partir de este momento puedes ingresar al Campus Virtual para comenzar tus programas formativos:</p>
    {$credencialesHtml}
    <p>Te recomendamos cambiar tu contraseña periódicamente desde el panel de perfil del alumno.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send($email, "¡Bienvenido a la Universidad del Aluminio! — Cuenta Aprobada", renderHtmlEmailTemplate("¡Bienvenido al Campus Virtual!", $html, "Ingresar al Campus", $loginUrl));
}

/**
 * Notifica al administrador que hay una nueva solicitud de registro.
 */
function notificarAdminNuevaSolicitud(mysqli $conn, string $tipo, string $nombreSolicitante, string $idSolicitante): bool {
    $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
    $adminEmail = ($res && $r = $res->fetch_assoc()) ? trim($r['valor'] ?? '') : '';
    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $adminUrl = rtrim($baseUrl, '/') . '/admin.php';

    $html = "<p>Hola Administrador,</p>
    <p>Se ha recibido una nueva solicitud de <strong>" . htmlspecialchars($tipo) . "</strong> en la plataforma:</p>
    <div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Colaborador:</strong> " . htmlspecialchars($nombreSolicitante) . "</p>
        <p style=\"margin: 0;\"><strong>Cédula:</strong> <code style=\"color: #0284c7; font-weight: bold;\">" . htmlspecialchars($idSolicitante) . "</code></p>
    </div>
    <p>Ingresa al panel de control para autorizar o denegar esta solicitud.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send($adminEmail, "Nueva Solicitud Pendiente: $nombreSolicitante", renderHtmlEmailTemplate("Nueva Solicitud de Acceso", $html, "Revisar en el Panel", $adminUrl));
}

/**
 * Notifica a un usuario que ha obtenido un certificado oficial, adjuntando el documento PDF oficial.
 */
function notificarCertificadoEmitido(mysqli $conn, string $email, string $nombre, string $programaTitulo, string $codigoVerificacion, string $cedula = '', string $itemId = '', string $tipo = 'curso'): bool {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    require_once __DIR__ . '/pdf_certificate.php';

    $fechaEmision = date('d/m/Y');
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'aluminiologo.oo.gd';
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/universidad');
    $dir = ($dir === '/' || $dir === '\\') ? '' : $dir;
    $baseUrl = $proto . '://' . $host . $dir;
    $verifyUrl = rtrim($baseUrl, '/') . '/verificar.php?codigo=' . urlencode($codigoVerificacion);

    $pdfBinary = generarPdfCertificadoBinario([
        'tipo'                => $tipo,
        'nombre'              => $nombre,
        'cedula'              => $cedula,
        'titulo_programa'     => $programaTitulo,
        'codigo_verificacion' => $codigoVerificacion,
        'url_verificacion'    => $verifyUrl,
        'fecha_emision'       => $fechaEmision,
        'institucion'         => 'Universidad del Aluminio'
    ]);

    $safeProg = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $programaTitulo);
    $safeNom  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nombre);
    $prefix   = ($tipo === 'carrera') ? 'Diploma_Carrera_' : 'Certificado_Curso_';
    $filename = $prefix . $safeProg . '_' . $safeNom . '.pdf';

    $attachments = [
        [
            'filename' => $filename,
            'content'  => $pdfBinary,
            'mime'     => 'application/pdf'
        ]
    ];

    $html = "<p>¡Felicitaciones, <strong>" . htmlspecialchars($nombre) . "</strong>!</p>
    <p>Has completado exitosamente todos los requerimientos académicos del programa:</p>
    <div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; margin: 18px 0; text-align: center;\">
        <h3 style=\"color: #166534; margin: 0 0 8px; font-size: 18px;\">" . htmlspecialchars($programaTitulo) . "</h3>
        <p style=\"margin: 0; color: #15803d; font-size: 14px;\">Código Oficial de Registro: <strong style=\"font-family: monospace; font-size: 16px; background: #dcfce7; padding: 2px 8px; border-radius: 4px;\">{$codigoVerificacion}</strong></p>
    </div>
    <div style=\"background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 18px; margin: 18px 0; text-align: center;\">
        <div style=\"font-size: 32px; margin-bottom: 6px;\">&#128196;</div>
        <strong style=\"color: #1e40af; font-size: 16px;\">Tu Certificado Oficial en Formato PDF</strong>
        <p style=\"margin: 8px 0 0; color: #3b82f6; font-size: 13.5px;\">Adjunto a este correo electrónico encontrarás tu documento oficial (<strong>{$filename}</strong>), emitido con plena validez académica por la Rectoría de la Universidad del Aluminio para su descarga, archivo o impresión.</p>
    </div>
    <p>Tu certificación ha sido asentada formalmente en el registro institucional de la plataforma educativa.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $email,
        "🎓 Tu Certificado Oficial en PDF: $programaTitulo",
        renderHtmlEmailTemplate("¡Certificado Oficial Emitido!", $html),
        '',
        $attachments
    );
}

/**
 * 1. Notifica al administrador que un colaborador ha aprobado un módulo.
 */
function notificarAdminModuloAprobado(mysqli $conn, string $userId, string $userName, string $cursoId, string $cursoTitulo, string $moduloTitulo, int $moduloIdx, int $calificacion, int $intento): bool {
    $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
    $adminEmail = ($res && $r = $res->fetch_assoc()) ? trim($r['valor'] ?? '') : '';
    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $adminUrl = rtrim($baseUrl, '/') . '/admin.php';

    $numMod = $moduloIdx + 1;
    $fecha = date('d/m/Y H:i');

    $html = "<p>Hola Administrador,</p>
    <p>El colaborador <strong>" . htmlspecialchars($userName) . "</strong> ha <strong style=\"color: #10b981;\">aprobado satisfactoriamente</strong> un módulo de evaluación:</p>
    <div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin: 18px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Colaborador:</strong> " . htmlspecialchars($userName) . " <span style=\"color: #64748b;\">(C.I: {$userId})</span></p>
        <p style=\"margin: 0 0 6px;\"><strong>Curso:</strong> " . htmlspecialchars($cursoTitulo) . "</p>
        <p style=\"margin: 0 0 6px;\"><strong>Módulo:</strong> Módulo {$numMod} — " . htmlspecialchars($moduloTitulo) . "</p>
        <p style=\"margin: 0 0 6px;\"><strong>Calificación Obtenida:</strong> <span style=\"font-size: 16px; font-weight: bold; color: #0284c7;\">{$calificacion}%</span></p>
        <p style=\"margin: 0 0 6px;\"><strong>Intento Realizado:</strong> Intento #{$intento}</p>
        <p style=\"margin: 0;\"><strong>Fecha y Hora:</strong> {$fecha}</p>
    </div>
    <p>El progreso del usuario ha sido actualizado en tiempo real en la plataforma académica.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $adminEmail,
        "✅ Módulo Aprobado: $userName — $cursoTitulo (Mód. $numMod)",
        renderHtmlEmailTemplate("Aprobación de Módulo Académico", $html, "Ver Panel de Reportes", $adminUrl)
    );
}

/**
 * 2. Notifica al administrador que un colaborador ha completado y certificado un curso, adjuntando la copia del PDF.
 */
function notificarAdminCursoCompletado(mysqli $conn, string $userId, string $userName, string $cursoId, string $cursoTitulo, string $codigoCertificado): bool {
    $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
    $adminEmail = ($res && $r = $res->fetch_assoc()) ? trim($r['valor'] ?? '') : '';
    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;

    require_once __DIR__ . '/pdf_certificate.php';

    $fecha = date('d/m/Y H:i');
    $fechaEmision = date('d/m/Y');
    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'aluminiologo.oo.gd';
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/universidad');
    $dir = ($dir === '/' || $dir === '\\') ? '' : $dir;
    $baseUrl = $proto . '://' . $host . $dir;
    $verifyUrl = rtrim($baseUrl, '/') . '/verificar.php?codigo=' . urlencode($codigoCertificado);

    $pdfBinary = generarPdfCertificadoBinario([
        'tipo'                => 'curso',
        'nombre'              => $userName,
        'cedula'              => $userId,
        'titulo_programa'     => $cursoTitulo,
        'codigo_verificacion' => $codigoCertificado,
        'url_verificacion'    => $verifyUrl,
        'fecha_emision'       => $fechaEmision,
        'institucion'         => 'Universidad del Aluminio'
    ]);

    $safeProg = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cursoTitulo);
    $safeNom  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $userName);
    $filename = 'Certificado_Curso_' . $safeProg . '_' . $safeNom . '.pdf';

    $attachments = [
        [
            'filename' => $filename,
            'content'  => $pdfBinary,
            'mime'     => 'application/pdf'
        ]
    ];

    $html = "<p>Hola Administrador,</p>
    <p>¡Buenas noticias! El colaborador <strong>" . htmlspecialchars($userName) . "</strong> ha completado el <strong>100% de los requisitos</strong> y obtenido la certificación oficial del curso:</p>
    <div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; margin: 18px 0; text-align: center;\">
        <h3 style=\"color: #166534; margin: 0 0 8px; font-size: 18px;\">" . htmlspecialchars($cursoTitulo) . "</h3>
        <p style=\"margin: 0 0 6px; color: #15803d;\">Colaborador: <strong>" . htmlspecialchars($userName) . "</strong> (C.I: {$userId})</p>
        <p style=\"margin: 0 0 6px; color: #15803d; font-size: 13px;\">Fecha de Certificación: {$fecha}</p>
        <p style=\"margin: 0; color: #15803d; font-size: 14px;\">Código de Registro: <strong style=\"font-family: monospace; font-size: 15px; background: #dcfce7; padding: 2px 8px; border-radius: 4px;\">{$codigoCertificado}</strong></p>
    </div>
    <div style=\"background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px; margin: 16px 0; text-align: center;\">
        <strong style=\"color: #1e40af; font-size: 14px;\">&#128196; Certificado Oficial Adjunto ({$filename})</strong>
        <p style=\"margin: 4px 0 0; color: #3b82f6; font-size: 12px;\">Se adjunta el archivo PDF oficial emitido para el expediente del colaborador.</p>
    </div>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $adminEmail,
        "🎉 Certificado Oficial Emitido: $userName culminó $cursoTitulo",
        renderHtmlEmailTemplate("¡Curso Completado y Certificado!", $html),
        '',
        $attachments
    );
}

/**
 * 3. Notifica al administrador que un colaborador ha completado todos los cursos asignados a su rol o carrera.
 */
function notificarAdminRolCompletado(mysqli $conn, string $userId, string $userName, string $rolNombre, array $cursosCompletados = []): bool {
    $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
    $adminEmail = ($res && $r = $res->fetch_assoc()) ? trim($r['valor'] ?? '') : '';
    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $adminUrl = rtrim($baseUrl, '/') . '/admin.php';

    $totalCursos = count($cursosCompletados);
    $fecha = date('d/m/Y H:i');

    $html = "<p>Hola Administrador,</p>
    <p>¡Gran logro institucional! El colaborador <strong>" . htmlspecialchars($userName) . "</strong> ha completado satisfactoriamente <strong>todos los cursos correspondientes a su plan de formación / rol</strong>:</p>
    <div style=\"background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; margin: 18px 0; text-align: center;\">
        <div style=\"font-size: 32px; margin-bottom: 6px;\">🏆</div>
        <h3 style=\"color: #1e40af; margin: 0 0 6px; font-size: 19px;\">Malla Curricular / Cargo: " . htmlspecialchars($rolNombre) . "</h3>
        <p style=\"margin: 0 0 6px; color: #1d4ed8;\">Colaborador: <strong>" . htmlspecialchars($userName) . "</strong> (C.I: {$userId})</p>
        <p style=\"margin: 0; color: #3b82f6; font-size: 14px;\">Total de Cursos Certificados en el Plan: <strong>{$totalCursos}</strong> &bull; Fecha: {$fecha}</p>
    </div>
    <p>El colaborador ha cumplido con el perfil técnico y formativo exigido para su cargo.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $adminEmail,
        "🏆 Plan de Formación Culminado: $userName completó su Malla ($rolNombre)",
        renderHtmlEmailTemplate("¡Plan de Formación del Rol Completado!", $html, "Revisar Historial Académico", $adminUrl)
    );
}

/**
 * 4. Notifica al administrador que un colaborador ha superado el límite de intentos en una evaluación.
 */
function notificarAdminIntentosAgotados(mysqli $conn, string $userId, string $userName, string $cursoId, string $cursoTitulo, string $moduloTitulo, int $moduloIdx, int $intentosUsados, int $maxIntentos, int $ultimaCalificacion): bool {
    $res = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'email_admin'");
    $adminEmail = ($res && $r = $res->fetch_assoc()) ? trim($r['valor'] ?? '') : '';
    if (!$adminEmail || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) return false;

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $adminUrl = rtrim($baseUrl, '/') . '/admin.php';

    $numMod = $moduloIdx + 1;
    $fecha = date('d/m/Y H:i');

    $html = "<p>Hola Administrador,</p>
    <p>Se te notifica que el colaborador <strong>" . htmlspecialchars($userName) . "</strong> ha <strong style=\"color: #dc2626;\">agotado el límite de intentos permitidos</strong> en una evaluación y ha quedado bloqueado:</p>
    <div style=\"background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 18px; margin: 18px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Colaborador:</strong> " . htmlspecialchars($userName) . " <span style=\"color: #64748b;\">(C.I: {$userId})</span></p>
        <p style=\"margin: 0 0 6px;\"><strong>Curso:</strong> " . htmlspecialchars($cursoTitulo) . "</p>
        <p style=\"margin: 0 0 6px;\"><strong>Módulo Afectado:</strong> Módulo {$numMod} — " . htmlspecialchars($moduloTitulo) . "</p>
        <p style=\"margin: 0 0 6px;\"><strong>Intentos Utilizados:</strong> <span style=\"color: #dc2626; font-weight: bold;\">{$intentosUsados} de {$maxIntentos} permitidos</span></p>
        <p style=\"margin: 0 0 6px;\"><strong>Última Calificación:</strong> {$ultimaCalificacion}%</p>
        <p style=\"margin: 0;\"><strong>Fecha y Hora:</strong> {$fecha}</p>
    </div>
    <p style=\"color: #991b1b; font-size: 14px;\"><strong>Acción requerida:</strong> El estudiante no podrá volver a presentar la evaluación hasta que un administrador o tutor revise su caso y restablezca sus intentos desde el panel de gestión de colaboradores.</p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $adminEmail,
        "⚠️ Límite de Intentos Agotado: $userName en $cursoTitulo (Mód. $numMod)",
        renderHtmlEmailTemplate("Evaluación Bloqueada por Intentos", $html, "Gestionar Colaboradores en el Panel", $adminUrl)
    );
}

/**
 * 5. Notifica al usuario con un enlace seguro para restablecer su contraseña.
 */
function notificarRecuperacionClave(mysqli $conn, string $email, string $nombre, string $userId, string $linkRecuperacion): bool {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $html = "<p>Estimado(a) <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
    <p>Hemos recibido una solicitud para <strong style=\"color: #0284c7;\">restablecer la contraseña</strong> de tu cuenta en el Campus Virtual de la <strong>Universidad del Aluminio</strong>.</p>
    <div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Identificación / Cédula:</strong> <code style=\"color: #0f2b48; font-size: 15px; font-weight: bold;\">{$userId}</code></p>
        <p style=\"margin: 0;\"><strong>Correo registrado:</strong> {$email}</p>
    </div>
    <p>Para definir una nueva contraseña de acceso seguro, haz clic en el siguiente botón:</p>";

    $html .= "<div style=\"background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px; margin: 20px 0; font-size: 13px; color: #1e40af;\">
        <strong>Nota de seguridad:</strong> Este enlace es de un solo uso y expirará en <strong>2 horas</strong>. Si tú no solicitaste este cambio, puedes ignorar este mensaje de forma segura; tu contraseña actual continuará protegida.
    </div>";

    $html .= "<p style=\"font-size: 12px; color: #64748b; word-break: break-all;\">Si el botón no funciona, copia y pega este enlace en tu navegador:<br><a href=\"{$linkRecuperacion}\" style=\"color: #0284c7;\">{$linkRecuperacion}</a></p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $email,
        "🔐 Restablecimiento de Contraseña — Universidad del Aluminio",
        renderHtmlEmailTemplate("Restablecimiento de Contraseña", $html, "Restablecer mi Contraseña", $linkRecuperacion)
    );
}

/**
 * 6. Notifica al usuario una invitación o enlace de acceso directo al Campus Virtual.
 */
function notificarInvitacionAcceso(mysqli $conn, string $email, string $nombre, string $userId, string $linkAcceso, string $tipo = 'invitacion'): bool {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $asunto = ($tipo === 'invitacion') 
        ? "🎟️ Invitación de Acceso al Campus Virtual — Universidad del Aluminio"
        : "🔑 Enlace de Acceso / Restablecimiento de Clave — Universidad del Aluminio";

    $tituloEncabezado = ($tipo === 'invitacion') ? "¡Bienvenido(a) a la Universidad del Aluminio!" : "Enlace de Acceso y Gestión de Clave";
    $botonTexto = ($tipo === 'invitacion') ? "Activar Mi Cuenta y Definir Clave" : "Acceder y Definir Contraseña";

    $html = "<p>Estimado(a) <strong>" . htmlspecialchars($nombre) . "</strong>,</p>";
    if ($tipo === 'invitacion') {
        $html .= "<p>La administración académica de la <strong>Universidad del Aluminio</strong> te ha otorgado acceso a la plataforma de formación técnica.</p>";
    } else {
        $html .= "<p>Se ha generado un enlace de acceso directo y restablecimiento de credenciales para tu cuenta en la <strong>Universidad del Aluminio</strong>.</p>";
    }

    $html .= "<div style=\"background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 16px 0;\">
        <p style=\"margin: 0 0 6px;\"><strong>Identificación / Cédula:</strong> <code style=\"color: #0284c7; font-size: 15px; font-weight: bold;\">{$userId}</code></p>
        <p style=\"margin: 0;\"><strong>Correo electrónico:</strong> {$email}</p>
    </div>
    <p>Para acceder y configurar tu contraseña personalizada, haz clic en el siguiente botón:</p>";

    $html .= "<div style=\"background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; margin: 20px 0; font-size: 13px; color: #166534;\">
        <strong>Acceso Seguro:</strong> Este enlace te permitirá ingresar directamente al Campus Virtual y definir tu clave de forma rápida y confiable.
    </div>";

    $html .= "<p style=\"font-size: 12px; color: #64748b; word-break: break-all;\">Si el botón no funciona, copia y pega este enlace en tu navegador web:<br><a href=\"{$linkAcceso}\" style=\"color: #0284c7;\">{$linkAcceso}</a></p>";

    $mailer = obtenerMailerInstance($conn);
    return $mailer->send(
        $email,
        $asunto,
        renderHtmlEmailTemplate($tituloEncabezado, $html, $botonTexto, $linkAcceso)
    );
}


