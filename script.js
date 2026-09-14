/**
 * Universidad del Aluminio — Main Orchestrator & Backward Compatibility Layer
 * Coordina la carga inicial, sincronización con el servidor MySQL y enrutamiento del ciclo de vida del frontend.
 */

// ============================================================
// CICLO DE VIDA Y BOOTSTRAP DE LA APLICACIÓN
// ============================================================

document.addEventListener('DOMContentLoaded', async () => {
    try {
        // 1. Cargar datos frescos del backend MySQL
        if (typeof cargarDatosDelServidor === 'function') {
            await cargarDatosDelServidor();
        }

        // 2. Aplicar personalización institucional (colores, logo, nombre)
        if (typeof cargarConfiguracion === 'function') {
            cargarConfiguracion();
        }

        // 3. Verificar sesión y permisos según la ruta activa
        if (typeof verificarProteccion === 'function') {
            verificarProteccion();
        } else if (typeof window.verificarProteccion === 'function') {
            window.verificarProteccion();
        }

        // 4. Si estamos en detalle.php (aula virtual), inicializar el curso solicitado
        const urlParams = new URLSearchParams(window.location.search);
        const cursoId = urlParams.get('id');
        if (document.getElementById('contenido-curso') && cursoId) {
            if (typeof mostrarDetalleCurso === 'function') {
                await mostrarDetalleCurso(cursoId);
            }
        }

        // 5. Si estamos en index.php (campus principal), renderizar galería de cursos
        if (document.getElementById('galeria-cursos-row') || document.querySelector('#galeria-cursos .row')) {
            if (typeof renderizarGaleria === 'function') {
                renderizarGaleria();
            }
        }

        // 6. Si estamos en admin.php, renderizar tablas del dashboard
        if (document.getElementById('admin-kpi-cursos') || document.getElementById('tabla-cursos-body')) {
            if (typeof actualizarTablas === 'function') {
                actualizarTablas();
            }

            // Enganchar pestaña de reportes avanzados
            const tabReportes = document.querySelector('button[data-bs-target="#tab-reportes"]');
            if (tabReportes) {
                tabReportes.addEventListener('shown.bs.tab', () => {
                    if (typeof renderRobustReports === 'function') {
                        renderRobustReports();
                    }
                });
            }
        }

        // 7. Registrar Service Worker para soporte PWA offline
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').then(reg => {
                reg.update();
            }).catch(err => {
                console.warn('SW no registrado:', err);
            });
        }
    } catch (err) {
        console.error('Error durante la inicialización de la aplicación:', err);
    }
});
