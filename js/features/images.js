/**
 * js/features/images.js
 * Gestión centralizada de subida de imágenes al servidor (PHP GD).
 * Reemplaza el almacenamiento de imágenes como base64 en la BD.
 *
 * Compatibilidad retroactiva: si el valor de una imagen ya es base64 o
 * una URL externa, se usa directamente como src sin re-subir.
 */

/**
 * Sube un archivo de imagen al servidor y devuelve la URL relativa.
 * @param {File}   file     - Archivo de imagen seleccionado por el usuario
 * @param {string} type     - 'logo' | 'portada'
 * @param {string} id       - ID de la entidad (curso_id para portadas, 'inst' para logo)
 * @param {string} prevUrl  - URL anterior para que el servidor la elimine (opcional)
 * @returns {Promise<string>} URL relativa del archivo guardado (ej: "uploads/portada_abc_1234.jpg")
 */
async function subirImagenServidor(file, type = 'portada', id = 'gen', prevUrl = '') {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);
    formData.append('id', String(id).replace(/[^a-zA-Z0-9_-]/g, '').substring(0, 40));
    if (prevUrl && prevUrl.startsWith('uploads/')) {
        formData.append('prev', prevUrl);
    }

    const res = await fetch('api.php?action=upload_image', {
        method: 'POST',
        body: formData   // NO poner Content-Type: multipart se establece automáticamente
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Error HTTP ${res.status} al subir imagen`);
    return data.url; // "uploads/portada_xxx_timestamp.jpg"
}

/**
 * Resuelve la src correcta para una imagen guardada.
 * Si ya es base64 o una URL http, la devuelve tal cual.
 * Si es una ruta relativa de uploads, la devuelve también tal cual
 * (el navegador la resolverá relativa a la página actual).
 * @param {string} src
 * @returns {string}
 */
function resolverSrcImagen(src) {
    if (!src) return '';
    // base64, URLs http, blob:, data:
    if (src.startsWith('data:') || src.startsWith('http') || src.startsWith('blob:')) return src;
    // Ruta relativa del servidor
    return src;
}

/**
 * Genera una miniatura base64 de baja resolución para usar como placeholder
 * mientras carga la imagen real del servidor.
 * @param {string} url - URL relativa de la imagen en uploads/
 * @returns {string} Placeholder SVG inline
 */
function placeholderImagen(texto = '') {
    const initials = texto ? texto.substring(0, 2).toUpperCase() : '?';
    return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='400' height='225' viewBox='0 0 400 225'><rect fill='%230f2b48' width='400' height='225'/><text fill='%23ffffff' font-family='sans-serif' font-size='48' font-weight='bold' text-anchor='middle' dominant-baseline='middle' x='200' y='112'>${initials}</text></svg>`)}`;
}

// Exportar al scope global
window.subirImagenServidor  = subirImagenServidor;
window.resolverSrcImagen    = resolverSrcImagen;
window.placeholderImagen    = placeholderImagen;
