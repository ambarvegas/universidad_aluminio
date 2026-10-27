/**
 * js/features/solicitudes.js
 * Gestión de solicitudes de registro y solicitudes de acceso a cursos
 */

async function solicitarRegistro(id, nombre, clave, perfilDeseado) {
    // Las carreras se asignan al aprobar, según la configuración del rol (carrerasDelRol)
    try {
        await window.API.solicitarRegistro({ id, nombre, clave, perfilDeseado });
        showToast("Solicitud enviada. Un administrador revisará su acceso pronto.", "success");
        setTimeout(() => location.reload(), 1500);
    } catch (err) {
        showToast(err.message || 'Error al enviar solicitud', 'danger');
    }
}

async function gestionarSolicitudRegistro(id, aprobado) {
    const btn = event?.target?.closest('button');
    const actionText = aprobado ? 'Aprobando solicitud...' : 'Rechazando solicitud...';

    await withLoading(btn, async () => {
        const idx = solicitudesRegistro.findIndex(s => s.id === id);
        const sol = solicitudesRegistro[idx];

        if (aprobado && sol) {
            const userCareers = carrerasDelRol(sol.perfilDeseado).map(carId => ({ id: carId, estado: "Incompleta" }));

            const nuevoUsuario = crearEstructuraUsuario({
                id: sol.id,
                nombre: sol.nombre,
                clave: sol.clave,
                rol: sol.perfilDeseado,
                estado: "activo",
                asignados: [],
                carrerasAsignadas: userCareers,
                progreso: {},
                certificadosCurso: [],
                certificadosCarrera: []
            });

            usuarios.push(nuevoUsuario);
            await window.API.guardarUsuario(nuevoUsuario);
        }

        solicitudesRegistro = solicitudesRegistro.filter(s => s.id !== id);
        await window.API.eliminarSolicitudRegistro(id);

        showToast(aprobado ? 'Usuario aprobado y registrado.' : 'Solicitud rechazada.', aprobado ? 'success' : 'danger');
        setTimeout(() => location.reload(), 1500);
    }, actionText);
}

async function solicitarAccesoCurso(cursoId) {
    if (!sesion) {
        window.location.href = 'login.php';
        return;
    }

    try {
        await window.API.solicitarAccesoCurso(sesion.id, sesion.nombre, cursoId);
        showToast("Solicitud de acceso enviada al administrador.", "success");
    } catch (err) {
        showToast(err.message || "Error al solicitar acceso", "danger");
    }
}

async function gestionarSolicitudCurso(userId, cursoId, aprobado) {
    const btn = event?.target?.closest('button');
    const actionText = aprobado ? 'Aprobando acceso...' : 'Rechazando acceso...';

    await withLoading(btn, async () => {
        if (aprobado) {
            const user = usuarios.find(u => u.id === userId);
            if (user) {
                if (!Array.isArray(user.asignados)) user.asignados = [];
                if (!user.asignados.includes(cursoId)) {
                    user.asignados.push(cursoId);
                    await window.API.guardarUsuario(user);
                }
            }
        }

        solicitudesCursos = solicitudesCursos.filter(s => !(s.userId === userId && s.cursoId === cursoId));
        await window.API.eliminarSolicitudCurso(userId, cursoId);

        showToast(aprobado ? 'Acceso aprobado.' : 'Acceso rechazado.', aprobado ? 'success' : 'danger');
        setTimeout(() => location.reload(), 1500);
    }, actionText);
}

window.solicitarRegistro = solicitarRegistro;
window.gestionarSolicitudRegistro = gestionarSolicitudRegistro;
window.solicitarAccesoCurso = solicitarAccesoCurso;
window.gestionarSolicitudCurso = gestionarSolicitudCurso;
