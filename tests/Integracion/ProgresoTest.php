<?php

/** Guardado de progreso, evaluación en servidor y restablecimiento (tablas normalizadas). */
final class ProgresoTest extends BaseDatosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
    }

    public function testInstalacionNuevaNoCreaLaTablaJsonAntigua(): void
    {
        $this->assertSame(0, self::$conn->query("SHOW TABLES LIKE 'usuario_progreso'")->num_rows);
    }

    public function testGuardarProgresoEsAditivo(): void
    {
        db_upsert_progreso(self::$conn, '3000', 'C1', ['leccionesCompletadas' => ['0-0']]);
        db_upsert_progreso(self::$conn, '3000', 'C1', ['leccionesCompletadas' => ['0-1'], 'modulosAprobados' => ['1'], 'medallas' => ['1']]);

        $p = $this->progreso('3000', 'C1');
        sort($p['leccionesCompletadas']);
        $this->assertSame(['0-0', '0-1'], $p['leccionesCompletadas']);
        $this->assertSame(['1'], $p['modulosAprobados']);
        $this->assertSame([], $p['evaluaciones']);
    }

    public function testReenviarEvaluacionSinFechaConservaLaFechaOriginal(): void
    {
        db_upsert_progreso(self::$conn, '2000', 'C1', ['evaluaciones' => ['0' => ['calificacion' => 50, 'aprobado' => false]]]);
        $p = $this->progreso('2000', 'C1');
        $this->assertSame('2026-10-01T10:00:00', $p['evaluaciones']['0']['fecha']);
        $this->assertEquals(50, $p['evaluaciones']['0']['calificacion']);
    }

    public function testEvaluarReprobadoSumaIntentoYDevuelveProgresoCompleto(): void
    {
        $r = db_evaluar_modulo(self::$conn, '2000', 'C2', 0, [0 => 1]);
        $p = json_decode(json_encode($r['progreso']), true);

        $this->assertFalse($r['aprobado']);
        $this->assertSame(1, $r['numIntentos']);
        $this->assertSame(1, $p['intentos']['0']);
        $this->assertFalse($p['evaluaciones']['0']['aprobado']);
    }

    public function testEvaluarAprobadoRegistraModuloMedallaYCertificado(): void
    {
        $r = db_evaluar_modulo(self::$conn, '2000', 'C2', 0, [0 => 0]);

        $this->assertTrue($r['aprobado']);
        $this->assertTrue($r['certificadoOtorgado']);
        $p = $this->progreso('2000', 'C2');
        $this->assertSame(['0'], $p['modulosAprobados']);
        $this->assertSame(['0'], $p['medallas']);
        $this->assertSame('1', $this->valor("SELECT COUNT(*) FROM usuario_certificados_curso WHERE usuario_id='2000' AND curso_id='C2'"));
    }

    public function testEvaluarConIntentosAgotadosEsRechazado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('límite máximo de 2 intentos');
        db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 0]);
    }

    public function testRestablecerModuloRevocaCertificadoSoloSiNoQuedanModulosAprobados(): void
    {
        db_upsert_progreso(self::$conn, '3000', 'C1', ['modulosAprobados' => ['0', '1'], 'leccionesCompletadas' => ['0-0', '1-0']]);
        self::$conn->query("INSERT INTO usuario_certificados_curso (usuario_id, curso_id) VALUES ('3000','C1')");

        db_restablecer_progreso(self::$conn, '3000', 'C1', [0]);
        $p = $this->progreso('3000', 'C1');
        $this->assertSame(['1'], $p['modulosAprobados']);
        $this->assertSame(['1-0'], $p['leccionesCompletadas']);
        $this->assertSame('1', $this->valor("SELECT COUNT(*) FROM usuario_certificados_curso WHERE usuario_id='3000'"));

        db_restablecer_progreso(self::$conn, '3000', 'C1', [1]);
        $this->assertSame('0', $this->valor("SELECT COUNT(*) FROM usuario_certificados_curso WHERE usuario_id='3000'"));
    }

    public function testRestablecerCursoCompletoBorraTodoElAvance(): void
    {
        db_restablecer_progreso(self::$conn, '2000', 'C1');
        $this->assertNull($this->progreso('2000', 'C1'));
    }

    public function testEliminarUsuarioBorraSuProgreso(): void
    {
        db_delete_usuario(self::$conn, '2000');
        $this->assertSame([], db_leer_progreso(self::$conn, '2000'));
    }
}
