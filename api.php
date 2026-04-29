<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$db_file = 'db.json';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (file_exists($db_file)) {
        echo file_get_contents($db_file);
    } else {
        // Si no existe, devolvemos un objeto vacío o la estructura inicial
        echo json_encode(["usuarios" => [], "cursos" => [], "carreras" => [], "rolesConfig" => [], "solicitudesRegistro" => [], "solicitudesCursos" => [], "configuracion" => []]);
    }
} elseif ($method === 'POST') {
    // Recibir el cuerpo de la petición (JSON)
    $json = file_get_contents('php://input');
    
    if (!empty($json)) {
        if (file_put_contents($db_file, $json)) {
            echo json_encode(["message" => "Base de datos guardada correctamente"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Error al escribir en el archivo db.json"]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["error" => "No se recibieron datos"]);
    }
} elseif ($method === 'OPTIONS') {
    http_response_code(200);
} else {
    http_response_code(405);
    echo json_encode(["error" => "Metodo no permitido"]);
}