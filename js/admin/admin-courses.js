/**
 * js/admin/admin-courses.js — Universidad del Aluminio
 * Módulo de administración de cursos: edición, lecciones, módulos y evaluaciones.
 */

(function () {
    'use strict';

    window.prepararFormulario = async (modo) => {
        const form = document.getElementById('form-curso');
        const idActual = document.getElementById('edit-id')?.value;

        if (window.tempModulos && window.tempModulos.length > 0 && !idActual) {
            const ok = await showConfirmModal({
                title: '¿Reiniciar Curso en Edición?',
                message: 'Hay un curso en proceso de creación. ¿Deseas descartar los cambios actuales y empezar de cero?',
                confirmText: 'Sí, descartar',
                confirmVariant: 'warning'
            });
            if (!ok) return;
        }

        if (form) form.reset();
        if (document.getElementById('edit-id')) document.getElementById('edit-id').value = '';
        if (document.getElementById('titulo')) document.getElementById('titulo').value = '';
        if (document.getElementById('descripcion')) document.getElementById('descripcion').value = '';
        if (document.getElementById('curso-prelacion')) document.getElementById('curso-prelacion').value = '';
        if (document.getElementById('curso-tipo')) {
            document.getElementById('curso-tipo').value = 'especializado';
        }
        if (document.getElementById('curso-en-construccion')) {
            document.getElementById('curso-en-construccion').checked = false;
        }
        const fileInput = document.getElementById('input-portada');
        if (fileInput) fileInput.value = '';
        window.tempModulos = [];
        window.tempImagenPortada = "";
        window.mostrarVistaPreviaPortada();
        if (document.getElementById('modalTitulo')) document.getElementById('modalTitulo').innerText = "Nuevo Curso";

        const selPrelacion = document.getElementById('curso-prelacion');
        if (selPrelacion) {
            selPrelacion.innerHTML = '<option value="">Ninguno</option>' + (window.cursos || []).map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
        }

        window.renderModulosEditor();
    };

    window.mostrarVistaPreviaPortada = () => {
        const vistaPrev = document.getElementById('vista-previa-portada');
        const imgPrev = document.getElementById('img-vista-previa');
        if (vistaPrev && imgPrev) {
            if (window.tempImagenPortada) {
                imgPrev.src = typeof resolverSrcImagen === 'function' ? resolverSrcImagen(window.tempImagenPortada) : window.tempImagenPortada;
                vistaPrev.style.display = 'block';
            } else {
                vistaPrev.style.display = 'none';
                imgPrev.src = '';
            }
        }
    };

    window.cargarImagenPortada = async (event) => {
        const file = event.target.files[0];
        if (!file) return;
        const cursoId = document.getElementById('edit-id')?.value || 'nuevo';
        try {
            if (typeof subirImagenServidor === 'function') {
                const prevUrl = window.tempImagenPortada;
                const url = await subirImagenServidor(file, 'portada', cursoId, prevUrl);
                window.tempImagenPortada = url;
            } else {
                const b64 = await window.comprimirImagenBase64(file, 1200, 0.82);
                window.tempImagenPortada = b64;
            }
            window.mostrarVistaPreviaPortada();
        } catch (err) {
            showToast('Error al cargar la imagen: ' + err.message, 'danger');
        }
    };

    window.eliminarImagenPortada = () => {
        window.tempImagenPortada = "";
        window.mostrarVistaPreviaPortada();
        const fileInput = document.getElementById('input-portada');
        if (fileInput) fileInput.value = "";
    };

    function escapeAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    window.abrirEditor = (id) => {
        try {
            const c = (window.cursos || []).find(item => String(item.id) === String(id));
            if (!c) {
                showToast('No se encontró el curso seleccionado.', 'warning');
                return;
            }

            const editId = document.getElementById('edit-id');
            const titulo = document.getElementById('titulo');
            const desc = document.getElementById('descripcion');
            if (editId) editId.value = c.id || '';
            if (titulo) titulo.value = c.titulo || '';
            if (desc) desc.value = c.descripcion || '';

            if (document.getElementById('curso-prelacion')) {
                document.getElementById('curso-prelacion').value = c.prelacion || '';
            }
            if (document.getElementById('curso-tipo')) {
                document.getElementById('curso-tipo').value = (c.tipo === 'publico' || c.tipo === 'libre') ? 'publico' : ((c.tipo === 'pruebas') ? 'pruebas' : 'especializado');
            }
            if (document.getElementById('curso-en-construccion')) {
                document.getElementById('curso-en-construccion').checked = !!c.enConstruccion;
            }
            window.tempImagenPortada = c.imagen || "";
            window.mostrarVistaPreviaPortada();

            window.tempModulos = (c.modulos || []).map(m => ({
                ...JSON.parse(JSON.stringify(m)),
                maxIntentos: (m.maxIntentos !== undefined) ? (parseInt(m.maxIntentos) || 0) : (parseInt(m.max_intentos) || 0)
            }));
            window.renderModulosEditor();

            if (document.getElementById('modalTitulo')) {
                document.getElementById('modalTitulo').innerText = "Editar Curso: " + (c.titulo || '');
            }

            const selPrelacion = document.getElementById('curso-prelacion');
            if (selPrelacion) {
                selPrelacion.innerHTML = '<option value="">Ninguno</option>' + (window.cursos || []).filter(item => String(item.id) !== String(id)).map(cp => `<option value="${cp.id}">${escapeAttr(cp.titulo)}</option>`).join('');
                selPrelacion.value = c.prelacion || '';
            }

            const modalElement = document.getElementById('cursoModal');
            if (modalElement && typeof bootstrap !== 'undefined') {
                const bModal = bootstrap.Modal.getOrCreateInstance(modalElement);
                bModal.show();
            }
        } catch (err) {
            console.error('Error al abrir editor de curso:', err);
            if (typeof showToast === 'function') {
                showToast('Error al abrir el editor: ' + err.message, 'danger');
            }
        }
    };

    window.guardarCurso = async (e) => {
        if (e) e.preventDefault();

        const btn = document.getElementById('btn-guardar-curso');
        await window.withLoading(btn, async () => {
            const enConstruccion = document.getElementById('curso-en-construccion') ? document.getElementById('curso-en-construccion').checked : false;

            if (!enConstruccion) {
                if (!window.tempModulos || window.tempModulos.length === 0) {
                    throw new Error('El curso publicado debe tener al menos un módulo.');
                }
                const todasTienenLecciones = window.tempModulos.every(m => m.lecciones && m.lecciones.length > 0);
                if (!todasTienenLecciones) {
                    throw new Error('Cada módulo debe contener al menos una lección para ser publicado. Si el contenido aún está en desarrollo, marca la casilla "En Construcción".');
                }
            }

            const tipo = document.getElementById('curso-tipo') ? document.getElementById('curso-tipo').value : 'especializado';
            const idEdit = document.getElementById('edit-id').value;

            const modulosSanitizados = (window.tempModulos || []).map(m => ({
                ...m,
                maxIntentos: (m.maxIntentos !== undefined && m.maxIntentos !== null && m.maxIntentos !== '') ? (parseInt(m.maxIntentos) || 0) : 0
            }));

            const nuevoCurso = {
                id: idEdit ? idEdit : "CUR-" + Date.now(),
                titulo: (document.getElementById('titulo')?.value || '').trim(),
                tipo: tipo || 'especializado',
                imagen: window.tempImagenPortada,
                descripcion: (document.getElementById('descripcion')?.value || '').trim(),
                prelacion: document.getElementById('curso-prelacion')?.value || '',
                enConstruccion: enConstruccion,
                modulos: modulosSanitizados
            };

            if (idEdit) {
                const idx = (window.cursos || []).findIndex(c => String(c.id) === String(idEdit));
                if (idx !== -1) {
                    window.cursos[idx] = nuevoCurso;
                } else {
                    window.cursos.push(nuevoCurso);
                }
            } else {
                window.cursos.push(nuevoCurso);
            }

            await window.API.guardarCurso(nuevoCurso);
            showToast('Curso guardado con éxito.', 'success');
            setTimeout(() => location.reload(), 1500);
        }, 'Guardando curso...');
    };

    window.eliminarCurso = async (id) => {
        const btn = event?.target?.closest('button');
        const ok = await showConfirmModal({
            title: '¿Eliminar Curso?',
            message: '¿Estás seguro de eliminar este curso y todo su contenido? Esta acción no se puede deshacer.',
            confirmText: 'Sí, eliminar',
            confirmVariant: 'danger'
        });
        if (!ok) return;

        await window.withLoading(btn, async () => {
            window.cursos = (window.cursos || []).filter(c => String(c.id) !== String(id));
            await window.API.eliminarCurso(id);
            showToast('Curso eliminado con éxito.', 'success');
            setTimeout(() => location.reload(), 1500);
        }, 'Eliminando curso...');
    };

    // ------------------------------------------------------------
    // EDITOR DE MÓDULOS Y LECCIONES
    // ------------------------------------------------------------

    window.eliminarModulo = (idx) => {
        window.tempModulos.splice(idx, 1);
        window.renderModulosEditor();
    };

    window.subirModulo = (idx) => {
        if (idx > 0) {
            [window.tempModulos[idx], window.tempModulos[idx - 1]] = [window.tempModulos[idx - 1], window.tempModulos[idx]];
            window.renderModulosEditor();
        }
    };

    window.bajarModulo = (idx) => {
        if (idx < window.tempModulos.length - 1) {
            [window.tempModulos[idx], window.tempModulos[idx + 1]] = [window.tempModulos[idx + 1], window.tempModulos[idx]];
            window.renderModulosEditor();
        }
    };

    window.agregarModulo = () => {
        window.tempModulos.push({ titulo: "Nuevo Módulo", enConstruccion: false, maxIntentos: 0, lecciones: [] });
        window.renderModulosEditor();
    };

    window.agregarLeccion = (mIdx) => {
        window.tempModulos[mIdx].lecciones.push({ titulo: "Nueva Lección", videoID: "", contenido: "", adjunto: "" });
        window.renderModulosEditor();
    };

    window.eliminarLeccion = (mIdx, lIdx) => {
        window.tempModulos[mIdx].lecciones.splice(lIdx, 1);
        window.renderModulosEditor();
    };

    window.cargarArchivoLeccion = (event, mIdx, lIdx) => {
        const file = event.target.files[0];
        if (!file) return;

        if (file.size > 100 * 1024 * 1024) {
            showToast("El archivo es demasiado grande. Máximo 100MB.", "warning");
            event.target.value = "";
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            window.tempModulos[mIdx].lecciones[lIdx].adjunto = e.target.result;
            window.tempModulos[mIdx].lecciones[lIdx].nombreAdjunto = file.name;
            window.renderModulosEditor();
        };
        reader.readAsDataURL(file);
    };

    window.renderModulosEditor = function () {
        try {
            const container = document.getElementById('contenedor-modulos-editor');
            if (!container) return;
            container.innerHTML = (window.tempModulos || []).map((mod, mIdx) => `
                <div class="border p-3 mb-3 ${mod.enConstruccion ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'} rounded shadow-sm">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <div class="btn-group me-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="subirModulo(${mIdx})" ${mIdx === 0 ? 'disabled' : ''} title="Subir Módulo"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bajarModulo(${mIdx})" ${mIdx === window.tempModulos.length - 1 ? 'disabled' : ''} title="Bajar Módulo"><i class="bi bi-arrow-down"></i></button>
                        </div>
                        <input type="text" class="form-control fw-bold flex-grow-1" style="min-width: 200px;" placeholder="Título del Módulo" value="${escapeAttr(mod.titulo || '')}" oninput="window.tempModulos[${mIdx}].titulo = this.value">
                        
                        <div class="form-check form-switch ms-2 me-2" title="Marcar este módulo como en construcción">
                            <input class="form-check-input" type="checkbox" role="switch" id="mod-const-${mIdx}" ${mod.enConstruccion ? 'checked' : ''} onchange="window.tempModulos[${mIdx}].enConstruccion = this.checked; window.renderModulosEditor();">
                            <label class="form-check-label small fw-bold text-warning" for="mod-const-${mIdx}">
                                <i class="bi bi-cone-striped me-1"></i>En Construcción
                            </label>
                        </div>

                        <div class="input-group" style="width: 130px;" title="Intentos máximos para la evaluación (0 o vacío para ilimitados)">
                            <span class="input-group-text"><i class="bi bi-arrow-repeat"></i></span>
                            <input type="number" class="form-control form-control-sm" placeholder="Intentos" value="${mod.maxIntentos || ''}" oninput="window.tempModulos[${mIdx}].maxIntentos = parseInt(this.value) || 0">
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" onclick="abrirEditorModuloEvaluacion(${mIdx})">
                            <i class="bi bi-clipboard-check"></i> Evaluación
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="eliminarModulo(${mIdx})" title="Eliminar Módulo">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="ms-4 border-start ps-3">
                        ${(mod.lecciones || []).map((lec, lIdx) => `
                            <div class="card p-2 mb-2 bg-white">
                                <input type="text" class="form-control form-control-sm mb-1" placeholder="Título Lección" value="${escapeAttr(lec.titulo || '')}" oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].titulo = this.value">
                                <div class="row g-2">
                                    <div class="col-8">
                                        <input type="text" class="form-control form-control-sm mb-1" placeholder="URL de YouTube" value="${lec.videoID ? escapeAttr('https://www.youtube.com/watch?v=' + lec.videoID) : ''}" oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].videoID = window.extraerID(this.value)">
                                    </div>
                                    <div class="col-4">
                                        ${lec.videoID ? `<button type="button" class="btn btn-sm btn-dark w-100" onclick="window.open('https://youtube.com/embed/${escapeAttr(lec.videoID)}')">Ver</button>` : ''}
                                    </div>
                                </div>
                                <textarea class="form-control form-control-sm mb-1" placeholder="Contenido..." oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].contenido = this.value">${escapeAttr(lec.contenido || '')}</textarea>
                                <div class="d-flex justify-content-between align-items-center">
                                    <input type="file" class="form-control form-control-sm" style="max-width: 200px;" onchange="cargarArchivoLeccion(event, ${mIdx}, ${lIdx})">
                                    <button type="button" class="btn btn-link btn-sm text-danger" onclick="eliminarLeccion(${mIdx}, ${lIdx})">Eliminar</button>
                                </div>
                                ${lec.nombreAdjunto ? `<div class="small text-success mt-1"><i class="bi bi-paperclip"></i> ${escapeAttr(lec.nombreAdjunto)}</div>` : ''}
                            </div>
                        `).join('')}
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarLeccion(${mIdx})">+ Añadir Lección</button>
                    </div>
                </div>
            `).join('') + `<button type="button" class="btn btn-primary w-100 mt-2" onclick="agregarModulo()">+ Añadir Nuevo Módulo</button>`;
        } catch (err) {
            console.error('Error al renderizar módulos:', err);
        }
    };

    // ------------------------------------------------------------
    // EDITOR DE EVALUACIÓN DE MÓDULOS (BANCO, DINÁMICO E IMÁGENES)
    // ------------------------------------------------------------

    window.actualizarInfoBanco = () => {
        const total = (window.tempModuloEvaluacion.preguntas || []).length;
        const badge = document.getElementById('badge-total-preguntas');
        if (badge) badge.innerText = `${total} ${total === 1 ? 'pregunta' : 'preguntas'} en el banco`;

        const info = document.getElementById('eval-banco-info');
        const num = window.tempModuloEvaluacion.numPreguntas || 0;
        const tipo = window.tempModuloEvaluacion.tipo || 'fijo';

        if (info) {
            if (tipo === 'aleatorio') {
                if (num > 0) {
                    info.innerHTML = `<span class="text-primary fw-bold"><i class="bi bi-shuffle me-1"></i>Se seleccionarán ${num} de ${total} preguntas al azar para cada evaluación.</span>`;
                } else {
                    info.innerHTML = `<span class="text-secondary"><i class="bi bi-shuffle me-1"></i>Se seleccionarán todas las ${total} preguntas al azar.</span>`;
                }
            } else {
                info.innerHTML = `Formulario Fijo: Se presentarán ${num > 0 ? num : total} preguntas en el orden definido.`;
            }
        }
    };

    window.abrirEditorModuloEvaluacion = (mIdx) => {
        const modulo = window.tempModulos[mIdx];
        const evalData = modulo.evaluacion || {};

        window.tempModuloEvaluacion = {
            tipo: evalData.tipo || 'fijo',
            numPreguntas: parseInt(evalData.numPreguntas) || 0,
            mezclarOpciones: !!evalData.mezclarOpciones,
            preguntas: (evalData.preguntas || []).map(p => ({
                id: p.id || null,
                enunciado: p.enunciado || '',
                imagen: p.imagen || '',
                correcta: parseInt(p.correcta) || 0,
                opciones: (p.opciones || []).map(opt => {
                    if (typeof opt === 'object' && opt !== null) {
                        return { texto: opt.texto || '', imagen: opt.imagen || '' };
                    }
                    return { texto: String(opt || ''), imagen: '' };
                })
            }))
        };

        document.getElementById('modalModuloEvaluacionTitulo').innerText = `Evaluación: ${modulo.titulo}`;
        document.getElementById('edit-modulo-idx').value = mIdx;

        const selTipo = document.getElementById('eval-tipo');
        if (selTipo) selTipo.value = window.tempModuloEvaluacion.tipo;

        const inputNum = document.getElementById('eval-num-preguntas');
        if (inputNum) inputNum.value = window.tempModuloEvaluacion.numPreguntas || '';

        const swMezclar = document.getElementById('eval-mezclar-opciones');
        if (swMezclar) swMezclar.checked = window.tempModuloEvaluacion.mezclarOpciones;

        window.renderPreguntasModuloEditor();
        window.actualizarInfoBanco();

        const modalElement = document.getElementById('moduloEvaluacionModal');
        const bModal = new bootstrap.Modal(modalElement);
        bModal.show();
    };

    window.agregarPreguntaModulo = () => {
        if (!window.tempModuloEvaluacion.preguntas) window.tempModuloEvaluacion.preguntas = [];
        window.tempModuloEvaluacion.preguntas.push({
            enunciado: "Nueva pregunta",
            imagen: "",
            opciones: [
                { texto: "Opción A", imagen: "" },
                { texto: "Opción B", imagen: "" }
            ],
            correcta: 0
        });
        window.renderPreguntasModuloEditor();
        window.actualizarInfoBanco();
    };

    window.eliminarPreguntaModulo = (idx) => {
        window.tempModuloEvaluacion.preguntas.splice(idx, 1);
        window.renderPreguntasModuloEditor();
        window.actualizarInfoBanco();
    };

    window.agregarOpcionPreguntaModulo = (pIdx) => {
        if (!window.tempModuloEvaluacion.preguntas[pIdx].opciones) {
            window.tempModuloEvaluacion.preguntas[pIdx].opciones = [];
        }
        const letra = String.fromCharCode(65 + window.tempModuloEvaluacion.preguntas[pIdx].opciones.length);
        window.tempModuloEvaluacion.preguntas[pIdx].opciones.push({
            texto: `Opción ${letra}`,
            imagen: ""
        });
        window.renderPreguntasModuloEditor();
    };

    window.eliminarOpcionPreguntaModulo = (pIdx, oIdx) => {
        const p = window.tempModuloEvaluacion.preguntas[pIdx];
        if (p.opciones.length <= 2) {
            showToast('Una pregunta debe tener al menos 2 opciones de respuesta.', 'warning');
            return;
        }
        p.opciones.splice(oIdx, 1);
        if (p.correcta >= p.opciones.length) {
            p.correcta = p.opciones.length - 1;
        }
        window.renderPreguntasModuloEditor();
    };

    window.cargarImagenPregunta = async (event, pIdx) => {
        const file = event.target.files[0];
        if (!file) return;
        try {
            const cursoId = document.getElementById('edit-id')?.value || 'nuevo';
            if (typeof subirImagenServidor === 'function') {
                const prevUrl = window.tempModuloEvaluacion.preguntas[pIdx].imagen || '';
                const url = await subirImagenServidor(file, 'pregunta', `p_${cursoId}_${pIdx}`, prevUrl);
                window.tempModuloEvaluacion.preguntas[pIdx].imagen = url;
            } else {
                const b64 = await window.comprimirImagenBase64(file, 800, 0.82);
                window.tempModuloEvaluacion.preguntas[pIdx].imagen = b64;
            }
            window.renderPreguntasModuloEditor();
        } catch (err) {
            showToast('Error al cargar imagen: ' + err.message, 'danger');
        }
    };

    window.eliminarImagenPregunta = (pIdx) => {
        window.tempModuloEvaluacion.preguntas[pIdx].imagen = '';
        window.renderPreguntasModuloEditor();
    };

    window.cargarImagenOpcion = async (event, pIdx, oIdx) => {
        const file = event.target.files[0];
        if (!file) return;
        try {
            const cursoId = document.getElementById('edit-id')?.value || 'nuevo';
            if (typeof subirImagenServidor === 'function') {
                const prevUrl = window.tempModuloEvaluacion.preguntas[pIdx].opciones[oIdx].imagen || '';
                const url = await subirImagenServidor(file, 'opcion', `opt_${cursoId}_${pIdx}_${oIdx}`, prevUrl);
                window.tempModuloEvaluacion.preguntas[pIdx].opciones[oIdx].imagen = url;
            } else {
                const b64 = await window.comprimirImagenBase64(file, 600, 0.82);
                window.tempModuloEvaluacion.preguntas[pIdx].opciones[oIdx].imagen = b64;
            }
            window.renderPreguntasModuloEditor();
        } catch (err) {
            showToast('Error al cargar imagen de opción: ' + err.message, 'danger');
        }
    };

    window.eliminarImagenOpcion = (pIdx, oIdx) => {
        window.tempModuloEvaluacion.preguntas[pIdx].opciones[oIdx].imagen = '';
        window.renderPreguntasModuloEditor();
    };

    window.renderPreguntasModuloEditor = function () {
        const container = document.getElementById('contenedor-preguntas-modulo-editor');
        if (!container) return;

        const preguntas = window.tempModuloEvaluacion.preguntas || [];
        if (preguntas.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 bg-white rounded border border-dashed text-muted mb-3">
                    <i class="bi bi-patch-question fs-2 d-block mb-2 text-secondary"></i>
                    <p class="mb-2 fw-bold">El banco de preguntas está vacío.</p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="agregarPreguntaModulo()">
                        <i class="bi bi-plus-circle me-1"></i>Crear Primera Pregunta
                    </button>
                </div>`;
            return;
        }

        container.innerHTML = preguntas.map((p, pIdx) => {
            const tieneImgPreg = !!p.imagen;
            const srcImgPreg = tieneImgPreg ? (typeof resolverSrcImagen === 'function' ? resolverSrcImagen(p.imagen) : p.imagen) : '';

            return `
            <div class="card p-3 mb-3 bg-white shadow-sm border rounded-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="badge bg-primary px-3 py-1 fw-bold">Pregunta ${pIdx + 1}</span>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaModulo(${pIdx})" title="Eliminar pregunta del banco">
                            <i class="bi bi-trash me-1"></i>Eliminar Pregunta
                        </button>
                    </div>
                </div>

                <!-- Enunciado -->
                <div class="mb-2">
                    <label class="form-label small fw-bold text-muted">Enunciado de la pregunta:</label>
                    <textarea class="form-control" rows="2" placeholder="Escribe el enunciado de la pregunta..." oninput="window.tempModuloEvaluacion.preguntas[${pIdx}].enunciado = this.value">${p.enunciado || ''}</textarea>
                </div>

                <!-- Imagen Ilustrativa de la Pregunta -->
                <div class="mb-3 p-2 bg-light rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-dark"><i class="bi bi-image text-primary me-1"></i>Ilustración / Diagrama de la Pregunta:</span>
                        ${tieneImgPreg ? `
                            <button type="button" class="btn btn-xs btn-outline-danger py-0 px-2" onclick="eliminarImagenPregunta(${pIdx})">
                                <i class="bi bi-x-circle me-1"></i>Quitar Ilustración
                            </button>
                        ` : ''}
                    </div>
                    ${tieneImgPreg ? `
                        <div class="d-flex align-items-center gap-3 mt-1">
                            <img src="${srcImgPreg}" class="rounded border bg-white shadow-sm" style="max-height: 90px; max-width: 140px; object-fit: contain;">
                            <label class="btn btn-sm btn-outline-secondary mb-0">
                                <i class="bi bi-arrow-repeat me-1"></i>Cambiar Imagen
                                <input type="file" accept="image/*" class="d-none" onchange="cargarImagenPregunta(event, ${pIdx})">
                            </label>
                        </div>
                    ` : `
                        <label class="btn btn-sm btn-outline-primary mb-0 mt-1">
                            <i class="bi bi-upload me-1"></i>Adjuntar Imagen / Esquema a esta Pregunta
                            <input type="file" accept="image/*" class="d-none" onchange="cargarImagenPregunta(event, ${pIdx})">
                        </label>
                    `}
                </div>

                <!-- Opciones de Respuesta -->
                <div class="ms-1">
                    <label class="form-label small fw-bold text-muted mb-1">Opciones de respuesta (marca la correcta):</label>
                    <div class="d-flex flex-column gap-2">
                        ${p.opciones.map((opt, oIdx) => {
                            const optTexto = typeof opt === 'object' ? (opt.texto || '') : String(opt || '');
                            const optImg = typeof opt === 'object' ? (opt.imagen || '') : '';
                            const tieneOptImg = !!optImg;
                            const srcOptImg = tieneOptImg ? (typeof resolverSrcImagen === 'function' ? resolverSrcImagen(optImg) : optImg) : '';
                            const esCorrecta = (p.correcta === oIdx);

                            return `
                            <div class="border p-2 rounded ${esCorrecta ? 'border-success bg-success bg-opacity-10' : 'bg-light'}">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-text ${esCorrecta ? 'bg-success text-white' : ''}" title="Marcar como respuesta correcta">
                                        <input type="radio" name="correcta-mod-${pIdx}" class="form-check-input mt-0" ${esCorrecta ? 'checked' : ''} onclick="window.tempModuloEvaluacion.preguntas[${pIdx}].correcta = ${oIdx}; window.renderPreguntasModuloEditor()">
                                    </div>
                                    <input type="text" class="form-control" placeholder="Texto de la opción..." value="${optTexto.replace(/"/g, '&quot;')}" oninput="window.tempModuloEvaluacion.preguntas[${pIdx}].opciones[${oIdx}].texto = this.value">
                                    <label class="btn btn-outline-secondary mb-0" title="${tieneOptImg ? 'Cambiar miniatura de opción' : 'Añadir miniatura a opción'}">
                                        <i class="bi bi-camera ${tieneOptImg ? 'text-primary' : ''}"></i>
                                        <input type="file" accept="image/*" class="d-none" onchange="cargarImagenOpcion(event, ${pIdx}, ${oIdx})">
                                    </label>
                                    <button type="button" class="btn btn-outline-danger" onclick="eliminarOpcionPreguntaModulo(${pIdx}, ${oIdx})" title="Eliminar opción">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                ${tieneOptImg ? `
                                    <div class="d-flex align-items-center gap-2 mt-2 ms-4">
                                        <img src="${srcOptImg}" class="rounded border bg-white" style="width: 44px; height: 44px; object-fit: cover;">
                                        <span class="small text-muted">Miniatura adjunta</span>
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 ms-1" onclick="eliminarImagenOpcion(${pIdx}, ${oIdx})" title="Quitar miniatura">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                ` : ''}
                            </div>`;
                        }).join('')}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="agregarOpcionPreguntaModulo(${pIdx})">
                        <i class="bi bi-plus-circle me-1"></i>Añadir Opción
                    </button>
                </div>
            </div>`;
        }).join('') + `<button type="button" class="btn btn-primary w-100 mt-2 py-2 fw-bold" onclick="agregarPreguntaModulo()"><i class="bi bi-plus-circle me-1"></i>Añadir Nueva Pregunta al Banco</button>`;
    };

    window.guardarEvaluacionModulo = () => {
        const btn = document.querySelector('#moduloEvaluacionModal button[type="submit"]');
        const originalHtml = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando evaluación...`;
        }

        try {
            const mIdx = document.getElementById('edit-modulo-idx').value;
            const tipo = document.getElementById('eval-tipo')?.value || 'fijo';
            const numPreguntas = parseInt(document.getElementById('eval-num-preguntas')?.value) || 0;
            const mezclarOpciones = document.getElementById('eval-mezclar-opciones')?.checked || false;

            window.tempModuloEvaluacion.tipo = tipo;
            window.tempModuloEvaluacion.numPreguntas = numPreguntas;
            window.tempModuloEvaluacion.mezclarOpciones = mezclarOpciones;

            window.tempModulos[mIdx].evaluacion = JSON.parse(JSON.stringify(window.tempModuloEvaluacion));

            showToast('Evaluación del módulo configurada con éxito.', 'success');
            const modalElement = document.getElementById('moduloEvaluacionModal');
            const bModal = bootstrap.Modal.getOrCreateInstance(modalElement);
            bModal.hide();

            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        } catch (error) {
            showToast('Error al guardar la evaluación: ' + error.message, 'danger');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    };
})();
