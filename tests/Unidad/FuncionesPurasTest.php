<?php

use PHPUnit\Framework\TestCase;

/** Funciones sin acceso a base de datos. */
final class FuncionesPurasTest extends TestCase
{
    /**
     * Los códigos de certificado ya emitidos están impresos en PDFs y QR: si cambia CERT_SALT
     * o el algoritmo, todos dejan de verificarse. Esta prueba fija valores conocidos.
     */
    public function testCodigoDeCertificadoEsEstable(): void
    {
        $this->assertSame('ALU-CUR-5F5CDAFEA5', db_generar_codigo_certificado('2000', 'C1'));
        $this->assertSame('ALU-CAR-00A89886BB', db_generar_codigo_certificado('2000', 'CAR-1', 'carrera'));
    }

    public function testCodigoDeCertificadoIgnoraEspaciosYMayusculasDelTipo(): void
    {
        $this->assertSame(db_generar_codigo_certificado('2000', 'C1'), db_generar_codigo_certificado(' 2000 ', ' C1 ', 'CURSO'));
    }

    public function testConfigPublicaQuitaCredencialesSmtpYEmailAdmin(): void
    {
        $publica = db_config_publica([
            'logo' => 'x.png', 'minAprobacion' => 70,
            'smtp_host' => 'mail', 'smtp_user' => 'u', 'smtp_pass' => 'secreto', 'email_admin' => 'a@b.c',
        ]);
        $this->assertSame(['logo' => 'x.png', 'minAprobacion' => 70], $publica);
    }

    public function testConfigPublicaAceptaObjetos(): void
    {
        $this->assertSame(['logo' => 'x'], db_config_publica((object)['logo' => 'x', 'smtp_pass' => 's']));
    }

    public function testFormatoDeBytes(): void
    {
        $this->assertSame('0 B', db_format_bytes(0));
        $this->assertSame('1.5 KB', db_format_bytes(1536));
        $this->assertSame('5 MB', db_format_bytes(5 * 1024 * 1024));
    }

    public function testAplicarHabilitacionesSoloACursosConProgreso(): void
    {
        $progreso = ['C1' => ['intentos' => (object)['0' => 2]]];
        $mapa = [
            'C1' => ['intentosExtra' => ['0' => 1], 'habilitaciones' => ['0' => [['intentosOtorgados' => 1]]]],
            'C9' => ['intentosExtra' => ['0' => 5], 'habilitaciones' => []],
        ];
        $res = json_decode(json_encode(db_habilitaciones_aplicar_a_progreso($progreso, $mapa)), true);
        $this->assertSame(['0' => 1], $res['C1']['intentosExtra']);
        $this->assertArrayNotHasKey('C9', $res, 'no se crea progreso para cursos sin avance');
    }
}
