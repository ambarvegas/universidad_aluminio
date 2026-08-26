<?php
/**
 * migrate_db.php
 * Script de migración one-shot: crea las tablas MySQL e importa db.json
 *
 * USO:
 *   Abrir en el navegador: http://tudominio.com/migrate_db.php?key=MIGRATE2026
 *   O desde CLI: php migrate_db.php MIGRATE2026
 */

// Sin límite de tiempo para migraciones grandes
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(0);

// ============================================================
// CLAVE DE ACCESO — Cámbiala antes de ejecutar si lo deseas
// ============================================================
const MIGRATE_KEY = 'MIGRATE2026';

// ============================================================
// Verificación de clave
// ============================================================
$providedKey = $_GET['key'] ?? ($argv[1] ?? '');
if ($providedKey !== MIGRATE_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado. Provee ?key=MIGRATE2026']);
    exit;
}

// ============================================================
// Output en HTML para mejor lectura en el navegador
// ============================================================
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Migración MySQL — Universidad del Aluminio</title>
<style>
  body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 2rem; }
  h1 { color: #38bdf8; }
  .ok   { color: #4ade80; }
  .err  { color: #f87171; }
  .warn { color: #facc15; }
  .info { color: #94a3b8; }
  pre  { background: #1e293b; padding: 1rem; border-radius: 0.5rem; }
</style></head><body>
<h1>🔧 Migración MySQL — Universidad del Aluminio</h1>';

function log_msg(string $msg, string $type = 'info'): void {
    echo "<p class=\"$type\">" . htmlspecialchars($msg) . "</p>\n";
    flush();
}

// ============================================================
// Cargar módulo de base de datos
// ============================================================
require_once __DIR__ . '/db_mysql.php';

// ============================================================
// Paso 1: Conectar a MySQL
// ============================================================
log_msg('Paso 1: Conectando a MySQL...', 'info');
try {
    $conn = db_connect();
    log_msg('✅ Conexión exitosa a MySQL (' . MYSQL_HOST . '/' . MYSQL_DB . ')', 'ok');
} catch (Throwable $e) {
    log_msg('❌ Error de conexión: ' . $e->getMessage(), 'err');
    echo '</body></html>';
    exit;
}

// ============================================================
// Paso 2: Crear tablas
// ============================================================
log_msg('Paso 2: Creando tablas (IF NOT EXISTS)...', 'info');
try {
    db_create_tables($conn);
    log_msg('✅ Tablas creadas/verificadas correctamente.', 'ok');
} catch (Throwable $e) {
    log_msg('❌ Error creando tablas: ' . $e->getMessage(), 'err');
    $conn->close();
    echo '</body></html>';
    exit;
}

// ============================================================
// Paso 3: Leer db.json
// ============================================================
$dbFile = __DIR__ . '/db.json';
log_msg('Paso 3: Buscando db.json...', 'info');

if (!file_exists($dbFile)) {
    log_msg('⚠️  db.json no encontrado. Las tablas fueron creadas pero no se importaron datos.', 'warn');
    log_msg('Si ya migraste, esto es normal. Las tablas están listas para usarse.', 'info');
    $conn->close();
    echo '</body></html>';
    exit;
}

$raw = @file_get_contents($dbFile);
if ($raw === false) {
    log_msg('❌ No se pudo leer db.json.', 'err');
    $conn->close();
    echo '</body></html>';
    exit;
}

$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    log_msg('❌ db.json contiene JSON inválido: ' . json_last_error_msg(), 'err');
    $conn->close();
    echo '</body></html>';
    exit;
}

log_msg('✅ db.json leído correctamente.', 'ok');

// Estadísticas
$numUsuarios  = count($data['usuarios']            ?? []);
$numCursos    = count($data['cursos']              ?? []);
$numCarreras  = count($data['carreras']            ?? []);
$numRoles     = count($data['rolesConfig']         ?? []);
$numSolReg    = count($data['solicitudesRegistro'] ?? []);
$numSolCur    = count($data['solicitudesCursos']   ?? []);

log_msg("   → Usuarios: $numUsuarios", 'info');
log_msg("   → Cursos: $numCursos", 'info');
log_msg("   → Carreras: $numCarreras", 'info');
log_msg("   → Roles: $numRoles", 'info');
log_msg("   → Solicitudes registro: $numSolReg", 'info');
log_msg("   → Solicitudes cursos: $numSolCur", 'info');

// ============================================================
// Paso 4: Migrar datos a MySQL
// ============================================================
log_msg('Paso 4: Importando datos a MySQL (transacción única)...', 'info');
try {
    db_write_all($conn, $data);
    log_msg('✅ Datos importados correctamente a MySQL.', 'ok');
} catch (Throwable $e) {
    log_msg('❌ Error durante la importación: ' . $e->getMessage(), 'err');
    $conn->close();
    echo '</body></html>';
    exit;
}

// ============================================================
// Paso 5: Verificación
// ============================================================
log_msg('Paso 5: Verificando conteo de registros en MySQL...', 'info');
$tables = [
    'usuarios'                    => 'usuarios',
    'usuario_asignados'           => 'usuario_asignados',
    'usuario_carreras_asignadas'  => 'usuario_carreras_asignadas',
    'usuario_progreso'            => 'usuario_progreso',
    'usuario_certificados_curso'  => 'usuario_certificados_curso',
    'usuario_certificados_carrera'=> 'usuario_certificados_carrera',
    'cursos'                      => 'cursos',
    'carreras'                    => 'carreras',
    'roles_config'                => 'roles_config',
    'solicitudes_registro'        => 'solicitudes_registro',
    'solicitudes_cursos'          => 'solicitudes_cursos',
    'configuracion'               => 'configuracion',
];
foreach ($tables as $label => $table) {
    $res = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
    $row = $res->fetch_assoc();
    log_msg("   ✅ $table: {$row['cnt']} filas", 'ok');
}

// ============================================================
// Paso 6: Archivar db.json
// ============================================================
log_msg('Paso 6: Archivando db.json → db.json.migrated...', 'info');
$migratedFile = __DIR__ . '/db.json.migrated';
if (@rename($dbFile, $migratedFile)) {
    log_msg('✅ db.json renombrado a db.json.migrated. El sistema usará MySQL exclusivamente.', 'ok');
} else {
    log_msg('⚠️  No se pudo renombrar db.json (puede estar bloqueado). Hazlo manualmente si deseas.', 'warn');
}

$conn->close();

echo '<hr>
<h2 class="ok">🎉 Migración completada con éxito</h2>
<p>El sistema ahora usa <strong>MySQL</strong> como base de datos.</p>
<p class="warn">⚠️  Elimina o protege este archivo <code>migrate_db.php</code> para evitar re-ejecuciones accidentales.</p>
</body></html>';
