/**
 * js/utils.js
 * Funciones de utilidad general para la plataforma
 */

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
    return {
        id: String(u.id || "").trim(),
        nombre: String(u.nombre || "").trim(),
        clave: String(u.clave || "12345"),
        rol: String(u.rol || "participante"),
        estado: String(u.estado || "activo"),
        asignados: Array.isArray(u.asignados) ? u.asignados : [],
        carrerasAsignadas: Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [],
        progreso: (u.progreso && typeof u.progreso === 'object' && !Array.isArray(u.progreso)) ? u.progreso : {},
        certificadosCurso: Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [],
        certificadosCarrera: Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : []
    };
}

function normalizar(texto) {
    return texto ? texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : "";
}

function extraerID(input) {
    if (!input) return "";
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
    const match = String(input).match(regExp);
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

window.getCareerIdFromRole = getCareerIdFromRole;
window.crearEstructuraUsuario = crearEstructuraUsuario;
window.normalizar = normalizar;
window.extraerID = extraerID;
window.comprimirImagenBase64 = comprimirImagenBase64;
