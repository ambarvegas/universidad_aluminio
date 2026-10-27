/**
 * Universidad del Aluminio — Player & LMS Classroom Module
 * Aula virtual, reproductor de lecciones y persistencia atómica de avance.
 * La evaluación de módulos está en evaluacion.js.
 */

async function guardarProgresoUsuario() {
    if (!sesion || !cursoActualData) return false;
    actualizarEstadoCarrerasUsuario(sesion);
    sessionStorage.setItem('aluSesion', JSON.stringify(sesion));

    const cursoId = cursoActualData.id;
    const prog = sesion.progreso?.[cursoId] || {};

    try {
        await window.API.guardarProgreso({
            usuario_id:            sesion.id,
            curso_id:              cursoId,
            leccionesCompletadas:  prog.leccionesCompletadas  || [],
            modulosAprobados:      prog.modulosAprobados      || [],
            medallas:              prog.medallas              || [],
            evaluaciones:          prog.evaluaciones          || {},
            intentos:              prog.intentos              || {},
            certificadosCurso:     sesion.certificadosCurso   || []
        });
        return true;
    } catch (err) {
        console.error('Error al guardar_progreso:', err.message);
        return false;
    }
}
window.guardarProgresoUsuario = guardarProgresoUsuario;

function verificarAccesoLeccion(mIdx, lIdx) {
    if (sesion && sesion.rol === 'admin') return true;
    if (mIdx === 0 && lIdx === 0) return true;

    if (!sesion || !cursoActualData) return false;
    const progreso = sesion.progreso ? sesion.progreso[cursoActualData.id] : null;
    if (!progreso) return false;

    if (Array.isArray(progreso)) {
        sesion.progreso[cursoActualData.id] = { leccionesCompletadas: progreso, modulosAprobados: [] };
    }

    const prog = sesion.progreso[cursoActualData.id];

    if (lIdx === 0 && mIdx > 0) {
        return prog.modulosAprobados && prog.modulosAprobados.includes(String(mIdx - 1));
    }

    let prevM = mIdx, prevL = lIdx - 1;
    if (prevL < 0) {
        prevM = mIdx - 1;
        if (prevM < 0) return true;
        prevL = cursoActualData.modulos[prevM].lecciones.length - 1;
    }
    const prevLecID = `${prevM}-${prevL}`;
    return prog.leccionesCompletadas && prog.leccionesCompletadas.includes(prevLecID);
}
window.verificarAccesoLeccion = verificarAccesoLeccion;

/**
 * Límite de intentos del módulo + intentos adicionales habilitados por un administrador.
 * 0 significa intentos ilimitados.
 */
function obtenerMaxIntentosEfectivo(modulo, progreso, mIdx) {
    const base = (modulo.maxIntentos !== undefined) ? (parseInt(modulo.maxIntentos) || 0) : (parseInt(modulo.max_intentos) || 0);
    if (base <= 0) return 0;
    const extra = parseInt(progreso?.intentosExtra?.[mIdx]) || 0;
    return base + extra;
}

function normalizarCurso(curso) {
    if (!curso) return null;
    curso.modulos = Array.isArray(curso.modulos) ? curso.modulos.map(mod => ({
        titulo: mod.titulo || 'Módulo sin título',
        enConstruccion: !!mod.enConstruccion,
        maxIntentos: mod.maxIntentos || 0,
        lecciones: Array.isArray(mod.lecciones) ? mod.lecciones : [],
        evaluacion: mod.evaluacion && Array.isArray(mod.evaluacion.preguntas) ? { preguntas: mod.evaluacion.preguntas } : { preguntas: [] }
    })) : [];
    return curso;
}
window.normalizarCurso = normalizarCurso;

window.marcarLeccionCompletada = async (event, mIdx, lIdx) => {
    const btn = event?.target?.closest('button');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Procesando...`;
    }

    try {
        if (!cursoActualData || !cursoActualData.id) {
            throw new Error('No se ha cargado el curso correctamente.');
        }

        const cursoID = cursoActualData.id;
        const lecID = `${mIdx}-${lIdx}`;

        if (!cursoActualData.modulos || !cursoActualData.modulos[mIdx]) {
            throw new Error(`El módulo ${mIdx} no existe en este curso.`);
        }

        const currentModule = cursoActualData.modulos[mIdx];

        if (!currentModule.lecciones || !currentModule.lecciones[lIdx]) {
            throw new Error(`La lección ${lIdx} no existe en el módulo ${mIdx}.`);
        }

        if (!sesion.progreso) {
            sesion.progreso = {};
        }

        if (!sesion.progreso[cursoID]) {
            sesion.progreso[cursoID] = {
                leccionesCompletadas: [],
                modulosAprobados: [],
                medallas: [],
                evaluaciones: {},
                intentos: {}
            };
        }

        if (Array.isArray(sesion.progreso[cursoID])) {
            const leccionesArray = sesion.progreso[cursoID];
            sesion.progreso[cursoID] = {
                leccionesCompletadas: leccionesArray,
                modulosAprobados: [],
                medallas: [],
                evaluaciones: {},
                intentos: {}
            };
        }

        const progresoCurso = sesion.progreso[cursoID];

        if (!progresoCurso.leccionesCompletadas) progresoCurso.leccionesCompletadas = [];
        if (!progresoCurso.modulosAprobados) progresoCurso.modulosAprobados = [];
        if (!progresoCurso.medallas) progresoCurso.medallas = [];
        if (!progresoCurso.evaluaciones || typeof progresoCurso.evaluaciones !== 'object') {
            progresoCurso.evaluaciones = {};
        }
        if (!progresoCurso.intentos || typeof progresoCurso.intentos !== 'object') {
            progresoCurso.intentos = {};
        }

        if (!progresoCurso.leccionesCompletadas.includes(lecID)) {
            progresoCurso.leccionesCompletadas.push(lecID);
        }

        const tieneLecciones = currentModule.lecciones && currentModule.lecciones.length > 0;
        let allLessonsInModuleCompleted = false;

        if (tieneLecciones) {
            allLessonsInModuleCompleted = currentModule.lecciones.every((_, index) => {
                const leccionId = `${mIdx}-${index}`;
                return progresoCurso.leccionesCompletadas.includes(leccionId);
            });
        }

        const tieneEvaluacion = currentModule.evaluacion &&
            currentModule.evaluacion.preguntas &&
            currentModule.evaluacion.preguntas.length > 0;

        if (allLessonsInModuleCompleted && !tieneEvaluacion) {
            if (!progresoCurso.modulosAprobados.includes(String(mIdx))) {
                progresoCurso.modulosAprobados.push(String(mIdx));
            }

            if (!progresoCurso.medallas.includes(String(mIdx))) {
                progresoCurso.medallas.push(String(mIdx));
            }

            const totalModulosCurso = (cursoActualData.modulos || []).length;
            const modulosConEvaluacion = cursoActualData.modulos.filter(m =>
                m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0
            ).length;

            const modulosAprobados = progresoCurso.modulosAprobados.length;
            if (modulosAprobados >= totalModulosCurso ||
                (modulosConEvaluacion > 0 && modulosAprobados >= modulosConEvaluacion)) {
                if (!sesion.certificadosCurso) sesion.certificadosCurso = [];
                if (!sesion.certificadosCurso.includes(cursoID)) {
                    sesion.certificadosCurso.push(cursoID);
                    showToast('🎉 ¡Felicidades! Has completado todos los módulos del curso.', 'success');
                }
            }
        }

        actualizarEstadoCarrerasUsuario(sesion);

        try {
            await guardarProgresoUsuario();
        } catch (saveError) {
            console.error('Error al guardar progreso:', saveError);
            sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
            showToast('Progreso guardado localmente. Se sincronizará automáticamente.', 'warning');
        }

        const contenidoCurso = document.getElementById('contenido-curso');
        if (contenidoCurso) {
            contenidoCurso.innerHTML = renderizarCursoTeachlr(cursoActualData);
        }

        if (allLessonsInModuleCompleted &&
            !progresoCurso.modulosAprobados.includes(String(mIdx)) &&
            tieneEvaluacion) {

            showToast(`¡Módulo "${currentModule.titulo}" finalizado! Procede a la evaluación.`, 'info');
            setTimeout(() => {
                mostrarEvaluacionModulo(cursoID, mIdx);
            }, 500);
            return;
        }

        setTimeout(() => {
            seleccionarLeccion(mIdx, lIdx);
        }, 200);

    } catch (error) {
        console.error('Error en marcarLeccionCompletada:', error);
        showToast('Error al guardar el progreso: ' + error.message, 'danger');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Finalizar Lección';
        }
    }
};

window.navegarLeccion = (dir, m, l) => {
    if (!cursoActualData || !cursoActualData.modulos) return;
    let newM = m, newL = l;
    if (dir === 'next') {
        newL++;
        if (newL >= cursoActualData.modulos[m].lecciones.length) {
            newM++;
            newL = 0;
        }
    } else {
        newL--;
        if (newL < 0) {
            newM--;
            if (newM >= 0 && cursoActualData.modulos[newM]?.lecciones) {
                newL = cursoActualData.modulos[newM].lecciones.length - 1;
            }
        }
    }
    if (cursoActualData.modulos[newM] && cursoActualData.modulos[newM].lecciones && cursoActualData.modulos[newM].lecciones[newL]) {
        seleccionarLeccion(newM, newL);
    }
};

window.seleccionarLeccion = (mIdx, lIdx) => {
    if (!cursoActualData || !cursoActualData.modulos) {
        console.error('No hay curso cargado');
        return;
    }

    if (!cursoActualData.modulos[mIdx]) {
        console.error(`Módulo ${mIdx} no encontrado`);
        return;
    }

    const moduloCheck = cursoActualData.modulos[mIdx];
    const visorCheck = document.getElementById('visor-contenido');
    if (moduloCheck && moduloCheck.enConstruccion) {
        if (visorCheck) {
            visorCheck.innerHTML = `
                <div class="card border-warning bg-warning bg-opacity-10 p-5 text-center my-4 rounded-4 shadow-sm">
                    <div class="display-2 text-warning mb-3"><i class="bi bi-cone-striped"></i></div>
                    <h3 class="fw-bold text-dark mb-2">Módulo en Construcción</h3>
                    <p class="text-muted fs-6 mb-0">El módulo <strong>"${escapeHtml(moduloCheck.titulo)}"</strong> se encuentra actualmente en desarrollo. ¡Sus lecciones estarán disponibles muy pronto!</p>
                </div>
            `;
        }
        return;
    }

    if (!moduloCheck.lecciones || !moduloCheck.lecciones[lIdx]) {
        console.error(`Lección no encontrada: módulo ${mIdx}, lección ${lIdx}`);
        return;
    }

    if (!verificarAccesoLeccion(mIdx, lIdx)) return;

    const modulo = cursoActualData.modulos[mIdx];
    const leccion = modulo.lecciones[lIdx];
    const visor = document.getElementById('visor-contenido');
    if (!visor) return;

    // Actualizar estados visuales de los botones de la barra lateral
    document.querySelectorAll('.lesson-item-btn').forEach(btn => btn.classList.remove('active'));
    const btnActivo = document.getElementById(`btn-l-${mIdx}-${lIdx}`);
    if (btnActivo) btnActivo.classList.add('active');

    const lecID = `${mIdx}-${lIdx}`;
    const progreso = sesion && sesion.progreso ? sesion.progreso[cursoActualData.id] : null;
    const estaCompletada = progreso && (Array.isArray(progreso) ?
        progreso.includes(lecID) :
        (progreso.leccionesCompletadas && progreso.leccionesCompletadas.includes(lecID)));

    // Calcular navegación previa y siguiente
    let hayPrev = (mIdx > 0 || lIdx > 0);
    let prevM = mIdx, prevL = lIdx - 1;
    if (prevL < 0) {
        prevM = mIdx - 1;
        if (prevM >= 0 && cursoActualData.modulos[prevM] && cursoActualData.modulos[prevM].lecciones) {
            prevL = cursoActualData.modulos[prevM].lecciones.length - 1;
        }
    }

    let haySiguiente = false;
    let proximoM = mIdx, proximoL = lIdx + 1;
    if (proximoL >= modulo.lecciones.length) {
        proximoM = mIdx + 1;
        proximoL = 0;
    }
    haySiguiente = cursoActualData.modulos[proximoM] && cursoActualData.modulos[proximoM].lecciones &&
        cursoActualData.modulos[proximoM].lecciones[proximoL];

    // Parser seguro de video ID (YouTube). Una URL que no sea de YouTube solo se acepta si es https.
    let videoUrl = '';
    const rawVideo = String(leccion.videoID || '').trim();
    if (rawVideo.includes('http') || rawVideo.includes('youtu')) {
        const parsed = typeof extraerID === 'function' ? extraerID(rawVideo) : '';
        if (parsed && parsed !== rawVideo) {
            videoUrl = `https://www.youtube.com/embed/${encodeURIComponent(parsed)}?rel=0&modestbranding=1`;
        } else if (/^https:\/\//i.test(rawVideo)) {
            videoUrl = rawVideo;
        }
    } else if (rawVideo) {
        videoUrl = `https://www.youtube.com/embed/${encodeURIComponent(rawVideo)}?rel=0&modestbranding=1`;
    }
    const adjuntoUrl = urlSegura(leccion.adjunto);

    visor.innerHTML = `
        <div class="card border-0 shadow-sm p-4 mb-4 bg-white rounded-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                    <span class="badge-soft-primary mb-1 d-inline-block">Módulo ${mIdx + 1}: ${escapeHtml(modulo.titulo)}</span>
                    <h3 class="fw-bold text-primary mb-0">${escapeHtml(leccion.titulo || 'Lección sin título')}</h3>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
                    ${estaCompletada 
                        ? `<span class="badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>Completada</span>`
                        : `<span class="badge-soft-warning"><i class="bi bi-clock me-1"></i>En curso</span>`}
                </div>
            </div>

            <!-- Reproductor de Video 16:9 -->
            ${videoUrl ? `
                <div class="player-container mb-3 shadow-sm">
                    <iframe src="${escapeHtml(videoUrl)}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>` : `
                <div class="alert alert-light border text-center py-4 mb-3">
                    <i class="bi bi-film display-4 text-muted d-block mb-2"></i>
                    <p class="text-muted mb-0">Esta lección es de lectura o material complementario.</p>
                </div>`}

            <!-- Barra de Navegación de Lecciones -->
            <div class="player-nav-bar mb-4">
                <button class="btn btn-outline-secondary btn-sm" ${!hayPrev ? 'disabled' : ''} onclick="seleccionarLeccion(${prevM}, ${prevL})">
                    <i class="bi bi-chevron-left"></i> Anterior
                </button>
                
                <div>
                    ${!estaCompletada ? `
                        <button class="btn btn-success btn-sm px-3" onclick="marcarLeccionCompletada(event, ${mIdx}, ${lIdx})" id="btn-finalizar-${mIdx}-${lIdx}">
                            <i class="bi bi-check2-circle me-1"></i> Marcar como Completada
                        </button>` : `
                        <button class="btn btn-outline-success btn-sm px-3" disabled>
                            <i class="bi bi-check-all me-1"></i> Lección Completada
                        </button>`}
                </div>

                <button class="btn btn-primary btn-sm" ${!haySiguiente ? 'disabled' : ''} onclick="seleccionarLeccion(${proximoM}, ${proximoL})">
                    Siguiente <i class="bi bi-chevron-right"></i>
                </button>
            </div>

            <!-- Material de Apoyo y Contenido Escrito -->
            <div class="bg-light p-4 rounded-3 border">
                <h5 class="fw-bold text-primary mb-3 d-flex align-items-center">
                    <i class="bi bi-file-text me-2 text-accent"></i>Contenido y Material de Apoyo
                </h5>
                <div class="text-secondary mb-3" style="white-space: pre-wrap; line-height: 1.7; font-size: 0.95rem;">
                    ${escapeHtml(leccion.contenido || 'No hay descripción adicional para esta lección.')}
                </div>
                ${adjuntoUrl ? `
                    <div class="pt-3 border-top">
                        <a href="${escapeHtml(adjuntoUrl)}" download="${escapeHtml(leccion.nombreAdjunto || 'recurso')}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-paperclip me-1"></i> Descargar Adjunto: <strong>${escapeHtml(leccion.nombreAdjunto || 'Archivo')}</strong>
                        </a>
                    </div>` : ''}
            </div>
        </div>
    `;
};

function renderizarCursoTeachlr(curso) {
    curso = normalizarCurso(curso);
    cursoActualData = curso;

    if (curso.enConstruccion) {
        return `
            <div class="card border-warning bg-warning bg-opacity-10 p-5 text-center my-4 rounded-4 shadow-sm">
                <div class="display-1 text-warning mb-3"><i class="bi bi-cone-striped"></i></div>
                <h2 class="fw-bold text-dark mb-2">Curso en Construcción</h2>
                <p class="text-muted fs-5 mb-4">El curso <strong>"${escapeHtml(curso.titulo)}"</strong> se encuentra actualmente en desarrollo y afinamiento. ¡Estará disponible para ti muy pronto!</p>
                <div>
                    <a href="index.php" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-arrow-left me-1"></i>Volver a Mis Cursos
                    </a>
                </div>
            </div>
        `;
    }

    if (!sesion.progreso) sesion.progreso = {};
    if (!sesion.progreso[curso.id]) {
        sesion.progreso[curso.id] = {
            leccionesCompletadas: [],
            modulosAprobados: [],
            medallas: [],
            evaluaciones: {},
            intentos: {}
        };
    }

    if (Array.isArray(sesion.progreso[curso.id])) {
        const leccionesArray = sesion.progreso[curso.id];
        sesion.progreso[curso.id] = {
            leccionesCompletadas: leccionesArray,
            modulosAprobados: [],
            medallas: [],
            evaluaciones: {},
            intentos: {}
        };
    }

    const leccionesCompletadas = sesion.progreso[curso.id].leccionesCompletadas || [];
    const modulosAprobados = sesion.progreso[curso.id].modulosAprobados || [];
    const modulosList = curso.modulos || [];
    const totalLecciones = modulosList.reduce((acc, m) => acc + (m.lecciones ? m.lecciones.length : 0), 0);
    const completadas = leccionesCompletadas.length;
    const progreso = totalLecciones > 0 ? Math.round((completadas / totalLecciones) * 100) : 0;

    const yaTieneCertificado = sesion.certificadosCurso && sesion.certificadosCurso.includes(curso.id);

    setTimeout(() => {
        if (typeof seleccionarLeccion === 'function') {
            let primeraPendiente = null;
            for (let m = 0; m < modulosList.length; m++) {
                if (modulosList[m].enConstruccion) continue;
                const lecs = modulosList[m].lecciones || [];
                for (let l = 0; l < lecs.length; l++) {
                    if (!leccionesCompletadas.includes(`${m}-${l}`) && verificarAccesoLeccion(m, l)) {
                        primeraPendiente = { m, l };
                        break;
                    }
                }
                if (primeraPendiente) break;
            }
            if (primeraPendiente) {
                seleccionarLeccion(primeraPendiente.m, primeraPendiente.l);
            } else if (modulosList.length > 0 && !modulosList[0].enConstruccion && modulosList[0].lecciones && modulosList[0].lecciones.length > 0) {
                seleccionarLeccion(0, 0);
            }
        }
    }, 100);

    return `
        <div class="row g-4">
            <!-- Sidebar del LMS -->
            <div class="col-lg-4 col-xl-3">
                
                <!-- Tarjeta Resumen del Curso -->
                <div class="card border-0 shadow-sm p-3 mb-3 bg-white rounded-3">
                    ${curso.tipo === 'pruebas'
                        ? '<span class="badge text-white mb-2 align-self-start" style="background:#7c3aed"><i class="bi bi-flask me-1"></i>Modo de Pruebas (Admin)</span>'
                        : '<span class="badge-soft-primary mb-2 align-self-start"><i class="bi bi-mortarboard-fill me-1"></i>Programa Académico</span>'}
                    <h5 class="fw-bold text-primary mb-2">${escapeHtml(curso.titulo)}</h5>
                    
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted fw-semibold">Progreso general</small>
                        <small class="fw-bold text-primary">${progreso}%</small>
                    </div>
                    <div class="progress-modern mb-3">
                        <div class="progress-bar ${progreso >= 100 ? 'bg-success' : 'bg-primary'}" style="width: ${progreso}%"></div>
                    </div>

                    <!-- Medallas Obtenidas -->
                    <div class="pt-2 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-secondary text-uppercase" style="font-size: 0.75rem;">Medallas Ganadas</span>
                            <span class="badge bg-light text-dark border">${modulosAprobados.length}</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            ${modulosAprobados.length > 0 ? modulosAprobados.map(m => {
                                const modIndex = parseInt(m);
                                const moduloNombre = curso.modulos[modIndex] ? curso.modulos[modIndex].titulo : `Módulo ${modIndex + 1}`;
                                return `
                                    <span class="badge bg-warning bg-opacity-25 text-dark border border-warning px-2 py-1 d-inline-flex align-items-center" title="${escapeHtml(moduloNombre)}">
                                        <span class="me-1">🏆</span> <span class="text-truncate" style="max-width: 120px;">M${modIndex + 1}</span>
                                    </span>`;
                            }).join('') : '<small class="text-muted fst-italic">Completa las evaluaciones para desbloquear medallas.</small>'}
                        </div>
                    </div>

                    ${yaTieneCertificado ? `
                        <div class="mt-3 pt-3 border-top">
                            <button class="btn btn-gold w-100 shadow-sm py-2" onclick="descargarCertificado(${jsArg(sesion.nombre)}, ${jsArg(sesion.id)}, ${jsArg(curso.titulo)})">
                                <i class="bi bi-award-fill me-1"></i> Descargar Certificado
                            </button>
                        </div>
                    ` : ''}
                </div>

                <!-- Acordeón de Módulos y Lecciones -->
                <div class="accordion lms-sidebar shadow-sm" id="accordionModulos">
                    ${modulosList.map((mod, idx) => {
                        const modEnConstruccion = !!mod.enConstruccion;
                        const tieneLecciones = mod.lecciones && mod.lecciones.length > 0;
                        const todasLeccionesMod = tieneLecciones && mod.lecciones.every((_, lIdx) =>
                            leccionesCompletadas.includes(`${idx}-${lIdx}`)
                        );
                        const moduloAprobado = modulosAprobados.includes(String(idx));
                        const tieneEvaluacion = mod.evaluacion && mod.evaluacion.preguntas && mod.evaluacion.preguntas.length > 0;
                        const estaEnCurso = tieneLecciones && mod.lecciones.some((_, lIdx) =>
                            leccionesCompletadas.includes(`${idx}-${lIdx}`)
                        ) && !moduloAprobado;

                        const progCurso = sesion.progreso[curso.id];
                        const intentosRealizados = progCurso.intentos ? (parseInt(progCurso.intentos[idx]) || 0) : 0;
                        const intentosMaximos = obtenerMaxIntentosEfectivo(mod, progCurso, idx);
                        const intentosAgotados = (intentosMaximos > 0 && intentosRealizados >= intentosMaximos && !moduloAprobado);

                        let estadoEvaluacion = 'pendiente';
                        if (modEnConstruccion) estadoEvaluacion = 'bloqueado';
                        else if (moduloAprobado) estadoEvaluacion = 'aprobado';
                        else if (intentosAgotados) estadoEvaluacion = 'agotado';
                        else if (!tieneLecciones || !todasLeccionesMod) estadoEvaluacion = 'bloqueado';
                        else if (tieneEvaluacion) estadoEvaluacion = 'disponible';

                        return `
                        <div class="accordion-item border mb-2 rounded-3 overflow-hidden ${modEnConstruccion ? 'border-warning' : ''}">
                            <h2 class="accordion-header">
                                <button class="accordion-button ${idx === 0 ? '' : 'collapsed'} py-2 px-3 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#mod${idx}" style="font-size: 0.875rem;">
                                    ${modEnConstruccion 
                                        ? '<i class="bi bi-cone-striped text-warning me-2 fs-5"></i>' 
                                        : (moduloAprobado 
                                            ? '<i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>' 
                                            : (estaEnCurso ? '<i class="bi bi-play-circle-fill text-primary me-2 fs-5"></i>' : '<i class="bi bi-folder2 text-secondary me-2 fs-5"></i>'))} 
                                    <div class="text-truncate">
                                        Módulo ${idx + 1}: ${escapeHtml(mod.titulo)}
                                        ${modEnConstruccion ? '<span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><i class="bi bi-cone-striped me-1"></i>En Construcción</span>' : ''}
                                    </div>
                                </button>
                            </h2>
                            <div id="mod${idx}" class="accordion-collapse collapse ${idx === 0 ? 'show' : ''}" data-bs-parent="#accordionModulos">
                                <div class="p-1 bg-white">
                                    ${modEnConstruccion ? `
                                        <div class="text-muted text-center py-3 small bg-warning bg-opacity-10 m-2 rounded border border-warning">
                                            <i class="bi bi-cone-striped text-warning me-1"></i>Módulo en construcción
                                        </div>
                                    ` : (tieneLecciones ? mod.lecciones.map((lec, lIdx) => {
                                        const lecID = `${idx}-${lIdx}`;
                                        const estaCompletada = leccionesCompletadas.includes(lecID);
                                        const estaBloqueada = !verificarAccesoLeccion(idx, lIdx);
                                        return `
                                            <button class="lesson-item-btn ${estaBloqueada ? 'locked' : ''} ${estaCompletada ? 'completed' : ''}"
                                                    ${estaBloqueada ? 'disabled' : ''} 
                                                    id="btn-l-${idx}-${lIdx}"
                                                    onclick="seleccionarLeccion(${idx}, ${lIdx})">
                                                <div class="d-flex align-items-center gap-2 w-100 justify-content-between">
                                                    <div class="d-flex align-items-center gap-2 text-truncate">
                                                        <i class="bi ${estaCompletada ? 'bi-check-circle-fill text-success' : (estaBloqueada ? 'bi-lock-fill text-muted' : 'bi-play-circle text-primary')}"></i>
                                                        <span class="text-truncate">${lIdx + 1}. ${escapeHtml(lec.titulo)}</span>
                                                    </div>
                                                    ${estaBloqueada ? '<i class="bi bi-lock-fill text-muted small"></i>' : ''}
                                                </div>
                                            </button>
                                        `;}).join('') : `
                                        <div class="text-muted text-center py-2 small">
                                            <i class="bi bi-info-circle me-1"></i>Sin lecciones registradas
                                        </div>
                                    `)}

                                    <!-- Botón de Evaluación del Módulo -->
                                    <div class="p-1 pt-2 border-top mt-1">
                                        <button class="btn btn-sm w-100 ${
                                            estadoEvaluacion === 'aprobado' ? 'btn-outline-success' :
                                            estadoEvaluacion === 'disponible' ? 'btn-primary shadow-sm' :
                                            estadoEvaluacion === 'agotado' ? 'btn-outline-danger' :
                                            'btn-light text-muted disabled'
                                        }"
                                        onclick="${estadoEvaluacion === 'disponible' || estadoEvaluacion === 'aprobado' || estadoEvaluacion === 'agotado' ? `mostrarEvaluacionModulo(${jsArg(curso.id)}, ${idx})` : 'return false'}"
                                        ${estadoEvaluacion === 'bloqueado' || (estadoEvaluacion === 'pendiente' && !tieneEvaluacion) ? 'disabled' : ''}>
                                            <i class="bi ${
                                                estadoEvaluacion === 'aprobado' ? 'bi-check-circle-fill text-success' :
                                                estadoEvaluacion === 'disponible' ? 'bi-patch-question-fill' :
                                                estadoEvaluacion === 'agotado' ? 'bi-lock-fill text-danger' :
                                                'bi-lock-fill'
                                            } me-1"></i>
                                            ${
                                                estadoEvaluacion === 'aprobado' ? 'Evaluación Aprobada ✓' :
                                                estadoEvaluacion === 'disponible' ? 'Realizar Evaluación' :
                                                estadoEvaluacion === 'agotado' ? 'Intentos Agotados (Bloqueada)' :
                                                !tieneEvaluacion ? 'Sin Evaluación' : 'Evaluación Bloqueada'
                                            }
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `}).join('')}
                </div>
            </div>

            <!-- Visor Principal del Contenido LMS -->
            <div class="col-lg-8 col-xl-9" id="visor-contenido">
                <div class="card border-0 shadow-sm p-5 text-center bg-white rounded-3">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <h5 class="fw-bold text-secondary">Cargando lección...</h5>
                    <p class="text-muted small">Selecciona cualquier lección del temario lateral si no inicia automáticamente.</p>
                </div>
            </div>
        </div>
    `;
}
window.renderizarCursoTeachlr = renderizarCursoTeachlr;

async function mostrarDetalleCurso(cursoId) {
    const contenidoCursoDiv = document.getElementById('contenido-curso');

    if (!contenidoCursoDiv) {
        console.error('Elemento contenido-curso no encontrado');
        return;
    }

    let curso = (cursos || []).find(c => c.id === cursoId);

    // Carga bajo demanda si el curso es un resumen del catálogo o no tiene lecciones completas
    const necesitaCargar = !curso || curso._esResumen || (curso.modulos && curso.modulos.length > 0 && (!curso.modulos[0].lecciones || curso.modulos[0].lecciones[0] === null));

    if (necesitaCargar) {
        contenidoCursoDiv.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
                    <span class="visually-hidden">Cargando curso...</span>
                </div>
                <h4 class="fw-bold text-primary mb-1">Cargando contenido del curso...</h4>
                <p class="text-muted small mb-0"><i class="bi bi-cloud-arrow-down me-1"></i>Sincronizando lecciones y multimedia...</p>
            </div>`;

        try {
            const res = await window.API.cargarCurso(cursoId);
            if (res && res.curso) {
                curso = res.curso;
                const idx = (cursos || []).findIndex(c => c.id === cursoId);
                if (idx !== -1) {
                    cursos[idx] = curso;
                } else {
                    cursos.push(curso);
                }
            }
        } catch (loadErr) {
            console.error('Error al cargar detalle del curso bajo demanda:', loadErr);
            showToast('Error al descargar el contenido del curso.', 'danger');
        }
    }

    if (curso) {
        try {
            const cNorm = normalizarCurso(curso);
            contenidoCursoDiv.innerHTML = renderizarCursoTeachlr(cNorm);

            // Si el curso completo está en construcción, no se deben cargar lecciones en el visor
            if (cNorm.enConstruccion) return;

            setTimeout(() => {
                if (cursoActualData && cursoActualData.modulos && cursoActualData.modulos.length > 0) {
                    const primerModulo = cursoActualData.modulos[0];
                    if (primerModulo && primerModulo.lecciones && primerModulo.lecciones.length > 0) {
                        seleccionarLeccion(0, 0);
                    } else {
                        const visor = document.getElementById('visor-contenido');
                        if (visor) {
                            visor.innerHTML = `
                                <div class="text-center py-5 text-muted bg-white rounded shadow-sm">
                                    <i class="bi bi-info-circle display-1 d-block mb-3"></i>
                                    <h4>Este curso no tiene lecciones aún</h4>
                                    <p>El administrador está preparando el contenido.</p>
                                </div>
                            `;
                        }
                    }
                }
            }, 300);
        } catch (error) {
            console.error('Error al renderizar el curso:', error);
            contenidoCursoDiv.innerHTML = `
                <div class="alert alert-danger text-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Error al cargar el curso: ${escapeHtml(error.message)}
                </div>
            `;
        }
    } else {
        contenidoCursoDiv.innerHTML = `
            <div class="alert alert-warning text-center" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                Curso no encontrado. Verifica que el ID sea correcto.
            </div>`;
    }
}
window.mostrarDetalleCurso = mostrarDetalleCurso;
