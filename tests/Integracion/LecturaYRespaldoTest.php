<?php

/** Lectores de datos (admin, login, campus) e importación de respaldos. */
final class LecturaYRespaldoTest extends BaseDatosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, 'motivo', '1000');
    }

    public function testAdminLoginYCampusDevuelvenElMismoProgreso(): void
    {
        $all = json_decode(json_encode(db_read_all(self::$conn)), true);
        $admin = self::buscarUsuario($all['usuarios'], '2000');
        $campus = self::buscarUsuario(json_decode(json_encode(db_read_catalogo(self::$conn, '2000', 'asesor')), true), '2000');
        $login = json_decode(json_encode(db_verify_login(self::$conn, '2000', 'x')), true);

        $this->assertNotNull($campus);
        $this->assertSame($admin['progreso'], $campus['progreso']);
        $this->assertSame($admin['progreso'], $login['progreso']);
        $this->assertSame(1, $admin['progreso']['C1']['intentosExtra']['0']);
    }

    public function testProgresoVacioSeSerializaComoObjetoJson(): void
    {
        $json = json_encode(db_read_all(self::$conn)['usuarios']);
        $this->assertStringContainsString('"id":"3000"', $json);
        $this->assertMatchesRegularExpression('/"id":"3000".*?"progreso":\{\}/s', $json);
    }

    public function testParticipanteYCampusNoRecibenCredencialesSmtp(): void
    {
        $part = db_read_for_participant(self::$conn, '2000', 'asesor');
        $cat = db_read_catalogo(self::$conn, '2000', 'asesor');
        foreach ([$part['configuracion'], $cat['configuracion']] as $cfg) {
            $this->assertArrayNotHasKey('smtp_pass', (array)$cfg);
            $this->assertArrayHasKey('nombreInstitucion', (array)$cfg);
        }
        $this->assertArrayHasKey('smtp_pass', db_read_safe(self::$conn)['configuracion'], 'el admin sí la recibe');
    }

    public function testCargaAdminNoExponeClaves(): void
    {
        foreach (db_read_safe(self::$conn)['usuarios'] as $u) {
            $this->assertArrayNotHasKey('clave', $u);
        }
    }

    public function testImportarRespaldoSinProgresoNoBorraElExistente(): void
    {
        $data = db_read_all(self::$conn);
        foreach ($data['usuarios'] as &$u) {
            if ($u['id'] === '2000') $u['progreso'] = [];
        }
        unset($u);

        db_write_all(self::$conn, $data);

        $p = $this->progreso('2000', 'C1');
        sort($p['leccionesCompletadas']);
        $this->assertSame(['0-0', '0-1'], $p['leccionesCompletadas']);
        $this->assertSame(2, $p['intentos']['0']);
    }

    public function testImportarRespaldoConservaLaMejorCalificacion(): void
    {
        $data = db_read_all(self::$conn);
        foreach ($data['usuarios'] as &$u) {
            if ($u['id'] === '2000') {
                $u['progreso'] = ['C1' => ['evaluaciones' => ['0' => ['calificacion' => 80, 'aprobado' => true]]]];
            }
        }
        unset($u);

        db_write_all(self::$conn, $data);

        $this->assertEquals(80, $this->progreso('2000', 'C1')['evaluaciones']['0']['calificacion']);
    }
}
