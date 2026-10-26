<?php

/**
 * api.php por HTTP: enrutado, permisos, CORS y flujos completos.
 * Clientes: "anon" (sin sesión), "admin" (1000) y "ana" (2000, participante).
 */
final class ApiTest extends BaseDatosTestCase
{
    private static ServidorApi $srv;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$srv = new ServidorApi();
    }

    public static function tearDownAfterClass(): void
    {
        self::$srv->detener();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
    }

    // ---------- Enrutado y errores ----------

    public function testPingResponde(): void
    {
        $r = self::$srv->api('anon', 'GET', 'ping');
        $this->assertSame(200, $r['status']);
        $this->assertSame('ok', $r['json']['status']);
        $this->assertArrayNotHasKey('db', $r['json'], 'el ping no debe exponer datos de la base');
    }

    public function testAccionDesconocidaDevuelve404(): void
    {
        $r = self::$srv->api('anon', 'GET', 'no_existe');
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('Accion desconocida', $r['json']['error']);
    }

    public function testMetodoIncorrectoDevuelve405(): void
    {
        self::$srv->login('admin', '1000');
        $this->assertSame(405, self::$srv->api('admin', 'GET', 'guardar_curso')['status']);
    }

    /** Todas las acciones de la tabla de rutas existen en su archivo de endpoints/. */
    public function testCadaRutaTieneSuCase(): void
    {
        preg_match('/const API_RUTAS = \[(.*?)\];/s', file_get_contents(APP_ROOT . '/api.php'), $m);
        preg_match_all("/'(\w+)'\s*=>\s*'(\w+)'/", $m[1], $rutas, PREG_SET_ORDER);
        $this->assertGreaterThan(40, count($rutas));
        foreach ($rutas as [, $accion, $archivo]) {
            $codigo = file_get_contents(APP_ROOT . "/endpoints/$archivo.php");
            $this->assertStringContainsString("case '$accion':", $codigo, "La acción '$accion' no está en endpoints/$archivo.php");
        }
    }

    // ---------- Autenticación y permisos ----------

    public function testSinSesionSePideLogin(): void
    {
        foreach (['db', 'catalogo', 'config', 'cursos'] as $accion) {
            $this->assertSame(401, self::$srv->api('anon', 'GET', $accion)['status'], $accion);
        }
    }

    public function testLoginIncorrectoYCorrecto(): void
    {
        $this->assertSame(401, self::$srv->login('anon', '2000', 'mala')['status']);
        $r = self::$srv->login('ana', '2000');
        $this->assertSame(200, $r['status']);
        $this->assertSame('2000', $r['json']['usuario']['id']);
        $this->assertArrayNotHasKey('clave', $r['json']['usuario']);
    }

    public function testParticipanteNoAccedeAAccionesDeAdmin(): void
    {
        self::$srv->login('ana', '2000');
        $this->assertSame(403, self::$srv->api('ana', 'GET', 'usuarios')['status']);
        $this->assertSame(403, self::$srv->api('ana', 'GET', 'reporte_evaluaciones')['status']);
        $this->assertSame(403, self::$srv->api('ana', 'GET', 'progreso_usuarios')['status']);
        $this->assertSame(403, self::$srv->api('ana', 'POST', 'habilitar_reintento', ['usuario_id' => '2000', 'curso_id' => 'C1', 'modulo_idx' => 0])['status']);
        $this->assertSame(403, self::$srv->api('ana', 'POST', 'guardar_progreso', ['usuario_id' => '3000', 'curso_id' => 'C1'])['status']);
    }

    public function testLogoutCierraLaSesion(): void
    {
        self::$srv->login('ana', '2000');
        self::$srv->api('ana', 'POST', 'logout', []);
        $this->assertSame(401, self::$srv->api('ana', 'GET', 'catalogo')['status']);
    }

    // ---------- Seguridad de datos ----------

    public function testConfigOcultaSmtpAlParticipanteYNoAlAdmin(): void
    {
        self::$srv->login('ana', '2000');
        self::$srv->login('admin', '1000');
        $this->assertArrayNotHasKey('smtp_pass', self::$srv->api('ana', 'GET', 'config')['json']['configuracion']);
        $this->assertArrayNotHasKey('smtp_pass', self::$srv->api('ana', 'GET', 'catalogo')['json']['configuracion']);
        $this->assertArrayHasKey('smtp_pass', self::$srv->api('admin', 'GET', 'config')['json']['configuracion']);
    }

    public function testCorsSoloEnKpiHumildad(): void
    {
        $this->assertArrayNotHasKey('access-control-allow-origin', self::$srv->api('anon', 'GET', 'ping')['cabeceras']);
        $pre = self::$srv->api('anon', 'OPTIONS', 'kpi_humildad');
        $this->assertSame('*', $pre['cabeceras']['access-control-allow-origin'] ?? null);
    }

    public function testKpiHumildadRechazaSinCredencial(): void
    {
        $this->assertSame(401, self::$srv->api('anon', 'GET', 'kpi_humildad&ci=2000')['status']);
    }

    public function testVerificarCertificado(): void
    {
        $this->assertSame(400, self::$srv->api('anon', 'GET', 'verificar_certificado')['status']);
        $this->assertSame(404, self::$srv->api('anon', 'GET', 'verificar_certificado&codigo=NOEXISTE')['status']);

        self::$conn->query("INSERT INTO usuario_certificados_curso (usuario_id, curso_id) VALUES ('2000','C2')");
        $codigo = db_generar_codigo_certificado('2000', 'C2');
        $r = self::$srv->api('anon', 'GET', "verificar_certificado&codigo=$codigo");
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['valido']);
    }

    // ---------- Flujos completos ----------

    public function testFlujoReprobadoBloqueadoHabilitadoYAprobado(): void
    {
        self::$srv->login('ana', '2000');
        self::$srv->login('admin', '1000');
        $evaluar = fn($opcion) => self::$srv->api('ana', 'POST', 'evaluar_modulo', ['curso_id' => 'C1', 'modulo_idx' => 0, 'respuestas' => [0 => $opcion]]);

        $this->assertSame(400, $evaluar(0)['status'], 'bloqueado con 2 de 2 intentos');

        $h = self::$srv->api('admin', 'POST', 'habilitar_reintento', ['usuario_id' => '2000', 'curso_id' => 'C1', 'modulo_idx' => 0, 'intentos' => 1, 'motivo' => 'API']);
        $this->assertSame(200, $h['status']);
        $this->assertSame(3, $h['json']['maxIntentosEfectivo']);

        $r = $evaluar(0);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['json']['aprobado']);
        $this->assertSame(3, $r['json']['numIntentos']);

        $rep = self::$srv->api('admin', 'GET', 'reporte_evaluaciones&modo=plana&usuario=2000&curso=C1');
        $this->assertSame(200, $rep['status']);
        $this->assertSame(1, $rep['json']['total']);
        $fila = $rep['json']['evaluaciones'][0];
        $this->assertSame(3, $fila['intentos']);
        $this->assertTrue($fila['aprobado']);
        $this->assertSame(2, $fila['vecesReprobado'], 'las reprobaciones previas se conservan');
        $this->assertCount(1, $fila['habilitaciones']);
    }

    public function testParticipanteNoPuedeColarEvaluacionesNiAprobarModulosConPreguntas(): void
    {
        self::$srv->login('ana', '2000');
        $r = self::$srv->api('ana', 'POST', 'guardar_progreso', [
            'usuario_id' => '2000', 'curso_id' => 'C1',
            'leccionesCompletadas' => ['1-0'], 'modulosAprobados' => ['0', '1'],
            'evaluaciones' => ['0' => ['calificacion' => 100, 'aprobado' => true]], 'intentos' => ['0' => 0],
        ]);
        $this->assertSame(200, $r['status']);

        $p = $this->progreso('2000', 'C1');
        $this->assertSame(['1'], $p['modulosAprobados'], 'solo el módulo sin preguntas');
        $this->assertFalse($p['evaluaciones']['0']['aprobado']);
        $this->assertSame(2, $p['intentos']['0']);
        $this->assertContains('1-0', $p['leccionesCompletadas']);
    }

    public function testCatalogoDelParticipanteIncluyeSuProgreso(): void
    {
        self::$srv->login('ana', '2000');
        $r = self::$srv->api('ana', 'GET', 'catalogo');
        $this->assertSame(200, $r['status']);
        $yo = self::buscarUsuario($r['json'], '2000');
        $this->assertNotNull($yo);
        $this->assertSame(2, $yo['progreso']['C1']['intentos']['0']);
    }

    public function testAdminGestionaUsuariosYSolicitudes(): void
    {
        self::$srv->login('admin', '1000');
        $this->assertSame(200, self::$srv->api('admin', 'POST', 'guardar_usuario', ['id' => '5000', 'nombre' => 'Nuevo', 'clave' => 'abc', 'rol' => 'asesor', 'estado' => 'activo'])['status']);
        $this->assertSame('1', $this->valor("SELECT COUNT(*) FROM usuarios WHERE id='5000'"));

        // La búsqueda también cubre el rol; el orden es alfabético por nombre
        $busq = self::$srv->api('admin', 'GET', 'usuarios_paginados&search=asesor');
        $this->assertSame(['Ana Pérez', 'Beto', 'Nuevo'], array_column($busq['json']['usuarios'], 'nombre'));

        // El tamaño de página se limita a 5..100: limit=2 se convierte en 5
        $pag = self::$srv->api('admin', 'GET', 'usuarios_paginados&page=1&limit=2');
        $this->assertSame(200, $pag['status']);
        $this->assertSame(5, $pag['json']['limit']);
        $this->assertCount(4, $pag['json']['usuarios']);

        $this->assertSame(200, self::$srv->api('admin', 'POST', 'eliminar_solicitud_registro', ['id' => '4000'])['status']);
        $this->assertSame('0', $this->valor("SELECT COUNT(*) FROM solicitudes_registro"));

        $this->assertSame(200, self::$srv->api('admin', 'POST', 'eliminar_usuario', ['id' => '3000'])['status']);
        $this->assertSame('0', $this->valor("SELECT COUNT(*) FROM usuarios WHERE id='3000'"));
    }

    // ---------- Carga ligera del panel y progreso bajo demanda ----------

    public function testPanelLigeroYProgresoBajoDemanda(): void
    {
        self::$srv->login('admin', '1000');
        $db = self::$srv->api('admin', 'GET', 'db&ligero=1');
        $this->assertSame(200, $db['status']);
        $this->assertFalse($db['json']['progresoIncluido']);
        $this->assertSame([], self::buscarUsuario($db['json'], '2000')['progreso']);

        $todos = self::$srv->api('admin', 'GET', 'progreso_usuarios');
        $this->assertSame(200, $todos['status']);
        $this->assertSame(2, $todos['json']['progreso']['2000']['C1']['intentos']['0']);

        $uno = self::$srv->api('admin', 'POST', 'progreso_usuarios', ['ids' => ['2000', '3000']]);
        $this->assertSame(200, $uno['status']);
        $this->assertSame(['2000'], array_map('strval', array_keys($uno['json']['progreso'])), 'Beto no tiene progreso');

        $this->assertSame(400, self::$srv->api('admin', 'POST', 'progreso_usuarios', ['ids' => []])['status']);
    }
}
