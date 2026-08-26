/**
 * js/features/config.js
 * Configuración institucional, logo, porcentaje de aprobación, exportar/importar DB
 */

async function cargarLogoInstitucion(event) {
    const file = event.target.files[0];
    if (!file) return;

    const label = document.getElementById('btn-cargar-logo');
    const originalHtml = label ? label.innerHTML : '';

    await withLoading(label, async () => {
        const logoBase64 = await comprimirImagenBase64(file, 400, 0.85);
        if (!db.configuracion) db.configuracion = {};
        db.configuracion.logo = logoBase64;
        localStorage.setItem('aluLogo', logoBase64);

        try {
            await window.API.guardarConfig('logo', logoBase64);
        } catch (e) {
            console.warn('Fallback a guardarLogo:', e);
            await guardarLogo(logoBase64);
        }

        showToast('Logo institucional actualizado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Cargando logo...');
}

async function actualizarMinAprobacionGlobal(val) {
    const num = parseInt(val) || 70;
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.minAprobacion = num;

    try {
        await window.API.guardarConfig('minAprobacion', num);
    } catch (e) {
        console.warn('Fallback a guardarTodo:', e);
        await guardarTodo();
    }
    showToast(`Calificación mínima establecida en ${num}%`, 'info');
}

function exportarBaseDeDatos() {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(db, null, 2));
    const downloadAnchorNode = document.createElement('a');
    downloadAnchorNode.setAttribute("href", dataStr);
    downloadAnchorNode.setAttribute("download", `universidad_aluminio_backup_${new Date().toISOString().split('T')[0]}.json`);
    document.body.appendChild(downloadAnchorNode);
    downloadAnchorNode.click();
    downloadAnchorNode.remove();
    showToast('Base de datos exportada con éxito.', 'success');
}

window.cargarLogoInstitucion = cargarLogoInstitucion;
window.actualizarMinAprobacionGlobal = actualizarMinAprobacionGlobal;
window.exportarBaseDeDatos = exportarBaseDeDatos;
