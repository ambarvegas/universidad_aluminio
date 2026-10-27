// Utilidades globales de js/core/state.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, esperar } from './entorno.mjs';

const ctx = cargarScripts(['js/core/state.js']);

test('escapeHtml neutraliza HTML y comillas', () => {
    assert.equal(ctx.escapeHtml(`<img src=x onerror="a('b')">&`), '&lt;img src=x onerror=&quot;a(&#39;b&#39;)&quot;&gt;&amp;');
    assert.equal(ctx.escapeHtml(null), '');
    assert.equal(ctx.escapeHtml(0), '0');
});

test('escapeAttr es alias de escapeHtml', () => {
    assert.equal(ctx.escapeAttr, ctx.escapeHtml);
});

test('debounce ejecuta una sola vez tras la última llamada', async () => {
    let n = 0;
    const f = ctx.debounce(() => n++, 30);
    f(); f(); f();
    assert.equal(n, 0);
    await esperar(60);
    assert.equal(n, 1);
});

test('extraerID obtiene el ID de YouTube y tolera vacíos', () => {
    assert.equal(ctx.extraerID('https://www.youtube.com/watch?v=dQw4w9WgXcQ'), 'dQw4w9WgXcQ');
    assert.equal(ctx.extraerID('https://youtu.be/dQw4w9WgXcQ'), 'dQw4w9WgXcQ');
    assert.equal(ctx.extraerID(null), '');
});

test('normalizar quita acentos y pasa a minúsculas', () => {
    assert.equal(ctx.normalizar('José PÉREZ'), 'jose perez');
});

test('carrerasDelRol sale de la configuración del rol (ya no de un mapa fijo)', () => {
    const c = cargarScripts(['js/core/state.js']);
    c.rolesConfig = [{ id: 'retail', carreras: ['CAR-RETAIL', 'CAR-BORRADA'] }, { id: 'asesor_ventas', carreras: [] }];
    c.carreras = [{ id: 'CAR-RETAIL', cursos: ['C1'] }, { id: 'CAR-ASESOR-VENTAS', cursos: ['C2'] }];
    assert.deepEqual([...c.carrerasDelRol('retail')], ['CAR-RETAIL'], 'omite carreras que no existen');
    assert.deepEqual([...c.carrerasDelRol('asesor_ventas')], [], 'sin configuración no hay carrera, aunque el mapa viejo la diera');
    assert.equal(c.getCareerIdFromRole, undefined);

    const u = { id: 'U', rol: 'retail', progreso: {}, carrerasAsignadas: [] };
    c.actualizarEstadoCarrerasUsuario(u);
    assert.deepEqual(u.carrerasAsignadas.map(ca => ca.id), ['CAR-RETAIL']);
});
