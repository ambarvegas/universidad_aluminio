<?php
/**
 * migrar_imagenes_uploads.php
 * Extrae portadas de cursos y logo institucional almacenados en Base64
 * hacia archivos físicos optimizados en el directorio /uploads/.
 */

require_once __DIR__ . '/db_mysql.php';

header('Content-Type: text/plain; charset=utf-8');

echo "====================================================\n";
echo "MIGRACIÓN DE IMÁGENES BASE64 A ARCHIVOS FÍSICOS\n";
echo "====================================================\n\n";

$conn = db_connect();

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        die("Error: No se pudo crear el directorio 'uploads/'\n");
    }
}

/**
 * Guarda una cadena data:image/... en un archivo JPG optimizado.
 */
function guardarBase64AArchivo(string $dataUri, string $destinoRuta, int $maxWidth = 1200, int $quality = 85): bool {
    if (strpos($dataUri, 'data:image/') !== 0) {
        return false;
    }

    $comaPos = strpos($dataUri, ',');
    if ($comaPos === false) return false;

    $base64Data = substr($dataUri, $comaPos + 1);
    $binaryData = base64_decode($base64Data);
    if (!$binaryData) return false;

    // Si GD está disponible, optimizar y re-escalar
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($binaryData);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);

            if ($w > $maxWidth) {
                $newH = (int)round($h * $maxWidth / $w);
                $newW = $maxWidth;
            } else {
                $newW = $w;
                $newH = $h;
            }

            $dst = imagecreatetruecolor($newW, $newH);
            // Fondo blanco para imágenes transparentes
            imagefilledrectangle($dst, 0, 0, $newW, $newH, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);

            $ok = imagejpeg($dst, $destinoRuta, $quality);
            imagedestroy($dst);
            if ($ok) return true;
        }
    }

    // Fallback: guardar binario directo
    return file_put_contents($destinoRuta, $binaryData) !== false;
}

$cursosMigrados = 0;
$bytesAhorradosCursos = 0;

// 1. Migrar cursos
echo "1. Analizando cursos...\n";
$resCursos = $conn->query("SELECT id, titulo, imagen FROM `cursos` WHERE imagen LIKE 'data:image/%'");

if ($resCursos && $resCursos->num_rows > 0) {
    $stmtUpdate = $conn->prepare("UPDATE `cursos` SET `imagen` = ? WHERE `id` = ?");

    while ($c = $resCursos->fetch_assoc()) {
        $cid = $c['id'];
        $cleanId = preg_replace('/[^a-zA-Z0-9_-]/', '', $cid);
        $fileName = "portada_{$cleanId}.jpg";
        $filePath = $uploadDir . $fileName;
        $relPath  = "uploads/{$fileName}";

        $oldLen = strlen($c['imagen']);

        if (guardarBase64AArchivo($c['imagen'], $filePath, 1200, 85)) {
            $newLen = filesize($filePath);
            $stmtUpdate->bind_param('ss', $relPath, $cid);
            $stmtUpdate->execute();

            $ahorro = $oldLen - $newLen;
            $bytesAhorradosCursos += $ahorro;
            $cursosMigrados++;

            echo "  ✓ [{$cid}] '{$c['titulo']}':\n";
            echo "    Base64: " . round($oldLen / 1024, 1) . " KB -> Archivo: " . round($newLen / 1024, 1) . " KB ($relPath)\n";
        } else {
            echo "  ✗ Error al procesar curso {$cid}\n";
        }
    }
    $stmtUpdate->close();
} else {
    echo "  No se encontraron cursos con imágenes en Base64.\n";
}

// 2. Migrar Logo institucional
echo "\n2. Analizando logo institucional...\n";
$resLogo = $conn->query("SELECT valor FROM `configuracion` WHERE clave = 'logo' AND valor LIKE 'data:image/%'");
$logoMigrado = false;
$bytesAhorradosLogo = 0;

if ($resLogo && $resLogo->num_rows > 0) {
    $rowLogo = $resLogo->fetch_assoc();
    $oldLogoLen = strlen($rowLogo['valor']);
    $logoFileName = "logo_institucional.jpg";
    $logoFilePath = $uploadDir . $logoFileName;
    $logoRelPath  = "uploads/{$logoFileName}";

    if (guardarBase64AArchivo($rowLogo['valor'], $logoFilePath, 400, 90)) {
        $newLogoLen = filesize($logoFilePath);
        $stmtLogo = $conn->prepare("UPDATE `configuracion` SET valor = ? WHERE clave = 'logo'");
        $stmtLogo->bind_param('s', $logoRelPath);
        $stmtLogo->execute();
        $stmtLogo->close();

        $bytesAhorradosLogo = $oldLogoLen - $newLogoLen;
        $logoMigrado = true;
        echo "  ✓ Logo institucional:\n";
        echo "    Base64: " . round($oldLogoLen / 1024, 1) . " KB -> Archivo: " . round($newLogoLen / 1024, 1) . " KB ($logoRelPath)\n";
    } else {
        echo "  ✗ Error al procesar logo institucional\n";
    }
} else {
    echo "  El logo no está en formato Base64 o ya fue migrado.\n";
}

$totalAhorro = $bytesAhorradosCursos + $bytesAhorradosLogo;

echo "\n====================================================\n";
echo "RESUMEN DE MIGRACIÓN:\n";
echo "- Cursos migrados a archivos: {$cursosMigrados}\n";
echo "- Logo institucional migrado: " . ($logoMigrado ? "Sí" : "No necesario") . "\n";
echo "- Peso total ahorrado en base de datos: " . round($totalAhorro / 1024 / 1024, 2) . " MB (" . round($totalAhorro / 1024, 1) . " KB)\n";
echo "====================================================\n";
