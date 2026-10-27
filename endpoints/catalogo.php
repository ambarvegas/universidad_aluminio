<?php
/**
 * endpoints/catalogo.php — Gestión del catálogo académico
 * Imágenes, cursos, carreras, roles y configuración general.
 *
 * Lo incluye el router de api.php según la acción: comparte su ámbito ($conn, $method,
 * $action y los helpers jsonBody(), require_session(), require_admin(), is_admin(),
 * kpi_api_key() y bearer_token()).
 */

switch ($action) {

    // ------ SUBIDA DE IMAGENES (multipart/form-data) -------------
    case 'upload_image':
        require_session();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }

        $uploadDir = APP_ROOT . '/uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                http_response_code(500); echo json_encode(['error' => 'No se pudo crear el directorio de uploads']); break;
            }
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? 'sin archivo';
            http_response_code(400); echo json_encode(['error' => "Error de subida: $err"]); break;
        }

        $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['file']['tmp_name']);

        if (!in_array($mime, $allowedMime)) {
            http_response_code(400); echo json_encode(['error' => 'Tipo de archivo no permitido: ' . $mime]); break;
        }

        $type    = trim($_POST['type'] ?? 'portada');
        $entidad = trim($_POST['id'] ?? 'gen');
        $entidad = preg_replace('/[^a-zA-Z0-9_\-]/', '', $entidad);
        $ext     = 'jpg';
        $nombre  = $type . '_' . $entidad . '_' . time() . '.' . $ext;
        $destino = $uploadDir . $nombre;

        $maxWidth = ($type === 'logo') ? 400 : (($type === 'opcion' || $type === 'pregunta') ? 800 : 1200);
        $quality  = 82;

        switch ($mime) {
            case 'image/jpeg': case 'image/jpg': $src = imagecreatefromjpeg($_FILES['file']['tmp_name']); break;
            case 'image/png':  $src = imagecreatefrompng($_FILES['file']['tmp_name']); break;
            case 'image/gif':  $src = imagecreatefromgif($_FILES['file']['tmp_name']); break;
            case 'image/webp': $src = imagecreatefromwebp($_FILES['file']['tmp_name']); break;
            default: $src = null;
        }

        if (!$src) { http_response_code(500); echo json_encode(['error' => 'No se pudo procesar la imagen']); break; }

        $w = imagesx($src); $h = imagesy($src);
        if ($w > $maxWidth) { $h = (int)round($h * $maxWidth / $w); $w = $maxWidth; }

        $dst = imagecreatetruecolor($w, $h);
        imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);

        if (!imagejpeg($dst, $destino, $quality)) {
            imagedestroy($dst);
            http_response_code(500); echo json_encode(['error' => 'Error al guardar la imagen']); break;
        }
        imagedestroy($dst);

        $prevFile = trim($_POST['prev'] ?? '');
        if ($prevFile && strpos($prevFile, 'uploads/') === 0) {
            $prevPath = APP_ROOT . '/' . $prevFile;
            if (is_file($prevPath)) @unlink($prevPath);
        }

        $url = 'uploads/' . $nombre;
        echo json_encode(['url' => $url, 'message' => 'Imagen subida correctamente']);
        break;

    // ------ CURSOS -----------------------------------------------
    case 'guardar_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_curso($conn, $body); echo json_encode(['message' => 'Curso guardado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_curso':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_curso($conn, $id); echo json_encode(['message' => 'Curso eliminado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CARRERAS ---------------------------------------------
    case 'guardar_carrera':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_carrera($conn, $body); echo json_encode(['message' => 'Carrera guardada']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_carrera':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_carrera($conn, $id); echo json_encode(['message' => 'Carrera eliminada']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ ROLES ------------------------------------------------
    case 'guardar_rol':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        if (empty($body['id'])) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_upsert_rol($conn, $body); echo json_encode(['message' => 'Rol guardado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'eliminar_rol':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $id = trim($body['id'] ?? '');
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Campo requerido: id']); break; }
        try { db_delete_rol($conn, $id); echo json_encode(['message' => 'Rol eliminado']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ------ CONFIGURACION ----------------------------------------
    case 'guardar_config':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody(); $clave = trim($body['clave'] ?? '');
        if (!$clave || !array_key_exists('valor', $body)) {
            http_response_code(400); echo json_encode(['error' => 'Se requieren clave y valor']); break;
        }
        try { db_upsert_config($conn, $clave, $body['valor']); echo json_encode(['message' => 'Configuracion guardada']); }
        catch (Throwable $e) { api_registrar_excepcion($e); http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'guardar_config_batch':
        require_admin();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Metodo no permitido']); break; }
        $body = jsonBody();
        $configs = $body['configuraciones'] ?? $body;
        if (!is_array($configs) || empty($configs)) {
            http_response_code(400); echo json_encode(['error' => 'Se requiere un arreglo asociativo de configuraciones']); break;
        }
        try {
            db_upsert_config_batch($conn, $configs);
            echo json_encode(['message' => 'Configuraciones guardadas']);
        } catch (Throwable $e) {
            api_registrar_excepcion($e);
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;
}
