/**
 * Universidad del Aluminio — Main Orchestrator & Backward Compatibility Layer
 * Coordina la carga inicial, sincronización con el servidor MySQL y enrutamiento del ciclo de vida del frontend.
 */

// ============================================================
// GESTIÓN DE VERSIONES Y HARD RESET AUTOMÁTICO
// ============================================================
const APP_CLIENT_VERSION = '2026.09.14.v6';

(function verificarVersionCliente() {
    try {
        const storedVersion = localStorage.getItem('unialuminio_build_ver');
        if (storedVersion && storedVersion !== APP_CLIENT_VERSION) {
            console.log(`[UniAluminio] Nueva versión detectada (${APP_CLIENT_VERSION}). Purgando cachés antiguas...`);
            localStorage.setItem('unialuminio_build_ver', APP_CLIENT_VERSION);
            if ('caches' in window) {
                caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k))));
            }
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(regs => {
                    regs.forEach(r => r.update());
                });
            }
        } else if (!storedVersion) {
            localStorage.setItem('unialuminio_build_ver', APP_CLIENT_VERSION);
        }
    } catch (e) {
        console.warn('Error en verificación de versión:', e);
    }
})();

/**
 * Función global para forzar hard reset de caché, desregistrar Service Worker y recargar.
 */
window.forzarHardReset = async function (btnElement) {
    let originalHtml = '';
    if (btnElement) {
        originalHtml = btnElement.innerHTML;
        btnElement.disabled = true;
        btnElement.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Actualizando...';
    }

    try {
        // 1. Desregistrar Service Workers
        if ('serviceWorker' in navigator) {
            const regs = await navigator.serviceWorker.getRegistrations();
            for (const reg of regs) {
                await reg.unregister();
            }
        }

        // 2. Borrar todos los caches de Cache Storage
        if ('caches' in window) {
            const keys = await caches.keys();
            for (const key of keys) {
                await caches.delete(key);
            }
        }

        // 3. Limpiar almacenamiento de sesión y marcar nueva versión
        sessionStorage.clear();
        localStorage.setItem('unialuminio_build_ver', APP_CLIENT_VERSION);

        // 4. Mostrar aviso si existe toast
        if (typeof showToast === 'function') {
            showToast('Caché purgada. Sincronizando con la última versión del servidor...', 'success');
        }

        // 5. Redireccionar con parámetro anti-caché
        setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.set('v_reset', Date.now());
            window.location.href = url.toString();
        }, 300);
    } catch (err) {
        console.error('Error durante hard reset:', err);
        window.location.reload(true);
    }
};

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

        // 7. Registrar Service Worker para soporte PWA offline con auto-update
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').then(reg => {
                reg.update();
                reg.addEventListener('updatefound', () => {
                    const newWorker = reg.installing;
                    if (newWorker) {
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                console.log('[SW] Nueva versión de la app lista.');
                            }
                        });
                    }
                });
            }).catch(err => {
                console.warn('SW no registrado:', err);
            });
        }
    } catch (err) {
        console.error('Error durante la inicialización de la aplicación:', err);
    }
});
