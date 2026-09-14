<?php
/**
 * pdf_certificate.php — Universidad del Aluminio
 * Generador nativo y autónomo de Certificados Oficiales y Diplomas en PDF 1.4.
 *
 * Características:
 *  - 100% PHP nativo sin dependencias externas ni librerías pesadas.
 *  - Formato estándar A4 Horizontal (297 x 210 mm / 841.89 x 595.28 pt).
 *  - Doble orla institucional perimetral en Azul Noche (#0f2b48) y Oro Imperial (#d4af37).
 *  - Tipografía estándar Type-1 (Helvetica, Helvetica-Bold, Courier-Bold) con soporte de acentos en español (WinAnsi).
 *  - Cálculo preciso de anchos de caracteres para centrado perfecto de nombres y títulos.
 *  - Sello institucional de Rectoría Académica con filetes dorados y bloque de validación.
 *  - Código de verificación criptográfico oficial compatible con verificar.php.
 */

class UniAluminioPdfEngine {
    private array $objects = [];
    private string $content = '';
    private float $pageWidth = 841.89;  // 297 mm en puntos
    private float $pageHeight = 595.28; // 210 mm en puntos
    private float $scale = 2.834645669; // 1 mm = 2.83465 pt

    // Tabla de anchos relativos de caracteres para Helvetica / Helvetica-Bold (por 1000 unidades)
    private static array $fontWidthsHelv = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, '\'' => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
        // Caracteres especiales y acentuados (WinAnsi)
        "\xE1" => 556, "\xE9" => 556, "\xED" => 222, "\xF3" => 556, "\xFA" => 556, "\xF1" => 556, // á,é,í,ó,ú,ñ
        "\xC1" => 667, "\xC9" => 667, "\xCD" => 278, "\xD3" => 778, "\xDA" => 722, "\xD1" => 722, // Á,É,Í,Ó,Ú,Ñ
        "\xAB" => 500, "\xBB" => 500, "\xFC" => 556, "\xDC" => 722, "\xBF" => 556, "\xA1" => 278, // «,»,ü,Ü,¿,¡
        "\x96" => 556, "\x97" => 1000 // –, —
    ];

    private static array $fontWidthsHelvBold = [
        ' ' => 278, '!' => 333, '"' => 474, '#' => 556, '$' => 556, '%' => 889, '&' => 722, '\'' => 238,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 333, ';' => 333, '<' => 584, '=' => 584, '>' => 584, '?' => 611,
        '@' => 975, 'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 584, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611,
        'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611,
        'p' => 611, 'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778,
        'x' => 556, 'y' => 556, 'z' => 500, '{' => 389, '|' => 280, '}' => 389, '~' => 584,
        "\xE1" => 556, "\xE9" => 556, "\xED" => 278, "\xF3" => 611, "\xFA" => 611, "\xF1" => 611,
        "\xC1" => 722, "\xC9" => 667, "\xCD" => 278, "\xD3" => 778, "\xDA" => 722, "\xD1" => 722,
        "\xAB" => 500, "\xBB" => 500, "\xFC" => 611, "\xDC" => 722, "\xBF" => 611, "\xA1" => 333,
        "\x96" => 556, "\x97" => 1000
    ];

    public function __construct() {
        $this->content = '';
    }

    private function mmToPt(float $mm): float {
        return $mm * $this->scale;
    }

    private function ptY(float $mmY): float {
        // Convierte coordenada Y en mm (donde 0 es arriba) a sistema PDF (donde 0 es abajo)
        return $this->pageHeight - ($mmY * $this->scale);
    }

    public function setFillColor(int $r, int $g, int $b): void {
        $rf = sprintf("%.3f", $r / 255.0);
        $gf = sprintf("%.3f", $g / 255.0);
        $bf = sprintf("%.3f", $b / 255.0);
        $this->content .= "{$rf} {$gf} {$bf} rg\n";
    }

    public function setStrokeColor(int $r, int $g, int $b): void {
        $rf = sprintf("%.3f", $r / 255.0);
        $gf = sprintf("%.3f", $g / 255.0);
        $bf = sprintf("%.3f", $b / 255.0);
        $this->content .= "{$rf} {$gf} {$bf} RG\n";
    }

    public function setLineWidth(float $mm): void {
        $pt = sprintf("%.2f", $this->mmToPt($mm));
        $this->content .= "{$pt} w\n";
    }

    public function drawRect(float $mmX, float $mmY, float $mmW, float $mmH, string $mode = 'S'): void {
        $x = sprintf("%.2f", $this->mmToPt($mmX));
        $y = sprintf("%.2f", $this->ptY($mmY + $mmH));
        $w = sprintf("%.2f", $this->mmToPt($mmW));
        $h = sprintf("%.2f", $this->mmToPt($mmH));

        $op = ($mode === 'F') ? 'f' : (($mode === 'B' || $mode === 'FD') ? 'B' : 'S');
        $this->content .= "{$x} {$y} {$w} {$h} re {$op}\n";
    }

    public function drawLine(float $mmX1, float $mmY1, float $mmX2, float $mmY2): void {
        $x1 = sprintf("%.2f", $this->mmToPt($mmX1));
        $y1 = sprintf("%.2f", $this->ptY($mmY1));
        $x2 = sprintf("%.2f", $this->mmToPt($mmX2));
        $y2 = sprintf("%.2f", $this->ptY($mmY2));
        $this->content .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    public function drawCircle(float $mmCenterX, float $mmCenterY, float $mmRadius, string $mode = 'S'): void {
        $cx = $this->mmToPt($mmCenterX);
        $cy = $this->ptY($mmCenterY);
        $r  = $this->mmToPt($mmRadius);
        $k  = 0.5522847498 * $r;

        $op = ($mode === 'F') ? 'f' : (($mode === 'B' || $mode === 'FD') ? 'B' : 'S');

        $p0x = sprintf("%.2f", $cx + $r); $p0y = sprintf("%.2f", $cy);
        $c1x = sprintf("%.2f", $cx + $r); $c1y = sprintf("%.2f", $cy + $k);
        $c2x = sprintf("%.2f", $cx + $k); $c2y = sprintf("%.2f", $cy + $r);
        $p1x = sprintf("%.2f", $cx);      $p1y = sprintf("%.2f", $cy + $r);

        $c3x = sprintf("%.2f", $cx - $k); $c3y = sprintf("%.2f", $cy + $r);
        $c4x = sprintf("%.2f", $cx - $r); $c4y = sprintf("%.2f", $cy + $k);
        $p2x = sprintf("%.2f", $cx - $r); $p2y = sprintf("%.2f", $cy);

        $c5x = sprintf("%.2f", $cx - $r); $c5y = sprintf("%.2f", $cy - $k);
        $c6x = sprintf("%.2f", $cx - $k); $c6y = sprintf("%.2f", $cy - $r);
        $p3x = sprintf("%.2f", $cx);      $p3y = sprintf("%.2f", $cy - $r);

        $c7x = sprintf("%.2f", $cx + $k); $c7y = sprintf("%.2f", $cy - $r);
        $c8x = sprintf("%.2f", $cx + $r); $c8y = sprintf("%.2f", $cy - $k);

        $this->content .= "{$p0x} {$p0y} m {$c1x} {$c1y} {$c2x} {$c2y} {$p1x} {$p1y} c ";
        $this->content .= "{$c3x} {$c3y} {$c4x} {$c4y} {$p2x} {$p2y} c ";
        $this->content .= "{$c5x} {$c5y} {$c6x} {$c6y} {$p3x} {$p3y} c ";
        $this->content .= "{$c7x} {$c7y} {$c8x} {$c8y} {$p0x} {$p0y} c {$op}\n";
    }

    private function sanitizeText(string $str): string {
        // Convierte texto UTF-8 a Windows-1252 para compatibilidad nativa con fuentes Type-1
        if (function_exists('mb_convert_encoding')) {
            $encoded = @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $encoded = @iconv('UTF-8', 'windows-1252//TRANSLIT', $str);
        } else {
            $encoded = utf8_decode($str);
        }
        return $encoded ?: $str;
    }

    private function escapePdfString(string $str): string {
        $str = str_replace('\\', '\\\\', $str);
        $str = str_replace('(', '\\(', $str);
        $str = str_replace(')', '\\)', $str);
        return $str;
    }

    public function measureTextWidth(string $text, string $fontKey, float $fontSizePt): float {
        $clean = $this->sanitizeText($text);
        $table = ($fontKey === 'F2') ? self::$fontWidthsHelvBold : self::$fontWidthsHelv;
        $totalUnits = 0;
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $char = $clean[$i];
            $totalUnits += $table[$char] ?? 556;
        }
        return ($totalUnits / 1000.0) * $fontSizePt;
    }

    public function drawText(string $text, float $mmX, float $mmY, string $fontKey = 'F1', float $fontSizePt = 12, string $align = 'left'): void {
        $clean = $this->sanitizeText($text);
        $escaped = $this->escapePdfString($clean);

        $widthPt = $this->measureTextWidth($text, $fontKey, $fontSizePt);
        $xPt = $this->mmToPt($mmX);
        $yPt = $this->ptY($mmY);

        if ($align === 'center') {
            $xPt = $xPt - ($widthPt / 2.0);
        } elseif ($align === 'right') {
            $xPt = $xPt - $widthPt;
        }

        $xStr = sprintf("%.2f", $xPt);
        $yStr = sprintf("%.2f", $yPt);
        $sizeStr = sprintf("%.2f", $fontSizePt);

        $this->content .= "BT /{$fontKey} {$sizeStr} Tf {$xStr} {$yStr} Td ({$escaped}) Tj ET\n";
    }

    /**
     * Dibuja texto con ajuste de línea automático para títulos extensos.
     */
    public function drawWrappedText(string $text, float $mmCenterX, float $mmCenterY, float $maxMmWidth, string $fontKey = 'F2', float $fontSizePt = 18, float $lineHeightMm = 7.5): float {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $currentLine = '';

        $maxPt = $this->mmToPt($maxMmWidth);

        foreach ($words as $w) {
            $test = ($currentLine === '') ? $w : ($currentLine . ' ' . $w);
            if ($this->measureTextWidth($test, $fontKey, $fontSizePt) <= $maxPt) {
                $currentLine = $test;
            } else {
                if ($currentLine !== '') $lines[] = $currentLine;
                $currentLine = $w;
            }
        }
        if ($currentLine !== '') $lines[] = $currentLine;

        $totalLines = count($lines);
        $startY = $mmCenterY - (($totalLines - 1) * $lineHeightMm / 2.0);

        foreach ($lines as $idx => $line) {
            $y = $startY + ($idx * $lineHeightMm);
            $this->drawText($line, $mmCenterX, $y, $fontKey, $fontSizePt, 'center');
        }

        return $startY + (($totalLines - 1) * $lineHeightMm);
    }

    public function compile(): string {
        $stream = $this->content;
        $streamLen = strlen($stream);

        $out = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [0];

        // 1 0 obj: Catalog
        $offsets[1] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // 2 0 obj: Pages
        $offsets[2] = strlen($out);
        $out .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // 3 0 obj: Page (A4 Landscape: 841.89 x 595.28 pt)
        $offsets[3] = strlen($out);
        $wPt = sprintf("%.2f", $this->pageWidth);
        $hPt = sprintf("%.2f", $this->pageHeight);
        $out .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$wPt} {$hPt}] /Resources 4 0 R /Contents 5 0 R >>\nendobj\n";

        // 4 0 obj: Resources (Fuentes con codificación WinAnsi para acentos en español)
        $offsets[4] = strlen($out);
        $out .= "4 0 obj\n<<\n";
        $out .= "  /Font <<\n";
        $out .= "    /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\n";
        $out .= "    /F2 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\n";
        $out .= "    /F3 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>\n";
        $out .= "    /F4 << /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>\n";
        $out .= "  >>\n";
        $out .= "  /ProcSet [/PDF /Text /ImageB /ImageC /ImageI]\n";
        $out .= ">>\nendobj\n";

        // 5 0 obj: Content Stream
        $offsets[5] = strlen($out);
        $out .= "5 0 obj\n<< /Length {$streamLen} >>\nstream\n" . $stream . "endstream\nendobj\n";

        // 6 0 obj: Metadata Info
        $offsets[6] = strlen($out);
        $creationDate = "D:" . date('YmdHis');
        $out .= "6 0 obj\n<< /Title (Certificado Oficial Universidad del Aluminio) /Author (Universidad del Aluminio) /Creator (UniAluminio LMS Academic Engine v2.0) /CreationDate ({$creationDate}) >>\nendobj\n";

        // XREF Table
        $xrefOffset = strlen($out);
        $out .= "xref\n0 7\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $out .= "trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF";

        return $out;
    }
}

// ============================================================
// FUNCIÓN PRINCIPAL DE RENDERIZADO INSTITUCIONAL
// ============================================================

/**
 * Genera el archivo binario del Certificado Oficial o Diploma de Carrera en formato PDF A4 Landscape.
 *
 * @param array $datos [
 *    'tipo'                => 'curso' | 'carrera',
 *    'nombre'              => string (Nombre del estudiante),
 *    'cedula'              => string (Cédula / Documento de identidad),
 *    'titulo_programa'     => string (Nombre del curso o carrera),
 *    'codigo_verificacion' => string (Código oficial ej. ALU-CUR-1234567890),
 *    'fecha_emision'       => string (Opcional, dd/mm/aaaa),
 *    'institucion'         => string (Opcional)
 * ]
 * @return string Binario del documento PDF
 */
function generarPdfCertificadoBinario(array $datos): string {
    $tipo        = strtolower(trim($datos['tipo'] ?? 'curso'));
    $esCarrera   = ($tipo === 'carrera');
    $nombre      = trim($datos['nombre'] ?? 'COLABORADOR');
    $cedula      = trim($datos['cedula'] ?? 'N/A');
    $tituloProg  = trim($datos['titulo_programa'] ?? 'Programa de Capacitación Técnica');
    $codigo      = trim($datos['codigo_verificacion'] ?? '');
    $fecha       = trim($datos['fecha_emision'] ?? date('d/m/Y'));
    $institucion = trim($datos['institucion'] ?? 'Universidad del Aluminio');

    $pdf = new UniAluminioPdfEngine();

    $pageW = 297.0; // mm
    $pageH = 210.0; // mm
    $centerX = $pageW / 2.0;

    // 1. Fondo marfil pulido (#FEFEFF)
    $pdf->setFillColor(254, 254, 255);
    $pdf->drawRect(0, 0, $pageW, $pageH, 'F');

    // 2. Marco exterior grueso: Azul Noche (#0f2b48)
    $pdf->setStrokeColor(15, 43, 72);
    $pdf->setLineWidth(2.8);
    $pdf->drawRect(10, 10, 277, 190, 'S');

    // 3. Marco interior fino: Oro Imperial (#d4af37)
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(1.1);
    $pdf->drawRect(13, 13, 271, 184, 'S');

    // 4. Esquinas ornamentales en Oro
    $corners = [
        [15.5, 15.5], [$pageW - 15.5, 15.5],
        [15.5, $pageH - 15.5], [$pageW - 15.5, $pageH - 15.5]
    ];
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(0.6);
    foreach ($corners as [$cx, $cy]) {
        $pdf->drawCircle($cx, $cy, 2.8, 'S');
        $pdf->drawCircle($cx, $cy, 1.4, 'S');
    }

    // 5. Encabezado Institucional
    $pdf->setFillColor(15, 43, 72);
    $pdf->drawText(strtoupper($institucion), $centerX, 29, 'F2', 25, 'center');

    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("CAMPUS DE FORMACIÓN TÉCNICA E INNOVACIÓN INDUSTRIAL", $centerX, 35.5, 'F1', 9.5, 'center');

    // Filete divisorio central en Oro Imperial
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(0.8);
    $pdf->drawLine($centerX - 65, 39.5, $centerX + 65, 39.5);

    // 6. Tipo de Reconocimiento / Certificación
    if ($esCarrera) {
        $pdf->setFillColor(180, 130, 20); // Oro rico
        $pdf->drawText("DIPLOMA DE GRADUACIÓN PROFESIONAL", $centerX, 50, 'F2', 13.5, 'center');
    } else {
        $pdf->setFillColor(2, 132, 199); // Cobalto institucional
        $pdf->drawText("CERTIFICACIÓN DE COMPETENCIA TÉCNICA", $centerX, 50, 'F2', 13.5, 'center');
    }

    $pdf->setFillColor(71, 85, 105);
    $pdf->drawText("Otorga el presente reconocimiento oficial a:", $centerX, 62, 'F1', 11, 'center');

    // 7. Nombre del Titular
    $pdf->setFillColor(15, 43, 72);
    $nombreMayus = mb_strtoupper($nombre, 'UTF-8');
    $pdf->drawText($nombreMayus, $centerX, 76, 'F2', 23, 'center');

    // Cédula / Identificación
    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("Documento de Identidad: " . ($cedula ?: 'N/A'), $centerX, 85, 'F1', 10.5, 'center');

    // 8. Texto de Concesión
    $pdf->setFillColor(51, 65, 85);
    $textoAcreditacion = $esCarrera
        ? "Por haber culminado con distinción la totalidad del plan curricular de la Carrera Profesional de:"
        : "Por haber cursado y aprobado satisfactoriamente todas las lecciones y evaluaciones técnicas del Curso:";
    $pdf->drawText($textoAcreditacion, $centerX, 101, 'F1', 11, 'center');

    // 9. Título del Curso o Carrera
    if ($esCarrera) {
        $pdf->setFillColor(180, 130, 20);
    } else {
        $pdf->setFillColor(15, 43, 72);
    }
    $tituloConComillas = "« " . $tituloProg . " »";
    $pdf->drawWrappedText($tituloConComillas, $centerX, 114, 215, 'F2', 17.5, 7.5);

    // 10. Bloque Izquierdo: Código Oficial de Verificación
    $pdf->setFillColor(248, 250, 252);
    $pdf->setStrokeColor(226, 232, 240);
    $pdf->setLineWidth(0.5);
    $pdf->drawRect(24, 136, 68, 38, 'B');

    // Cabecera del bloque de verificación
    $pdf->setFillColor(15, 43, 72);
    $pdf->setStrokeColor(15, 43, 72);
    $pdf->drawRect(24, 136, 68, 7.5, 'F');
    $pdf->setFillColor(255, 255, 255);
    $pdf->drawText("REGISTRO INSTITUCIONAL", 58, 141.5, 'F2', 7.5, 'center');

    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("Código Oficial de Verificación:", 58, 148.5, 'F1', 7.5, 'center');

    $pdf->setFillColor(15, 43, 72);
    $pdf->drawText($codigo ?: 'ALU-CUR-OFICIAL', 58, 155.5, 'F4', 9.5, 'center');

    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("Validez pública consultable en el portal", 58, 163, 'F1', 6.5, 'center');
    $pdf->setFillColor(2, 132, 199);
    $pdf->drawText("verificar.php", 58, 168.5, 'F2', 7.5, 'center');

    // 11. Bloque Central: Sello Oficial de Rectoría
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(1.4);
    $pdf->drawCircle($centerX, 154, 13.5, 'S');
    $pdf->setLineWidth(0.6);
    $pdf->drawCircle($centerX, 154, 11.5, 'S');

    $pdf->setFillColor(212, 175, 55);
    $pdf->drawText("VALIDEZ OFICIAL", $centerX, 151, 'F2', 7, 'center');
    $pdf->drawText("RECTORÍA", $centerX, 155, 'F2', 6.5, 'center');
    $pdf->drawText("ACADÉMICA", $centerX, 158.5, 'F2', 6.5, 'center');
    $pdf->drawText("★ ★ ★", $centerX, 162.5, 'F1', 5.5, 'center');

    // 12. Bloque Derecho: Firma de Rectoría y Fecha
    $firmaX = $pageW - 55;
    $pdf->setStrokeColor(100, 116, 139);
    $pdf->setLineWidth(0.6);
    $pdf->drawLine($pageW - 85, 154, $pageW - 25, 154);

    $pdf->setFillColor(15, 43, 72);
    $pdf->drawText("Rectoría Académica", $firmaX, 160, 'F2', 10, 'center');

    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("Universidad del Aluminio", $firmaX, 165, 'F1', 8, 'center');
    $pdf->drawText("Fecha de Emisión: " . $fecha, $firmaX, 170, 'F1', 8, 'center');

    return $pdf->compile();
}
