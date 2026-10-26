/**
 * js/core/state.js — Universidad del Aluminio
 * Estado central de la aplicación (window.App) y utilidades compartidas.
 */

(function () {
    'use strict';

    // ------------------------------------------------------------
    // ESTADO CENTRAL (window.App)
    // ------------------------------------------------------------
    // Todo el estado vive en App.estado. Las variables globales históricas (usuarios,
    // cursos, db, sesion, tempModulos...) siguen funcionando: son accesos (get/set) a
    // App.estado, de modo que el código existente puede migrarse poco a poco.
    //
    //   App.get('usuarios')              leer
    //   App.set('usuarios', lista)       reemplazar y avisar a los suscriptores
    //   App.on('datos', fn)              tras cada carga desde el servidor
    //   App.on('cambio:usuarios', fn)    tras App.set('usuarios', ...)
    //   App.buscarUsuario(id)            búsqueda por id (string)
    //   App.asegurarProgreso([ids])      en el panel admin, carga bajo demanda el progreso

    const COLECCIONES = ['usuarios', 'cursos', 'carreras', 'rolesConfig', 'solicitudesRegistro', 'solicitudesCursos'];
    const CONFIG_POR_DEFECTO = { nombreInstitucion: 'Universidad del Aluminio', logo: '', minAprobacion: 70 };

    function leerSesionGuardada() {
        try {
            return JSON.parse(sessionStorage.getItem('aluSesion')) || null;
        } catch (_) {
            return null;
        }
    }

    const estado = {
        usuarios: [],
        cursos: [],
        carreras: [],
        rolesConfig: [],
        solicitudesRegistro: [],
        solicitudesCursos: [],
        configuracion: { ...CONFIG_POR_DEFECTO },
        sesion: leerSesionGuardada(),
        // false cuando el servidor envió los usuarios sin progreso (panel admin)
        progresoCompleto: true,
        // Editor de cursos (admin)
        tempModulos: [],
        tempModuloEvaluacion: { preguntas: [] },
        tempImagenPortada: '',
        // Aula virtual
        cursoActualData: null,
        moduloActualIdx: 0,
        leccionActualIdx: 0,
    };

    const oyentes = new Map();

    function emitir(evento, detalle) {
        (oyentes.get(evento) || []).slice().forEach(fn => {
            try {
                fn(detalle);
            } catch (err) {
                console.error(`[App] Error en suscriptor de "${evento}":`, err);
            }
        });
    }

    const App = {
        estado,

        get(clave) {
            return estado[clave];
        },

        set(clave, valor) {
            if (COLECCIONES.includes(clave) && !Array.isArray(valor)) valor = [];
            estado[clave] = valor;
            emitir('cambio:' + clave, valor);
            return valor;
        },

        on(evento, fn) {
            if (!oyentes.has(evento)) oyentes.set(evento, []);
            oyentes.get(evento).push(fn);
            return () => App.off(evento, fn);
        },

        off(evento, fn) {
            const lista = oyentes.get(evento);
            if (lista) oyentes.set(evento, lista.filter(f => f !== fn));
        },

        emitir,

        /** Reemplaza los datos con la respuesta del servidor y emite 'datos' (salvo silencioso). */
        cargarDatos(data, { silencioso = false } = {}) {
            if (!data || typeof data !== 'object' || Array.isArray(data)) return;
            COLECCIONES.forEach(clave => {
                if (clave in data) estado[clave] = Array.isArray(data[clave]) ? data[clave] : [];
            });
            if (data.configuracion && typeof data.configuracion === 'object') {
                estado.configuracion = { ...CONFIG_POR_DEFECTO, ...data.configuracion };
            }
            progresoBajoDemanda = data.progresoIncluido === false;
            estado.progresoCompleto = !progresoBajoDemanda;
            conProgreso.clear();
            if (!silencioso) emitir('datos', estado);
        },

        /** Vista de compatibilidad con el objeto `db` histórico (mismas referencias). */
        comoDB() {
            const db = {};
            COLECCIONES.forEach(clave => { db[clave] = estado[clave]; });
            db.configuracion = estado.configuracion;
            return db;
        },

        buscarUsuario(id) {
            const idStr = String(id);
            return estado.usuarios.find(u => String(u.id) === idStr) || null;
        },

        /**
         * Garantiza que los usuarios tengan su progreso cargado. El panel admin recibe los
         * usuarios sin progreso para no descargar el de todos al entrar; las vistas que lo
         * necesitan (reportes, restablecer, marcar completado, respaldo) llaman aquí antes.
         * Sin ids, carga el de todos. Devuelve una promesa.
         */
        asegurarProgreso(ids) {
            if (estado.progresoCompleto) return Promise.resolve();
            const pendientes = ids
                ? [...new Set([].concat(ids).map(String))].filter(id => !conProgreso.has(id) && App.buscarUsuario(id))
                : null;
            if (pendientes && pendientes.length === 0) return Promise.resolve();
            if (!pendientes && cargaProgresoTodos) return cargaProgresoTodos;

            const carga = window.API.cargarProgresoUsuarios(pendientes).then(r => {
                const mapa = (r && r.progreso) || {};
                const objetivo = pendientes || estado.usuarios.map(u => String(u.id));
                objetivo.forEach(id => {
                    const u = App.buscarUsuario(id);
                    if (!u) return;
                    const p = mapa[id];
                    u.progreso = p && typeof p === 'object' && !Array.isArray(p) ? p : {};
                    conProgreso.add(id);
                });
                if (!pendientes) estado.progresoCompleto = true;
                emitir('progreso', objetivo);
            });
            if (!pendientes) {
                cargaProgresoTodos = carga.finally(() => { cargaProgresoTodos = null; });
                return cargaProgresoTodos;
            }
            return carga;
        },

        /** Marca el progreso como desactualizado tras cambiarlo en el servidor (solo panel admin). */
        invalidarProgreso(ids) {
            if (!progresoBajoDemanda) return;
            if (ids) [].concat(ids).forEach(id => conProgreso.delete(String(id)));
            else conProgreso.clear();
            estado.progresoCompleto = false;
        },
    };
    // Panel admin: los usuarios llegan sin progreso y se completa bajo demanda
    let progresoBajoDemanda = false;
    const conProgreso = new Set();
    let cargaProgresoTodos = null;

    // Accesos globales de compatibilidad: window.usuarios, usuarios = [...], etc.
    const GLOBALES = [...COLECCIONES, 'sesion', 'tempModulos', 'tempModuloEvaluacion', 'tempImagenPortada',
        'cursoActualData', 'moduloActualIdx', 'leccionActualIdx'];
    GLOBALES.forEach(clave => {
        Object.defineProperty(window, clave, {
            configurable: true,
            enumerable: true,
            get: () => estado[clave],
            set: (valor) => App.set(clave, valor),
        });
    });
    Object.defineProperty(window, 'db', {
        configurable: true,
        enumerable: true,
        get: () => App.comoDB(),
        set: (valor) => App.cargarDatos(valor),
    });

    window.App = App;

    // ------------------------------------------------------------
    // UTILIDADES DE BOTÓN Y LOADING
    // ------------------------------------------------------------

    window.handleButtonLoading = function (btn, loading, textLoading = 'Procesando...', textOriginal = null) {
        if (typeof btn === 'string') btn = document.querySelector(btn);
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
        } else {
            btn.disabled = false;
            if (textOriginal) {
                btn.innerHTML = textOriginal;
            } else if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
        }
        return btn;
    };

    window.withLoading = async function (btnElement, asyncFn, textLoading = 'Procesando...') {
        try {
            if (btnElement) window.handleButtonLoading(btnElement, true, textLoading);
            return await asyncFn();
        } finally {
            if (btnElement) window.handleButtonLoading(btnElement, false);
        }
    };

    // ------------------------------------------------------------
    // MODELOS Y MAPEO DE CARRERAS
    // ------------------------------------------------------------

    window.getCareerIdFromRole = function (roleId) {
        const careerMap = {
            "asesor_ventas": "CAR-ASESOR-VENTAS",
            "retail": "CAR-RETAIL",
            "almacenista_retail": "CAR-ALMACENISTA",
            "coordinador_retail": "CAR-COORD-RETAIL",
            "cristalero": "CAR-CRISTALERO"
        };
        return careerMap[roleId] || null;
    };

    window.crearEstructuraUsuario = function (u) {
        if (!u) return null;
        const res = {
            id: String(u.id || "").trim(),
            nombre: String(u.nombre || "").trim(),
            rol: String(u.rol || "participante"),
            estado: String(u.estado || "activo"),
            telefono: String(u.telefono || "").trim(),
            email: String(u.email || "").trim(),
            fecha_nacimiento: String(u.fecha_nacimiento || u.fechaNacimiento || "").trim(),
            asignados: Array.isArray(u.asignados) ? u.asignados : [],
            carrerasAsignadas: Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [],
            certificadosCurso: Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [],
            certificadosCarrera: Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : [],
            progreso: (u.progreso && typeof u.progreso === 'object' && !Array.isArray(u.progreso)) ? u.progreso : {}
        };
        if (u.clave) {
            res.clave = String(u.clave).trim();
        }
        return res;
    };

    window.actualizarEstadoCarrerasUsuario = function (usuario) {
        if (!usuario) return;
        usuario.carrerasAsignadas = Array.isArray(usuario.carrerasAsignadas) ? usuario.carrerasAsignadas : [];
        usuario.certificadosCarrera = Array.isArray(usuario.certificadosCarrera) ? usuario.certificadosCarrera : [];
        usuario.certificadosCurso = Array.isArray(usuario.certificadosCurso) ? usuario.certificadosCurso : [];
        if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
            usuario.progreso = {};
        }

        const userRoleConfig = (window.rolesConfig || []).find(r => r.id === usuario.rol);
        const roleCareerIds = (userRoleConfig && Array.isArray(userRoleConfig.carreras)) ? userRoleConfig.carreras : [];
        const autoCareerId = window.getCareerIdFromRole(usuario.rol);
        const todasCarrerasIds = new Set([...roleCareerIds]);
        if (autoCareerId) todasCarrerasIds.add(autoCareerId);

        todasCarrerasIds.forEach(carId => {
            if ((window.carreras || []).some(c => c.id === carId)) {
                if (!usuario.carrerasAsignadas.some(ca => ca.id === carId)) {
                    usuario.carrerasAsignadas.push({ id: carId, estado: "Incompleta" });
                }
            }
        });

        usuario.carrerasAsignadas.forEach(ca => {
            const carrera = (window.carreras || []).find(c => c.id === ca.id);
            if (!carrera || !carrera.cursos || carrera.cursos.length === 0) return;

            const todosCursosCompletados = carrera.cursos.every(cId => {
                if (usuario.certificadosCurso && usuario.certificadosCurso.includes(cId)) return true;
                const cursoObj = (window.cursos || []).find(c => c.id === cId);
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
    };

    window.normalizar = function (texto) {
        return texto ? texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : "";
    };

    /**
     * Escapa texto para insertarlo de forma segura en HTML (contenido o atributos).
     * Usar SIEMPRE que se interpolen datos de usuario/BD en plantillas innerHTML.
     */
    const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    window.escapeHtml = function (str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"']/g, ch => HTML_ESCAPES[ch]);
    };
    // Alias histórico usado por los módulos de administración
    window.escapeAttr = window.escapeHtml;

    /**
     * Retrasa la ejecución de fn hasta que pasen `wait` ms sin nuevas llamadas.
     * Útil para filtros en vivo (oninput) sobre listas grandes.
     */
    window.debounce = function (fn, wait = 200) {
        let timer = null;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), wait);
        };
    };

    window.extraerID = function (input) {
        if (!input) return "";
        const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
        const match = String(input).match(regExp);
        return (match && match[2].length === 11) ? match[2] : input;
    };

    window.comprimirImagenBase64 = function (file, maxWidth = 1000, maxQuality = 0.82) {
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
    };

    window.obtenerIniciales = function (nombre) {
        if (!nombre) return "AL";
        const parts = nombre.trim().split(/\s+/);
        if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    };

    // ------------------------------------------------------------
    // GUARDAR PROGRESO ATÓMICO DEL USUARIO EN CURSO
    // ------------------------------------------------------------

    window.guardarProgresoUsuario = async function () {
        if (!window.sesion || !window.cursoActualData) return false;
        window.actualizarEstadoCarrerasUsuario(window.sesion);
        sessionStorage.setItem('aluSesion', JSON.stringify(window.sesion));

        const cursoId = window.cursoActualData.id;
        const prog = window.sesion.progreso?.[cursoId] || {};

        try {
            await window.API.guardarProgreso({
                usuario_id:            window.sesion.id,
                curso_id:              cursoId,
                leccionesCompletadas:  prog.leccionesCompletadas  || [],
                modulosAprobados:      prog.modulosAprobados      || [],
                medallas:              prog.medallas              || [],
                evaluaciones:          prog.evaluaciones          || {},
                intentos:              prog.intentos              || {},
                certificadosCurso:     window.sesion.certificadosCurso || []
            });
            return true;
        } catch (err) {
            console.error('Error al guardar_progreso:', err.message);
            return false;
        }
    };

    // ------------------------------------------------------------
    // CARGA INICIAL DESDE EL SERVIDOR
    // ------------------------------------------------------------

    window.cargarDatosDelServidor = async function () {
        const loadingScreen = document.getElementById('admin-loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.remove('hidden');
        }

        try {
            const isAdminPage = window.location.pathname.includes('admin.php') || window.location.pathname.includes('admin.html');
            let data;
            if (isAdminPage) {
                // Sin progreso: cada vista lo pide con App.asegurarProgreso() cuando lo necesita
                data = await window.API.cargarDB({ ligero: true });
            } else {
                try {
                    data = await window.API.cargarCatalogo();
                } catch (catErr) {
                    console.warn('Fallback a cargarDB por error en catalogo:', catErr.message);
                    data = await window.API.cargarDB();
                }
            }

            App.cargarDatos(data, { silencioso: true });

            // Sincronizar la sesión antes de avisar a los suscriptores. Sin progreso (panel
            // admin) no se recalculan las carreras: se marcarían incompletas por error.
            const sesionActual = App.get('sesion');
            const updatedUser = sesionActual ? App.buscarUsuario(sesionActual.id) : null;
            if (updatedUser && App.get('progresoCompleto')) {
                window.actualizarEstadoCarrerasUsuario(updatedUser);
                window.sesion = JSON.parse(JSON.stringify(updatedUser));
                sessionStorage.setItem('aluSesion', JSON.stringify(window.sesion));
            }

            App.emitir('datos', App.estado);

            return true;
        } catch (err) {
            console.error('Error al sincronizar datos:', err);
            return false;
        } finally {
            if (loadingScreen) {
                loadingScreen.classList.add('hidden');
            }
        }
    };
})();
