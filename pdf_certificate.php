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
// MOTOR AUTÓNOMO DE CÓDIGOS QR (ISO/IEC 18004 COMPLIANT)
// ============================================================

/**
 * Generador nativo y puro de matrices QR para validación institucional.
 * Soporta modo Byte de 8 bits, control de errores Reed-Solomon (Level M),
 * intercalado estándar de bloques, patrones de alineación y evaluación de máscaras.
 */
class UniAluminioQrEngine {
    public const ECC_M = 1; // 15% error correction
    private const MODE_BYTE = 0b0100;

    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    private static array $eccTableM = [
        1  => [26,  10, [[1, 16]]],
        2  => [44,  16, [[1, 28]]],
        3  => [70,  26, [[1, 44]]],
        4  => [100, 18, [[2, 32]]],
        5  => [134, 24, [[2, 43]]],
        6  => [172, 16, [[4, 27]]],
        7  => [196, 18, [[4, 31]]],
        8  => [242, 22, [[2, 38], [2, 39]]],
        9  => [292, 22, [[3, 36], [2, 37]]],
        10 => [346, 26, [[4, 43], [1, 44]]],
    ];

    private static array $alignmentPatternCoords = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    private static array $formatInfoM = [
        0 => 0b101010000010010,
        1 => 0b101000100100101,
        2 => 0b101111001111100,
        3 => 0b101101101001011,
        4 => 0b100010111111001,
        5 => 0b100000011001110,
        6 => 0b100111110010111,
        7 => 0b100101010100000
    ];

    private static array $versionInfo = [
        7  => 0x07C94,
        8  => 0x085BC,
        9  => 0x09A99,
        10 => 0x0A4D3,
    ];

    private static function initGF(): void {
        if (self::$gfExp !== null) return;
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $val;
            self::$gfExp[$i + 255] = $val;
            self::$gfLog[$val] = $i;
            $val = $val << 1;
            if ($val & 0x100) $val ^= 0x11D;
        }
    }

    public static function encode(string $text): array {
        self::initGF();

        $dataLen = strlen($text);
        $version = self::pickVersion($dataLen);
        $spec = self::$eccTableM[$version];
        $totalCodewords = $spec[0];
        $eccPerBlock = $spec[1];
        $blockDefs = $spec[2];

        $totalDataCodewords = 0;
        foreach ($blockDefs as [$numB, $dataPerB]) {
            $totalDataCodewords += $numB * $dataPerB;
        }

        $bits = [];
        self::appendBits($bits, 4, self::MODE_BYTE);
        $countBits = ($version < 10) ? 8 : 16;
        self::appendBits($bits, $countBits, $dataLen);
        for ($i = 0; $i < $dataLen; $i++) {
            self::appendBits($bits, 8, ord($text[$i]));
        }

        $maxBits = $totalDataCodewords * 8;
        $termLen = min(4, $maxBits - count($bits));
        for ($i = 0; $i < $termLen; $i++) $bits[] = 0;

        while (count($bits) % 8 !== 0) $bits[] = 0;

        $padBytes = [0xEC, 0x11];
        $pIdx = 0;
        while (count($bits) < $maxBits) {
            self::appendBits($bits, 8, $padBytes[$pIdx]);
            $pIdx = 1 - $pIdx;
        }

        $allDataCodewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0;
            for ($k = 0; $k < 8; $k++) $b = ($b << 1) | $bits[$i + $k];
            $allDataCodewords[] = $b;
        }

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($blockDefs as [$numB, $dataPerB]) {
            for ($b = 0; $b < $numB; $b++) {
                $blockData = array_slice($allDataCodewords, $offset, $dataPerB);
                $offset += $dataPerB;
                $dataBlocks[] = $blockData;
                $eccBlocks[] = self::calculateReedSolomon($blockData, $eccPerBlock);
            }
        }

        $finalCodewords = [];
        $maxDataLenInBlock = 0;
        foreach ($dataBlocks as $db) $maxDataLenInBlock = max($maxDataLenInBlock, count($db));

        for ($i = 0; $i < $maxDataLenInBlock; $i++) {
            foreach ($dataBlocks as $db) {
                if ($i < count($db)) $finalCodewords[] = $db[$i];
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $eb) {
                $finalCodewords[] = $eb[$i];
            }
        }

        $remainderBitsCount = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0][$version] ?? 0;
        $finalBitstream = [];
        foreach ($finalCodewords as $cw) {
            self::appendBits($finalBitstream, 8, $cw);
        }
        for ($i = 0; $i < $remainderBitsCount; $i++) {
            $finalBitstream[] = 0;
        }

        $size = 17 + 4 * $version;
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFunctionPatterns($matrix, $isFunction, $version, $size);
        self::placeDataBitsInMatrix($matrix, $isFunction, $finalBitstream, $size);

        $bestScore = PHP_INT_MAX;
        $bestMatrix = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $testMatrix = $matrix;
            self::applyMask($testMatrix, $isFunction, $size, $mask);
            self::embedFormatInformation($testMatrix, $size, $mask);
            $score = self::calculatePenaltyScore($testMatrix, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMatrix = $testMatrix;
            }
        }

        return $bestMatrix;
    }

    private static function pickVersion(int $dataLen): int {
        foreach (self::$eccTableM as $v => $spec) {
            $totalData = 0;
            foreach ($spec[2] as [$numB, $dataPerB]) $totalData += $numB * $dataPerB;
            $headerBits = ($v < 10) ? (4 + 8) : (4 + 16);
            $maxPayloadBytes = intdiv($totalData * 8 - $headerBits - 4, 8);
            if ($dataLen <= $maxPayloadBytes) return $v;
        }
        return 10;
    }

    private static function appendBits(array &$arr, int $count, int $value): void {
        for ($i = $count - 1; $i >= 0; $i--) {
            $arr[] = ($value >> $i) & 1;
        }
    }

    private static function calculateReedSolomon(array $data, int $eccCount): array {
        $gen = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $root = self::$gfExp[$i];
            $temp = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                $temp[$j] ^= $gen[$j];
                $temp[$j + 1] ^= self::$gfExp[(self::$gfLog[$gen[$j]] + $i) % 255];
            }
            $gen = $temp;
        }

        $remainder = array_fill(0, $eccCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                $logFactor = self::$gfLog[$factor];
                for ($i = 0; $i < $eccCount; $i++) {
                    if ($gen[$i + 1] !== 0) {
                        $remainder[$i] ^= self::$gfExp[(self::$gfLog[$gen[$i + 1]] + $logFactor) % 255];
                    }
                }
            }
        }
        return $remainder;
    }

    private static function placeFunctionPatterns(array &$m, array &$fn, int $version, int $size): void {
        self::placeFinder($m, $fn, 0, 0);
        self::placeFinder($m, $fn, $size - 7, 0);
        self::placeFinder($m, $fn, 0, $size - 7);

        self::placeSeparators($m, $fn, $size);

        $coords = self::$alignmentPatternCoords[$version] ?? [];
        foreach ($coords as $y) {
            foreach ($coords as $x) {
                if ($fn[$y][$x]) continue;
                self::placeAlignment($m, $fn, $x, $y);
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            if (!$fn[6][$i]) {
                $m[6][$i] = ($i % 2 === 0);
                $fn[6][$i] = true;
            }
            if (!$fn[$i][6]) {
                $m[$i][6] = ($i % 2 === 0);
                $fn[$i][6] = true;
            }
        }

        $m[$size - 8][8] = true;
        $fn[$size - 8][8] = true;

        for ($i = 0; $i < 9; $i++) {
            $fn[8][$i] = true;
            $fn[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fn[8][$size - 1 - $i] = true;
            $fn[$size - 1 - $i][8] = true;
        }

        if ($version >= 7 && isset(self::$versionInfo[$version])) {
            $bits = self::$versionInfo[$version];
            for ($i = 0; $i < 18; $i++) {
                $mod = (($bits >> $i) & 1) === 1;
                $rTR = intdiv($i, 3);
                $cTR = ($i % 3) + $size - 11;
                $m[$rTR][$cTR] = $mod;
                $fn[$rTR][$cTR] = true;

                $rBL = ($i % 3) + $size - 11;
                $cBL = intdiv($i, 3);
                $m[$rBL][$cBL] = $mod;
                $fn[$rBL][$cBL] = true;
            }
        }
    }

    private static function placeFinder(array &$m, array &$fn, int $x, int $y): void {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $isBlack = ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                $m[$y + $r][$x + $c] = $isBlack;
                $fn[$y + $r][$x + $c] = true;
            }
        }
    }

    private static function placeSeparators(array &$m, array &$fn, int $size): void {
        for ($i = 0; $i < 8; $i++) {
            if ($i < 7) {
                $m[$i][7] = false; $fn[$i][7] = true;
                $m[7][$i] = false; $fn[7][$i] = true;
                $m[$size - 1 - $i][7] = false; $fn[$size - 1 - $i][7] = true;
                $m[$size - 8][$i] = false; $fn[$size - 8][$i] = true;
                $m[$i][$size - 8] = false; $fn[$i][$size - 8] = true;
                $m[7][$size - 1 - $i] = false; $fn[7][$size - 1 - $i] = true;
            }
        }
        $m[7][7] = false; $fn[7][7] = true;
        $m[$size - 8][7] = false; $fn[$size - 8][7] = true;
        $m[7][$size - 8] = false; $fn[7][$size - 8] = true;
    }

    private static function placeAlignment(array &$m, array &$fn, int $cx, int $cy): void {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $isBlack = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                $m[$cy + $r][$cx + $c] = $isBlack;
                $fn[$cy + $r][$cx + $c] = true;
            }
        }
    }

    private static function placeDataBitsInMatrix(array &$m, array $fn, array $bits, int $size): void {
        $bitIdx = 0;
        $numBits = count($bits);
        $up = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) $right = 5;

            for ($vert = 0; $vert < $size; $vert++) {
                $r = $up ? ($size - 1 - $vert) : $vert;
                for ($col = 0; $col < 2; $col++) {
                    $c = $right - $col;
                    if (!$fn[$r][$c]) {
                        $m[$r][$c] = ($bitIdx < $numBits) ? ($bits[$bitIdx++] === 1) : false;
                    }
                }
            }
            $up = !$up;
        }
    }

    private static function applyMask(array &$m, array $fn, int $size, int $mask): void {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($fn[$r][$c]) continue;
                $invert = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                    6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                    7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                    default => false
                };
                if ($invert) $m[$r][$c] = !$m[$r][$c];
            }
        }
    }

    private static function embedFormatInformation(array &$m, int $size, int $mask): void {
        $formatInt = self::$formatInfoM[$mask];
        $bits = [];
        for ($i = 14; $i >= 0; $i--) {
            $bits[] = ($formatInt >> $i) & 1;
        }

        $coordsTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        foreach ($coordsTopLeft as $idx => [$r, $c]) {
            $m[$r][$c] = ($bits[$idx] === 1);
        }

        for ($i = 0; $i < 7; $i++) {
            $m[$size - 1 - $i][8] = ($bits[$i] === 1);
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 8 + $i] = ($bits[7 + $i] === 1);
        }
    }

    private static function calculatePenaltyScore(array $m, int $size): int {
        $penalty = 0;

        for ($r = 0; $r < $size; $r++) {
            $count = 0;
            $last = null;
            for ($c = 0; $c < $size; $c++) {
                $val = $m[$r][$c];
                if ($val === $last) {
                    $count++;
                } else {
                    if ($count >= 5) $penalty += 3 + ($count - 5);
                    $last = $val;
                    $count = 1;
                }
            }
            if ($count >= 5) $penalty += 3 + ($count - 5);
        }

        for ($c = 0; $c < $size; $c++) {
            $count = 0;
            $last = null;
            for ($r = 0; $r < $size; $r++) {
                $val = $m[$r][$c];
                if ($val === $last) {
                    $count++;
                } else {
                    if ($count >= 5) $penalty += 3 + ($count - 5);
                    $last = $val;
                    $count = 1;
                }
            }
            if ($count >= 5) $penalty += 3 + ($count - 5);
        }

        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r + 1][$c] && $v === $m[$r][$c + 1] && $v === $m[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        $p1 = [true, false, true, true, true, false, true, false, false, false, false];
        $p2 = [false, false, false, false, true, false, true, true, true, false, true];

        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $match1 = true;
                $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r][$c + $k] !== $p1[$k]) $match1 = false;
                    if ($m[$r][$c + $k] !== $p2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $penalty += 40;
            }
        }

        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $match1 = true;
                $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r + $k][$c] !== $p1[$k]) $match1 = false;
                    if ($m[$r + $k][$c] !== $p2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $penalty += 40;
            }
        }

        $darkCount = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c]) $darkCount++;
            }
        }
        $total = $size * $size;
        $pct = ($darkCount * 100) / $total;
        $prev5 = intval(floor($pct / 5)) * 5;
        $next5 = $prev5 + 5;
        $k1 = abs($prev5 - 50) / 5;
        $k2 = abs($next5 - 50) / 5;
        $penalty += intval(min($k1, $k2) * 10);

        return $penalty;
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
 *    'url_verificacion'    => string (Opcional, URL completa de verificación con QR),
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

    // Construcción de la URL oficial de verificación para el código QR
    $urlVerif = trim($datos['url_verificacion'] ?? '');
    if ($urlVerif === '') {
        $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'aluminiologo.oo.gd';
        $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/universidad');
        $dir = ($dir === '/' || $dir === '\\') ? '' : $dir;
        $baseUrl = $proto . '://' . $host . $dir;
        $urlVerif = rtrim($baseUrl, '/') . '/verificar.php?codigo=' . urlencode($codigo ?: 'ALU-CUR-OFICIAL');
    }

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

    // 10. Bloque Izquierdo: Tarjeta con Código QR Escaneable (Exclusivo)
    $boxX = 38.0;
    $boxY = 135.5;
    $boxW = 40.0;
    $boxH = 40.0;

    // Tarjeta con fondo blanco y doble filete sutil
    $pdf->setFillColor(255, 255, 255);
    $pdf->setStrokeColor(203, 213, 225);
    $pdf->setLineWidth(0.6);
    $pdf->drawRect($boxX, $boxY, $boxW, $boxH, 'B');

    // Filete interior dorado fino
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(0.3);
    $pdf->drawRect($boxX + 1.2, $boxY + 1.2, $boxW - 2.4, $boxH - 2.4, 'S');

    // Generar y dibujar matriz de módulos QR vectoriales centrado en la tarjeta
    $qrMatrix = UniAluminioQrEngine::encode($urlVerif);
    $qrCount = count($qrMatrix);
    if ($qrCount > 0) {
        $qrPadding = 3.5;
        $qrDrawSize = $boxW - ($qrPadding * 2);
        $modSize = $qrDrawSize / $qrCount;
        $qrStartX = $boxX + $qrPadding;
        $qrStartY = $boxY + $qrPadding;

        $pdf->setFillColor(15, 43, 72); // Azul noche institucional
        for ($r = 0; $r < $qrCount; $r++) {
            for ($c = 0; $c < $qrCount; $c++) {
                if ($qrMatrix[$r][$c]) {
                    $pdf->drawRect($qrStartX + ($c * $modSize), $qrStartY + ($r * $modSize), $modSize, $modSize, 'F');
                }
            }
        }
    }

    // 11. Bloque Central: Sello Oficial de Rectoría
    $pdf->setStrokeColor(212, 175, 55);
    $pdf->setLineWidth(1.4);
    $pdf->drawCircle($centerX, 155.5, 13.5, 'S');
    $pdf->setLineWidth(0.6);
    $pdf->drawCircle($centerX, 155.5, 11.5, 'S');

    $pdf->setFillColor(212, 175, 55);
    $pdf->drawText("VALIDEZ OFICIAL", $centerX, 152.5, 'F2', 7, 'center');
    $pdf->drawText("RECTORÍA", $centerX, 156.5, 'F2', 6.5, 'center');
    $pdf->drawText("ACADÉMICA", $centerX, 160.0, 'F2', 6.5, 'center');
    $pdf->drawText("★ ★ ★", $centerX, 164.0, 'F1', 5.5, 'center');

    // 12. Bloque Derecho: Firma de Rectoría y Fecha
    $firmaX = $pageW - 55;
    $pdf->setStrokeColor(100, 116, 139);
    $pdf->setLineWidth(0.6);
    $pdf->drawLine($pageW - 85, 155.5, $pageW - 25, 155.5);

    $pdf->setFillColor(15, 43, 72);
    $pdf->drawText("Rectoría Académica", $firmaX, 161.5, 'F2', 10, 'center');

    $pdf->setFillColor(100, 116, 139);
    $pdf->drawText("Universidad del Aluminio", $firmaX, 166.5, 'F1', 8, 'center');
    $pdf->drawText("Fecha de Emisión: " . $fecha, $firmaX, 171.5, 'F1', 8, 'center');

    return $pdf->compile();
}
