// Tablas del panel de administración (js/admin/admin-dashboard.js)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar, esperar } from './entorno.mjs';

const sinAcentos = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

function preparar() {
    const ctx = cargarScripts(['js/core/state.js', 'js/admin/admin-dashboard.js']);
    const todos = Array.from({ length: 60 }, (_, i) => ({
        id: 'U' + i, nombre: i === 0 ? '<img src=x onerror=alert(1)>' : 'José Pérez ' + i,
        rol: 'asesor_ventas', carrerasAsignadas: [{ id: 'CAR1', estado: 'Completada' }],
    }));
    const pedidos = [];
    // Simula usuarios_paginados: búsqueda por nombre/id/rol, tamaño 5..100 y página fija
    ctx.API = {
        async cargarUsuariosPaginados({ page, limit, search }) {
            pedidos.push({ page, limit, search });
            const q = sinAcentos(search || '');
            const lista = todos.filter(u => !q || [u.nombre, u.id, u.rol].some(v => sinAcentos(v).includes(q)));
            const lim = Math.max(5, Math.min(100, limit));
            const totalPages = Math.max(1, Math.ceil(lista.length / lim));
            return { usuarios: lista.slice((page - 1) * lim, page * lim), total: lista.length, page, limit: lim, totalPages };
        },
    };
    ejecutar(ctx, `
        cursos = [{ id: "C'1", titulo: 'Seguridad <b>', modulos: [{ lecciones: [1, 2] }], prelacion: 'X' }];
        carreras = [{ id: 'CAR1', nombre: 'Ventas' }];
    `);
    ctx.pedidos = pedidos;
    return ctx;
}

test('los datos de usuario se escapan (sin XSS)', async () => {
    const ctx = preparar();
    await ctx.renderTablaUsuariosAdmin();
    const html = ctx.elementos['tabla-usuarios-body'].innerHTML;
    assert.ok(!html.includes('<img src=x'));
    assert.ok(html.includes('&lt;img src=x'));
});

test('los IDs en onclick se pasan como string JSON escapado', () => {
    const ctx = preparar();
    ctx.actualizarTablas();
    assert.ok(ctx.elementos['tabla-cursos-body'].innerHTML.includes('abrirEditor(&quot;C&#39;1&quot;)'));
});

test('usuarios paginados en el servidor: 25 por página y cambio de página', async () => {
    const ctx = preparar();
    await ctx.renderTablaUsuariosAdmin();
    assert.equal((ctx.elementos['tabla-usuarios-body'].innerHTML.match(/<tr>/g) || []).length, 25);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 1 - 25 de 60 colaboradores');

    await ctx.cambiarPaginaUsuarios(3);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 51 - 60 de 60 colaboradores');
    assert.deepEqual({ ...ctx.pedidos.at(-1) }, { page: 3, limit: 25, search: '' });
});

test('la búsqueda se envía al servidor con debounce y vuelve a la página 1', async () => {
    const ctx = preparar();
    await ctx.cambiarPaginaUsuarios(2);
    const antes = ctx.pedidos.length;
    ctx.elementos['search-admin-usuarios'].value = 'jose';
    ctx.filtrarTablaUsuariosAdmin();
    ctx.filtrarTablaUsuariosAdmin();
    assert.equal(ctx.pedidos.length, antes, 'aún no consultó');
    await esperar(400);
    assert.equal(ctx.pedidos.length, antes + 1, 'una sola consulta tras dejar de escribir');
    assert.deepEqual({ ...ctx.pedidos.at(-1) }, { page: 1, limit: 25, search: 'jose' });
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 1 - 25 de 59 colaboradores');
});

test('una página fuera de rango se corrige a la última', async () => {
    const ctx = preparar();
    await ctx.cambiarPaginaUsuarios(9);
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 51 - 60 de 60 colaboradores');
});

test('una respuesta vieja no pisa a la más reciente', async () => {
    const ctx = preparar();
    const original = ctx.API.cargarUsuariosPaginados;
    ctx.API.cargarUsuariosPaginados = async (p) => {
        if (p.page === 1) await esperar(50);
        return original(p);
    };
    const lenta = ctx.cambiarPaginaUsuarios(1);
    await ctx.cambiarPaginaUsuarios(2);
    await lenta;
    assert.equal(ctx.elementos['usuarios-info-paginacion'].textContent, 'Mostrando 26 - 50 de 60 colaboradores');
});

test('búsqueda de cursos sin resultados muestra el término escapado', async () => {
    const ctx = preparar();
    ctx.document.getElementById('search-admin-cursos').value = '<script>';
    ctx.filtrarTablaCursosAdmin();
    await esperar(250);
    const html = ctx.elementos['tabla-cursos-body'].innerHTML;
    assert.ok(html.includes('No se encontraron cursos'));
    assert.ok(html.includes('&lt;script&gt;'));
});

test('el panel se redibuja solo al recibir datos del servidor', async () => {
    const ctx = preparar();
    ctx.App.cargarDatos({ usuarios: [{ id: 'A' }, { id: 'B' }], cursos: [], carreras: [], rolesConfig: [], solicitudesRegistro: [], solicitudesCursos: [] });
    assert.equal(ctx.elementos['admin-kpi-usuarios'].textContent, 2);
    await esperar(0);
    assert.ok(ctx.pedidos.length >= 1, 'pidió la tabla de usuarios al servidor');
});
