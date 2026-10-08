<?php
/**
 * Copiar como config.local.php en el servidor (NO se versiona) y completar.
 * Alternativa: definir la variable de entorno KPI_API_KEY.
 *
 * kpi_api_key: clave compartida con el dashboard Alu-Cultura (secret
 * UNIVERSIDAD_API_KEY de su Edge Function). Generar una larga y aleatoria:
 *   php -r "echo bin2hex(random_bytes(32));"
 */
return [
    'kpi_api_key' => '',
];
