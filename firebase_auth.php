<?php
/**
 * firebase_auth.php - Universidad del Aluminio
 * Autenticación con ID tokens de Firebase Auth (cuentas de Opening Checklist)
 * sin dependencias: sólo curl y openssl.
 * https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library
 */

const FIREBASE_CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

/**
 * Configuración de Firebase desde config.local.php (mismo archivo que kpi_api_key()).
 * firebase_emulador = true desactiva la verificación de firma: NUNCA en producción.
 */
function firebase_config(): array {
    $cfg = [];
    $file = __DIR__ . '/config.local.php';
    if (is_file($file)) {
        $c = include $file;
        if (is_array($c)) $cfg = $c;
    }
    $host = $cfg['firestore_emulador_host'] ?? null;
    return [
        'firebase_project_id'     => !empty($cfg['firebase_project_id']) ? (string)$cfg['firebase_project_id'] : 'opening-c3cf5',
        'firebase_emulador'       => ($cfg['firebase_emulador'] ?? false) === true,
        'firestore_emulador_host' => is_string($host) && $host !== '' ? $host : null,
    ];
}

function fb_b64url_decode(string $s): string {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: '';
}

/** Certificados públicos de Google (kid => PEM), cacheados según su Cache-Control. */
function firebase_certs(): array {
    $cache = sys_get_temp_dir() . '/universidad_firebase_certs.json';
    if (is_file($cache)) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && ($c['expira'] ?? 0) > time() && is_array($c['certs'] ?? null)) return $c['certs'];
    }
    $ch = curl_init(FIREBASE_CERTS_URL);
    $maxAge = 3600;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HEADERFUNCTION => function ($ch, $linea) use (&$maxAge) {
            if (stripos($linea, 'cache-control:') === 0 && preg_match('/max-age=(\d+)/i', $linea, $m)) $maxAge = (int)$m[1];
            return strlen($linea);
        },
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $certs = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($certs) || !$certs) {
        throw new RuntimeException('No se pudieron obtener los certificados de Firebase');
    }
    @file_put_contents($cache, json_encode(['expira' => time() + $maxAge, 'certs' => $certs]), LOCK_EX);
    return $certs;
}

/**
 * Valida el ID token y devuelve sus claims. Lanza excepción si no es válido.
 * $certs (kid => PEM) sustituye a los certificados de Google (sólo pruebas).
 * Con $emulador = true acepta los tokens sin firma del emulador de Auth
 * (NUNCA activarlo en producción).
 */
function verificar_id_token(string $jwt, string $projectId, bool $emulador = false, ?array $certs = null): array {
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) throw new InvalidArgumentException('Token mal formado');
    [$h64, $p64, $s64] = $partes;
    $header = json_decode(fb_b64url_decode($h64), true);
    $claims = json_decode(fb_b64url_decode($p64), true);
    if (!is_array($header) || !is_array($claims)) throw new InvalidArgumentException('Token mal formado');

    if (!$emulador) {
        if (($header['alg'] ?? '') !== 'RS256') throw new InvalidArgumentException('Algoritmo inválido');
        $certs = $certs ?? firebase_certs();
        $kid = $header['kid'] ?? '';
        $cert = is_string($kid) ? ($certs[$kid] ?? null) : null;
        if (!$cert) throw new InvalidArgumentException('Certificado desconocido');
        $ok = openssl_verify("$h64.$p64", fb_b64url_decode($s64), $cert, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new InvalidArgumentException('Firma inválida');
    }

    $ahora = time();
    $margen = 60; // tolerancia de reloj
    if (($claims['aud'] ?? '') !== $projectId) throw new InvalidArgumentException('Audiencia inválida');
    if (($claims['iss'] ?? '') !== "https://securetoken.google.com/$projectId") throw new InvalidArgumentException('Emisor inválido');
    if (!is_numeric($claims['exp'] ?? null) || $claims['exp'] < $ahora - $margen) throw new InvalidArgumentException('Token expirado');
    if (!is_numeric($claims['iat'] ?? null) || $claims['iat'] > $ahora + $margen) throw new InvalidArgumentException('Token emitido en el futuro');
    if (!is_numeric($claims['auth_time'] ?? null) || $claims['auth_time'] > $ahora + $margen) throw new InvalidArgumentException('auth_time inválido');
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 128) throw new InvalidArgumentException('Usuario inválido');
    return $claims;
}

/**
 * Cédula (sólo dígitos) del perfil users/{uid} de Opening Checklist, leída
 * con el propio ID token del usuario (se aplican las reglas de Firestore).
 * Devuelve null si el documento no existe o no tiene ci.
 * Lanza RuntimeException ante otros errores HTTP o de red.
 */
function ci_de_usuario_firebase(string $idToken, string $projectId, string $uid, ?string $emuladorFirestore = null): ?string {
    $raiz = $emuladorFirestore ? "http://$emuladorFirestore/v1" : 'https://firestore.googleapis.com/v1';
    $url = "$raiz/projects/" . rawurlencode($projectId) . '/databases/(default)/documents/users/' . rawurlencode($uid);
    $bearer = $emuladorFirestore ? 'owner' : $idToken;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new RuntimeException("Firestore: $err");
    if ($status === 404) return null;
    if ($status !== 200) throw new RuntimeException("Firestore HTTP $status");

    $doc = json_decode($resp, true);
    $campo = is_array($doc) ? ($doc['fields']['ci'] ?? null) : null;
    if (!is_array($campo)) return null;
    $valor = $campo['stringValue'] ?? $campo['integerValue'] ?? null;
    if (!is_string($valor) && !is_int($valor)) return null;
    $ci = preg_replace('/[^0-9]/', '', (string)$valor);
    return $ci === '' ? null : $ci;
}
