// ============================================================
// 1. GESTIÓN DE DATOS
// ============================================================

const DB_KEY = 'uniDatabase';

const initialDB = {
    usuarios: [
        { id: "25482938", clave: "12345", nombre: "Ambar Vegas", rol: "admin", estado: "activo", asignados: [], carreras: [], carrerasAsignadas: [], examenesAprobados: {}, progreso: {}, certificadosCurso: [], certificadosCarrera: [] }
    ],
    cursos: [],
    carreras: [],
    rolesConfig: [
        { id: "admin", nombre: "Administrador", permisos: ["*"] },
        { id: "asesor_ventas", nombre: "Asesor de Ventas", cursos: [], carreras: [] },
        { id: "proyectista", nombre: "Proyectista / Diseño", cursos: [], carreras: [] },
        { id: "participante", nombre: "Participante General", cursos: [], carreras: [] }
    ],
    solicitudesRegistro: [],
    solicitudesCursos: [],
    configuracion: { nombreInstitucion: "Universidad del Aluminio", logo: "", minAprobacion: 70 }
};

let db = JSON.parse(JSON.stringify(initialDB));
let usuarios = db.usuarios || [];
let cursos = db.cursos || [];
let carreras = db.carreras || [];
let rolesConfig = db.rolesConfig || [];
let tempModuloEvaluacion = { preguntas: [] };
let solicitudesRegistro = db.solicitudesRegistro || [];
let solicitudesCursos = db.solicitudesCursos || [];
let sesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;
let tempModulos = [];
let tempImagenPortada = "";
let cursoActualData = null;

// ============================================================
// 2. FUNCIONES DE SPINNER Y LOADING
// ============================================================

/**
 * Configura un botón con estado de carga (spinner)
 */
function handleButtonLoading(btn, loading, textLoading = 'Procesando...', textOriginal = null) {
    if (typeof btn === 'string') {
        btn = document.querySelector(btn);
    }
    if (!btn) return null;

    if (!btn.dataset.originalHtml && !loading) {
        btn.dataset.originalHtml = btn.innerHTML;
        btn.dataset.originalText = btn.textContent.trim();
    }

    if (loading) {
        btn.disabled = true;
        btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
        btn.innerHTML = `
            <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
            ${textLoading}
        `;
        btn.classList.add('opacity-75');
        btn.classList.add('btn-loading');
    } else {
        btn.disabled = false;
        if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
        } else if (textOriginal) {
            btn.innerHTML = textOriginal;
        }
        btn.classList.remove('opacity-75');
        btn.classList.remove('btn-loading');
    }
    return btn;
}

/**
 * Envuelve una función asíncrona con control de spinner automático
 */
async function withLoading(btn, asyncFn, loadingText = 'Procesando...', onError = null) {
    const btnElement = typeof btn === 'string' ? document.querySelector(btn) : btn;

    if (btnElement && btnElement.disabled) return;

    try {
        if (btnElement) handleButtonLoading(btnElement, true, loadingText);
        await asyncFn();
    } catch (error) {
        console.error('Error en operación:', error);
        if (onError) {
            onError(error);
        } else {
            showToast('Error: ' + error.message, 'danger');
        }
    } finally {
        if (btnElement) handleButtonLoading(btnElement, false);
    }
}

// ============================================================
// 3. TOAST NOTIFICATIONS
// ============================================================

function showToast(message, type = 'success', duration = 3000) {
    const toastContainer = document.getElementById('toast-container') || createToastContainer();

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

    toastContainer.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: duration });
    bsToast.show();

    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    container.style.zIndex = '9999';
    document.body.appendChild(container);
    return container;
}

// ============================================================
// 4. FUNCIONES AUXILIARES
// ============================================================

const getCareerIdFromRole = (roleId) => {
    const careerMap = {
        "asesor_ventas": "CAR-ASESOR-VENTAS",
        "retail": "CAR-RETAIL",
        "almacenista_retail": "CAR-ALMACENISTA",
        "coordinador_retail": "CAR-COORD-RETAIL",
        "cristalero": "CAR-CRISTALERO"
    };
    return careerMap[roleId] || null;
};

function crearEstructuraUsuario(u) {
    if (!u) return null;
    const res = {
        id: String(u.id || "").trim(),
        nombre: String(u.nombre || "").trim(),
        rol: String(u.rol || "participante"),
        estado: String(u.estado || "activo"),
        asignados: Array.isArray(u.asignados) ? u.asignados : [],
        carrerasAsignadas: Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [],
        progreso: (u.progreso && typeof u.progreso === 'object' && !Array.isArray(u.progreso)) ? u.progreso : {},
        certificadosCurso: Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [],
        certificadosCarrera: Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : []
    };
    if (u.clave && String(u.clave).trim() !== "") {
        res.clave = String(u.clave).trim();
    }
    return res;
}

function actualizarEstadoCarrerasUsuario(usuario) {
    if (!usuario) return;
    usuario.carrerasAsignadas = Array.isArray(usuario.carrerasAsignadas) ? usuario.carrerasAsignadas : [];
    usuario.certificadosCarrera = Array.isArray(usuario.certificadosCarrera) ? usuario.certificadosCarrera : [];
    usuario.certificadosCurso = Array.isArray(usuario.certificadosCurso) ? usuario.certificadosCurso : [];
    if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
        usuario.progreso = {};
    }

    const userRoleConfig = rolesConfig.find(r => r.id === usuario.rol);
    const roleCareerIds = (userRoleConfig && Array.isArray(userRoleConfig.carreras)) ? userRoleConfig.carreras : [];
    const autoCareerId = getCareerIdFromRole(usuario.rol);
    const todasCarrerasIds = new Set([...roleCareerIds]);
    if (autoCareerId) todasCarrerasIds.add(autoCareerId);

    todasCarrerasIds.forEach(carId => {
        if (carreras.some(c => c.id === carId)) {
            if (!usuario.carrerasAsignadas.some(ca => ca.id === carId)) {
                usuario.carrerasAsignadas.push({ id: carId, estado: "Incompleta" });
            }
        }
    });

    usuario.carrerasAsignadas.forEach(ca => {
        const carrera = carreras.find(c => c.id === ca.id);
        if (!carrera || !carrera.cursos || carrera.cursos.length === 0) return;

        const todosCursosCompletados = carrera.cursos.every(cId => {
            if (usuario.certificadosCurso && usuario.certificadosCurso.includes(cId)) return true;
            const cursoObj = cursos.find(c => c.id === cId);
            if (!cursoObj) return false;
            const prog = usuario.progreso[cId];
            if (!prog || !prog.modulosAprobados) return false;
            const totalModulos = (cursoObj.modulos || []).length;
            return totalModulos > 0 && prog.modulosAprobados.length >= totalModulos;
        });

        if (todosCursosCompletados) {
            ca.estado = "Completada";
            if (!usuario.certificadosCarrera.includes(ca.id)) {
                usuario.certificadosCarrera.push(ca.id);
            }
        } else {
            ca.estado = "Incompleta";
            usuario.certificadosCarrera = usuario.certificadosCarrera.filter(id => id !== ca.id);
        }
    });
}

function normalizar(texto) {
    return texto ? texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : "";
}

function extraerID(input) {
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
    const match = input.match(regExp);
    return (match && match[2].length === 11) ? match[2] : input;
}

function comprimirImagenBase64(file, maxWidth = 1000, maxQuality = 0.82) {
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = (err) => reject(err);
            reader.readAsDataURL(file);
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;

                if (width > maxWidth) {
                    height = Math.round((height * maxWidth) / width);
                    width = maxWidth;
                }

                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                const dataUrl = canvas.toDataURL('image/jpeg', maxQuality);
                resolve(dataUrl);
            };
            img.onerror = (err) => reject(err);
            img.src = e.target.result;
        };
        reader.onerror = (err) => reject(err);
        reader.readAsDataURL(file);
    });
}

const guardarTodo = async () => {
    usuarios.forEach(u => {
        if (!u.progreso || Array.isArray(u.progreso) || typeof u.progreso !== 'object') {
            u.progreso = {};
        }
    });

    if (sesion) {
        if (!sesion.progreso || Array.isArray(sesion.progreso) || typeof sesion.progreso !== 'object') {
            sesion.progreso = {};
        }
        const uIdx = usuarios.findIndex(u => u.id === sesion.id);
        if (uIdx !== -1) {
            usuarios[uIdx] = JSON.parse(JSON.stringify(sesion));
            actualizarEstadoCarrerasUsuario(usuarios[uIdx]);
            sesion = JSON.parse(JSON.stringify(usuarios[uIdx]));
            sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
        }
    }

    db.usuarios = usuarios;
    db.cursos = cursos;
    db.carreras = carreras;
    db.rolesConfig = rolesConfig;
    db.solicitudesRegistro = solicitudesRegistro;
    db.solicitudesCursos = solicitudesCursos;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(db)
        });
        if (!response.ok) {
            const errData = await response.json().catch(() => ({ error: 'Error desconocido en servidor' }));
            console.error("Error al guardar en el servidor:", response.status, errData);
            throw new Error(errData.error || response.statusText);
        } else {
            console.log("Sincronizado correctamente con la base de datos del servidor (api.php)");
            return true;
        }
    } catch (err) {
        console.error("Error de conexión al guardar en el servidor:", err);
        throw new Error("Error de conexión: " + err.message);
    }
};

const guardar = guardarTodo;
const guardarCarreras = guardarTodo;
const guardarUsuarios = guardarTodo;
const guardarRoles = guardarTodo;
const guardarSolicitudes = guardarTodo;
const guardarLogo = async (logo) => {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.logo = logo;
    await guardarTodo();
};

async function guardarProgresoUsuario() {
    if (!sesion || !cursoActualData) return false;
    actualizarEstadoCarrerasUsuario(sesion);
    sessionStorage.setItem('aluSesion', JSON.stringify(sesion));

    const cursoId = cursoActualData.id;
    const prog = sesion.progreso?.[cursoId] || {};

    try {
        // Endpoint granular: solo envía el progreso del curso actual (~500 bytes)
        const res = await fetch('api.php?action=guardar_progreso', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                usuario_id:            sesion.id,
                curso_id:              cursoId,
                leccionesCompletadas:  prog.leccionesCompletadas  || [],
                modulosAprobados:      prog.modulosAprobados      || [],
                medallas:              prog.medallas              || [],
                evaluaciones:          prog.evaluaciones          || {},
                intentos:              prog.intentos              || {},
                certificadosCurso:     sesion.certificadosCurso   || []
            })
        });
        if (!res.ok) throw new Error('Error del servidor al guardar progreso');
        return true;
    } catch (err) {
        console.warn('Fallback a guardarTodo por error en guardar_progreso:', err.message);
        await guardarTodo();
        return false;
    }
}

// ============================================================
// 5. FUNCIONES DE AUTENTICACIÓN Y LOGIN
// ============================================================

/**
 * Login server-side (bcrypt). Llama a api.php?action=login.
 * Retorna Promise<boolean>.
 */
window.login = async (id, clave) => {
    try {
        const res = await fetch('api.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, clave })
        });
        if (!res.ok) return false;
        const data = await res.json();
        if (data.usuario) {
            sessionStorage.setItem('aluSesion', JSON.stringify(data.usuario));
            sesion = data.usuario;
            return true;
        }
        return false;
    } catch (err) {
        console.error('Error de login:', err);
        return false;
    }
};

window.logout = () => {
    sessionStorage.removeItem('aluSesion');
    window.location.href = 'login.html';
};

window.solicitarRegistro = async (id, nombre, clave, perfilDeseado) => {
    const autoAssignCareerMap = {
        "asesor_ventas": "CAR-ASESOR-VENTAS",
        "retail": "CAR-RETAIL",
        "almacenista_retail": "CAR-ALMACENISTA",
        "coordinador_retail": "CAR-COORD-RETAIL",
        "cristalero": "CAR-CRISTALERO"
    };
    try {
        const res = await fetch('api.php?action=solicitar_registro', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id, nombre, clave, perfilDeseado,
                autoAssignCareerId: autoAssignCareerMap[perfilDeseado] || null
            })
        });
        const data = await res.json();
        if (!res.ok) {
            showToast(data.error || 'Error al enviar solicitud', 'danger');
            return;
        }
        showToast("Solicitud enviada. Un administrador revisará su acceso pronto.", "success");
        setTimeout(() => location.reload(), 1500);
    } catch (err) {
        showToast('Error de conexión: ' + err.message, 'danger');
    }
};

// ============================================================
// 6. GESTIÓN DE SOLICITUDES
// ============================================================

window.gestionarSolicitudRegistro = async (id, aprobado) => {
    const btn = event?.target?.closest('button');
    const actionText = aprobado ? 'Aprobando solicitud...' : 'Rechazando solicitud...';

    await withLoading(btn, async () => {
        const idx = solicitudesRegistro.findIndex(s => s.id === id);
        const sol = solicitudesRegistro[idx];

        if (aprobado && sol) {
            const userCareers = [];
            const autoAssignCareerId = getCareerIdFromRole(sol.perfilDeseado);
            if (autoAssignCareerId) {
                if (carreras.some(c => c.id === autoAssignCareerId)) {
                    userCareers.push({ id: autoAssignCareerId, estado: "Incompleta" });
                }
            }

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
            await guardarUsuarios();
        }

        solicitudesRegistro.splice(idx, 1);
        await guardarSolicitudes();
        showToast(aprobado ? 'Solicitud aprobada y usuario creado.' : 'Solicitud rechazada.', aprobado ? 'success' : 'danger');
        setTimeout(() => location.reload(), 1500);
    }, actionText);
};

window.solicitarAccesoCurso = async (cursoId) => {
    const yaSolicitado = solicitudesCursos.find(s => s.userId === sesion.id && s.cursoId === cursoId);
    if (yaSolicitado) {
        showToast("Ya tienes una solicitud pendiente para este curso.", "warning");
        return;
    }

    solicitudesCursos.push({ userId: sesion.id, cursoId, userName: sesion.nombre, fecha: new Date().toLocaleDateString() });
    await guardarSolicitudes();
    showToast("Solicitud de acceso enviada al administrador.", "success");
    setTimeout(() => location.reload(), 1500);
};

window.gestionarSolicitudCurso = async (userId, cursoId, aprobado) => {
    const btn = event?.target?.closest('button');
    const actionText = aprobado ? 'Aprobando acceso...' : 'Rechazando acceso...';

    await withLoading(btn, async () => {
        const idx = solicitudesCursos.findIndex(s => s.userId === userId && s.cursoId === cursoId);
        if (aprobado) {
            const uIdx = usuarios.findIndex(u => u.id === userId);
            if (uIdx !== -1) {
                usuarios[uIdx].asignados = Array.isArray(usuarios[uIdx].asignados) ? usuarios[uIdx].asignados : [];
                if (!usuarios[uIdx].asignados.includes(cursoId)) {
                    usuarios[uIdx].asignados.push(cursoId);
                }
                await guardarUsuarios();
            }
        }
        solicitudesCursos.splice(idx, 1);
        await guardarSolicitudes();
        showToast(aprobado ? 'Acceso aprobado.' : 'Acceso rechazado.', aprobado ? 'success' : 'danger');
        setTimeout(() => location.reload(), 1500);
    }, actionText);
};

// ============================================================
// 7. GESTIÓN DE CURSOS
// ============================================================

window.prepararFormulario = async (modo) => {
    const form = document.getElementById('form-curso');
    const idActual = document.getElementById('edit-id').value;

    if (tempModulos.length > 0 && !idActual) {
        const ok = await showConfirmModal({
            title: '¿Reiniciar Curso en Edición?',
            message: 'Hay un curso en proceso de creación. ¿Deseas descartar los cambios actuales y empezar de cero?',
            confirmText: 'Sí, descartar',
            confirmVariant: 'warning'
        });
        if (!ok) return;
    }

    if (form) form.reset();
    document.getElementById('edit-id').value = '';
    document.getElementById('titulo').value = '';
    document.getElementById('descripcion').value = '';
    document.getElementById('curso-prelacion').value = '';
    if (document.getElementById('curso-tipo')) {
        document.getElementById('curso-tipo').value = 'especializado';
    }
    if (document.getElementById('curso-en-construccion')) {
        document.getElementById('curso-en-construccion').checked = false;
    }
    const fileInput = document.getElementById('input-portada');
    if (fileInput) fileInput.value = '';
    tempModulos = [];
    tempImagenPortada = "";
    mostrarVistaPreviaPortada();
    document.getElementById('modalTitulo').innerText = "Nuevo Curso";

    const selPrelacion = document.getElementById('curso-prelacion');
    if (selPrelacion) {
        selPrelacion.innerHTML = '<option value="">Ninguno</option>' + cursos.map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
    }

    renderModulosEditor();
};

window.mostrarVistaPreviaPortada = () => {
    const vistaPrev = document.getElementById('vista-previa-portada');
    const imgPrev = document.getElementById('img-vista-previa');
    if (vistaPrev && imgPrev) {
        if (tempImagenPortada) {
            imgPrev.src = typeof resolverSrcImagen === 'function' ? resolverSrcImagen(tempImagenPortada) : tempImagenPortada;
            vistaPrev.style.display = 'block';
        } else {
            vistaPrev.style.display = 'none';
            imgPrev.src = '';
        }
    }
};

/**
 * Carga imagen de portada al servidor (PHP GD) y actualiza la vista previa.
 * Usa subirImagenServidor() si está disponible, fallback a base64.
 */
window.cargarImagenPortada = async (event) => {
    const file = event.target.files[0];
    if (!file) return;
    const btn = event.target.closest('label') || event.target;
    const cursoId = document.getElementById('edit-id')?.value || 'nuevo';
    try {
        if (typeof subirImagenServidor === 'function') {
            const prevUrl = tempImagenPortada;
            const url = await subirImagenServidor(file, 'portada', cursoId, prevUrl);
            tempImagenPortada = url;
        } else {
            // Fallback base64
            const b64 = await comprimirImagenBase64(file, 1200, 0.82);
            tempImagenPortada = b64;
        }
        mostrarVistaPreviaPortada();
    } catch (err) {
        showToast('Error al cargar la imagen: ' + err.message, 'danger');
    }
};

window.eliminarImagenPortada = () => {
    tempImagenPortada = "";
    mostrarVistaPreviaPortada();
    const fileInput = document.getElementById('input-portada');
    if (fileInput) fileInput.value = "";
};

window.abrirEditor = (id) => {
    const c = cursos.find(item => item.id === id);
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
    tempImagenPortada = c.imagen || "";
    mostrarVistaPreviaPortada();

    tempModulos = JSON.parse(JSON.stringify(c.modulos || []));
    renderModulosEditor();

    document.getElementById('modalTitulo').innerText = "Editar Curso";

    const selPrelacion = document.getElementById('curso-prelacion');
    if (selPrelacion) {
        selPrelacion.innerHTML = '<option value="">Ninguno</option>' + cursos.filter(item => item.id !== id).map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
        selPrelacion.value = c.prelacion || '';
    }

    const modalElement = document.getElementById('cursoModal');
    const bModal = new bootstrap.Modal(modalElement);
    bModal.show();
};

window.guardarCurso = async (e) => {
    if (e) e.preventDefault();

    const btn = document.getElementById('btn-guardar-curso');
    await withLoading(btn, async () => {
        const enConstruccion = document.getElementById('curso-en-construccion') ? document.getElementById('curso-en-construccion').checked : false;

        // Si el curso NO está en construcción, debe tener al menos 1 módulo y cada módulo debe tener lecciones
        if (!enConstruccion) {
            if (tempModulos.length === 0) {
                throw new Error('El curso publicado debe tener al menos un módulo.');
            }
            const todasTienenLecciones = tempModulos.every(m => m.lecciones && m.lecciones.length > 0);
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
            imagen: tempImagenPortada,
            descripcion: (document.getElementById('descripcion')?.value || '').trim(),
            prelacion: document.getElementById('curso-prelacion')?.value || '',
            enConstruccion: enConstruccion,
            modulos: tempModulos
        };

        if (idEdit) {
            const idx = cursos.findIndex(c => String(c.id) === String(idEdit));
            if (idx !== -1) {
                cursos[idx] = nuevoCurso;
            } else {
                cursos.push(nuevoCurso);
            }
        } else {
            cursos.push(nuevoCurso);
        }
        
        try {
            await window.API.guardarCurso(nuevoCurso);
        } catch (err) {
            console.warn('Fallback a guardarTodo:', err);
            await guardarTodo();
        }
        
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

    await withLoading(btn, async () => {
        cursos = cursos.filter(c => String(c.id) !== String(id));
        try {
            await window.API.eliminarCurso(id);
        } catch (err) {
            console.warn('Fallback a guardar:', err);
            await guardar();
        }
        showToast('Curso eliminado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando curso...');
};

// ============================================================
// 8. GESTIÓN DE USUARIOS
// ============================================================

window.prepararFormularioUsuario = () => {
    const form = document.getElementById('form-usuario-integral');
    if (form) form.reset();
    document.getElementById('u-id').disabled = false;
    document.getElementById('userModalTitle').innerText = "Nuevo Colaborador";
    renderSelectRoles();
};

window.abrirEditorUsuario = (id) => {
    const u = usuarios.find(user => user.id === id);
    if (!u) return;

    document.getElementById('userModalTitle').innerText = `Editando: ${u.nombre}`;
    document.getElementById('u-id').value = u.id;
    document.getElementById('u-id').disabled = true;
    document.getElementById('u-nombre').value = u.nombre;

    renderSelectRoles();
    document.getElementById('u-rol').value = u.rol;
    document.getElementById('u-estado').value = u.estado || 'activo';
    document.getElementById('u-clave').value = '';

    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
};

function renderSelectRoles() {
    const select = document.getElementById('u-rol');
    if (select) {
        select.innerHTML = rolesConfig.map(r => `<option value="${r.id}">${r.nombre}</option>`).join('');
    }
}

window.guardarUsuario = async (e) => {
    if (e) e.preventDefault();

    const btn = document.getElementById('btn-guardar-usuario');
    await withLoading(btn, async () => {
        const id = document.getElementById('u-id').value;
        const nombre = document.getElementById('u-nombre').value;
        const rol = document.getElementById('u-rol').value;
        const estado = document.getElementById('u-estado').value;
        const claveNueva = document.getElementById('u-clave').value;

        const autoAssignCareerId = getCareerIdFromRole(rol);
        const userCareers = [];
        if (autoAssignCareerId) {
            if (carreras.some(c => c.id === autoAssignCareerId)) {
                userCareers.push({ id: autoAssignCareerId, estado: "Incompleta" });
            }
        }

        const idx = usuarios.findIndex(u => u.id === id);
        let userToSave = null;
        if (idx !== -1) {
            usuarios[idx].nombre = nombre;
            usuarios[idx].rol = rol;
            usuarios[idx].estado = estado;
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
            if (usuarios.find(u => u.id === id)) throw new Error("ID ya registrado");
            const nuevoUsuario = crearEstructuraUsuario({
                id, nombre, clave: claveNueva || "12345",
                rol, estado, asignados: [],
                carrerasAsignadas: userCareers,
                progreso: {}, certificadosCurso: [], certificadosCarrera: []
            });
            usuarios.push(nuevoUsuario);
            userToSave = nuevoUsuario;
        }

        try {
            await window.API.guardarUsuario(userToSave);
        } catch (err) {
            console.warn('Fallback a guardarUsuarios:', err);
            await guardarUsuarios();
        }
        
        showToast('Usuario guardado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
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
        try {
            await window.API.eliminarUsuario(idStr);
        } catch (err) {
            console.warn('Fallback a guardarUsuarios:', err);
            await guardarUsuarios();
        }
        showToast('Usuario eliminado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando usuario...');
};

// ============================================================
// 9. GESTIÓN DE CARRERAS
// ============================================================

window.crearCarrera = async (e) => {
    e.preventDefault();

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

        try {
            await window.API.guardarCarrera(carreraToSave);
        } catch (err) {
            console.warn('Fallback a guardarCarreras:', err);
            await guardarCarreras();
        }

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
        try {
            await window.API.eliminarCarrera(id);
        } catch (err) {
            console.warn('Fallback a guardarCarreras:', err);
            await guardarCarreras();
        }
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
            }
        }

        carreras.push(newCareer);
        await guardarTodo();
        showToast('Carrera duplicada con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Duplicando carrera...');
};

window.abrirEditorCarrera = (id) => {
    const car = carreras.find(c => c.id === id);
    document.getElementById('edit-career-id').value = car.id;
    document.getElementById('career-name').value = car.nombre;
    renderListaCursosCarrera();
    car.cursos.forEach(cId => {
        const chk = document.getElementById(`chk-${cId}`);
        if (chk) chk.checked = true;
    });
    const btn = document.getElementById('btn-guardar-carrera');
    btn.textContent = 'Actualizar Carrera';
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

// ============================================================
// 10. GESTIÓN DE ROLES
// ============================================================

window.prepararFormularioRol = () => {
    const form = document.getElementById('form-rol-integral');
    if (form) form.reset();
    document.getElementById('r-id').disabled = false;
    document.getElementById('roleModalTitle').innerText = "Nuevo Rol";
    renderContenidoRol([], []);
};

window.abrirEditorRol = (id) => {
    const rol = rolesConfig.find(r => r.id === id);
    if (!rol) return;

    document.getElementById('roleModalTitle').innerText = `Editando Rol: ${rol.nombre}`;
    document.getElementById('r-id').value = rol.id;
    document.getElementById('r-id').disabled = true;
    document.getElementById('r-nombre').value = rol.nombre;
    renderContenidoRol(rol.cursos || [], rol.carreras || []);

    const modal = new bootstrap.Modal(document.getElementById('roleModal'));
    modal.show();
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

window.guardarRol = async (e) => {
    e.preventDefault();

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

        try {
            await window.API.guardarRol(rolToSave);
        } catch (err) {
            console.warn('Fallback a guardarRoles:', err);
            await guardarRoles();
        }

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
        try {
            await window.API.eliminarRol(id);
        } catch (err) {
            console.warn('Fallback a guardarRoles:', err);
            await guardarRoles();
        }
        showToast('Rol eliminado con éxito.', 'success');
        setTimeout(() => location.reload(), 1500);
    }, 'Eliminando rol...');
};

// ============================================================
// 11. FUNCIONES DE PROGRESO
// ============================================================

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
            if (newM >= 0) newL = cursoActualData.modulos[newM].lecciones.length - 1;
        }
    }
    if (cursoActualData.modulos[newM] && cursoActualData.modulos[newM].lecciones[newL]) {
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
                    <p class="text-muted fs-6 mb-0">El módulo <strong>"${moduloCheck.titulo}"</strong> se encuentra actualmente en desarrollo. ¡Sus lecciones estarán disponibles muy pronto!</p>
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
    const progreso = sesion.progreso[cursoActualData.id];
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

    // Parser seguro de video ID (YouTube)
    let videoUrl = '';
    const rawVideo = leccion.videoID || '';
    if (rawVideo.includes('http') || rawVideo.includes('youtu')) {
        const parsed = extraerID(rawVideo);
        videoUrl = parsed ? `https://www.youtube.com/embed/${parsed}?rel=0&modestbranding=1` : rawVideo;
    } else if (rawVideo) {
        videoUrl = `https://www.youtube.com/embed/${rawVideo}?rel=0&modestbranding=1`;
    }

    visor.innerHTML = `
        <div class="card border-0 shadow-sm p-4 mb-4 bg-white rounded-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                    <span class="badge-soft-primary mb-1 d-inline-block">Módulo ${mIdx + 1}: ${modulo.titulo}</span>
                    <h3 class="fw-bold text-primary mb-0">${leccion.titulo || 'Lección sin título'}</h3>
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
                    <iframe src="${videoUrl}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
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
                    ${leccion.contenido || 'No hay descripción adicional para esta lección.'}
                </div>
                ${leccion.adjunto ? `
                    <div class="pt-3 border-top">
                        <a href="${leccion.adjunto}" download="${leccion.nombreAdjunto || 'recurso'}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-paperclip me-1"></i> Descargar Adjunto: <strong>${leccion.nombreAdjunto || 'Archivo'}</strong>
                        </a>
                    </div>` : ''}
            </div>
        </div>
    `;
};

window.mostrarEvaluacionModulo = (cursoID, mIdx) => {
    const curso = cursos.find(c => c.id === cursoID) || cursoActualData;
    const modulo = curso.modulos[mIdx];
    if (modulo && modulo.enConstruccion) {
        showToast('Este módulo se encuentra en construcción. La evaluación aún no está disponible.', 'warning');
        return;
    }
    const visor = document.getElementById('visor-contenido');

    if (!sesion.progreso[curso.id]) sesion.progreso[curso.id] = { leccionesCompletadas: [], modulosAprobados: [] };
    const progreso = sesion.progreso[curso.id];
    const modulosAprobados = Array.isArray(progreso) ? [] : (progreso.modulosAprobados || []);

    const moduloYaAprobado = modulosAprobados.includes(String(mIdx));

    if (moduloYaAprobado) {
        visor.innerHTML = `
            <div class="quiz-card text-center p-5">
                <div class="quiz-score-badge pass"><i class="bi bi-award-fill"></i></div>
                <h2 class="fw-bold text-primary mb-2">¡Módulo "${modulo.titulo}" Aprobado!</h2>
                <p class="lead text-muted mb-4">Has aprobado satisfactoriamente la evaluación correspondiente a este módulo.</p>
                <div class="d-flex justify-content-center gap-3">
                    <button class="btn btn-outline-primary" onclick="reintentarEvaluacion('${curso.id}', ${mIdx})">
                        <i class="bi bi-arrow-repeat me-1"></i>Repasar Evaluación
                    </button>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="bi bi-arrow-left me-1"></i>Volver al Curso
                    </button>
                </div>
            </div>`;
        return;
    }

    const minAprobacion = (db.configuracion && db.configuracion.minAprobacion) || 70;
    const totalPreguntas = (modulo.evaluacion && modulo.evaluacion.preguntas) ? modulo.evaluacion.preguntas.length : 0;

    visor.innerHTML = `
        <div class="quiz-card p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                <div>
                    <span class="badge-soft-warning mb-1 d-inline-block">Examen de Módulo ${mIdx + 1}</span>
                    <h3 class="fw-bold text-primary mb-0">${modulo.titulo}</h3>
                </div>
                <span class="badge bg-light text-dark border px-3 py-2">
                    <i class="bi bi-check2-square text-primary me-1"></i> ${totalPreguntas} preguntas
                </span>
            </div>

            <p class="text-muted small mb-4">
                <i class="bi bi-info-circle me-1 text-primary"></i> 
                Responde todas las preguntas. Se requiere un puntaje mínimo de <strong>${minAprobacion}%</strong> para aprobar este módulo y desbloquear los siguientes contenidos.
            </p>

            <div class="quiz-area mb-4">
                ${modulo.evaluacion.preguntas.map((p, i) => `
                    <div class="card p-3 mb-3 border bg-light">
                        <h6 class="fw-bold text-primary mb-3">
                            <span class="badge bg-primary me-2">${i + 1}</span> ${p.enunciado}
                        </h6>
                        <div class="quiz-options-list">
                            ${p.opciones.map((opt, oIdx) => `
                                <label class="quiz-option-label" for="q${i}o${oIdx}">
                                    <input type="radio" name="q${i}" value="${oIdx}" id="q${i}o${oIdx}" class="form-check-input">
                                    <span>${opt}</span>
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
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Calificando evaluación...`;

        const modulo = cursoActualData.modulos[mIdx];
        const preguntas = (modulo.evaluacion && modulo.evaluacion.preguntas) || [];
        let aciertos = 0;
        const feedback = document.getElementById('feedback');

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
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            return;
        }

        preguntas.forEach((p, i) => {
            const sel = document.querySelector(`input[name="q${i}"]:checked`);
            if (sel && parseInt(sel.value) === p.correcta) aciertos++;
        });

        const porcentaje = Math.round((aciertos / preguntas.length) * 100);
        const min = (db.configuracion && db.configuracion.minAprobacion) || 70;

        if (!sesion.progreso[cursoActualData.id]) {
            sesion.progreso[cursoActualData.id] = {
                leccionesCompletadas: [],
                modulosAprobados: []
            };
        }
        if (Array.isArray(sesion.progreso[cursoActualData.id])) {
            sesion.progreso[cursoActualData.id] = {
                leccionesCompletadas: sesion.progreso[cursoActualData.id],
                modulosAprobados: []
            };
        }
        const progreso = sesion.progreso[cursoActualData.id];

        if (!progreso.intentos) progreso.intentos = {};
        if (!progreso.intentos[mIdx]) progreso.intentos[mIdx] = 0;
        progreso.intentos[mIdx]++;

        const numIntentos = progreso.intentos[mIdx];
        const maxIntentos = modulo.maxIntentos || 0;

        if (!progreso.evaluaciones) progreso.evaluaciones = {};
        progreso.evaluaciones[mIdx] = {
            calificacion: porcentaje,
            aprobado: porcentaje >= min,
            fecha: new Date().toISOString()
        };

        const quizArea = document.querySelector('.quiz-area');
        if (quizArea) {
            document.querySelectorAll('.quiz-area input').forEach(input => input.disabled = true);
            quizArea.innerHTML = preguntas.map((p, i) => {
                const selectedInput = document.querySelector(`input[name="q${i}"]:checked`);
                const selectedVal = selectedInput ? parseInt(selectedInput.value) : -1;
                const isCorrect = selectedVal === p.correcta;
                return `
                    <div class="card p-3 mb-3 border ${isCorrect ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10'}">
                        <h6 class="fw-bold d-flex justify-content-between align-items-center">
                            <span><span class="badge ${isCorrect ? 'bg-success' : 'bg-danger'} me-2">${i + 1}</span> ${p.enunciado}</span>
                            ${isCorrect 
                                ? '<span class="badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>Correcto</span>' 
                                : '<span class="badge-soft-danger"><i class="bi bi-x-circle-fill me-1"></i>Incorrecto</span>'}
                        </h6>
                        <div class="mt-2">
                            ${p.opciones.map((opt, oIdx) => {
                                let labelStyle = "";
                                if (oIdx === p.correcta) labelStyle = "font-weight-bold text-success";
                                else if (oIdx === selectedVal) labelStyle = "text-danger text-decoration-line-through";
                                return `
                                    <div class="py-1 small ${labelStyle}">
                                        ${oIdx === p.correcta ? '✓ ' : (oIdx === selectedVal ? '✗ ' : '• ')}${opt}
                                    </div>`;
                            }).join('')}
                        </div>
                    </div>`;
            }).join('');
        }

        if (submitBtn = document.getElementById('btn-enviar-evaluacion')) {
            submitBtn.style.display = 'none';
        }

        if (porcentaje >= min) {
            sesion.certificadosCurso = sesion.certificadosCurso || [];
            if (!progreso.modulosAprobados) progreso.modulosAprobados = [];
            if (!progreso.modulosAprobados.includes(String(mIdx))) {
                progreso.modulosAprobados.push(String(mIdx));
            }

            const modulosConEvaluacion = cursoActualData.modulos.filter(m =>
                m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0
            ).length;
            if (progreso.modulosAprobados.length >= modulosConEvaluacion) {
                if (!sesion.certificadosCurso.includes(cursoActualData.id)) {
                    sesion.certificadosCurso.push(cursoActualData.id);
                }
            }

            if (!progreso.medallas) progreso.medallas = [];
            if (!progreso.medallas.includes(String(mIdx))) {
                progreso.medallas.push(String(mIdx));
            }

            await guardarProgresoUsuario();

            feedback.innerHTML = `
                <div class="card border-success bg-success bg-opacity-10 text-center p-4">
                    <div class="quiz-score-badge pass">${porcentaje}%</div>
                    <h3 class="fw-bold text-success mb-2">¡Felicitaciones! Módulo Aprobado</h3>
                    <p class="text-muted mb-3">Has obtenido <strong>${aciertos} de ${preguntas.length}</strong> respuestas correctas (Mínimo: ${min}%).</p>
                    <div class="d-flex justify-content-center gap-3">
                        <button class="btn btn-success px-4" onclick="window.location.reload()">
                            <i class="bi bi-arrow-right-circle me-1"></i>Continuar al Siguiente Contenido
                        </button>
                    </div>
                </div>`;
        } else {
            await guardarProgresoUsuario();

            feedback.innerHTML = `
                <div class="card border-danger bg-danger bg-opacity-10 text-center p-4">
                    <div class="quiz-score-badge fail">${porcentaje}%</div>
                    <h3 class="fw-bold text-danger mb-2">Módulo No Aprobado</h3>
                    <p class="text-muted mb-3">Obtuviste <strong>${aciertos} de ${preguntas.length}</strong> aciertos. Se requiere al menos un <strong>${min}%</strong> para aprobar.</p>
                    <div class="d-flex justify-content-center gap-3">
                        <button class="btn btn-warning px-4" onclick="reintentarEvaluacion('${cursoActualData.id}', ${mIdx})">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reintentar Evaluación
                        </button>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            Volver al Curso
                        </button>
                    </div>
                </div>`;
        }
    } catch (error) {
        console.error('Error en validación:', error);
        showToast('Ocurrió un error al validar la evaluación.', 'danger');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
};

window.reintentarEvaluacion = (cursoID, mIdx) => {
    mostrarEvaluacionModulo(cursoID, mIdx);
};

// ============================================================
// 12. FUNCIONES DE EVALUACIÓN DE MÓDULOS (EDITOR)
// ============================================================

window.abrirEditorModuloEvaluacion = (mIdx) => {
    const modulo = tempModulos[mIdx];
    tempModuloEvaluacion = JSON.parse(JSON.stringify(modulo.evaluacion || { preguntas: [] }));

    document.getElementById('modalModuloEvaluacionTitulo').innerText = `Evaluación: ${modulo.titulo}`;
    document.getElementById('edit-modulo-idx').value = mIdx;

    renderPreguntasModuloEditor();

    const modalElement = document.getElementById('moduloEvaluacionModal');
    const bModal = new bootstrap.Modal(modalElement);
    bModal.show();
};

window.agregarPreguntaModulo = () => {
    if (!tempModuloEvaluacion.preguntas) tempModuloEvaluacion.preguntas = [];
    tempModuloEvaluacion.preguntas.push({
        enunciado: "Nueva pregunta",
        opciones: ["Opción A", "Opción B"],
        correcta: 0
    });
    renderPreguntasModuloEditor();
};

window.eliminarPreguntaModulo = (idx) => {
    tempModuloEvaluacion.preguntas.splice(idx, 1);
    renderPreguntasModuloEditor();
};

function eliminarPreguntaOpcionesModulo(idx) {
    tempModuloEvaluacion.preguntas[idx].opciones.pop();
    renderPreguntasModuloEditor();
}

function renderPreguntasModuloEditor() {
    const container = document.getElementById('contenedor-preguntas-modulo-editor');
    if (!container) return;

    container.innerHTML = (tempModuloEvaluacion.preguntas || []).map((p, pIdx) => `
        <div class="card p-3 mb-3 bg-white shadow-sm">
            <div class="d-flex justify-content-between mb-2">
                <input type="text" class="form-control me-2" value="${p.enunciado}" oninput="tempModuloEvaluacion.preguntas[${pIdx}].enunciado = this.value">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaModulo(${pIdx})">X</button>
            </div>
            ${p.opciones.map((opt, oIdx) => `
                <div class="input-group mb-1">
                    <div class="input-group-text">
                        <input type="radio" name="correcta-mod-${pIdx}" ${p.correcta == oIdx ? 'checked' : ''} onclick="tempModuloEvaluacion.preguntas[${pIdx}].correcta = ${oIdx}">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaOpcionesModulo(${pIdx})">X</button>
                    </div>
                    <input type="text" class="form-control form-control-sm" value="${opt}" oninput="tempModuloEvaluacion.preguntas[${pIdx}].opciones[${oIdx}] = this.value">
                </div>
            `).join('')}
            <button type="button" class="btn btn-sm btn-link" onclick="tempModuloEvaluacion.preguntas[${pIdx}].opciones.push('Nueva Opción'); renderPreguntasModuloEditor()">+ Añadir Opción</button>
        </div>
    `).join('') + `<button type="button" class="btn btn-outline-dark w-100" onclick="agregarPreguntaModulo()">+ Añadir Pregunta al Examen</button>`;
}

window.guardarEvaluacionModulo = () => {
    const btn = document.querySelector('#moduloEvaluacionModal button[type="submit"]');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando evaluación...`;

    try {
        const mIdx = document.getElementById('edit-modulo-idx').value;
        tempModulos[mIdx].evaluacion = JSON.parse(JSON.stringify(tempModuloEvaluacion));

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
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
};

// ============================================================
// 13. FUNCIONES DE MODULOS Y LECCIONES (EDITOR)
// ============================================================

window.eliminarModulo = (idx) => {
    tempModulos.splice(idx, 1);
    renderModulosEditor();
};

window.subirModulo = (idx) => {
    if (idx > 0) {
        [tempModulos[idx], tempModulos[idx - 1]] = [tempModulos[idx - 1], tempModulos[idx]];
        renderModulosEditor();
    }
};

window.bajarModulo = (idx) => {
    if (idx < tempModulos.length - 1) {
        [tempModulos[idx], tempModulos[idx + 1]] = [tempModulos[idx + 1], tempModulos[idx]];
        renderModulosEditor();
    }
};

window.agregarModulo = () => {
    tempModulos.push({ titulo: "Nuevo Módulo", enConstruccion: false, lecciones: [] });
    renderModulosEditor();
};

window.agregarLeccion = (mIdx) => {
    tempModulos[mIdx].lecciones.push({ titulo: "Nueva Lección", videoID: "", contenido: "", adjunto: "" });
    renderModulosEditor();
};

window.eliminarLeccion = (mIdx, lIdx) => {
    tempModulos[mIdx].lecciones.splice(lIdx, 1);
    renderModulosEditor();
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
        tempModulos[mIdx].lecciones[lIdx].adjunto = e.target.result;
        tempModulos[mIdx].lecciones[lIdx].nombreAdjunto = file.name;
        renderModulosEditor();
    };
    reader.readAsDataURL(file);
};

function renderModulosEditor() {
    const container = document.getElementById('contenedor-modulos-editor');
    if (!container) return;
    container.innerHTML = tempModulos.map((mod, mIdx) => `
        <div class="border p-3 mb-3 ${mod.enConstruccion ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light'} rounded shadow-sm">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <div class="btn-group me-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="subirModulo(${mIdx})" ${mIdx === 0 ? 'disabled' : ''} title="Subir Módulo"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bajarModulo(${mIdx})" ${mIdx === tempModulos.length - 1 ? 'disabled' : ''} title="Bajar Módulo"><i class="bi bi-arrow-down"></i></button>
                </div>
                <input type="text" class="form-control fw-bold flex-grow-1" style="min-width: 200px;" placeholder="Título del Módulo" value="${mod.titulo}" oninput="tempModulos[${mIdx}].titulo = this.value">
                
                <div class="form-check form-switch ms-2 me-2" title="Marcar este módulo como en construcción">
                    <input class="form-check-input" type="checkbox" role="switch" id="mod-const-${mIdx}" ${mod.enConstruccion ? 'checked' : ''} onchange="tempModulos[${mIdx}].enConstruccion = this.checked; renderModulosEditor();">
                    <label class="form-check-label small fw-bold text-warning" for="mod-const-${mIdx}">
                        <i class="bi bi-cone-striped me-1"></i>En Construcción
                    </label>
                </div>

                <div class="input-group" style="width: 130px;" title="Intentos máximos para la evaluación (0 o vacío para ilimitados)">
                    <span class="input-group-text"><i class="bi bi-arrow-repeat"></i></span>
                    <input type="number" class="form-control form-control-sm" placeholder="Intentos" value="${mod.maxIntentos || ''}" oninput="tempModulos[${mIdx}].maxIntentos = parseInt(this.value) || 0">
                </div>
                <button type="button" class="btn btn-sm btn-primary" onclick="abrirEditorModuloEvaluacion(${mIdx})">
                    <i class="bi bi-clipboard-check"></i> Evaluación
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="eliminarModulo(${mIdx})" title="Eliminar Módulo">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div class="ms-4 border-start ps-3">
                ${mod.lecciones.map((lec, lIdx) => `
                    <div class="card p-2 mb-2 bg-white">
                        <input type="text" class="form-control form-control-sm mb-1" placeholder="Título Lección" value="${lec.titulo}" oninput="tempModulos[${mIdx}].lecciones[${lIdx}].titulo = this.value">
                        <div class="row g-2">
                            <div class="col-8">
                                <input type="text" class="form-control form-control-sm mb-1" placeholder="URL de YouTube" value="${lec.videoID ? 'https://www.youtube.com/watch?v=' + lec.videoID : ''}" oninput="tempModulos[${mIdx}].lecciones[${lIdx}].videoID = extraerID(this.value)">
                            </div>
                            <div class="col-4">
                                ${lec.videoID ? `<button type="button" class="btn btn-sm btn-dark w-100" onclick="window.open('https://youtube.com/embed/${lec.videoID}')">Ver</button>` : ''}
                            </div>
                        </div>
                        <textarea class="form-control form-control-sm mb-1" placeholder="Contenido..." oninput="tempModulos[${mIdx}].lecciones[${lIdx}].contenido = this.value">${lec.contenido || ''}</textarea>
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
}

// ============================================================
// 14. FUNCIONES DE RESTABLECER AVANCE
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

    document.getElementById('restablecer-modulos-container').style.display = 'none';

    const modalEl = document.getElementById('restablecerAvanceModal');
    const bModal = new bootstrap.Modal(modalEl);
    bModal.show();
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
        } else {
            const checked = Array.from(document.querySelectorAll('.modulo-restablecer-checkbox:checked'));
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
        }

        actualizarEstadoCarrerasUsuario(u);
        await guardarTodo();

        const modalEl = document.getElementById('restablecerAvanceModal');
        const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bModal.hide();
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
// 15. FUNCIONES DE MARCAR COMPLETADO
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

    document.getElementById('marcar-modulos-container').style.display = 'none';

    const modalEl = document.getElementById('marcarCompletadoModal');
    const bModal = new bootstrap.Modal(modalEl);
    bModal.show();
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

    const ok = await showConfirmModal({
        title: '¿Marcar Módulos como Completados?',
        message: `
            <p>¿Estás seguro de marcar como <strong>COMPLETADOS</strong> los siguientes módulos del curso <em>"${curso.titulo}"</em> para el usuario <strong>${usuario.nombre}</strong>?</p>
            <ul class="mb-3">${moduloListaHtml}</ul>
            <div class="alert alert-info py-2 px-3 small mb-0">
                <strong>Efectos automáticos:</strong>
                <ul class="mb-0 ps-3">
                    <li>Aprobará automáticamente las evaluaciones</li>
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
        const minAprobacion = (db.configuracion && db.configuracion.minAprobacion) || 70;

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

            if (modulo.evaluacion && modulo.evaluacion.preguntas && modulo.evaluacion.preguntas.length > 0) {
                if (!progreso.evaluaciones) progreso.evaluaciones = {};
                if (!progreso.evaluaciones[mIdx]) {
                    progreso.evaluaciones[mIdx] = {
                        calificacion: minAprobacion,
                        aprobado: true,
                        marcadoManual: true,
                        fecha: new Date().toISOString()
                    };
                }
            }

            if (!progreso.leccionesCompletadas) progreso.leccionesCompletadas = [];
            if (modulo.lecciones && modulo.lecciones.length > 0) {
                for (let lIdx = 0; lIdx < modulo.lecciones.length; lIdx++) {
                    const lecId = `${mIdx}-${lIdx}`;
                    if (!progreso.leccionesCompletadas.includes(lecId)) {
                        progreso.leccionesCompletadas.push(lecId);
                    }
                }
            }

            if (!progreso.intentos) progreso.intentos = {};
            progreso.intentos[mIdx] = 1;
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
        await guardarTodo();

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
        const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bModal.hide();
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) backdrop.remove();
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';

        setTimeout(() => location.reload(), 2000);
    }, 'Marcando módulos como completados...');
};

// ============================================================
// 16. FUNCIONES DE CONFIGURACIÓN (Ver js/features/config.js)
// ============================================================

window.actualizarMinAprobacionGlobal = async (val) => {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.minAprobacion = parseInt(val) || 70;
    await guardarTodo();
    showToast('Porcentaje de aprobación actualizado.', 'success');
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
                await guardarTodo();
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

// ============================================================
// 17. FUNCIONES DE RENDERIZADO Y REPORTES
// ============================================================

function verificarAccesoLeccion(mIdx, lIdx) {
    if (mIdx === 0 && lIdx === 0) return true;

    const progreso = sesion.progreso[cursoActualData.id];
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

function normalizarCurso(curso) {
    curso.modulos = Array.isArray(curso.modulos) ? curso.modulos.map(mod => ({
        titulo: mod.titulo || 'Módulo sin título',
        enConstruccion: !!mod.enConstruccion,
        maxIntentos: mod.maxIntentos || 0,
        lecciones: Array.isArray(mod.lecciones) ? mod.lecciones : [],
        evaluacion: mod.evaluacion && Array.isArray(mod.evaluacion.preguntas) ? { preguntas: mod.evaluacion.preguntas } : { preguntas: [] }
    })) : [];
    return curso;
}

function renderizarCursoTeachlr(curso) {
    curso = normalizarCurso(curso);
    cursoActualData = curso;

    if (curso.enConstruccion) {
        return `
            <div class="card border-warning bg-warning bg-opacity-10 p-5 text-center my-4 rounded-4 shadow-sm">
                <div class="display-1 text-warning mb-3"><i class="bi bi-cone-striped"></i></div>
                <h2 class="fw-bold text-dark mb-2">Curso en Construcción</h2>
                <p class="text-muted fs-5 mb-4">El curso <strong>"${curso.titulo}"</strong> se encuentra actualmente en desarrollo y afinamiento. ¡Estará disponible para ti muy pronto!</p>
                <div>
                    <a href="index.html" class="btn btn-primary px-4 shadow-sm">
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

    // Encontrar primera lección pendiente para sugerir
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
                    <span class="badge-soft-primary mb-2 align-self-start"><i class="bi bi-mortarboard-fill me-1"></i>Programa Académico</span>
                    <h5 class="fw-bold text-primary mb-2">${curso.titulo}</h5>
                    
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
                                    <span class="badge bg-warning bg-opacity-25 text-dark border border-warning px-2 py-1 d-inline-flex align-items-center" title="${moduloNombre}">
                                        <span class="me-1">🏆</span> <span class="text-truncate" style="max-width: 120px;">M${modIndex + 1}</span>
                                    </span>`;
                            }).join('') : '<small class="text-muted fst-italic">Completa las evaluaciones para desbloquear medallas.</small>'}
                        </div>
                    </div>

                    ${yaTieneCertificado ? `
                        <div class="mt-3 pt-3 border-top">
                            <button class="btn btn-gold w-100 shadow-sm py-2" onclick="descargarCertificado('${sesion.nombre.replace(/'/g, "\\'")}', '${sesion.id}', '${curso.titulo.replace(/'/g, "\\'")}')">
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

                        let estadoEvaluacion = 'pendiente';
                        if (modEnConstruccion) estadoEvaluacion = 'bloqueado';
                        else if (moduloAprobado) estadoEvaluacion = 'aprobado';
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
                                        Módulo ${idx + 1}: ${mod.titulo}
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
                                                        <span class="text-truncate">${lIdx + 1}. ${lec.titulo}</span>
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
                                            'btn-light text-muted disabled'
                                        }"
                                        onclick="${estadoEvaluacion === 'disponible' ? `mostrarEvaluacionModulo('${curso.id}', ${idx})` : (estadoEvaluacion === 'aprobado' ? `mostrarEvaluacionModulo('${curso.id}', ${idx})` : 'return false')}"
                                        ${estadoEvaluacion === 'bloqueado' || (estadoEvaluacion === 'pendiente' && !tieneEvaluacion) ? 'disabled' : ''}>
                                            <i class="bi ${
                                                estadoEvaluacion === 'aprobado' ? 'bi-check-circle-fill text-success' :
                                                estadoEvaluacion === 'disponible' ? 'bi-patch-question-fill' :
                                                'bi-lock-fill'
                                            } me-1"></i>
                                            ${
                                                estadoEvaluacion === 'aprobado' ? 'Evaluación Aprobada ✓' :
                                                estadoEvaluacion === 'disponible' ? 'Realizar Evaluación' :
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

// ============================================================
// FUNCIÓN PARA MOSTRAR DETALLE DEL CURSO
// ============================================================

function mostrarDetalleCurso(cursoId) {
    const curso = cursos.find(c => c.id === cursoId);
    const contenidoCursoDiv = document.getElementById('contenido-curso');

    if (!contenidoCursoDiv) {
        console.error('Elemento contenido-curso no encontrado');
        return;
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
                    Error al cargar el curso: ${error.message}
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

window.descargarCertificado = (nombre, cedula, curso) => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const logo = localStorage.getItem('aluLogo');

    doc.setDrawColor(43, 61, 79);
    doc.setLineWidth(2);
    doc.rect(10, 10, 277, 190);
    doc.setDrawColor(255, 215, 0);
    doc.rect(12, 12, 273, 186);

    if (logo) {
        try { doc.addImage(logo, 'PNG', 20, 20, 40, 40); } catch (e) { console.error("Error al cargar logo", e); }
    }

    doc.setFont("helvetica", "bold");
    doc.setFontSize(30);
    doc.text("UNIVERSIDAD DEL ALUMINIO", 148, 45, { align: "center" });

    doc.setFontSize(20);
    doc.setFont("helvetica", "normal");
    doc.text("Otorga el presente certificado a:", 148, 80, { align: "center" });

    doc.setFontSize(35);
    doc.text(nombre.toUpperCase(), 148, 100, { align: "center" });
    doc.setFontSize(16);
    doc.text(`Cédula de Identidad: ${cedula}`, 148, 110, { align: "center" });

    doc.setFontSize(18);
    doc.text("Por haber cumplido con los requisitos académicos del curso:", 148, 135, { align: "center" });
    doc.setFont("helvetica", "bold");
    doc.text(curso, 148, 150, { align: "center" });

    doc.setFont("helvetica", "italic");
    doc.setFontSize(12);
    doc.text(`Fecha de emisión: ${new Date().toLocaleDateString()}`, 148, 180, { align: "center" });

    doc.save(`Certificado_${curso}_${nombre}.pdf`);
    showToast('Certificado descargado con éxito.', 'success');
};

// ============================================================
// 18. FUNCIONES DE SERVIDOR Y CARGA INICIAL
// ============================================================

async function cargarDatosDelServidor() {
    const loadingScreen = document.getElementById('admin-loading-screen');
    if (loadingScreen) {
        loadingScreen.classList.remove('hidden');
    }

    try {
        const response = await fetch('api.php');
        const data = await response.json();

        if (data && typeof data === 'object' && !Array.isArray(data)) {
            db = data;
        }

        usuarios = db.usuarios || [];
        cursos = db.cursos || [];
        carreras = db.carreras || [];
        rolesConfig = db.rolesConfig || [];
        solicitudesRegistro = db.solicitudesRegistro || [];
        solicitudesCursos = db.solicitudesCursos || [];

        usuarios.forEach(u => {
            u.asignados = Array.isArray(u.asignados) ? u.asignados : [];
            u.carrerasAsignadas = Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [];
            u.certificadosCurso = Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [];
            u.certificadosCarrera = Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : [];

            if (!u.progreso || Array.isArray(u.progreso) || typeof u.progreso !== 'object') {
                u.progreso = {};
            }

            for (let cursoId in u.progreso) {
                if (Array.isArray(u.progreso[cursoId])) {
                    u.progreso[cursoId] = {
                        leccionesCompletadas: u.progreso[cursoId],
                        modulosAprobados: [],
                        medallas: [],
                        evaluaciones: {},
                        intentos: {}
                    };
                } else if (u.progreso[cursoId] && typeof u.progreso[cursoId] === 'object') {
                    u.progreso[cursoId].leccionesCompletadas = Array.isArray(u.progreso[cursoId].leccionesCompletadas) ? u.progreso[cursoId].leccionesCompletadas : [];
                    u.progreso[cursoId].modulosAprobados = Array.isArray(u.progreso[cursoId].modulosAprobados) ? u.progreso[cursoId].modulosAprobados : [];
                    u.progreso[cursoId].medallas = Array.isArray(u.progreso[cursoId].medallas) ? u.progreso[cursoId].medallas : [];
                    u.progreso[cursoId].evaluaciones = (u.progreso[cursoId].evaluaciones && typeof u.progreso[cursoId].evaluaciones === 'object' && !Array.isArray(u.progreso[cursoId].evaluaciones)) ? u.progreso[cursoId].evaluaciones : {};
                    u.progreso[cursoId].intentos = (u.progreso[cursoId].intentos && typeof u.progreso[cursoId].intentos === 'object' && !Array.isArray(u.progreso[cursoId].intentos)) ? u.progreso[cursoId].intentos : {};
                }
            }

            actualizarEstadoCarrerasUsuario(u);
        });

        if (sesion) {
            const usuarioFresco = usuarios.find(u => u.id === sesion.id);
            if (usuarioFresco) {
                sesion = usuarioFresco;
                sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
            }
        }

        if (typeof actualizarTablas === 'function') actualizarTablas();
        if (document.getElementById('cfg-min-aprobacion')) {
            document.getElementById('cfg-min-aprobacion').value = db.configuracion ? (db.configuracion.minAprobacion || 70) : 70;
        }
        renderizarGaleria();
    } catch (err) {
        console.error("Error al conectar con la base de datos del servidor:", err);
    } finally {
        if (loadingScreen) {
            setTimeout(() => {
                loadingScreen.classList.add('hidden');
            }, 200);
        }
    }
}

let filtroGaleriaCategoria = 'todos';

window.setFiltroGaleria = function(cat) {
    filtroGaleriaCategoria = cat;
    document.querySelectorAll('#filtros-categoria .filter-pill-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-filter') === cat);
    });
    renderizarGaleria();
};

window.filtrarGaleriaCursos = function() {
    renderizarGaleria();
};

function obtenerIniciales(nombre) {
    if (!nombre) return 'UA';
    const partes = nombre.trim().split(/\s+/);
    if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
}

function calcularProgresoCurso(cursoId, cursoData) {
    if (!sesion || !sesion.progreso || !sesion.progreso[cursoId]) {
        const totalMod = (cursoData && cursoData.modulos) ? cursoData.modulos.length : 0;
        let totalLec = 0;
        if (cursoData && cursoData.modulos) {
            cursoData.modulos.forEach(m => totalLec += (m.lecciones || []).length);
        }
        return { porcentaje: 0, leccionesCompletadas: 0, totalLecciones: totalLec, modulosCompletados: 0, totalModulos: totalMod, completado: false };
    }
    const prog = sesion.progreso[cursoId];
    const modulos = (cursoData && cursoData.modulos) ? cursoData.modulos : [];
    let totalLecciones = 0;
    modulos.forEach(m => {
        totalLecciones += (m.lecciones || []).length;
    });

    const leccionesComp = Array.isArray(prog.leccionesCompletadas) ? prog.leccionesCompletadas.length : 0;
    const modulosAprob = Array.isArray(prog.modulosAprobados) ? prog.modulosAprobados.length : 0;
    const totalModulos = modulos.length;

    let porcentaje = 0;
    if (totalModulos > 0) {
        porcentaje = Math.round((modulosAprob / totalModulos) * 100);
    } else if (totalLecciones > 0) {
        porcentaje = Math.round((leccionesComp / totalLecciones) * 100);
    }

    const tieneCertificado = Array.isArray(sesion.certificadosCurso) && sesion.certificadosCurso.includes(cursoId);
    const completado = tieneCertificado || porcentaje >= 100;
    if (completado) porcentaje = 100;

    return {
        porcentaje: Math.min(100, porcentaje),
        leccionesCompletadas: leccionesComp,
        totalLecciones,
        modulosCompletados: modulosAprob,
        totalModulos,
        completado
    };
}

function renderizarGaleria() {
    const galeria = document.getElementById('galeria-cursos-row') || document.querySelector('#galeria-cursos .row');
    
    // 1. Actualizar Header y KPIs del Usuario si están en el DOM
    if (sesion) {
        const navAdminLink = document.getElementById('nav-admin-link');
        if (navAdminLink) {
            navAdminLink.style.display = (sesion.rol === 'admin') ? 'block' : 'none';
        }

        const navUserInfo = document.getElementById('nav-user-info');
        if (navUserInfo) {
            navUserInfo.innerHTML = `
                <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">${obtenerIniciales(sesion.nombre)}</div>
                <div class="d-none d-sm-block text-start lh-1">
                    <div class="text-white small fw-bold">${sesion.nombre}</div>
                    <small class="text-white-50" style="font-size: 0.725rem;">${(sesion.rol || 'Estudiante').replace('_', ' ')}</small>
                </div>
            `;
        }

        const greetingTitle = document.getElementById('user-greeting-title');
        if (greetingTitle) {
            greetingTitle.textContent = `¡Hola, ${sesion.nombre}! 👋`;
        }

        const roleBadge = document.getElementById('user-role-label');
        if (roleBadge) {
            roleBadge.textContent = (sesion.rol === 'admin') ? 'Administrador' : `Cargo: ${(sesion.rol || 'Estudiante').replace('_', ' ')}`;
        }
    }

    if (!galeria) return;

    // 2. Determinar Cursos Visibles según Permisos / Carrera / Rol
    let cursosVisibles = [];
    if (sesion && sesion.rol === 'admin') {
        cursosVisibles = cursos.map(c => ({ ...c, bloqueado: false }));
    } else {
        const configRol = sesion ? rolesConfig.find(r => r.id === sesion.rol) : null;
        const directosDelRol = configRol ? configRol.cursos || [] : [];
        const deCarreras = [];
        if (configRol && configRol.carreras) {
            configRol.carreras.forEach(carId => {
                const carrera = carreras.find(c => c.id === carId);
                if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
            });
        }
        if (sesion && Array.isArray(sesion.carrerasAsignadas)) {
            sesion.carrerasAsignadas.forEach(ca => {
                const carrera = carreras.find(c => c.id === ca.id);
                if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
            });
        }
        const porSolicitud = (sesion && sesion.asignados) || [];
        const idsAccesoTotal = [...new Set([...directosDelRol, ...deCarreras, ...porSolicitud])];
        cursosVisibles = cursos.map(c => {
            const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
            const tieneAcceso = esAccesoLibre || idsAccesoTotal.includes(c.id);
            return { ...c, bloqueado: !tieneAcceso };
        });
    }

    // 3. Calcular Métricas para los KPIs
    let countTotal = cursosVisibles.length;
    let countEnProgreso = 0;
    let countCompletados = 0;

    cursosVisibles.forEach(c => {
        const info = calcularProgresoCurso(c.id, c);
        c._progresoInfo = info;
        if (info.completado) {
            countCompletados++;
        } else if (info.porcentaje > 0) {
            countEnProgreso++;
        }
    });

    const elTotal = document.getElementById('kpi-cursos-total');
    if (elTotal) elTotal.textContent = countTotal;
    const elProg = document.getElementById('kpi-cursos-progreso');
    if (elProg) elProg.textContent = countEnProgreso;
    const elComp = document.getElementById('kpi-cursos-completados');
    if (elComp) elComp.textContent = countCompletados;

    // 4. Aplicar Filtros (Búsqueda y Categoría)
    const query = (document.getElementById('input-buscar-cursos')?.value || '').trim().toLowerCase();
    let cursosFiltrados = cursosVisibles.filter(c => {
        // Filtro de texto
        if (query) {
            const matchTitulo = (c.titulo || '').toLowerCase().includes(query);
            const matchId = (c.id || '').toLowerCase().includes(query);
            if (!matchTitulo && !matchId) return false;
        }

        // Filtro de categoría
        const esLibre = c.tipo === 'publico' || c.tipo === 'libre';
        if (filtroGaleriaCategoria === 'progreso') {
            return c._progresoInfo.porcentaje > 0 && !c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'completados') {
            return c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'libre') {
            return esLibre;
        } else if (filtroGaleriaCategoria === 'especializado') {
            return !esLibre;
        }
        return true;
    });

    if (cursosFiltrados.length === 0) {
        galeria.innerHTML = `
            <div class="col-12 text-center py-5">
                <div class="py-4">
                    <i class="bi bi-search display-3 text-muted opacity-50 mb-3 d-block"></i>
                    <h5 class="fw-bold text-secondary">No se encontraron cursos</h5>
                    <p class="text-muted small">Intenta con otro término de búsqueda o cambia la categoría seleccionada.</p>
                </div>
            </div>`;
        return;
    }

    // 5. Renderizar Tarjetas de Cursos
    let galeriaHTML = '';
    cursosFiltrados.forEach(c => {
        let bloqueadoPorPrelacion = false;
        let mensajePrelacion = "";
        if (c.prelacion) {
            const cursoPrevio = cursos.find(cp => cp.id === c.prelacion);
            if (cursoPrevio) {
                const progresoPrevio = sesion && sesion.progreso ? sesion.progreso[c.prelacion] : null;
                const modulosConEval = (cursoPrevio.modulos || []).filter(m => m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0).length;
                const totalModulosPrevio = modulosConEval > 0 ? modulosConEval : (cursoPrevio.modulos || []).length;
                const modulosAprobados = (progresoPrevio && progresoPrevio.modulosAprobados) ? progresoPrevio.modulosAprobados.length : 0;

                if (modulosAprobados < totalModulosPrevio) {
                    bloqueadoPorPrelacion = true;
                    mensajePrelacion = `Requiere: ${cursoPrevio.titulo}`;
                }
            }
        }

        const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
        const prog = c._progresoInfo;
        const totalModulos = (c.modulos || []).length;
        let totalLecciones = 0;
        (c.modulos || []).forEach(m => totalLecciones += (m.lecciones || []).length);

        // Badge de Estado Superior
        let badgeEstado = '';
        if (c.enConstruccion) {
            badgeEstado = `<span class="badge bg-warning text-dark shadow-sm fw-bold"><i class="bi bi-cone-striped me-1"></i>En Construcción</span>`;
        } else if (prog.completado) {
            badgeEstado = `<span class="badge bg-success text-white shadow-sm"><i class="bi bi-award-fill me-1"></i>Completado</span>`;
        } else if (prog.porcentaje > 0) {
            badgeEstado = `<span class="badge bg-primary text-white shadow-sm"><i class="bi bi-clock-history me-1"></i>En Curso ${prog.porcentaje}%</span>`;
        } else if (esAccesoLibre) {
            badgeEstado = `<span class="badge bg-emerald text-white shadow-sm" style="background:#10b981"><i class="bi bi-unlock-fill me-1"></i>Libre</span>`;
        } else {
            badgeEstado = `<span class="badge bg-dark text-white bg-opacity-75 shadow-sm"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>`;
        }

        // Botón de Acción
        let btnAccion = '';
        if (c.enConstruccion) {
            btnAccion = `
                <button class="btn btn-warning text-dark w-100 fw-bold shadow-sm" onclick="showToast('El curso &quot;${(c.titulo || '').replace(/"/g, '&quot;')}&quot; se encuentra actualmente en construcción. ¡Próximamente disponible!', 'warning')" style="font-size:0.875rem;">
                    <i class="bi bi-cone-striped me-1"></i>En Construcción
                </button>`;
        } else if (c.bloqueado || bloqueadoPorPrelacion) {
            btnAccion = `
                <button class="btn btn-outline-secondary w-100" onclick="${bloqueadoPorPrelacion ? "showToast('Debes completar primero: " + mensajePrelacion.replace(/'/g, "\\'") + "', 'warning')" : "solicitarAccesoCurso('" + c.id + "')"}" style="font-size:0.875rem;">
                    <i class="bi ${bloqueadoPorPrelacion ? 'bi-shield-lock-fill' : 'bi-lock-fill'} me-1"></i>
                    ${bloqueadoPorPrelacion ? mensajePrelacion : 'Solicitar Acceso'}
                </button>`;
        } else if (prog.completado) {
            btnAccion = `
                <a href="detalle.html?id=${c.id}" class="btn btn-success w-100" style="font-size:0.875rem;">
                    <i class="bi bi-check2-circle me-1"></i>Repasar / Certificado
                </a>`;
        } else if (prog.porcentaje > 0) {
            btnAccion = `
                <a href="detalle.html?id=${c.id}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-play-circle-fill me-1"></i>Continuar (${prog.porcentaje}%)
                </a>`;
        } else {
            btnAccion = `
                <a href="detalle.html?id=${c.id}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-arrow-right-circle-fill me-1"></i>Comenzar Curso
                </a>`;
        }

        const imagenUrl = c.imagen || 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=600&auto=format&fit=crop&q=80';
        const isLocked = c.bloqueado || bloqueadoPorPrelacion;

        galeriaHTML += `
            <div class="col-sm-6 col-lg-4 mb-4">
                <div class="course-card ${isLocked ? 'opacity-75' : ''}">
                    <div class="course-thumb-container">
                        <img src="${imagenUrl}" alt="${c.titulo}" style="${isLocked ? 'filter: grayscale(0.85);' : ''}">
                        <div class="course-thumb-overlay"></div>
                        <div class="course-badge-floating">${badgeEstado}</div>
                        ${isLocked ? '<div class="position-absolute top-50 start-50 translate-middle text-center"><i class="bi bi-lock-fill display-5 text-white drop-shadow"></i></div>' : ''}
                    </div>
                    
                    <div class="course-body">
                        <div class="course-meta">
                            <span><i class="bi bi-folder2-open me-1"></i>${totalModulos} módulos</span>
                            <span><i class="bi bi-play-btn me-1"></i>${totalLecciones} lecciones</span>
                            ${c.prelacion ? '<span class="badge-soft-warning"><i class="bi bi-diagram-3-fill me-1"></i>Prelación</span>' : ''}
                        </div>

                        <h5 class="course-title" title="${c.titulo}">${c.titulo}</h5>

                        <div class="course-progress-wrapper">
                            <div class="course-progress-label">
                                <span>Progreso general</span>
                                <span>${prog.porcentaje}%</span>
                            </div>
                            <div class="progress-modern mb-3">
                                <div class="progress-bar ${prog.completado ? 'bg-success' : 'bg-primary'}" style="width: ${prog.porcentaje}%;"></div>
                            </div>
                            ${btnAccion}
                        </div>
                    </div>
                </div>
            </div>`;
    });

    galeria.innerHTML = galeriaHTML;
}

const verificarProteccion = () => {
    const path = window.location.pathname;
    if (!sesion && !path.includes('login.html')) {
        window.location.href = 'login.html';
    }
    if (sesion && sesion.rol !== 'admin' && path.includes('admin.html')) {
        window.location.href = 'index.html';
    }
};

// ============================================================
// 19. ACTUALIZACIÓN DE TABLAS Y CONTROL ADMIN
// ============================================================

window.refrescarDatosAdmin = async (btn) => {
    const icon = btn?.querySelector('i');
    if (icon) icon.classList.add('spin-animation');

    try {
        await cargarDatosDelServidor();
        showToast('Datos re-sincronizados con éxito desde el servidor.', 'success');
    } catch (err) {
        console.error('Error al refrescar datos:', err);
        showToast('Error al refrescar datos: ' + err.message, 'danger');
    } finally {
        if (icon) setTimeout(() => icon.classList.remove('spin-animation'), 600);
    }
};

window.filtrarTablaCursosAdmin = () => {
    actualizarTablas();
};

window.filtrarTablaUsuariosAdmin = () => {
    actualizarTablas();
};

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

function actualizarTablas() {
    if (typeof renderListaCursosCarrera === 'function') {
        renderListaCursosCarrera();
    }

    // 1. Actualizar Métricas KPI del Dashboard de Administración
    const elKpiCursos = document.getElementById('admin-kpi-cursos');
    if (elKpiCursos) elKpiCursos.textContent = (cursos || []).length;

    const elKpiUsuarios = document.getElementById('admin-kpi-usuarios');
    if (elKpiUsuarios) elKpiUsuarios.textContent = (usuarios || []).length;

    const totalSolicitudes = (solicitudesRegistro || []).length + (solicitudesCursos || []).length;
    const elKpiSolicitudes = document.getElementById('admin-kpi-solicitudes');
    if (elKpiSolicitudes) elKpiSolicitudes.textContent = totalSolicitudes;

    const badgeSolTotal = document.getElementById('badge-solicitudes-total');
    if (badgeSolTotal) {
        if (totalSolicitudes > 0) {
            badgeSolTotal.textContent = totalSolicitudes;
            badgeSolTotal.style.display = 'inline-block';
        } else {
            badgeSolTotal.style.display = 'none';
        }
    }

    let totalCertificados = 0;
    (usuarios || []).forEach(u => {
        if (Array.isArray(u.certificadosCurso)) totalCertificados += u.certificadosCurso.length;
    });
    const elKpiCertificados = document.getElementById('admin-kpi-certificados');
    if (elKpiCertificados) elKpiCertificados.textContent = totalCertificados;

    // 2. Renderizar Tabla de Cursos con Filtro en Vivo
    const tablaCursosBody = document.getElementById('tabla-cursos-body');
    if (tablaCursosBody) {
        tablaCursosBody.innerHTML = '';
        const searchCursoQuery = (document.getElementById('search-admin-cursos')?.value || '').trim().toLowerCase();

        const cursosFiltrados = (cursos || []).filter(c => {
            if (!searchCursoQuery) return true;
            return (c.titulo || '').toLowerCase().includes(searchCursoQuery) ||
                   (c.id || '').toLowerCase().includes(searchCursoQuery);
        });

        if (cursosFiltrados.length === 0) {
            tablaCursosBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron cursos coincidentes.</td></tr>';
        } else {
            cursosFiltrados.forEach(c => {
                const esLibre = c.tipo === 'publico' || c.tipo === 'libre';
                const badgeTipo = esLibre
                    ? '<span class="badge-soft-success"><i class="bi bi-unlock-fill me-1"></i>Acceso Libre</span>'
                    : '<span class="badge-soft-warning"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>';
                
                const totalModulos = (c.modulos || []).length;
                let totalLecciones = 0;
                (c.modulos || []).forEach(m => totalLecciones += (m.lecciones || []).length);

                let prelacionTexto = '<span class="text-muted small">Ninguna</span>';
                if (c.prelacion) {
                    const cPrev = cursos.find(cp => cp.id === c.prelacion);
                    prelacionTexto = `<span class="badge bg-info bg-opacity-10 text-dark border"><i class="bi bi-diagram-3 me-1"></i>${cPrev ? cPrev.titulo : c.prelacion}</span>`;
                }

                tablaCursosBody.innerHTML += `
                    <tr>
                        <td><span class="code-chip">${c.id}</span></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                ${c.imagen ? `<img src="${typeof resolverSrcImagen === 'function' ? resolverSrcImagen(c.imagen) : c.imagen}" class="rounded border" style="width:48px; height:32px; object-fit:cover;" alt="Portada">` : `<div class="bg-primary bg-opacity-10 text-primary rounded d-flex align-items-center justify-content-center" style="width:48px; height:32px;"><i class="bi bi-journal-bookmark"></i></div>`}
                                <div>
                                    <div class="fw-bold text-dark">${c.titulo}</div>
                                    <div class="mt-1">
                                        ${badgeTipo}
                                        ${c.enConstruccion ? '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-cone-striped me-1"></i>En Construcción</span>' : ''}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border me-1"><i class="bi bi-folder2-open me-1 text-primary"></i>${totalModulos} mód.</span>
                            <span class="badge bg-light text-dark border"><i class="bi bi-play-btn me-1 text-primary"></i>${totalLecciones} lecc.</span>
                        </td>
                        <td>${prelacionTexto}</td>
                        <td class="text-end">
                            <div class="btn-action-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="abrirEditor('${c.id}')" title="Editar contenido y temario">
                                    <i class="bi bi-pencil-square me-1"></i>Editar
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarCurso('${c.id}')" title="Eliminar curso">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });
        }
    }

    // 3. Renderizar Tabla de Usuarios con Filtro en Vivo y Avatares
    const userTable = document.getElementById('tabla-usuarios-body');
    if (userTable) {
        userTable.innerHTML = '';
        const searchUserQuery = (document.getElementById('search-admin-usuarios')?.value || '').trim().toLowerCase();

        const usuariosFiltrados = (usuarios || []).filter(u => {
            if (!searchUserQuery) return true;
            return (u.nombre || '').toLowerCase().includes(searchUserQuery) ||
                   (u.id || '').toLowerCase().includes(searchUserQuery) ||
                   (u.rol || '').toLowerCase().includes(searchUserQuery);
        });

        if (usuariosFiltrados.length === 0) {
            userTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron colaboradores coincidentes.</td></tr>';
        } else {
            usuariosFiltrados.forEach(u => {
                const assignedCareersNames = (u.carrerasAsignadas || []).map(ca => {
                    const car = carreras.find(c => c.id === ca.id);
                    const carNombre = car ? car.nombre : (ca.id || 'Desconocida');
                    const isComplete = ca.estado === 'Completada';
                    return `<span class="badge ${isComplete ? 'bg-success' : 'bg-secondary bg-opacity-25 text-dark border'} me-1 mb-1">${carNombre} (${ca.estado || 'Incompleta'})</span>`;
                }).join(' ');

                const certCount = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0;
                const badgeStatus = (u.estado === 'suspendido')
                    ? '<span class="badge-soft-danger"><i class="bi bi-slash-circle me-1"></i>Suspendido</span>'
                    : '<span class="badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>';

                userTable.innerHTML += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar" style="width: 40px; height: 40px; font-size: 0.9rem;">
                                    ${obtenerIniciales(u.nombre)}
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">${u.nombre}</div>
                                    ${certCount > 0 ? `<small class="text-success fw-semibold"><i class="bi bi-award-fill me-1"></i>${certCount} certificado${certCount !== 1 ? 's' : ''}</small>` : ''}
                                </div>
                            </div>
                        </td>
                        <td><span class="code-chip">${u.id}</span></td>
                        <td>
                            <span class="badge bg-light text-dark border mb-1">${(u.rol || 'Sin Rol').replace('_', ' ')}</span>
                            <div>${assignedCareersNames || '<small class="text-muted fst-italic">Sin carreras asociadas</small>'}</div>
                        </td>
                        <td>${badgeStatus}</td>
                        <td class="text-end">
                            <div class="btn-action-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorUsuario('${u.id}')" title="Editar datos del usuario">
                                    <i class="bi bi-person-gear me-1"></i>Editar
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="abrirRestablecerAvance('${u.id}')" title="Restablecer progreso">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="abrirMarcarCompletado('${u.id}')" title="Marcar módulos completados">
                                    <i class="bi bi-check2-circle"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarUsuario('${u.id}')" title="Eliminar usuario">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });
        }
    }

    // 4. Renderizar Roles & Permisos
    const rolesTable = document.getElementById('tabla-roles-body');
    if (rolesTable) {
        rolesTable.innerHTML = '';
        rolesConfig.filter(r => r.id !== 'admin').forEach(r => {
            const totalC = (r.cursos || []).length;
            const totalCar = (r.carreras || []).length;
            rolesTable.innerHTML += `
                <tr>
                    <td><span class="code-chip">${r.id}</span></td>
                    <td><strong class="text-dark">${r.nombre}</strong></td>
                    <td>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 me-1"><i class="bi bi-journal me-1"></i>${totalC} Cursos</span>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="bi bi-diagram-3 me-1"></i>${totalCar} Carreras</span>
                    </td>
                    <td class="text-end">
                        <div class="btn-action-group">
                            <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorRol('${r.id}')">
                                <i class="bi bi-sliders me-1"></i>Configurar Carga
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarRol('${r.id}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });
    }

    // 5. Renderizar Solicitudes de Registro
    const reqRegTable = document.getElementById('tabla-solicitudes-registro');
    if (reqRegTable) {
        reqRegTable.innerHTML = (solicitudesRegistro || []).map(s => `
            <tr>
                <td><span class="code-chip">${s.id}</span></td>
                <td><strong class="text-dark">${s.nombre}</strong></td>
                <td><span class="badge bg-light text-dark border">${s.perfilDeseado}</span></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudRegistro('${s.id}', true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudRegistro('${s.id}', false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de registro pendientes.</td></tr>';
    }

    // 6. Renderizar Solicitudes de Cursos
    const reqCurTable = document.getElementById('tabla-solicitudes-cursos');
    if (reqCurTable) {
        reqCurTable.innerHTML = (solicitudesCursos || []).map(s => `
            <tr>
                <td><strong class="text-dark">${s.userName || s.userId}</strong></td>
                <td><span class="badge-soft-primary">${s.cursoId}</span></td>
                <td><small class="text-muted">${s.fecha || 'Reciente'}</small></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de acceso a cursos.</td></tr>';
    }

    // 7. Renderizar Carreras
    const careerTable = document.getElementById('tabla-carreras-body');
    if (careerTable) {
        careerTable.innerHTML = '';
        (carreras || []).forEach(car => {
            careerTable.innerHTML += `
                <tr>
                    <td><strong class="text-dark">${car.nombre}</strong></td>
                    <td>
                        <span class="badge bg-light text-dark border me-2">${(car.cursos || []).length} cursos asignados</span>
                        <button class="btn btn-xs btn-outline-secondary" onclick="duplicarCarrera('${car.id}')" title="Duplicar">
                            <i class="bi bi-copy me-1"></i>Duplicar
                        </button>
                    </td>
                    <td class="text-end">
                        <div class="btn-action-group">
                            <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorCarrera('${car.id}')">
                                <i class="bi bi-pencil-square me-1"></i>Editar
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarCarrera('${car.id}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });
    }

}

document.addEventListener('DOMContentLoaded', async () => {
    await cargarDatosDelServidor();

    // Aplicar configuración guardada (colores, nombre, etc.)
    if (typeof cargarConfiguracion === 'function') {
        cargarConfiguracion();
    }

    verificarProteccion();

    const urlParams = new URLSearchParams(window.location.search);
    const cursoId = urlParams.get('id');
    if (document.getElementById('contenido-curso') && cursoId) {
        mostrarDetalleCurso(cursoId);
    }

    actualizarTablas();

    const tabReportes = document.querySelector('button[data-bs-target="#tab-reportes"]');
    if (tabReportes) {
        tabReportes.addEventListener('shown.bs.tab', () => {
            if (typeof renderRobustReports === 'function') {
                renderRobustReports();
            }
        });
    }

    // Registrar Service Worker (PWA)
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').then(reg => {
            reg.update();
        }).catch(err => {
            console.warn('SW no registrado:', err);
        });
    }
});

// ============================================================
// REPORTES — Cambio de Vista
// ============================================================
window.cambiarVistaReporte = (vista) => {
    const vistaEval     = document.getElementById('vista-evaluaciones');
    const vistaLearners = document.getElementById('vista-learners');
    const vistaBrechas  = document.getElementById('vista-brechas');
    const btnE = document.getElementById('btn-vista-evaluaciones');
    const btnL = document.getElementById('btn-vista-learners');
    const btnB = document.getElementById('btn-vista-brechas');

    if (vista === 'evaluaciones') {
        if (vistaEval)     vistaEval.style.display     = '';
        if (vistaLearners) vistaLearners.style.display = 'none';
        if (vistaBrechas)  vistaBrechas.style.display  = 'none';
        if (btnE) btnE.className = 'btn btn-sm btn-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-outline-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-outline-primary';
        if (typeof renderReporteEvaluaciones === 'function') renderReporteEvaluaciones();
    } else if (vista === 'learners') {
        if (vistaEval)     vistaEval.style.display     = 'none';
        if (vistaLearners) vistaLearners.style.display = '';
        if (vistaBrechas)  vistaBrechas.style.display  = 'none';
        if (btnE) btnE.className = 'btn btn-sm btn-outline-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-outline-primary';
        if (typeof renderTopLearners === 'function') renderTopLearners();
        if (typeof renderCumplimientoCargo === 'function') renderCumplimientoCargo();
    } else {
        if (vistaEval)     vistaEval.style.display     = 'none';
        if (vistaLearners) vistaLearners.style.display = 'none';
        if (vistaBrechas)  vistaBrechas.style.display  = '';
        if (btnE) btnE.className = 'btn btn-sm btn-outline-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-outline-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-primary';
        if (typeof renderBrechasAprendizaje === 'function') {
            if (typeof inicializarFiltroUsuariosBrechas === 'function') inicializarFiltroUsuariosBrechas();
            renderBrechasAprendizaje();
        }
    }
};
