/**
 * js/admin/admin-avance.js
 * Gestión del avance de un colaborador desde el panel: restablecer progreso (curso completo
 * o módulos) y marcar módulos como completados.
 */

// ============================================================
// RESTABLECER AVANCE
// ============================================================

window.abrirRestablecerAvance = (userId) => {
    const u = usuarios.find(user => user.id === userId);
    if (!u) return;

    document.getElementById('restablecer-user-id').value = u.id;
    document.getElementById('restablecer-user-nombre').value = u.nombre;

    const select = document.getElementById('restablecer-curso-select');
    select.innerHTML = '<option value="">-- Seleccionar Curso --</option>';

    const cursosIds = Object.keys(u.progreso || {});

    cursosIds.forEach(cursoId => {
        const curso = cursos.find(c => c.id === cursoId);
        if (curso) {
            select.innerHTML += `<option value="${curso.id}">${curso.titulo}</option>`;
        }
    });

    const cont = document.getElementById('restablecer-modulos-container');
    if (cont) cont.style.display = 'none';

    const modalEl = document.getElementById('restablecerAvanceModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const bModal = new bootstrap.Modal(modalEl);
        bModal.show();
    }
};

window.cambiarCarreraRestablecer = () => {
    const cursoId = document.getElementById('restablecer-curso-select').value;
    const container = document.getElementById('restablecer-modulos-container');
    const body = document.getElementById('restablecer-modulos-body');
    const checkAll = document.getElementById('restablecer-select-all');

    if (!body) return;

    if (checkAll) checkAll.checked = false;

    if (!cursoId) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    if (!curso) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    if (container) container.style.display = 'block';
    body.innerHTML = '';

    let tieneModulos = false;

    if (curso.modulos && curso.modulos.length > 0) {
        curso.modulos.forEach((mod, mIdx) => {
            tieneModulos = true;
            body.innerHTML += `
                <tr>
                    <td style="width: 40px;" class="text-center">
                        <input type="checkbox" class="form-check-input modulo-restablecer-checkbox" data-course-id="${curso.id}" data-module-idx="${mIdx}">
                    </td>
                    <td>
                        <strong>${curso.titulo}</strong>
                    </td>
                    <td>
                        ${mod.titulo}
                    </td>
                </tr>
            `;
        });
    }

    if (!tieneModulos) {
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Este curso no tiene módulos.</td></tr>';
    }
};

window.seleccionarTodosModulosRestablecer = (check) => {
    document.querySelectorAll('.modulo-restablecer-checkbox').forEach(cb => {
        cb.checked = check;
    });
};

window.confirmarRestablecerAvance = async (completa) => {
    const btn = event?.target?.closest('button');
    const userId = document.getElementById('restablecer-user-id').value;
    const cursoId = document.getElementById('restablecer-curso-select').value;
    const uIdx = usuarios.findIndex(u => u.id === userId);
    if (uIdx === -1) return;
    const u = usuarios[uIdx];

    if (!cursoId) {
        showToast("Por favor selecciona un curso.", "warning");
        return;
    }

    if (completa) {
        const ok = await showConfirmModal({
            title: '¿Restablecer Avance Completo?',
            message: `¿Estás seguro de restablecer por completo el avance del curso para <strong>${u.nombre}</strong>? Se borrarán sus evaluaciones, medallas y certificados de este curso.`,
            confirmText: 'Sí, restablecer todo',
            confirmVariant: 'danger'
        });
        if (!ok) return;
    } else {
        const checked = Array.from(document.querySelectorAll('.modulo-restablecer-checkbox:checked'));
        if (checked.length === 0) {
            showToast("Selecciona al menos un módulo para restablecer.", "warning");
            return;
        }
        const ok = await showConfirmModal({
            title: '¿Restablecer Módulos?',
            message: `¿Estás seguro de restablecer el avance de los <strong>${checked.length}</strong> módulo(s) seleccionado(s) para <strong>${u.nombre}</strong>?`,
            confirmText: 'Sí, restablecer',
            confirmVariant: 'warning'
        });
        if (!ok) return;
    }

    await withLoading(btn, async () => {
        if (completa) {
            const curso = cursos.find(c => c.id === cursoId);
            if (!curso) throw new Error("Curso no encontrado");
            if (u.progreso && u.progreso[cursoId]) {
                delete u.progreso[cursoId];
            }
            if (u.certificadosCurso) {
                u.certificadosCurso = u.certificadosCurso.filter(id => id !== cursoId);
            }
            await window.API.restablecerProgreso({
                usuario_id: u.id,
                curso_id: cursoId,
                completa: true
            });
        } else {
            const checked = Array.from(document.querySelectorAll('.modulo-restablecer-checkbox:checked'));
            const modulosIdx = checked.map(cb => parseInt(cb.getAttribute('data-module-idx')));
            checked.forEach(cb => {
                const courseId = cb.getAttribute('data-course-id');
                const mIdx = parseInt(cb.getAttribute('data-module-idx'));
                if (u.progreso && u.progreso[courseId]) {
                    const prog = u.progreso[courseId];
                    if (prog.modulosAprobados) {
                        prog.modulosAprobados = prog.modulosAprobados.filter(idx => parseInt(idx) !== mIdx);
                    }
                    if (prog.medallas) {
                        prog.medallas = prog.medallas.filter(idx => parseInt(idx) !== mIdx);
                    }
                    if (prog.evaluaciones && prog.evaluaciones[mIdx]) {
                        delete prog.evaluaciones[mIdx];
                    }
                    if (prog.intentos && prog.intentos[mIdx]) {
                        delete prog.intentos[mIdx];
                    }
                    if (prog.leccionesCompletadas) {
                        prog.leccionesCompletadas = prog.leccionesCompletadas.filter(lecId =>
                            !lecId.startsWith(mIdx + '-')
                        );
                    }
                }
                if (u.progreso && u.progreso[courseId]) {
                    const prog = u.progreso[courseId];
                    if (prog.modulosAprobados && prog.modulosAprobados.length === 0) {
                        if (u.certificadosCurso) {
                            u.certificadosCurso = u.certificadosCurso.filter(id => id !== courseId);
                        }
                    }
                }
            });
            await window.API.restablecerProgreso({
                usuario_id: u.id,
                curso_id: cursoId,
                modulos: modulosIdx,
                completa: false
            });
        }

        actualizarEstadoCarrerasUsuario(u);

        const modalEl = document.getElementById('restablecerAvanceModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bModal.hide();
        }
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';

        showToast('Avance restablecido con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, completa ? 'Restableciendo todo...' : 'Restableciendo módulos...');
};

// ============================================================
// MARCAR COMPLETADO
// ============================================================

window.abrirMarcarCompletado = (userId) => {
    const u = usuarios.find(user => user.id === userId);
    if (!u) return;

    document.getElementById('marcar-user-id').value = u.id;
    document.getElementById('marcar-user-nombre').value = u.nombre;

    const select = document.getElementById('marcar-curso-select');
    select.innerHTML = '<option value="">-- Seleccionar Curso --</option>';

    cursos.forEach(curso => {
        select.innerHTML += `<option value="${curso.id}">${curso.titulo}</option>`;
    });

    const cont = document.getElementById('marcar-modulos-container');
    if (cont) cont.style.display = 'none';

    const modalEl = document.getElementById('marcarCompletadoModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const bModal = new bootstrap.Modal(modalEl);
        bModal.show();
    }
};

window.cargarModulosParaMarcar = () => {
    const cursoId = document.getElementById('marcar-curso-select').value;
    const userId = document.getElementById('marcar-user-id').value;
    const container = document.getElementById('marcar-modulos-container');
    const body = document.getElementById('marcar-modulos-body');
    const checkAll = document.getElementById('marcar-select-all');

    if (!body) return;

    if (checkAll) checkAll.checked = false;

    if (!cursoId) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    const usuario = usuarios.find(u => u.id === userId);

    if (!curso || !usuario) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    if (container) container.style.display = 'block';
    body.innerHTML = '';

    // Inicializar input de nota con mínimo de aprobación configurado
    const minAprob = (typeof db !== 'undefined' && db.configuracion && db.configuracion.minAprobacion != null)
        ? Number(db.configuracion.minAprobacion) : 70;
    const inputNota = document.getElementById('marcar-nota-input');
    const badgeMin = document.getElementById('marcar-min-aprob-badge');
    const badgeRango = document.getElementById('marcar-rango-badge');
    if (inputNota) {
        inputNota.min = minAprob;
        inputNota.max = 100;
        inputNota.value = 100;
    }
    if (badgeMin) badgeMin.textContent = `${minAprob}`;
    if (badgeRango) badgeRango.textContent = `${minAprob} - 100`;

    let tieneModulos = false;

    if (curso.modulos && curso.modulos.length > 0) {
        if (!usuario.progreso) usuario.progreso = {};
        if (!usuario.progreso[cursoId]) {
            usuario.progreso[cursoId] = {
                leccionesCompletadas: [],
                modulosAprobados: [],
                medallas: [],
                evaluaciones: {},
                intentos: {}
            };
        }

        const modulosAprobados = usuario.progreso[cursoId]?.modulosAprobados || [];

        curso.modulos.forEach((mod, mIdx) => {
            tieneModulos = true;
            const yaAprobado = modulosAprobados.includes(String(mIdx));
            const estadoActual = yaAprobado ?
                '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Completado</span>' :
                '<span class="badge bg-secondary"><i class="bi bi-hourglass"></i> Pendiente</span>';

            const tieneEvaluacion = mod.evaluacion && mod.evaluacion.preguntas && mod.evaluacion.preguntas.length > 0;
            const badgeEvaluacion = tieneEvaluacion ?
                '<span class="badge bg-info ms-2">Con Evaluación</span>' :
                '<span class="badge bg-secondary ms-2">Sin Evaluación</span>';

            const totalLecciones = mod.lecciones ? mod.lecciones.length : 0;
            const leccionesInfo = totalLecciones > 0 ? `${totalLecciones} lección(es)` : 'Sin lecciones';

            body.innerHTML += `
                <tr>
                    <td style="width: 40px;" class="text-center">
                        <input type="checkbox" class="form-check-input modulo-marcar-checkbox" 
                               data-course-id="${curso.id}" 
                               data-module-idx="${mIdx}"
                               ${yaAprobado ? 'disabled' : ''}>
                    </td>
                    <td>
                        <strong>${curso.titulo}</strong>
                        <br>
                        <small class="text-muted">ID: ${curso.id}</small>
                    </td>
                    <td>
                        <strong>${mod.titulo}</strong>
                        ${badgeEvaluacion}
                        <br>
                        <small class="text-muted">${leccionesInfo}</small>
                    </td>
                    <td class="text-center">
                        ${estadoActual}
                    </td>
                </tr>
            `;
        });
    }

    if (!tieneModulos) {
        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Este curso no tiene módulos definidos. Crea módulos primero.</td></tr>';
    } else {
        const totalModulos = curso.modulos.length;
        const modulosAprobados = usuario.progreso[cursoId]?.modulosAprobados || [];
        const completados = modulosAprobados.length;
        const progressPercent = totalModulos > 0 ? Math.round((completados / totalModulos) * 100) : 0;

        const progressRow = document.createElement('tr');
        progressRow.innerHTML = `
            <td colspan="4" class="bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-bold">Progreso actual del usuario:</span>
                    <span class="small fw-bold">${completados}/${totalModulos} módulos (${progressPercent}%)</span>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" style="width: ${progressPercent}%"></div>
                </div>
            </td>
        `;
        body.insertBefore(progressRow, body.firstChild);
    }
};

window.seleccionarTodosModulosParaMarcar = (check) => {
    document.querySelectorAll('.modulo-marcar-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = check;
    });
};

window.confirmarMarcarCompletado = async () => {
    const btn = document.getElementById('btn-marcar-completado');
    const userId = document.getElementById('marcar-user-id').value;
    const cursoId = document.getElementById('marcar-curso-select').value;
    const uIdx = usuarios.findIndex(u => u.id === userId);

    if (uIdx === -1) {
        showToast("Usuario no encontrado.", "danger");
        return;
    }

    const usuario = usuarios[uIdx];

    if (!cursoId) {
        showToast("Por favor selecciona un curso.", "warning");
        return;
    }

    const checked = Array.from(document.querySelectorAll('.modulo-marcar-checkbox:checked'));
    if (checked.length === 0) {
        showToast("Selecciona al menos un módulo para marcar como completado.", "warning");
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    if (!curso) {
        showToast("Curso no encontrado.", "danger");
        return;
    }

    const moduloListaHtml = checked.map(cb => {
        const mIdx = parseInt(cb.getAttribute('data-module-idx'));
        return `<li>${curso.modulos[mIdx]?.titulo || `Módulo ${mIdx + 1}`}</li>`;
    }).join('');

    const inputNota = document.getElementById('marcar-nota-input');
    const minAprobacion = (typeof db !== 'undefined' && db.configuracion && db.configuracion.minAprobacion != null)
        ? Number(db.configuracion.minAprobacion) : 70;
    let notaCargar = inputNota ? parseFloat(inputNota.value) : 100;

    if (isNaN(notaCargar) || notaCargar < minAprobacion || notaCargar > 100) {
        showToast(`La calificación a asignar debe estar entre el mínimo institucional (${minAprobacion} pts) y 100 pts.`, "warning");
        if (inputNota) inputNota.focus();
        return;
    }

    const ok = await showConfirmModal({
        title: '¿Marcar Módulos como Completados?',
        message: `
            <p>¿Estás seguro de marcar como <strong>COMPLETADOS</strong> los siguientes módulos del curso <em>"${curso.titulo}"</em> para el usuario <strong>${usuario.nombre}</strong>?</p>
            <ul class="mb-3">${moduloListaHtml}</ul>
            <div class="p-2 mb-3 bg-light rounded border">
                <small class="text-muted d-block">Calificación que se registrará:</small>
                <span class="badge bg-success fs-6">${notaCargar} pts</span>
            </div>
            <div class="alert alert-info py-2 px-3 small mb-0">
                <strong>Efectos automáticos:</strong>
                <ul class="mb-0 ps-3">
                    <li>Aprobará automáticamente las evaluaciones con <strong>${notaCargar} pts</strong></li>
                    <li>Otorgará las medallas correspondientes</li>
                    <li>Marcará todas las lecciones como completadas</li>
                </ul>
            </div>
        `,
        confirmText: 'Sí, completar módulos',
        confirmVariant: 'success',
        icon: 'bi-check-circle-fill'
    });
    if (!ok) return;

    await withLoading(btn, async () => {
        if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
            usuario.progreso = {};
        }
        if (!usuario.progreso[cursoId] || Array.isArray(usuario.progreso[cursoId]) || typeof usuario.progreso[cursoId] !== 'object') {
            usuario.progreso[cursoId] = {
                leccionesCompletadas: [],
                modulosAprobados: [],
                medallas: [],
                evaluaciones: {},
                intentos: {}
            };
        }

        const progreso = usuario.progreso[cursoId];

        let marcadosExitosos = 0;
        let errores = [];

        for (const cb of checked) {
            const mIdx = parseInt(cb.getAttribute('data-module-idx'));
            const modulo = curso.modulos[mIdx];
            if (!modulo) {
                errores.push(`Módulo índice ${mIdx} no encontrado`);
                continue;
            }

            if (!progreso.modulosAprobados.includes(String(mIdx))) {
                progreso.modulosAprobados.push(String(mIdx));
            }

            if (!progreso.medallas) progreso.medallas = [];
            if (!progreso.medallas.includes(String(mIdx))) {
                progreso.medallas.push(String(mIdx));
            }

            // Registrar la evaluación con la calificación elegida por el administrador
            if (!progreso.evaluaciones) progreso.evaluaciones = {};
            progreso.evaluaciones[mIdx] = {
                calificacion: notaCargar,
                aprobado: true,
                marcadoManual: true,
                fecha: new Date().toISOString()
            };

            if (!progreso.leccionesCompletadas) progreso.leccionesCompletadas = [];
            if (modulo.lecciones && modulo.lecciones.length > 0) {
                for (let lIdx = 0; lIdx < modulo.lecciones.length; lIdx++) {
                    const lecId = `${mIdx}-${lIdx}`;
                    if (!progreso.leccionesCompletadas.includes(lecId)) {
                        progreso.leccionesCompletadas.push(lecId);
                    }
                }
            }

            // No se registra un intento: la acreditación manual no es un examen presentado,
            // y los intentos reales previos (si los hay) se conservan para los reportes.
            marcadosExitosos++;
        }

        const modulosConEvaluacion = curso.modulos.filter(m => m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0).length;
        const modulosRequeridosParaCertificado = modulosConEvaluacion > 0 ? modulosConEvaluacion : curso.modulos.length;

        if (progreso.modulosAprobados.length >= modulosRequeridosParaCertificado) {
            if (!usuario.certificadosCurso) usuario.certificadosCurso = [];
            if (!usuario.certificadosCurso.includes(cursoId)) {
                usuario.certificadosCurso.push(cursoId);
            }
        }

        actualizarEstadoCarrerasUsuario(usuario);
        await window.API.guardarUsuario(usuario);

        let mensaje = `✅ Se han marcado ${marcadosExitosos} módulo(s) como completados exitosamente para ${usuario.nombre}.`;
        if (errores.length > 0) {
            mensaje += `\n\n⚠️ Errores:\n• ${errores.join('\n• ')}`;
        }

        const totalModulosCurso = curso.modulos.length;
        const progresoActual = (progreso.modulosAprobados || []).length;
        if (progresoActual >= totalModulosCurso) {
            mensaje += `\n\n🎉 ¡FELICIDADES! El usuario ha completado TODOS los módulos del curso "${curso.titulo}". Se ha generado su certificado.`;
        }

        const carrerasCompletas = (usuario.carrerasAsignadas || []).filter(ca => ca.estado === 'Completada').map(ca => {
            const cObj = carreras.find(c => c.id === ca.id);
            return cObj ? cObj.nombre : ca.id;
        });
        if (carrerasCompletas.length > 0) {
            mensaje += `\n\n🎓 ¡CARRERA COMPLETADA! ${usuario.nombre} ha completado la carrera: ${carrerasCompletas.join(', ')}.`;
        }

        showToast(mensaje, 'success');

        const modalEl = document.getElementById('marcarCompletadoModal');
        if (modalEl && typeof bootstrap !== 'undefined') {
            const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bModal.hide();
        }
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';

        setTimeout(() => location.reload(), 2000);
    }, 'Marcando módulos como completados...');
};

// El panel recibe los usuarios sin progreso: antes de abrir estos modales se carga el del usuario.
(function () {
    const conProgreso = (fn) => async (userId) => {
        try {
            await App.asegurarProgreso([userId]);
        } catch (err) {
            showToast('No se pudo cargar el progreso: ' + err.message, 'danger');
            return;
        }
        return fn(userId);
    };
    window.abrirRestablecerAvance = conProgreso(window.abrirRestablecerAvance);
    window.abrirMarcarCompletado = conProgreso(window.abrirMarcarCompletado);
})();
