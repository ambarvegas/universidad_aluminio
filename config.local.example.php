<?php
/**
 * Copiar como config.local.php en el servidor (NO se versiona) y completar.
 * El acceso web a config.local*.php está bloqueado en .htaccess.
 *
 * db_*: conexión MySQL (obligatoria; sin ella la API responde 503).
 * Alternativa: variables de entorno DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT.
 *
 * kpi_api_key: clave compartida con el servidor PHP de Alu-Cultura (ruta
 * heredada de kpi_humildad con X-Api-Key; se retirará junto con ese servidor).
 * Alternativa: definir la variable de entorno KPI_API_KEY.
 * Generar una larga y aleatoria:
 *   php -r "echo bin2hex(random_bytes(32));"
 *
 * firebase_project_id: proyecto de Firebase cuyos ID tokens se aceptan en
 * kpi_humildad (Authorization: Bearer <token>). Cuentas de Opening Checklist.
 *
 * firestore_emulador_host: sólo para pruebas locales (p. ej. '127.0.0.1:8080').
 * En producción debe quedar en null.
 */
return [
    'db_host' => '',
    'db_user' => '',
    'db_pass' => '',
    'db_name' => '',
    'db_port' => 3306,

    'kpi_api_key' => '',

    'firebase_project_id' => 'opening-c3cf5',

    // ¡¡ATENCIÓN!! true DESACTIVA la verificación de firma de los tokens
    // (acepta tokens del emulador de Auth). NUNCA activarlo en producción:
    // cualquiera podría hacerse pasar por cualquier usuario.
    'firebase_emulador' => false,

    'firestore_emulador_host' => null,
];
