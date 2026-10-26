<?php

/**
 * Servidor PHP embebido apuntando a la base de prueba, con un "navegador" por cliente
 * (cookies de sesión independientes) para probar api.php por HTTP.
 */
final class ServidorApi
{
    private $proceso;
    private string $base;
    private array $cookies = [];

    public function __construct()
    {
        $raiz = realpath(__DIR__ . '/../..');
        $puerto = self::puertoLibre();
        $this->base = "http://127.0.0.1:$puerto";
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open(
            [PHP_BINARY,
             '-d', 'auto_prepend_file=' . __DIR__ . '/prepend_api.php',
             // Correo: fallar de inmediato en lugar de esperar a un SMTP inexistente
             '-d', 'SMTP=127.0.0.1', '-d', 'smtp_port=9',
             '-S', "127.0.0.1:$puerto", '-t', $raiz],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias,
            $raiz
        );
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.2)) return;
            usleep(100_000);
        }
        throw new RuntimeException('No arrancó el servidor de pruebas en el puerto ' . $puerto);
    }

    public function detener(): void
    {
        if (is_resource($this->proceso)) {
            proc_terminate($this->proceso);
            proc_close($this->proceso);
        }
    }

    /**
     * Ejecuta una petición a api.php?action=... como el cliente indicado.
     * @return array{status:int, json:mixed, texto:string, cabeceras:array<string,string>}
     */
    public function api(string $cliente, string $metodo, string $accion, ?array $cuerpo = null, array $cabeceras = []): array
    {
        $ch = curl_init("{$this->base}/api.php?action=$accion");
        $enviar = array_merge(['Content-Type: application/json'], $cabeceras);
        if (!empty($this->cookies[$cliente])) $enviar[] = 'Cookie: ' . $this->cookies[$cliente];
        $recibidas = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $enviar,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$recibidas, $cliente) {
                if (strpos($linea, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $linea, 2));
                    $recibidas[strtolower($k)] = $v;
                    if (strtolower($k) === 'set-cookie') $this->cookies[$cliente] = explode(';', $v)[0];
                }
                return strlen($linea);
            },
        ]);
        if ($metodo === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo ?? []));
        $texto = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => json_decode($texto, true), 'texto' => $texto, 'cabeceras' => $recibidas];
    }

    /** Inicia sesión y devuelve la respuesta del login. */
    public function login(string $cliente, string $id, string $clave = 'x'): array
    {
        return $this->api($cliente, 'POST', 'login', ['id' => $id, 'clave' => $clave]);
    }

    private static function puertoLibre(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $puerto;
    }
}
