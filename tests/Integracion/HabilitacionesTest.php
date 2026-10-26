<?php

/**
 * Reintentos habilitados por administración sobre evaluaciones reprobadas.
 * En los datos base, Ana tiene C1/M0 reprobado con 2 de 2 intentos (bloqueado).
 */
final class HabilitacionesTest extends BaseDatosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
    }

    public function testHabilitarConservaIntentosYCalificacionPrevios(): void
    {
        $h = db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, 'Refuerzo', '1000');

        $this->assertSame(2, $h['intentos']);
        $this->assertSame(1, $h['intentosExtra']);
        $this->assertSame(3, $h['maxIntentosEfectivo']);
        $p = $this->progreso('2000', 'C1');
        $this->assertSame(2, $p['intentos']['0'], 'el contador histórico no se reinicia');
        $this->assertFalse($p['evaluaciones']['0']['aprobado'], 'la reprobación queda registrada');
        $this->assertSame('Refuerzo', $p['habilitaciones']['0'][0]['motivo']);
    }

    public function testRepasarLeccionesReabreSoloLasDelModulo(): void
    {
        db_upsert_progreso(self::$conn, '2000', 'C1', ['leccionesCompletadas' => ['1-0']]);
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, true, '', '1000');

        $this->assertSame(['1-0'], $this->progreso('2000', 'C1')['leccionesCompletadas']);
    }

    public function testElReintentoHabilitadoPermiteEvaluarYLuegoVuelveABloquear(): void
    {
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, '', '1000');

        $r = db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 1]);
        $this->assertSame(3, $r['numIntentos']);
        $this->assertSame(3, $r['maxIntentos']);
        $this->assertSame(2, $r['maxIntentosBase']);
        $this->assertTrue($r['bloqueado']);

        $this->expectException(InvalidArgumentException::class);
        db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 0]);
    }

    public function testApruebaConReintentoYElHistorialQuedaIntacto(): void
    {
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 2, false, '', '1000');
        $r = db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 0]);

        $this->assertTrue($r['aprobado']);
        $this->assertSame(3, $r['numIntentos']);
    }

    public function testNoSeHabilitaUnModuloAprobado(): void
    {
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, '', '1000');
        db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 0]);

        $this->expectExceptionMessage('ya está aprobado');
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, '', '1000');
    }

    public function testNoSeHabilitaSinIntentosPreviosNiEnModuloInexistente(): void
    {
        try {
            db_habilitar_reintento(self::$conn, '3000', 'C1', 0, 1, false, '', '1000');
            $this->fail('Debió rechazar: sin intentos previos');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no registra intentos', $e->getMessage());
        }
        $this->expectExceptionMessage('Módulo no encontrado');
        db_habilitar_reintento(self::$conn, '2000', 'C1', 9, 1, false, '', '1000');
    }

    public function testRestablecerDesactivaHabilitacionesPeroConservaElHistorial(): void
    {
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, '', '1000');
        db_restablecer_progreso(self::$conn, '2000', 'C1', [0]);

        $this->assertSame('1', $this->valor("SELECT COUNT(*) FROM usuario_habilitaciones WHERE usuario_id='2000'"));
        $this->assertSame(0, db_intentos_extra(self::$conn, '2000', 'C1', '0'));
    }

    public function testIntentosOtorgadosSeLimitanEntre1Y10(): void
    {
        $this->assertSame(10, db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 50, false, '', '1000')['intentosExtra']);
    }
}
