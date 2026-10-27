/**
 * Universidad del Aluminio — Mi perfil
 * Datos personales del estudiante y cambio seguro de contraseña.
 */

// ============================================================
// MODAL MI PERFIL Y CAMBIO SEGURO DE CONTRASEÑA
// ============================================================

window.abrirModalPerfil = function() {
    if (!sesion) {
        if (typeof showToast === 'function') showToast('Inicia sesión para consultar tu perfil.', 'warning');
        return;
    }

    const modalEl = document.getElementById('modal-mi-perfil');
    if (!modalEl) return;

    // 1. Datos personales
    const elAvatar = document.getElementById('perfil-avatar-lg');
    if (elAvatar) elAvatar.textContent = typeof obtenerIniciales === 'function' ? obtenerIniciales(sesion.nombre) : 'UA';

    const elNombre = document.getElementById('perfil-nombre');
    if (elNombre) elNombre.textContent = sesion.nombre;

    const elCedula = document.getElementById('perfil-cedula');
    if (elCedula) elCedula.textContent = sesion.id;

    const elRol = document.getElementById('perfil-rol');
    if (elRol) elRol.textContent = (sesion.rol === 'admin') ? 'Administrador' : (sesion.rol || 'Estudiante').replace('_', ' ');

    // Datos de contacto en resumen
    const elResEmail = document.getElementById('perfil-resumen-email');
    if (elResEmail) {
        elResEmail.innerHTML = sesion.email
            ? `<i class="bi bi-envelope-fill me-1 text-primary"></i>${escapeHtml(sesion.email)}`
            : `<span class="text-muted"><i class="bi bi-envelope me-1"></i>Sin correo</span>`;
    }
    const elResTel = document.getElementById('perfil-resumen-telefono');
    if (elResTel) {
        elResTel.innerHTML = sesion.telefono
            ? `<i class="bi bi-telephone-fill me-1 text-success"></i>${escapeHtml(sesion.telefono)}`
            : `<span class="text-muted"><i class="bi bi-telephone me-1"></i>Sin teléfono</span>`;
    }
    const elResNac = document.getElementById('perfil-resumen-nacimiento');
    if (elResNac) {
        const fNac = sesion.fecha_nacimiento || sesion.fechaNacimiento || '';
        elResNac.innerHTML = fNac
            ? `<i class="bi bi-cake2-fill me-1 text-warning"></i>${escapeHtml(fNac)}`
            : `<span class="text-muted"><i class="bi bi-cake2 me-1"></i>Sin fecha nac.</span>`;
    }

    // Cargar valores en formulario de datos personales (Pestaña 2)
    const inCedula = document.getElementById('perfil-input-cedula');
    if (inCedula) inCedula.value = sesion.id || '';

    const inNombre = document.getElementById('perfil-input-nombre');
    if (inNombre) inNombre.value = sesion.nombre || '';

    const inEmail = document.getElementById('perfil-input-email');
    if (inEmail) inEmail.value = sesion.email || '';

    const inTel = document.getElementById('perfil-input-telefono');
    if (inTel) inTel.value = sesion.telefono || '';

    const inNac = document.getElementById('perfil-input-fecha-nacimiento');
    if (inNac) inNac.value = sesion.fecha_nacimiento || sesion.fechaNacimiento || '';

    // 2. Calcular métricas del colaborador
    const userProg = sesion.progreso || {};
    let countIniciados = 0;
    let countModulosAprobados = 0;

    Object.keys(userProg).forEach(cid => {
        const p = userProg[cid];
        if (p) {
            const mods = Array.isArray(p.modulosAprobados) ? p.modulosAprobados : [];
            const lecs = Array.isArray(p.leccionesCompletadas) ? p.leccionesCompletadas : [];
            if (mods.length > 0 || lecs.length > 0) countIniciados++;
            countModulosAprobados += mods.length;
        }
    });

    const certsCurso = Array.isArray(sesion.certificadosCurso) ? sesion.certificadosCurso : [];
    const certsCarrera = Array.isArray(sesion.certificadosCarrera) ? sesion.certificadosCarrera : [];
    const totalCerts = certsCurso.length + certsCarrera.length;

    const elDisp = document.getElementById('perfil-kpi-disponibles');
    if (elDisp) elDisp.textContent = Array.isArray(cursos) ? cursos.length : 0;

    const elProg = document.getElementById('perfil-kpi-progreso');
    if (elProg) elProg.textContent = countIniciados;

    const elMods = document.getElementById('perfil-kpi-modulos');
    if (elMods) elMods.textContent = countModulosAprobados;

    const elCert = document.getElementById('perfil-kpi-certificados');
    if (elCert) elCert.textContent = totalCerts;

    // Limpiar formulario de clave
    const formClave = document.getElementById('form-cambiar-clave');
    if (formClave) formClave.reset();

    // Activar pestaña resumen por defecto
    const tabResumenBtn = document.getElementById('tab-perfil-resumen-btn');
    if (tabResumenBtn && window.bootstrap && window.bootstrap.Tab) {
        new bootstrap.Tab(tabResumenBtn).show();
    }

    if (window.bootstrap && window.bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
};

window.guardarDatosPerfil = async function(event) {
    if (event) event.preventDefault();

    const inNombre = document.getElementById('perfil-input-nombre');
    const inEmail  = document.getElementById('perfil-input-email');
    const inTel    = document.getElementById('perfil-input-telefono');
    const inNac    = document.getElementById('perfil-input-fecha-nacimiento');
    const btnSubmit = document.getElementById('btn-guardar-perfil');

    const nombre   = (inNombre?.value || '').trim();
    const email    = (inEmail?.value || '').trim();
    const telefono = (inTel?.value || '').trim();
    const fechaNac = (inNac?.value || '').trim();

    if (!nombre) {
        if (typeof showToast === 'function') showToast('El nombre es obligatorio.', 'warning');
        if (inNombre) inNombre.focus();
        return;
    }

    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (typeof showToast === 'function') showToast('Por favor introduce un correo electrónico válido.', 'warning');
        if (inEmail) inEmail.focus();
        return;
    }

    try {
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
        }

        const res = await window.API.guardarPerfil({
            nombre: nombre,
            email: email,
            telefono: telefono,
            fecha_nacimiento: fechaNac
        });

        // Actualizar datos en sesión local
        if (sesion) {
            sesion.nombre = nombre;
            sesion.email = email;
            sesion.telefono = telefono;
            sesion.fecha_nacimiento = fechaNac;
            sesion.fechaNacimiento = fechaNac;
            sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
        }

        // Actualizar visualizaciones en el campus
        const elNombre = document.getElementById('perfil-nombre');
        if (elNombre) elNombre.textContent = nombre;
        const elUserGreeting = document.getElementById('user-greeting-title');
        if (elUserGreeting) elUserGreeting.textContent = `¡Hola, ${nombre.split(' ')[0]}!`;

        // Actualizar badges en resumen
        const elResEmail = document.getElementById('perfil-resumen-email');
        if (elResEmail) {
            elResEmail.innerHTML = email ? `<i class="bi bi-envelope-fill me-1 text-primary"></i>${escapeHtml(email)}` : `<span class="text-muted"><i class="bi bi-envelope me-1"></i>Sin correo</span>`;
        }
        const elResTel = document.getElementById('perfil-resumen-telefono');
        if (elResTel) {
            elResTel.innerHTML = telefono ? `<i class="bi bi-telephone-fill me-1 text-success"></i>${escapeHtml(telefono)}` : `<span class="text-muted"><i class="bi bi-telephone me-1"></i>Sin teléfono</span>`;
        }
        const elResNac = document.getElementById('perfil-resumen-nacimiento');
        if (elResNac) {
            elResNac.innerHTML = fechaNac ? `<i class="bi bi-cake2-fill me-1 text-warning"></i>${escapeHtml(fechaNac)}` : `<span class="text-muted"><i class="bi bi-cake2 me-1"></i>Sin fecha nac.</span>`;
        }

        if (typeof showToast === 'function') {
            showToast(res.message || 'Datos de perfil actualizados con éxito.', 'success');
        }

        // Cambiar a la pestaña de resumen
        const tabResumenBtn = document.getElementById('tab-perfil-resumen-btn');
        if (tabResumenBtn && window.bootstrap && window.bootstrap.Tab) {
            new bootstrap.Tab(tabResumenBtn).show();
        }
    } catch (err) {
        console.error('Error al guardar datos de perfil:', err);
        if (typeof showToast === 'function') {
            showToast('Error al actualizar datos: ' + err.message, 'danger');
        }
    } finally {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Guardar Mis Datos';
        }
    }
};

window.guardarNuevaClave = async function(event) {
    if (event) event.preventDefault();

    const inputActual = document.getElementById('input-clave-actual');
    const inputNueva = document.getElementById('input-clave-nueva');
    const inputConfirm = document.getElementById('input-clave-confirmar');
    const btnSubmit = document.getElementById('btn-guardar-clave');

    const claveActual = (inputActual?.value || '').trim();
    const claveNueva = (inputNueva?.value || '').trim();
    const claveConfirmar = (inputConfirm?.value || '').trim();

    if (!claveActual) {
        if (typeof showToast === 'function') showToast('Por favor introduce tu contraseña actual.', 'warning');
        if (inputActual) inputActual.focus();
        return;
    }

    if (claveNueva.length < 4) {
        if (typeof showToast === 'function') showToast('La nueva contraseña debe tener al menos 4 caracteres.', 'warning');
        if (inputNueva) inputNueva.focus();
        return;
    }

    if (claveNueva !== claveConfirmar) {
        if (typeof showToast === 'function') showToast('Las nuevas contraseñas no coinciden. Por favor verifica.', 'warning');
        if (inputConfirm) inputConfirm.focus();
        return;
    }

    if (claveActual === claveNueva) {
        if (typeof showToast === 'function') showToast('La nueva contraseña no puede ser idéntica a la anterior.', 'warning');
        if (inputNueva) inputNueva.focus();
        return;
    }

    try {
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Actualizando...';
        }

        const res = await window.API.cambiarClave(claveActual, claveNueva);

        if (typeof showToast === 'function') {
            showToast(res.message || 'Contraseña actualizada con éxito.', 'success');
        }

        if (inputActual) inputActual.value = '';
        if (inputNueva) inputNueva.value = '';
        if (inputConfirm) inputConfirm.value = '';

        const modalEl = document.getElementById('modal-mi-perfil');
        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        }
    } catch (err) {
        console.error('Error al cambiar contraseña:', err);
        if (typeof showToast === 'function') {
            showToast(err.message || 'Error al cambiar contraseña', 'danger');
        }
    } finally {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="bi bi-check2 me-1"></i>Actualizar Contraseña';
        }
    }
};

window.toggleVerClave = function(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
    }
};
