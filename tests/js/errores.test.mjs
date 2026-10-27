// Captura de errores del navegador (js/core/errores.js): qué se envía al servidor y qué se ignora.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const ORIGEN = 'https://aluminio.test';

function preparar() {
    const manejadores = {};
    const enviados = [];
    const ctx = {
        URL, JSON, String, Set, Error,
        location: { href: ORIGEN + '/universidad/index.php?x=1', origin: ORIGEN, pathname: '/universidad/index.php' },
        addEventListener: (tipo, fn) => { manejadores[tipo] = fn; },
        fetch: (url, opciones) => { enviados.push({ endpoint: url, ...JSON.parse(opciones.body) }); return Promise.resolve(); },
        APP_CLIENT_VERSION: '2026.test',
    };
    ctx.window = ctx;
    vm.createContext(ctx);
    vm.runInContext(fs.readFileSync(path.join(RAIZ, 'js/core/errores.js'), 'utf8'), ctx);
    return { ctx, manejadores, enviados };
}

const errorDe = (mensaje, archivo, error = null) => ({ message: mensaje, filename: archivo, lineno: 10, colno: 5, error });

test('envía los errores de scripts propios con ruta, versión y pila', () => {
    const { manejadores, enviados } = preparar();
    const err = new TypeError('x is undefined');
    manejadores.error(errorDe('Uncaught TypeError: x is undefined', ORIGEN + '/universidad/js/campus/player.js?v=1', err));

    assert.equal(enviados.length, 1);
    const e = enviados[0];
    assert.equal(e.endpoint, 'api.php?action=registrar_error');
    assert.equal(e.url, '/universidad/index.php', 'solo la ruta, sin parámetros');
    assert.equal(e.mensaje, 'TypeError: x is undefined');
    assert.equal(e.version, '2026.test');
    assert.ok(e.detalle.startsWith('/universidad/js/campus/player.js?v=1:10:5'));
    assert.ok(e.detalle.includes('TypeError'), 'incluye la pila');
});

test('ignora extensiones, otros dominios, "Script error." y fallos de carga de recursos', () => {
    const { manejadores, enviados } = preparar();
    manejadores.error(errorDe('boom', 'chrome-extension://abc/content.js'));
    manejadores.error(errorDe('boom', 'https://cdn.jsdelivr.net/npm/bootstrap.js'));
    manejadores.error(errorDe('Script error.', ''));
    manejadores.error(errorDe('ResizeObserver loop limit exceeded', ORIGEN + '/a.js'));
    manejadores.error({ message: '', error: null, filename: '' });
    assert.equal(enviados.length, 0);
});

test('promesas rechazadas, sin repetir y con un máximo por página', () => {
    const { manejadores, enviados } = preparar();
    manejadores.unhandledrejection({ reason: new Error('falló la carga') });
    manejadores.unhandledrejection({ reason: new Error('falló la carga') });
    assert.equal(enviados.length, 1);
    assert.equal(enviados[0].mensaje, 'Promesa rechazada sin capturar — Error: falló la carga');

    for (let i = 0; i < 20; i++) manejadores.unhandledrejection({ reason: `motivo ${i}` });
    assert.equal(enviados.length, 10);
});

test('reportarError a mano y sin romper si fetch falla', () => {
    const { ctx, enviados } = preparar();
    ctx.reportarError(new Error('guardando curso'), 'admin-courses');
    assert.equal(enviados[0].mensaje, 'admin-courses — Error: guardando curso');
    ctx.fetch = () => { throw new Error('sin red'); };
    assert.doesNotThrow(() => ctx.reportarError(new Error('otro')));
});
