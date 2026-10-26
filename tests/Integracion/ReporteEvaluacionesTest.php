<?php

/**
 * Reporte de evaluaciones calculado, filtrado y paginado en el servidor (lib/reportes.php).
 * Además de los datos base (Ana: C1/M0 reprobado 2 de 2, bloqueado):
 * - Ana tiene C1/M1 aprobado sin evaluación (acreditación manual de Rectoría).
 * - Beto aprobó C2/M0 en línea al tercer intento (90 pts).
 */
final class ReporteEvaluacionesTest extends BaseDatosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
        self::$conn->query("INSERT INTO usuario_modulos_aprobados (usuario_id,curso_id,modulo_num) VALUES ('2000','C1','1'),('3000','C2','0')");
        self::$conn->query("INSERT INTO usuario_evaluaciones (usuario_id,curso_id,modulo_num,calificacion,aprobado,marcado_manual,fecha)
                            VALUES ('3000','C2','0',90,1,0,'2026-10-05 09:00:00')");
        self::$conn->query("INSERT INTO usuario_intentos (usuario_id,curso_id,modulo_num,intentos) VALUES ('3000','C2','0',3)");
    }

    private function fila(array $filas, string $usuario, string $curso, int $modulo): array
    {
        foreach ($filas as $f) {
            if ($f['usuarioId'] === $usuario && $f['cursoId'] === $curso && $f['moduloNum'] === $modulo) return $f;
        }
        $this->fail("Sin fila para $usuario/$curso/$modulo");
    }

    public function testReprobadoSinIntentosQuedaBloqueadoYHabilitable(): void
    {
        $f = $this->fila(db_reporte_evaluaciones_filas(self::$conn), '2000', 'C1', 0);

        $this->assertFalse($f['aprobado']);
        $this->assertSame(2, $f['vecesReprobado']);
        $this->assertSame(2, $f['maxIntentosEfectivo']);
        $this->assertTrue($f['bloqueado']);
        $this->assertTrue($f['puedeHabilitar']);
        $this->assertSame('Módulo 1: M1', $f['moduloTitulo']);
        $this->assertSame('Asesor de Ventas', $f['rolNombre']);
    }

    public function testHabilitarSumaIntentosSinBorrarReprobaciones(): void
    {
        db_habilitar_reintento(self::$conn, '2000', 'C1', 0, 1, false, 'Refuerzo', '1000');
        $f = $this->fila(db_reporte_evaluaciones_filas(self::$conn), '2000', 'C1', 0);

        $this->assertSame(2, $f['vecesReprobado']);
        $this->assertSame(3, $f['maxIntentosEfectivo']);
        $this->assertFalse($f['bloqueado']);
        $this->assertTrue($f['reintentoPendiente']);
        $this->assertCount(1, $f['habilitaciones']);
    }

    public function testModuloAprobadoSinEvaluacionCuentaComoAcreditacionManual(): void
    {
        $f = $this->fila(db_reporte_evaluaciones_filas(self::$conn), '2000', 'C1', 1);

        $this->assertTrue($f['marcadoManual']);
        $this->assertSame(100.0, $f['calificacion']);
        $this->assertSame(0, $f['vecesReprobado'], 'Rectoría no suma reprobaciones');
        $this->assertSame('', $f['fecha']);
    }

    public function testAprobadoAlTercerIntentoCuentaDosReprobaciones(): void
    {
        $f = $this->fila(db_reporte_evaluaciones_filas(self::$conn), '3000', 'C2', 0);

        $this->assertTrue($f['aprobado']);
        $this->assertSame(2, $f['vecesReprobado']);
        $this->assertFalse($f['puedeHabilitar']);
        $this->assertSame(0, $f['maxIntentosEfectivo'], 'intentos ilimitados');
    }

    public function testFiltrosPorEstadoYBusquedaSinAcentos(): void
    {
        $this->assertSame(1, db_reporte_evaluaciones(self::$conn, ['estado' => 'bloqueado'])['total']);
        $this->assertSame(0, db_reporte_evaluaciones(self::$conn, ['estado' => 'habilitado'])['total']);
        $this->assertSame(2, db_reporte_evaluaciones(self::$conn, ['estado' => 'aprobado'])['total']);
        $this->assertSame(1, db_reporte_evaluaciones(self::$conn, ['estado' => 'manual'])['total']);

        $r = db_reporte_evaluaciones(self::$conn, ['search' => 'PEREZ']);
        $this->assertSame(2, $r['total'], '"PEREZ" encuentra a "Ana Pérez"');
        $this->assertSame(1, $r['totalUsuarios']);

        $this->assertSame(1, db_reporte_evaluaciones(self::$conn, ['curso' => 'C2'])['total']);
        $this->assertSame(1, db_reporte_evaluaciones(self::$conn, ['usuario' => '3000'])['total']);
    }

    public function testVistaAgrupadaPaginaPorColaborador(): void
    {
        $p1 = db_reporte_evaluaciones(self::$conn, ['modo' => 'agrupada', 'limit' => 1, 'page' => 1]);
        $p2 = db_reporte_evaluaciones(self::$conn, ['modo' => 'agrupada', 'limit' => 1, 'page' => 2]);

        $this->assertSame(3, $p1['total']);
        $this->assertSame(2, $p1['totalUsuarios']);
        $this->assertSame(2, $p1['paginas']);
        $this->assertSame('Ana Pérez', $p1['grupos'][0]['usuarioNombre'], 'orden A-Z');
        $this->assertCount(2, $p1['grupos'][0]['evaluaciones']);
        $this->assertSame('Beto', $p2['grupos'][0]['usuarioNombre']);
        $this->assertSame([2, 2], [$p2['desde'], $p2['hasta']]);
        $this->assertSame([], $p1['evaluaciones'], 'paginado: sin lista plana');

        // Promedio de Ana: (0 + 100) / 2
        $this->assertSame(50.0, $p1['grupos'][0]['promedio']);
        $this->assertSame(['total' => 3, 'promedio' => 63.3, 'tasaAprob' => 67, 'perfectas' => 1], $p1['kpis']);
    }

    public function testVistaPlanaOrdenadaPorCalificacionYPaginaFueraDeRango(): void
    {
        $r = db_reporte_evaluaciones(self::$conn, ['modo' => 'plana', 'orden' => 'calif_desc', 'limit' => 2, 'page' => 99]);

        $this->assertSame(2, $r['pagina'], 'se ajusta a la última página');
        $this->assertSame([0.0], array_column($r['evaluaciones'], 'calificacion'));
        $this->assertSame([], $r['grupos']);

        $primera = db_reporte_evaluaciones(self::$conn, ['modo' => 'plana', 'orden' => 'calif_desc', 'limit' => 2]);
        $this->assertSame([100.0, 90.0], array_column($primera['evaluaciones'], 'calificacion'));
    }

    public function testExportarDevuelveTodoSinPaginar(): void
    {
        $r = db_reporte_evaluaciones(self::$conn, ['modo' => 'agrupada', 'limit' => 1, 'todo' => '1']);

        $this->assertCount(3, $r['evaluaciones']);
        $this->assertCount(2, $r['grupos']);
        $this->assertSame('all', $r['porPagina']);
    }

    public function testCargaLigeraDelPanelOmiteElProgreso(): void
    {
        $ligero = db_read_safe(self::$conn, false);
        $ana = self::buscarUsuario($ligero, '2000');

        $this->assertFalse($ligero['progresoIncluido']);
        $this->assertEquals((object)[], $ana['progreso']);
        $this->assertArrayNotHasKey('clave', $ana);
        $this->assertSame(['C2'], $ana['asignados'], 'el resto de los datos del usuario se mantiene');

        $completo = db_read_safe(self::$conn);
        $this->assertArrayNotHasKey('progresoIncluido', $completo);
        $this->assertNotEmpty((array)self::buscarUsuario($completo, '2000')['progreso']);
    }
}
