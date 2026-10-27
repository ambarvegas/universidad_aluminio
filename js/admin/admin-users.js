/**
 * Universidad del Aluminio — Admin Users Module
 * Gestión integral de colaboradores, restablecimiento de progreso, marcado manual y exportación CSV.
 */

window.prepararFormularioUsuario = () => {
    const form = document.getElementById('form-usuario-integral');
    if (form) form.reset();
    const uId = document.getElementById('u-id');
    if (uId) uId.disabled = false;
    if (document.getElementById('u-email')) document.getElementById('u-email').value = '';
    if (document.getElementById('u-telefono')) document.getElementById('u-telefono').value = '';
    if (document.getElementById('u-fecha-nacimiento')) document.getElementById('u-fecha-nacimiento').value = '';
    const title = document.getElementById('userModalTitle');
    if (title) title.innerText = "Nuevo Colaborador";
    renderSelectRoles();
};

window.abrirEditorUsuario = (id) => {
    const u = usuarios.find(user => String(user.id) === String(id));
    if (!u) return;

    const title = document.getElementById('userModalTitle');
    if (title) title.innerText = `Editando: ${u.nombre}`;
    const uId = document.getElementById('u-id');
    if (uId) {
        uId.value = u.id;
        uId.disabled = true;
    }
    const uNombre = document.getElementById('u-nombre');
    if (uNombre) uNombre.value = u.nombre;

    renderSelectRoles();
    const uRol = document.getElementById('u-rol');
    if (uRol) uRol.value = u.rol;
    const uEstado = document.getElementById('u-estado');
    if (uEstado) uEstado.value = u.estado || 'activo';
    const uClave = document.getElementById('u-clave');
    if (uClave) uClave.value = '';

    if (document.getElementById('u-email')) {
        document.getElementById('u-email').value = u.email || '';
    }
    if (document.getElementById('u-telefono')) {
        document.getElementById('u-telefono').value = u.telefono || '';
    }
    if (document.getElementById('u-fecha-nacimiento')) {
        document.getElementById('u-fecha-nacimiento').value = u.fecha_nacimiento || u.fechaNacimiento || '';
    }

    const modalEl = document.getElementById('userModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
};

// ============================================================
// GESTIÓN DE ENLACES DE ACCESO, INVITACIÓN Y RESTABLECIMIENTO
// ============================================================

let _currentInviteUserId = null;

window.abrirModalInvitacionAcceso = async (id) => {
    const idStr = String(id || '').trim();
    const u = (typeof usuarios !== 'undefined' && Array.isArray(usuarios)) ? usuarios.find(user => String(user.id) === idStr) : null;
    if (!u) {
        showToast("Colaborador no encontrado.", "danger");
        return;
    }

    _currentInviteUserId = idStr;
    const nombreEl = document.getElementById('inv-user-nombre');
    const idEl = document.getElementById('inv-user-id');
    const emailEl = document.getElementById('inv-user-email');
    const inputLink = document.getElementById('inv-link-input');
    const expiraEl = document.getElementById('inv-expira-txt');
    const statusEl = document.getElementById('inv-email-status');

    if (nombreEl) nombreEl.innerText = u.nombre || idStr;
    if (idEl) idEl.innerText = idStr;
    if (emailEl) emailEl.innerText = u.email || 'Sin correo configurado';
    if (inputLink) inputLink.value = 'Generando enlace seguro...';
    if (expiraEl) expiraEl.innerText = '—';
    if (statusEl) statusEl.style.display = 'none';

    // Seleccionar por defecto 'invitacion'
    const radInv = document.getElementById('inv-tipo-invitacion');
    if (radInv) radInv.checked = true;

    const modalEl = document.getElementById('invitacionAccesoModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    await actualizarLinkInvitacionActual(false);
};

window.abrirModalInvitacionDesdeEditor = () => {
    const uId = document.getElementById('u-id')?.value?.trim();
    if (!uId) {
        showToast("Primero ingresa la cédula del colaborador.", "warning");
        return;
    }
    abrirModalInvitacionAcceso(uId);
};

window.actualizarLinkInvitacionActual = async (isReenvio = false) => {
    if (!_currentInviteUserId) return;

    const tipoRadio = document.querySelector('input[name="inv-tipo-radio"]:checked')?.value || 'invitacion';
    const duracionHoras = parseInt(document.getElementById('inv-duracion')?.value || '48', 10);
    const inputLink = document.getElementById('inv-link-input');
    const expiraEl = document.getElementById('inv-expira-txt');
    const statusEl = document.getElementById('inv-email-status');
    const btnReenviar = document.getElementById('btn-reenviar-inv-email');

    if (btnReenviar) btnReenviar.disabled = true;

    try {
        const res = await window.API.generarLinkInvitacion({
            usuario_id: _currentInviteUserId,
            tipo: tipoRadio,
            horas_validez: duracionHoras
        });

        if (inputLink) inputLink.value = res.link || '';
        if (expiraEl) expiraEl.innerText = res.expira || '—';

        if (statusEl) {
            statusEl.style.display = 'block';
            if (res.email_enviado) {
                statusEl.className = 'alert alert-success small mb-0';
                statusEl.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> Correo institucional enviado automáticamente a <strong>${res.usuario.email}</strong>.`;
                showToast(`Enlace enviado a ${res.usuario.email}`, 'success');
            } else if (res.usuario?.email) {
                statusEl.className = 'alert alert-warning small mb-0';
                statusEl.innerHTML = `<i class="bi bi-exclamation-circle-fill me-1"></i> ${res.mensaje}`;
            } else {
                statusEl.className = 'alert alert-secondary small mb-0';
                statusEl.innerHTML = `<i class="bi bi-info-circle-fill me-1"></i> El colaborador no posee correo registrado en su perfil. Puedes copiar el enlace generado y enviárselo directamente.`;
            }
        }
    } catch (err) {
        if (inputLink) inputLink.value = 'Error al generar enlace';
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.className = 'alert alert-danger small mb-0';
            statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${err.message || 'Error al procesar la solicitud.'}`;
        }
    } finally {
        if (btnReenviar) btnReenviar.disabled = false;
    }
};

window.copiarLinkInvitacion = async () => {
    const inputLink = document.getElementById('inv-link-input');
    const btn = document.getElementById('btn-copiar-inv-link');
    const icon = document.getElementById('icon-copiar-inv');
    const link = inputLink?.value || '';

    if (!link || link.startsWith('Generando') || link.startsWith('Error')) {
        showToast("No hay un enlace válido para copiar.", "warning");
        return;
    }

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(link);
        } else {
            inputLink.select();
            document.execCommand('copy');
        }

        if (btn) btn.className = 'btn btn-success btn-sm';
        if (icon) icon.className = 'bi bi-check-lg me-1';
        showToast("¡Enlace copiado al portapapeles!", "success");

        setTimeout(() => {
            if (btn) btn.className = 'btn btn-outline-secondary btn-sm';
            if (icon) icon.className = 'bi bi-clipboard me-1';
        }, 2000);
    } catch (e) {
        showToast("Por favor selecciona y copia el enlace manualmente.", "info");
    }
};

function renderSelectRoles() {
    const select = document.getElementById('u-rol');
    if (select && Array.isArray(rolesConfig)) {
        select.innerHTML = rolesConfig.map(r => `<option value="${r.id}">${r.nombre}</option>`).join('');
    }
}
window.renderSelectRoles = renderSelectRoles;

window.guardarUsuario = async (e) => {
    if (e) e.preventDefault();

    const btn = document.getElementById('btn-guardar-usuario');
    await withLoading(btn, async () => {
        const id = document.getElementById('u-id').value.trim();
        const nombre = document.getElementById('u-nombre').value.trim();
        const rol = document.getElementById('u-rol').value;
        const estado = document.getElementById('u-estado').value;
        const claveNueva = document.getElementById('u-clave').value.trim();
        const email = (document.getElementById('u-email')?.value || '').trim();
        const telefono = (document.getElementById('u-telefono')?.value || '').trim();
        const fechaNacimiento = (document.getElementById('u-fecha-nacimiento')?.value || '').trim();

        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            throw new Error("El formato del correo electrónico es inválido.");
        }

        const autoAssignCareerId = getCareerIdFromRole(rol);
        const userCareers = [];
        if (autoAssignCareerId) {
            if (carreras.some(c => c.id === autoAssignCareerId)) {
                userCareers.push({ id: autoAssignCareerId, estado: "Incompleta" });
            }
        }

        const idx = usuarios.findIndex(u => String(u.id) === String(id));
        let userToSave = null;
        if (idx !== -1) {
            usuarios[idx].nombre = nombre;
            usuarios[idx].rol = rol;
            usuarios[idx].estado = estado;
            usuarios[idx].email = email;
            usuarios[idx].telefono = telefono;
            usuarios[idx].fecha_nacimiento = fechaNacimiento;
            usuarios[idx].fechaNacimiento = fechaNacimiento;
            if (claveNueva) {
                usuarios[idx].clave = claveNueva;
            } else {
                delete usuarios[idx].clave;
            }
            if (autoAssignCareerId && !usuarios[idx].carrerasAsignadas.some(ca => ca.id === autoAssignCareerId)) {
                usuarios[idx].carrerasAsignadas.push({ id: autoAssignCareerId, estado: "Incompleta" });
            }
            usuarios[idx] = crearEstructuraUsuario(usuarios[idx]);
            userToSave = usuarios[idx];
        } else {
            if (usuarios.find(u => String(u.id) === String(id))) throw new Error("ID ya registrado");
            const nuevoUsuario = crearEstructuraUsuario({
                id, nombre, clave: claveNueva || "12345",
                rol, estado, email, telefono, fecha_nacimiento: fechaNacimiento,
                asignados: [], carrerasAsignadas: userCareers,
                progreso: {}, certificadosCurso: [], certificadosCarrera: []
            });
            usuarios.push(nuevoUsuario);
            userToSave = nuevoUsuario;
        }

        await window.API.guardarUsuario(userToSave);
        showToast('Usuario guardado con éxito.', 'success');
        setTimeout(() => location.reload(), 1200);
    }, 'Guardando usuario...');
};

window.eliminarUsuario = async (id) => {
    const idStr = String(id || '').trim();
    if (idStr === '25482938') {
        showToast("No se puede eliminar al administrador principal.", "warning");
        return;
    }
    const u = usuarios.find(user => String(user.id) === idStr);
    const nombreUsuario = u ? u.nombre : idStr;

    const ok = await showConfirmModal({
        title: '¿Eliminar Usuario?',
        message: `¿Estás seguro de eliminar el acceso para <strong>${nombreUsuario}</strong> (C.I. ${idStr})?`,
        confirmText: 'Sí, eliminar',
        confirmVariant: 'danger'
    });
    if (!ok) return;

    const btn = document.querySelector('#confirmModal .btn-danger') || event?.target?.closest('button');
    await withLoading(btn, async () => {
        usuarios = usuarios.filter(user => String(user.id) !== idStr);
        await window.API.eliminarUsuario(idStr);
        showToast('Usuario eliminado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando usuario...');
};

// ============================================================
// EXPORTACIÓN DE USUARIOS A CSV
// ============================================================

window.exportarUsuariosCSV = () => {
    if (!usuarios || usuarios.length === 0) {
        showToast('No hay usuarios registrados para exportar.', 'warning');
        return;
    }

    const headers = ['Cédula', 'Nombre Completo', 'Rol Académico', 'Estado', 'Cursos Iniciados', 'Módulos Aprobados', 'Certificados Obtenidos', 'Carreras Asignadas'];
    const rows = usuarios.map(u => {
        const id = `"${(u.id || '').toString().replace(/"/g, '""')}"`;
        const nombre = `"${(u.nombre || '').replace(/"/g, '""')}"`;
        const rol = `"${(u.rol || '').replace(/"/g, '""')}"`;
        const estado = `"${(u.estado || 'activo').replace(/"/g, '""')}"`;
        
        const prog = u.progreso || {};
        const cursosIniciados = Object.keys(prog).length;
        let modulosAprobados = 0;
        Object.values(prog).forEach(p => {
            if (p && Array.isArray(p.modulosAprobados)) modulosAprobados += p.modulosAprobados.length;
        });

        const certs = (u.certificadosCurso || []).length;
        const carrerasTxt = (u.carrerasAsignadas || []).map(ca => {
            const cObj = carreras.find(c => c.id === ca.id);
            return `${cObj ? cObj.nombre : ca.id} (${ca.estado || 'Incompleta'})`;
        }).join('; ');
        const carrerasField = `"${carrerasTxt.replace(/"/g, '""')}"`;

        return [id, nombre, rol, estado, cursosIniciados, modulosAprobados, certs, carrerasField].join(',');
    });

    const csvContent = '\uFEFF' + [headers.join(','), ...rows].join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `colaboradores_universidad_aluminio_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Planilla de usuarios exportada exitosamente a CSV.', 'success');
};

// ============================================================
// PROGRESO BAJO DEMANDA
// ============================================================
// El panel recibe los usuarios sin progreso (App.asegurarProgreso). Las acciones que lo
// leen esperan a tenerlo (aquí, exportar: el de todos; en admin-avance.js, el del usuario).
(function () {
    const conProgreso = (fn, deTodos = false) => async (...args) => {
        try {
            await App.asegurarProgreso(deTodos ? undefined : [args[0]]);
        } catch (err) {
            showToast('No se pudo cargar el progreso: ' + err.message, 'danger');
            return;
        }
        return fn(...args);
    };
    window.exportarUsuariosCSV = conProgreso(window.exportarUsuariosCSV, true);
})();
