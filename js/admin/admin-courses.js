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

    window.abrirEditor = (id) => {
        const c = (window.cursos || []).find(item => item.id === id);
        if (!c) return;

        document.getElementById('edit-id').value = c.id;
        document.getElementById('titulo').value = c.titulo || '';
        document.getElementById('descripcion').value = c.descripcion || '';
        if (document.getElementById('curso-prelacion')) {
            document.getElementById('curso-prelacion').value = c.prelacion || '';
        }
        if (document.getElementById('curso-tipo')) {
            document.getElementById('curso-tipo').value = (c.tipo === 'publico' || c.tipo === 'libre') ? 'publico' : 'especializado';
        }
        if (document.getElementById('curso-en-construccion')) {
            document.getElementById('curso-en-construccion').checked = !!c.enConstruccion;
        }
        window.tempImagenPortada = c.imagen || "";
        window.mostrarVistaPreviaPortada();

        window.tempModulos = JSON.parse(JSON.stringify(c.modulos || []));
        window.renderModulosEditor();

        document.getElementById('modalTitulo').innerText = "Editar Curso";

        const selPrelacion = document.getElementById('curso-prelacion');
        if (selPrelacion) {
            selPrelacion.innerHTML = '<option value="">Ninguno</option>' + (window.cursos || []).filter(item => item.id !== id).map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
            selPrelacion.value = c.prelacion || '';
        }

        const modalElement = document.getElementById('cursoModal');
        const bModal = new bootstrap.Modal(modalElement);
        bModal.show();
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

            const nuevoCurso = {
                id: idEdit ? idEdit : "CUR-" + Date.now(),
                titulo: (document.getElementById('titulo')?.value || '').trim(),
                tipo: tipo || 'especializado',
                imagen: window.tempImagenPortada,
                descripcion: (document.getElementById('descripcion')?.value || '').trim(),
                prelacion: document.getElementById('curso-prelacion')?.value || '',
                enConstruccion: enConstruccion,
                modulos: window.tempModulos
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
        window.tempModulos.push({ titulo: "Nuevo Módulo", enConstruccion: false, lecciones: [] });
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
        const container = document.getElementById('contenedor-modulos-editor');
        if (!container) return;
        container.innerHTML = (window.tempModulos || []).map((mod, mIdx) => `
            <div class="border p-3 mb-3 ${mod.enConstruccion ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'} rounded shadow-sm">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div class="btn-group me-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="subirModulo(${mIdx})" ${mIdx === 0 ? 'disabled' : ''} title="Subir Módulo"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bajarModulo(${mIdx})" ${mIdx === window.tempModulos.length - 1 ? 'disabled' : ''} title="Bajar Módulo"><i class="bi bi-arrow-down"></i></button>
                    </div>
                    <input type="text" class="form-control fw-bold flex-grow-1" style="min-width: 200px;" placeholder="Título del Módulo" value="${mod.titulo}" oninput="window.tempModulos[${mIdx}].titulo = this.value">
                    
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
                            <input type="text" class="form-control form-control-sm mb-1" placeholder="Título Lección" value="${lec.titulo}" oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].titulo = this.value">
                            <div class="row g-2">
                                <div class="col-8">
                                    <input type="text" class="form-control form-control-sm mb-1" placeholder="URL de YouTube" value="${lec.videoID ? 'https://www.youtube.com/watch?v=' + lec.videoID : ''}" oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].videoID = window.extraerID(this.value)">
                                </div>
                                <div class="col-4">
                                    ${lec.videoID ? `<button type="button" class="btn btn-sm btn-dark w-100" onclick="window.open('https://youtube.com/embed/${lec.videoID}')">Ver</button>` : ''}
                                </div>
                            </div>
                            <textarea class="form-control form-control-sm mb-1" placeholder="Contenido..." oninput="window.tempModulos[${mIdx}].lecciones[${lIdx}].contenido = this.value">${lec.contenido || ''}</textarea>
                            <div class="d-flex justify-content-between align-items-center">
                                <input type="file" class="form-control form-control-sm" style="max-width: 200px;" onchange="cargarArchivoLeccion(event, ${mIdx}, ${lIdx})">
                                <button type="button" class="btn btn-link btn-sm text-danger" onclick="eliminarLeccion(${mIdx}, ${lIdx})">Eliminar</button>
                            </div>
                            ${lec.nombreAdjunto ? `<div class="small text-success mt-1"><i class="bi bi-paperclip"></i> ${lec.nombreAdjunto}</div>` : ''}
                        </div>
                    `).join('')}
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarLeccion(${mIdx})">+ Añadir Lección</button>
                </div>
            </div>
        `).join('') + `<button type="button" class="btn btn-primary w-100 mt-2" onclick="agregarModulo()">+ Añadir Nuevo Módulo</button>`;
    };

    // ------------------------------------------------------------
    // EDITOR DE EVALUACIÓN DE MÓDULOS
    // ------------------------------------------------------------

    window.abrirEditorModuloEvaluacion = (mIdx) => {
        const modulo = window.tempModulos[mIdx];
        window.tempModuloEvaluacion = JSON.parse(JSON.stringify(modulo.evaluacion || { preguntas: [] }));

        document.getElementById('modalModuloEvaluacionTitulo').innerText = `Evaluación: ${modulo.titulo}`;
        document.getElementById('edit-modulo-idx').value = mIdx;

        window.renderPreguntasModuloEditor();

        const modalElement = document.getElementById('moduloEvaluacionModal');
        const bModal = new bootstrap.Modal(modalElement);
        bModal.show();
    };

    window.agregarPreguntaModulo = () => {
        if (!window.tempModuloEvaluacion.preguntas) window.tempModuloEvaluacion.preguntas = [];
        window.tempModuloEvaluacion.preguntas.push({
            enunciado: "Nueva pregunta",
            opciones: ["Opción A", "Opción B"],
            correcta: 0
        });
        window.renderPreguntasModuloEditor();
    };

    window.eliminarPreguntaModulo = (idx) => {
        window.tempModuloEvaluacion.preguntas.splice(idx, 1);
        window.renderPreguntasModuloEditor();
    };

    window.eliminarPreguntaOpcionesModulo = (idx) => {
        window.tempModuloEvaluacion.preguntas[idx].opciones.pop();
        window.renderPreguntasModuloEditor();
    };

    window.renderPreguntasModuloEditor = function () {
        const container = document.getElementById('contenedor-preguntas-modulo-editor');
        if (!container) return;

        container.innerHTML = (window.tempModuloEvaluacion.preguntas || []).map((p, pIdx) => `
            <div class="card p-3 mb-3 bg-white shadow-sm">
                <div class="d-flex justify-content-between mb-2">
                    <input type="text" class="form-control me-2" value="${p.enunciado}" oninput="window.tempModuloEvaluacion.preguntas[${pIdx}].enunciado = this.value">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaModulo(${pIdx})">X</button>
                </div>
                ${p.opciones.map((opt, oIdx) => `
                    <div class="input-group mb-1">
                        <div class="input-group-text">
                            <input type="radio" name="correcta-mod-${pIdx}" ${p.correcta == oIdx ? 'checked' : ''} onclick="window.tempModuloEvaluacion.preguntas[${pIdx}].correcta = ${oIdx}">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaOpcionesModulo(${pIdx})">X</button>
                        </div>
                        <input type="text" class="form-control form-control-sm" value="${opt}" oninput="window.tempModuloEvaluacion.preguntas[${pIdx}].opciones[${oIdx}] = this.value">
                    </div>
                `).join('')}
                <button type="button" class="btn btn-sm btn-link" onclick="window.tempModuloEvaluacion.preguntas[${pIdx}].opciones.push('Nueva Opción'); window.renderPreguntasModuloEditor()">+ Añadir Opción</button>
            </div>
        `).join('') + `<button type="button" class="btn btn-outline-dark w-100" onclick="agregarPreguntaModulo()">+ Añadir Pregunta al Examen</button>`;
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
            window.tempModulos[mIdx].evaluacion = JSON.parse(JSON.stringify(window.tempModuloEvaluacion));

            showToast('Evaluación del módulo guardada temporalmente.', 'success');
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
