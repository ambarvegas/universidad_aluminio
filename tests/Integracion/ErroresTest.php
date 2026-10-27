<?php

/** Registro de errores (lib/errores.php). */
final class ErroresTest extends BaseDatosTestCase
{
    private function error(array $extra = []): array
    {
        return array_merge(['origen' => 'js', 'mensaje' => 'TypeError: x is undefined', 'detalle' => "/js/a.js:1:2\nstack",
                            'ubicacion' => '/index.php', 'ip' => '10.0.0.1'], $extra);
    }

    private function filas(): array
    {
        return self::$conn->query('SELECT * FROM errores_log ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    }

    public function testAgrupaLasRepeticionesDelMismoError(): void
    {
        $this->assertTrue(db_registrar_error(self::$conn, $this->error(['usuario_id' => '2000'])));
        $this->assertTrue(db_registrar_error(self::$conn, $this->error(['usuario_id' => '3000'])));
        db_registrar_error(self::$conn, $this->error(['mensaje' => 'Otro error']));

        $filas = $this->filas();
        $this->assertCount(2, $filas);
        $this->assertSame('2', $filas[0]['veces']);
        $this->assertSame('3000', $filas[0]['usuario_id'], 'queda el último usuario afectado');
    }

    public function testLimitaLosErroresNuevosPorIp(): void
    {
        for ($i = 0; $i < ERRORES_MAX_POR_IP; $i++) {
            $this->assertTrue(db_registrar_error(self::$conn, $this->error(['mensaje' => "error $i"])));
        }
        $this->assertFalse(db_registrar_error(self::$conn, $this->error(['mensaje' => 'uno más'])), 'descartado');
        $this->assertTrue(db_registrar_error(self::$conn, $this->error(['mensaje' => 'error 0'])), 'repetir uno existente sí suma');
        $this->assertTrue(db_registrar_error(self::$conn, $this->error(['mensaje' => 'uno más', 'ip' => '10.0.0.2'])), 'otra IP sí entra');
        $this->assertCount(ERRORES_MAX_POR_IP + 1, $this->filas());
    }

    public function testRecortaTextosYQuitaParametrosDeLaUrl(): void
    {
        db_registrar_error(self::$conn, $this->error(['mensaje' => str_repeat('ñ', 600) . "\x07", 'origen' => 'otro']));
        $fila = $this->filas()[0];
        $this->assertSame(500, mb_strlen($fila['mensaje']));
        $this->assertSame('js', $fila['origen'], 'origen desconocido se guarda como js');
        $this->assertSame('/universidad/recuperar.php', errores_ruta('https://x.y/universidad/recuperar.php?token=SECRETO#a'));
        $this->assertSame('', errores_ruta('javascript:alert(1)'));
    }

    public function testRegistraExcepcionesConRutaRelativaYNuncaLanza(): void
    {
        $e = new RuntimeException('falló algo');
        db_registrar_excepcion(self::$conn, $e, ['ubicacion' => 'api:guardar_curso', 'usuario_id' => '1000']);
        $fila = $this->filas()[0];
        $this->assertSame('php', $fila['origen']);
        $this->assertSame('RuntimeException: falló algo', $fila['mensaje']);
        $this->assertStringStartsWith('tests/Integracion/ErroresTest.php:', $fila['detalle']);
        $this->assertStringNotContainsString(str_replace('\\', '/', APP_ROOT), str_replace('\\', '/', $fila['detalle']));
        $this->assertSame('', $fila['ip'], 'los errores del servidor no consumen el límite por IP');

        // Sin la tabla (migración aún no aplicada) no debe romper la respuesta
        self::$conn->query('DROP TABLE errores_log');
        db_registrar_excepcion(self::$conn, $e);
        $this->addToAssertionCount(1);
    }

    public function testPurgaLosAntiguosYLimpiaPorIdsOTodos(): void
    {
        db_registrar_error(self::$conn, $this->error(['mensaje' => 'viejo']));
        db_registrar_error(self::$conn, $this->error(['mensaje' => 'a']));
        db_registrar_error(self::$conn, $this->error(['mensaje' => 'b']));
        self::$conn->query("UPDATE errores_log SET ultima_vez = NOW() - INTERVAL 40 DAY WHERE mensaje = 'viejo'");
        db_errores_purgar(self::$conn);
        $this->assertSame(['a', 'b'], array_column($this->filas(), 'mensaje'));

        $res = db_errores_recientes(self::$conn);
        $this->assertSame(2, $res['total']);
        $this->assertSame(2, $res['ultimas24h']);
        $this->assertSame(1, db_errores_limpiar(self::$conn, [$res['errores'][0]['id'], 999]));
        $this->assertSame(1, db_errores_limpiar(self::$conn));
        $this->assertSame([], $this->filas());
    }

    public function testElDiagnosticoAvisaDeErroresRecientes(): void
    {
        $this->assertSame(0, db_get_health_status(self::$conn)['database']['errores_24h']);
        db_registrar_error(self::$conn, $this->error());
        $salud = db_get_health_status(self::$conn);
        $this->assertSame(1, $salud['database']['errores_24h']);
        $this->assertNotEmpty(array_filter($salud['warnings'], fn($w) => str_contains($w, 'error(es)')));
    }
}
