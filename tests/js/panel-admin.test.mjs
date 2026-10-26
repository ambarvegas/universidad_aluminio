// Tablas del panel de administración (js/admin/admin-dashboard.js)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar, esperar } from './entorno.mjs';

function preparar() {
    const ctx = cargarScripts(['js/core/state.js', 'js/admin/admin-dashboard.js']);
    ejecutar(ctx, `
        usuarios = window.usuarios = Array.from({ length: 60 }, (_, i) => ({
            id: 'U' + i, nombre: i === 0 ? '<img src=x onerror=alert(1)>' : 'José Pérez ' + i,
            rol: 'asesor_ventas', carrerasAsignadas: [{ id: 'CAR1', estado: 'Completada' }],
        }));
        cursos = window.cursos = [{ id: "C'1", titulo: 'Seguridad <b>', modulos: [{ lecciones: [1, 2] }], prelacion: 'X' }];
        carreras = window.carreras = [{ id: 'CAR1', nombre: 'Ventas' }];
        rolesConfig = window.rolesConfig = [];
        solicitudesRegistro = window.solicitudesRegistro = [];
        solicitudesCursos = window.solicitudesCursos = [];
    `);
    ctx.actualizarTablas();
    return ctx;
}

test('los datos de usuario se escapan (sin XSS)', () => {
    const { elementos } = preparar();
    const html = elementos['tabla-usuarios-body'].innerHTML;
    assert.ok(!html.includes('<img src=x'));
    assert.ok(html.includes('&lt;img src=x'));
});

test('los IDs en onclick se pasan como string JSON escapado', () => {
    const { elementos } = preparar();
    assert.ok(elementos['tabla-cursos-body'].innerHTML.includes('abrirEditor(&quot;C&#39;1&quot;)'));
});

test('paginación de usuarios: 25 por página', () => {
    const ctx = preparar();
    assert.equal((ctx.elementos['tabla-usuarios-body'].innerHTML.match(/<tr>/g) || []).length, 25);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 1 - 25 de 60 colaboradores');
    ctx.cambiarPaginaUsuarios(3);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 51 - 60 de 60 colaboradores');
});

test('la búsqueda ignora acentos y usa debounce', async () => {
    const ctx = preparar();
    ctx.elementos['search-admin-usuarios'].value = 'jose';
    ctx.filtrarTablaUsuariosAdmin();
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 1 - 25 de 60 colaboradores', 'aún no filtró');
    await esperar(250);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 1 - 25 de 59 colaboradores');
});

test('búsqueda sin resultados muestra el término escapado', async () => {
    const ctx = preparar();
    ctx.elementos['search-admin-cursos'].value = '<script>';
    ctx.filtrarTablaCursosAdmin();
    await esperar(250);
    const html = ctx.elementos['tabla-cursos-body'].innerHTML;
    assert.ok(html.includes('No se encontraron cursos'));
    assert.ok(html.includes('&lt;script&gt;'));
});
