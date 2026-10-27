/**
 * Universidad del Aluminio — Evaluación de módulos
 * Muestra la evaluación (preguntas fijas o aleatorias, límite de intentos) y envía las
 * respuestas al servidor para calificarlas. Usa obtenerMaxIntentosEfectivo() de player.js.
 */

window.mostrarEvaluacionModulo = (cursoID, mIdx) => {
    const curso = (cursos || []).find(c => c.id === cursoID) || cursoActualData;
    if (!curso || !curso.modulos || !curso.modulos[mIdx]) return;
    const modulo = curso.modulos[mIdx];
    if (modulo && modulo.enConstruccion) {
        showToast('Este módulo se encuentra en construcción. La evaluación aún no está disponible.', 'warning');
        return;
    }
    const visor = document.getElementById('visor-contenido');
    if (!visor) return;

    if (!sesion.progreso) sesion.progreso = {};
    if (!sesion.progreso[curso.id]) sesion.progreso[curso.id] = { leccionesCompletadas: [], modulosAprobados: [], intentos: {} };
    const progreso = sesion.progreso[curso.id];
    const modulosAprobados = Array.isArray(progreso) ? [] : (progreso.modulosAprobados || []);

    const moduloYaAprobado = modulosAprobados.includes(String(mIdx));

    if (moduloYaAprobado) {
        visor.innerHTML = `
            <div class="quiz-card text-center p-5">
                <div class="quiz-score-badge pass"><i class="bi bi-award-fill"></i></div>
                <h2 class="fw-bold text-primary mb-2">¡Módulo "${escapeHtml(modulo.titulo)}" Aprobado!</h2>
                <p class="lead text-muted mb-4">Has aprobado satisfactoriamente la evaluación correspondiente a este módulo.</p>
                <div class="d-flex justify-content-center gap-3">
                    <button class="btn btn-outline-primary" onclick="reintentarEvaluacion(${jsArg(curso.id)}, ${mIdx})">
                        <i class="bi bi-arrow-repeat me-1"></i>Repasar Evaluación
                    </button>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-left me-1"></i>Volver al Curso
                    </button>
                </div>
            </div>`;
        return;
    }

    // Verificar límite de intentos (incluye reintentos habilitados por administración)
    const maxIntentos = obtenerMaxIntentosEfectivo(modulo, progreso, mIdx);
    const intentosActuales = (progreso.intentos && progreso.intentos[mIdx]) ? (parseInt(progreso.intentos[mIdx]) || 0) : 0;
    const bloqueadoPorIntentos = (maxIntentos > 0 && intentosActuales >= maxIntentos);

    if (bloqueadoPorIntentos) {
        visor.innerHTML = `
            <div class="quiz-card text-center p-5 border-danger shadow-sm">
                <div class="quiz-score-badge fail mb-3"><i class="bi bi-lock-fill"></i></div>
                <h2 class="fw-bold text-danger mb-2">Evaluación Bloqueada</h2>
                <p class="lead text-muted mb-3">Has alcanzado el límite máximo de <strong>${maxIntentos} ${maxIntentos === 1 ? 'intento' : 'intentos'}</strong> permitidos para el módulo "${escapeHtml(modulo.titulo)}".</p>
                <div class="alert alert-warning text-start mx-auto my-4" style="max-width: 550px;">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Para solicitar una nueva oportunidad o el restablecimiento de tus intentos en este módulo, ponte en contacto con el <strong>Administrador o Tutor</strong> de la plataforma.
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button class="btn btn-primary px-4" onclick="location.reload()">
                        <i class="bi bi-arrow-left me-1"></i>Volver al Curso
                    </button>
                </div>
            </div>`;
        return;
    }

    const minAprobacion = (db && db.configuracion && db.configuracion.minAprobacion) || 70;
    const evalConfig = modulo.evaluacion || {};
    const poolPreguntas = Array.isArray(evalConfig.preguntas) ? evalConfig.preguntas : [];
    const evalTipo = evalConfig.tipo || 'fijo';
    const numPreguntasConfig = parseInt(evalConfig.numPreguntas, 10) || 0;
    const mezclarOpciones = Boolean(evalConfig.mezclarOpciones);

    // Función auxiliar para barajar arreglos (Fisher-Yates)
    const mezclarArray = (arr) => {
        const res = [...arr];
        for (let i = res.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [res[i], res[j]] = [res[j], res[i]];
        }
        return res;
    };

    // Seleccionar preguntas para la evaluación activa
    let preguntasActivas = [];
    if (poolPreguntas.length > 0) {
        if (evalTipo === 'aleatorio') {
            const poolMezclado = mezclarArray(poolPreguntas);
            const cantidad = (numPreguntasConfig > 0 && numPreguntasConfig <= poolPreguntas.length)
                ? numPreguntasConfig
                : poolPreguntas.length;
            preguntasActivas = poolMezclado.slice(0, cantidad);
        } else {
            // Fijo
            if (numPreguntasConfig > 0 && numPreguntasConfig <= poolPreguntas.length) {
                preguntasActivas = poolPreguntas.slice(0, numPreguntasConfig);
            } else {
                preguntasActivas = [...poolPreguntas];
            }
        }
    }

    // Mapear y mezclar opciones si corresponde preservando el índice original para la calificación
    const preguntasRenderizadas = preguntasActivas.map((p, pIdx) => {
        const opcionesRaw = Array.isArray(p.opciones) ? p.opciones : [];
        let opcionesMap = opcionesRaw.map((opt, origIdx) => {
            const texto = (typeof opt === 'object' && opt !== null) ? (opt.texto || '') : String(opt || '');
            const imagen = (typeof opt === 'object' && opt !== null) ? (opt.imagen || '') : '';
            return { origIdx, texto, imagen };
        });

        if (mezclarOpciones) {
            opcionesMap = mezclarArray(opcionesMap);
        }

        return {
            id: p.id !== undefined ? p.id : pIdx,
            enunciado: p.enunciado || '',
            imagen: p.imagen || '',
            opciones: opcionesMap,
            preguntaRef: p
        };
    });

    // Guardar estado del quiz activo en memoria
    window._quizActivo = {
        cursoId: curso.id,
        mIdx: mIdx,
        preguntas: preguntasRenderizadas
    };

    const totalBanco = poolPreguntas.length;
    const totalMostrar = preguntasRenderizadas.length;

    const badgeIntentos = (maxIntentos > 0)
        ? `<span class="badge bg-warning bg-opacity-25 text-dark border border-warning px-3 py-2 ms-2">
            <i class="bi bi-arrow-repeat text-warning me-1"></i> Intento ${intentosActuales + 1} de ${maxIntentos}
           </span>`
        : `<span class="badge bg-light text-muted border px-3 py-2 ms-2">
            <i class="bi bi-infinity me-1"></i> Intentos ilimitados
           </span>`;

    const badgeModo = (evalTipo === 'aleatorio' && totalBanco > totalMostrar)
        ? `<span class="badge bg-info bg-opacity-10 text-info border border-info px-3 py-2 ms-2">
            <i class="bi bi-shuffle me-1"></i> Banco: ${totalMostrar} de ${totalBanco} preguntas
           </span>`
        : `<span class="badge bg-light text-dark border px-3 py-2">
            <i class="bi bi-check2-square text-primary me-1"></i> ${totalMostrar} preguntas
           </span>`;

    visor.innerHTML = `
        <div class="quiz-card p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom flex-wrap gap-2">
                <div>
                    <span class="badge-soft-warning mb-1 d-inline-block">Examen de Módulo ${mIdx + 1}</span>
                    <h3 class="fw-bold text-primary mb-0">${escapeHtml(modulo.titulo)}</h3>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-1">
                    ${badgeModo}
                    ${badgeIntentos}
                </div>
            </div>

            <p class="text-muted small mb-4">
                <i class="bi bi-info-circle me-1 text-primary"></i> 
                Responde todas las preguntas. Se requiere un puntaje mínimo de <strong>${minAprobacion}%</strong> para aprobar este módulo y desbloquear los siguientes contenidos.
                ${maxIntentos > 0 ? `<br><strong class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Atención:</strong> Tienes un máximo de <strong>${maxIntentos} ${maxIntentos === 1 ? 'intento' : 'intentos'}</strong> para aprobar.` : ''}
            </p>

            <div class="quiz-area mb-4">
                ${preguntasRenderizadas.map((p, i) => `
                    <div class="card p-3 mb-4 border bg-light shadow-sm rounded-3">
                        <h6 class="fw-bold text-primary mb-3 d-flex align-items-start gap-2">
                            <span class="badge bg-primary flex-shrink-0">${i + 1}</span>
                            <span class="flex-grow-1">${escapeHtml(p.enunciado)}</span>
                        </h6>
                        ${p.imagen ? `
                            <div class="quiz-question-img-container mb-3 text-center">
                                <img src="${escapeHtml(urlSegura(p.imagen))}" alt="Ilustración de la pregunta" class="img-fluid rounded border shadow-sm quiz-question-img" style="max-height: 280px; object-fit: contain;">
                            </div>
                        ` : ''}
                        <div class="quiz-options-list d-flex flex-column gap-2">
                            ${p.opciones.map((optItem, oIdx) => `
                                <label class="quiz-option-label d-flex align-items-center gap-3 p-2 px-3 rounded border bg-white shadow-xs" for="q${i}o${oIdx}" style="cursor: pointer;">
                                    <input type="radio" name="q${i}" value="${optItem.origIdx}" id="q${i}o${oIdx}" class="form-check-input mt-0 flex-shrink-0" style="width: 1.2rem; height: 1.2rem;">
                                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                                        ${optItem.imagen ? `
                                            <img src="${escapeHtml(urlSegura(optItem.imagen))}" alt="Opción" class="rounded border quiz-option-img" style="width: 45px; height: 45px; object-fit: cover; flex-shrink: 0;">
                                        ` : ''}
                                        <span class="quiz-option-text">${escapeHtml(optItem.texto)}</span>
                                    </div>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                `).join('')}
            </div>

            <button id="btn-enviar-evaluacion" class="btn btn-primary btn-lg w-100 py-3" onclick="validarEvaluacionModulo(${mIdx})">
                <i class="bi bi-send-check-fill me-2"></i>Enviar y Calificar Evaluación
            </button>
            <div id="feedback" class="mt-4"></div>
        </div>`;
};

window.validarEvaluacionModulo = async (mIdx) => {
    const btn = document.getElementById('btn-enviar-evaluacion');
    if (!btn) return;

    const originalHtml = btn.innerHTML;

    try {
        const modulo = cursoActualData.modulos[mIdx];
        const activeQuiz = (window._quizActivo && window._quizActivo.mIdx === mIdx) 
            ? window._quizActivo 
            : null;
        const preguntas = activeQuiz 
            ? activeQuiz.preguntas 
            : ((modulo.evaluacion && modulo.evaluacion.preguntas) || []);
        const feedback = document.getElementById('feedback');

        // 1. Verificar que todas las preguntas hayan sido respondidas
        let todasRespondidas = true;
        for (let i = 0; i < preguntas.length; i++) {
            const sel = document.querySelector(`input[name="q${i}"]:checked`);
            if (!sel) {
                todasRespondidas = false;
                break;
            }
        }

        if (!todasRespondidas) {
            showToast("Por favor responde todas las preguntas antes de enviar la evaluación.", "warning");
            return;
        }

        // 2. Recolectar respuestas seleccionadas con pregunta_id e índice original de opción
        const respuestas = preguntas.map((p, i) => {
            const sel = document.querySelector(`input[name="q${i}"]:checked`);
            const opcionOriginal = sel ? parseInt(sel.value, 10) : -1;
            const pid = p.id !== undefined ? p.id : (p.preguntaRef && p.preguntaRef.id !== undefined ? p.preguntaRef.id : i);
            return {
                pregunta_id: pid,
                opcion: opcionOriginal
            };
        });

        // 3. Estado de carga en el botón
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Calificando en el servidor...`;

        // 4. Enviar respuestas al servidor para calificación segura
        const res = await window.API.evaluarModulo({
            curso_id: cursoActualData.id,
            modulo_idx: mIdx,
            respuestas: respuestas
        });

        // 5. Actualizar sesión y progreso local con la respuesta autorizada del servidor
        if (!sesion.progreso) sesion.progreso = {};
        const progPrevio = sesion.progreso[cursoActualData.id] || {};
        sesion.progreso[cursoActualData.id] = {
            ...res.progreso,
            // El servidor no reenvía los reintentos habilitados en esta respuesta; conservarlos
            intentosExtra: progPrevio.intentosExtra || {},
            habilitaciones: progPrevio.habilitaciones || {}
        };
        if (Array.isArray(res.certificadosCurso)) {
            sesion.certificadosCurso = res.certificadosCurso;
        }
        if (Array.isArray(res.certificadosCarrera)) {
            sesion.certificadosCarrera = res.certificadosCarrera;
        }
        if (typeof actualizarEstadoCarrerasUsuario === 'function') {
            actualizarEstadoCarrerasUsuario(sesion);
        }
        sessionStorage.setItem('aluSesion', JSON.stringify(sesion));

        // 6. Deshabilitar botón de envío
        btn.style.display = 'none';

        // 7. Renderizar retroalimentación detallada de cada pregunta
        const quizArea = document.querySelector('.quiz-area');
        if (quizArea && Array.isArray(res.preguntasDetalle)) {
            quizArea.innerHTML = res.preguntasDetalle.map((p, i) => {
                const isCorrect = p.esCorrecta;
                return `
                    <div class="card p-3 mb-3 border ${isCorrect ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10'} shadow-sm rounded-3">
                        <h6 class="fw-bold d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span><span class="badge ${isCorrect ? 'bg-success' : 'bg-danger'} me-2">${i + 1}</span> ${escapeHtml(p.enunciado)}</span>
                            ${isCorrect 
                                ? '<span class="badge-soft-success flex-shrink-0"><i class="bi bi-check-circle-fill me-1"></i>Correcto</span>' 
                                : '<span class="badge-soft-danger flex-shrink-0"><i class="bi bi-x-circle-fill me-1"></i>Incorrecto</span>'}
                        </h6>
                        ${p.imagen ? `
                            <div class="quiz-question-img-container mb-2 text-start">
                                <img src="${escapeHtml(urlSegura(p.imagen))}" alt="Ilustración" class="img-fluid rounded border shadow-sm" style="max-height: 180px; object-fit: contain;">
                            </div>
                        ` : ''}
                        <div class="mt-2 d-flex flex-column gap-1">
                            ${(Array.isArray(p.opciones) ? p.opciones : []).map((opt, oIdx) => {
                                const optText = (typeof opt === 'object' && opt !== null) ? (opt.texto || '') : String(opt || '');
                                const optImg = (typeof opt === 'object' && opt !== null) ? (opt.imagen || '') : '';
                                let labelStyle = "text-muted";
                                let icon = "•";
                                if (oIdx === p.correcta) {
                                    labelStyle = "fw-bold text-success";
                                    icon = "✓";
                                } else if (oIdx === p.seleccionada && !isCorrect) {
                                    labelStyle = "text-danger text-decoration-line-through";
                                    icon = "✗";
                                }
                                return `
                                    <div class="py-1 px-2 rounded small d-flex align-items-center gap-2 ${labelStyle}">
                                        <span class="fw-bold">${icon}</span>
                                        ${optImg ? `<img src="${escapeHtml(urlSegura(optImg))}" class="rounded border flex-shrink-0" style="width: 32px; height: 32px; object-fit: cover;">` : ''}
                                        <span>${escapeHtml(optText)}</span>
                                    </div>`;
                            }).join('')}
                        </div>
                        ${p.retroalimentacion ? `
                            <div class="quiz-retro mt-2 p-2 px-3 rounded-2 small bg-white border-start border-4 ${isCorrect ? 'border-success' : 'border-danger'}" style="white-space: pre-wrap;"><i class="bi ${isCorrect ? 'bi-lightbulb-fill text-success' : 'bi-info-circle-fill text-danger'} me-1"></i>${escapeHtml(p.retroalimentacion)}</div>
                        ` : ''}
                    </div>`;
            }).join('');
        }

        // 8. Renderizar tarjeta de resultado global
        const min = res.minAprobacion || 75;
        const porcentaje = res.calificacion;
        const aciertos = res.aciertos;
        const total = res.total;
        const maxIntentos = res.maxIntentos !== undefined ? res.maxIntentos : ((modulo.maxIntentos !== undefined) ? (parseInt(modulo.maxIntentos) || 0) : (parseInt(modulo.max_intentos) || 0));
        const numIntentos = res.numIntentos || 1;
        const bloqueado = res.bloqueado || (maxIntentos > 0 && !res.aprobado && numIntentos >= maxIntentos);

        if (res.aprobado) {
            showToast('¡Felicitaciones! Has aprobado la evaluación del módulo.', 'success');
            if (res.certificadoOtorgado) {
                showToast('🎉 ¡Felicidades! Has completado y certificado este curso.', 'success');
            }
            if (res.carreraOtorgada) {
                showToast('🎓 ¡Felicidades! Has completado todos los requisitos de tu carrera.', 'success');
            }
            if (feedback) {
                feedback.innerHTML = `
                    <div class="card border-success bg-success bg-opacity-10 text-center p-4">
                        <div class="quiz-score-badge pass">${porcentaje}%</div>
                        <h3 class="fw-bold text-success mb-2">¡Felicitaciones! Módulo Aprobado</h3>
                        <p class="text-muted mb-3">Has obtenido <strong>${aciertos} de ${total}</strong> respuestas correctas (Mínimo: ${min}%).</p>
                        ${res.certificadoOtorgado ? '<div class="alert alert-success fw-bold py-2 mb-3"><i class="bi bi-patch-check-fill me-2"></i>¡Certificado del Curso Obtenido!</div>' : ''}
                        <div class="d-flex justify-content-center gap-3">
                            <button class="btn btn-success px-4" onclick="window.location.reload()">
                                <i class="bi bi-arrow-right-circle me-1"></i>Continuar al Siguiente Contenido
                            </button>
                        </div>
                    </div>`;
            }
        } else {
            if (bloqueado) {
                showToast(`Evaluación no aprobada (${porcentaje}%). Has alcanzado el límite máximo de intentos permitidos (${maxIntentos}).`, 'danger');
                if (feedback) {
                    feedback.innerHTML = `
                        <div class="card border-danger bg-danger bg-opacity-10 text-center p-4">
                            <div class="quiz-score-badge fail">${porcentaje}%</div>
                            <h3 class="fw-bold text-danger mb-2">Evaluación No Aprobada — Intentos Agotados</h3>
                            <p class="text-muted mb-2">Obtuviste <strong>${aciertos} de ${total}</strong> aciertos (${porcentaje}%). Se requiere al menos un <strong>${min}%</strong> para aprobar.</p>
                            <p class="text-danger fw-bold mb-3"><i class="bi bi-lock-fill me-1"></i>Has utilizado tus ${maxIntentos} intentos permitidos. Esta evaluación ha quedado bloqueada.</p>
                            <div class="alert alert-warning text-start mx-auto mb-3" style="max-width: 550px;">
                                <i class="bi bi-info-circle-fill me-2"></i>Por favor contacta a un administrador para evaluar tu caso o solicitar el restablecimiento de tus intentos.
                            </div>
                            <div class="d-flex justify-content-center gap-3">
                                <button class="btn btn-primary px-4" onclick="window.location.reload()">
                                    <i class="bi bi-arrow-left me-1"></i>Volver al Curso
                                </button>
                            </div>
                        </div>`;
                }
            } else {
                const intentosRestantes = maxIntentos > 0 ? (maxIntentos - numIntentos) : null;
                const msgRestantes = (intentosRestantes !== null) ? ` Te quedan ${intentosRestantes} ${intentosRestantes === 1 ? 'intento' : 'intentos'}.` : '';
                showToast(`Obtuviste ${porcentaje}%. Se requiere al menos un ${min}% para aprobar.${msgRestantes}`, 'warning');
                if (feedback) {
                    feedback.innerHTML = `
                        <div class="card border-danger bg-danger bg-opacity-10 text-center p-4">
                            <div class="quiz-score-badge fail">${porcentaje}%</div>
                            <h3 class="fw-bold text-danger mb-2">Módulo No Aprobado</h3>
                            <p class="text-muted mb-2">Obtuviste <strong>${aciertos} de ${total}</strong> aciertos. Se requiere al menos un <strong>${min}%</strong> para aprobar.</p>
                            ${maxIntentos > 0 ? `<p class="badge bg-warning text-dark px-3 py-2 mb-3"><i class="bi bi-arrow-repeat me-1"></i>Intento ${numIntentos} de ${maxIntentos} — Te ${intentosRestantes === 1 ? 'queda' : 'quedan'} ${intentosRestantes} ${intentosRestantes === 1 ? 'intento' : 'intentos'}</p>` : ''}
                            <div class="d-flex justify-content-center gap-3">
                                <button class="btn btn-warning px-4" onclick="reintentarEvaluacion(${jsArg(cursoActualData.id)}, ${mIdx})">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reintentar Evaluación
                                </button>
                                <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                                    Volver al Curso
                                </button>
                            </div>
                        </div>`;
                }
            }
        }
    } catch (error) {
        console.error('Error en validación:', error);
        showToast(error.message || 'Ocurrió un error al calificar la evaluación.', 'danger');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
};

window.reintentarEvaluacion = (cursoID, mIdx) => {
    mostrarEvaluacionModulo(cursoID, mIdx);
};
