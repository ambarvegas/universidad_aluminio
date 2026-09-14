/**
 * Universidad del Aluminio — Admin Careers, Roles & Backup Module
 * Gestión de carreras académicas, roles del sistema, y respaldos JSON.
 */

// ============================================================
// GESTIÓN DE CARRERAS
// ============================================================

window.crearCarrera = async (e) => {
    if (e) e.preventDefault();

    const btn = document.getElementById('btn-guardar-carrera');
    const isEdit = document.getElementById('edit-career-id').value;
    const loadingText = isEdit ? 'Actualizando carrera...' : 'Creando carrera...';

    await withLoading(btn, async () => {
        const idEdit = document.getElementById('edit-career-id').value;
        const nombre = document.getElementById('career-name').value;
        const selectedCursos = Array.from(document.querySelectorAll('.curso-check:checked')).map(cb => cb.value);

        if (!nombre.trim()) {
            throw new Error('El nombre de la carrera es obligatorio.');
        }

        let carreraToSave = null;
        if (idEdit) {
            const idx = carreras.findIndex(c => c.id === idEdit);
            carreras[idx].nombre = nombre;
            carreras[idx].cursos = selectedCursos;
            carreraToSave = carreras[idx];
        } else {
            const nueva = {
                id: "CAR-" + Date.now(),
                nombre,
                cursos: selectedCursos
            };
            carreras.push(nueva);
            carreraToSave = nueva;
        }

        await window.API.guardarCarrera(carreraToSave);
        showToast(idEdit ? 'Carrera actualizada con éxito.' : 'Carrera creada con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, loadingText);
};

window.eliminarCarrera = async (id) => {
    const car = carreras.find(c => c.id === id);
    const nombre = car ? car.nombre : id;

    const ok = await showConfirmModal({
        title: '¿Eliminar Carrera?',
        message: `¿Estás seguro de eliminar la carrera <strong>${nombre}</strong>?`,
        confirmText: 'Sí, eliminar',
        confirmVariant: 'danger'
    });
    if (!ok) return;

    const btn = event?.target?.closest('button');
    await withLoading(btn, async () => {
        carreras = carreras.filter(c => c.id !== id);
        await window.API.eliminarCarrera(id);
        showToast('Carrera eliminada con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando carrera...');
};

window.duplicarCarrera = async (originalCareerId) => {
    const originalCareer = carreras.find(c => c.id === originalCareerId);
    if (!originalCareer) {
        showToast('Carrera original no encontrada.', 'danger');
        return;
    }

    const ok = await showConfirmModal({
        title: '¿Duplicar Carrera?',
        message: `¿Estás seguro de duplicar la carrera <strong>${originalCareer.nombre}</strong>? Se clonarán todos sus cursos y módulos con nuevos identificadores.`,
        confirmText: 'Sí, duplicar',
        confirmVariant: 'primary',
        icon: 'bi-copy'
    });
    if (!ok) return;

    const btn = event?.target?.closest('button');
    await withLoading(btn, async () => {
        const newCareerId = "CAR-" + Date.now() + "-DUP";
        const newCareer = { ...originalCareer, id: newCareerId, nombre: `Copia de ${originalCareer.nombre}` };
        newCareer.cursos = [];

        for (const courseId of originalCareer.cursos) {
            const originalCourse = cursos.find(c => c.id === courseId);
            if (originalCourse) {
                const newCourseId = "CUR-" + Date.now() + "-" + Math.random().toString(36).substr(2, 5);
                const newCourse = JSON.parse(JSON.stringify({
                    ...originalCourse,
                    id: newCourseId,
                    titulo: `Copia de ${originalCourse.titulo}`
                }));
                cursos.push(newCourse);
                newCareer.cursos.push(newCourseId);
                await window.API.guardarCurso(newCourse);
            }
        }

        carreras.push(newCareer);
        await window.API.guardarCarrera(newCareer);
        showToast('Carrera duplicada con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Duplicando carrera...');
};

window.abrirEditorCarrera = (id) => {
    const car = carreras.find(c => c.id === id);
    if (!car) return;
    const idEl = document.getElementById('edit-career-id');
    if (idEl) idEl.value = car.id;
    const nameEl = document.getElementById('career-name');
    if (nameEl) nameEl.value = car.nombre;
    renderListaCursosCarrera();
    (car.cursos || []).forEach(cId => {
        const chk = document.getElementById(`chk-${cId}`);
        if (chk) chk.checked = true;
    });
    const btn = document.getElementById('btn-guardar-carrera');
    if (btn) btn.textContent = 'Actualizar Carrera';
};

window.filtrarCursosCarrera = (busqueda) => {
    const termo = normalizar(busqueda);
    const items = document.querySelectorAll('.curso-item-carrera');
    items.forEach(item => {
        const texto = normalizar(item.innerText);
        item.style.display = texto.includes(termo) ? "block" : "none";
    });
};

function renderListaCursosCarrera() {
    const container = document.getElementById('career-courses-list');
    if (!container) return;
    container.innerHTML = cursos.map(c => `
        <div class="form-check curso-item-carrera">
            <input class="form-check-input curso-check" type="checkbox" value="${c.id}" id="chk-${c.id}">
            <label class="form-check-label" for="chk-${c.id}">${c.titulo}</label>
        </div>
    `).join('');
}
window.renderListaCursosCarrera = renderListaCursosCarrera;

// ============================================================
// GESTIÓN DE ROLES
// ============================================================

window.prepararFormularioRol = () => {
    const form = document.getElementById('form-rol-integral');
    if (form) form.reset();
    const rId = document.getElementById('r-id');
    if (rId) rId.disabled = false;
    const title = document.getElementById('roleModalTitle');
    if (title) title.innerText = "Nuevo Rol";
    renderContenidoRol([], []);
};

window.abrirEditorRol = (id) => {
    const rol = rolesConfig.find(r => r.id === id);
    if (!rol) return;

    const title = document.getElementById('roleModalTitle');
    if (title) title.innerText = `Editando Rol: ${rol.nombre}`;
    const rId = document.getElementById('r-id');
    if (rId) {
        rId.value = rol.id;
        rId.disabled = true;
    }
    const rNombre = document.getElementById('r-nombre');
    if (rNombre) rNombre.value = rol.nombre;
    renderContenidoRol(rol.cursos || [], rol.carreras || []);

    const modalEl = document.getElementById('roleModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }
};

function renderContenidoRol(cursosActuales = [], carrerasActuales = []) {
    const cursosCont = document.getElementById('r-lista-cursos');
    if (!cursosCont) return;

    cursosCont.innerHTML = cursos.map(c => `
        <div class="form-check small">
            <input class="form-check-input check-r-curso" type="checkbox" value="${c.id}" ${cursosActuales.includes(c.id) ? 'checked' : ''}>
            <label class="form-check-label">${c.titulo}</label>
        </div>
    `).join('') || '<p class="text-muted small">No hay cursos disponibles.</p>';
}
window.renderContenidoRol = renderContenidoRol;

window.guardarRol = async (e) => {
    if (e) e.preventDefault();

    const btn = document.getElementById('btn-guardar-rol');
    await withLoading(btn, async () => {
        const id = document.getElementById('r-id').value;
        const nombre = document.getElementById('r-nombre').value;
        const cursosSel = Array.from(document.querySelectorAll('.check-r-curso:checked')).map(cb => cb.value);
        const carrerasSel = Array.from(document.querySelectorAll('.check-r-carrera:checked')).map(cb => cb.value);

        if (!id.trim()) throw new Error('El ID del rol es obligatorio.');
        if (!nombre.trim()) throw new Error('El nombre del rol es obligatorio.');

        let rolToSave = null;
        const idx = rolesConfig.findIndex(r => r.id === id);
        if (idx !== -1) {
            rolesConfig[idx].nombre = nombre;
            rolesConfig[idx].cursos = cursosSel;
            rolesConfig[idx].carreras = carrerasSel;
            rolToSave = rolesConfig[idx];
        } else {
            if (rolesConfig.find(r => r.id === id)) throw new Error("ID de rol ya registrado.");
            const nuevo = { id, nombre, cursos: cursosSel, carreras: carrerasSel };
            rolesConfig.push(nuevo);
            rolToSave = nuevo;
        }

        await window.API.guardarRol(rolToSave);
        showToast('Rol guardado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Guardando rol...');
};

window.eliminarRol = async (id) => {
    const usuariosConRol = usuarios.filter(u => u.rol === id);
    if (usuariosConRol.length > 0) {
        showToast(`No se puede eliminar el rol "${id}" porque tiene ${usuariosConRol.length} usuario(s) asignados.`, "warning");
        return;
    }

    const rol = rolesConfig.find(r => r.id === id);
    const nombreRol = rol ? rol.nombre : id;

    const ok = await showConfirmModal({
        title: '¿Eliminar Rol?',
        message: `¿Estás seguro de eliminar el rol <strong>${nombreRol}</strong>? Los usuarios que lo tenían podrían perder acceso a cursos.`,
        confirmText: 'Sí, eliminar',
        confirmVariant: 'danger'
    });
    if (!ok) return;

    const btn = event?.target?.closest('button');
    await withLoading(btn, async () => {
        rolesConfig = rolesConfig.filter(r => r.id !== id);
        await window.API.eliminarRol(id);
        showToast('Rol eliminado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando rol...');
};

// ============================================================
// RESPALDO Y CONFIGURACIÓN GLOBAL
// ============================================================

window.actualizarMinAprobacionGlobal = async (val) => {
    const num = parseInt(val) || 70;
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.minAprobacion = num;
    try {
        await window.API.guardarConfig('minAprobacion', num);
        showToast('Porcentaje de aprobación actualizado.', 'success');
    } catch (err) {
        showToast(`Error al actualizar porcentaje: ${err.message}`, 'danger');
    }
};

window.exportarBaseDeDatos = () => {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(db, null, 2));
    const downloadAnchorNode = document.createElement('a');
    downloadAnchorNode.setAttribute("href", dataStr);
    downloadAnchorNode.setAttribute("download", "universidad_aluminio_db.json");
    document.body.appendChild(downloadAnchorNode);
    downloadAnchorNode.click();
    downloadAnchorNode.remove();
    showToast('Base de datos exportada con éxito.', 'success');
};

window.importarBaseDeDatos = (event) => {
    const file = event.target.files[0];
    if (!file) return;

    const label = document.getElementById('btn-importar-json');
    if (!label) return;

    const originalHtml = label.innerHTML;

    handleButtonLoading(label, true, 'Importando...');

    const reader = new FileReader();
    reader.onload = async (e) => {
        try {
            const importedData = JSON.parse(e.target.result);
            if (!importedData || typeof importedData !== 'object' || !Array.isArray(importedData.usuarios) || !Array.isArray(importedData.cursos)) {
                throw new Error("El archivo JSON no tiene la estructura requerida para la base de datos.");
            }
            const ok = await showConfirmModal({
                title: '¿Importar Base de Datos?',
                message: '¿Estás seguro? Esto reemplazará toda la información actual por la del archivo seleccionado y la sincronizará en MySQL.',
                confirmText: 'Sí, importar y reemplazar',
                confirmVariant: 'danger',
                icon: 'bi-database-fill-down'
            });
            if (ok) {
                db = importedData;
                usuarios = db.usuarios || [];
                cursos = db.cursos || [];
                carreras = db.carreras || [];
                rolesConfig = db.rolesConfig || [];
                solicitudesRegistro = db.solicitudesRegistro || [];
                solicitudesCursos = db.solicitudesCursos || [];
                await window.API.importarBackupDB(db);
                showToast('Base de datos importada y sincronizada exitosamente.', 'success');
                setTimeout(() => location.reload(), 1500);
            }
        } catch (err) {
            console.error("Error al importar la base de datos:", err);
            showToast('Error: ' + err.message, 'danger');
        } finally {
            handleButtonLoading(label, false);
            label.innerHTML = originalHtml;
            event.target.value = '';
        }
    };
    reader.onerror = () => {
        handleButtonLoading(label, false);
        label.innerHTML = originalHtml;
        showToast('Error al leer el archivo.', 'danger');
        event.target.value = '';
    };
    reader.readAsText(file);
};
