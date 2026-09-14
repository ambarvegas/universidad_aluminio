/**
 * Universidad del Aluminio — Diploma & Credentials Module
 * Generador institucional de certificados (Cursos) y diplomas (Carreras) en PDF con QR y hash criptográfico.
 */

async function generarCodigoCertificadoAsync(userId, itemId, tipo = 'curso') {
    tipo = String(tipo || 'curso').toLowerCase().trim();
    const raw = `ALU_CERT_SECRET_SALT_2026_${tipo}_${String(userId).trim()}_${String(itemId).trim()}`;
    try {
        if (window.crypto && window.crypto.subtle) {
            const msgBuffer = new TextEncoder().encode(raw);
            const hashBuffer = await window.crypto.subtle.digest('SHA-256', msgBuffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('').toUpperCase();
            const prefix = (tipo === 'carrera') ? 'ALU-CAR' : 'ALU-CUR';
            return `${prefix}-${hashHex.substring(0, 10)}`;
        }
    } catch (e) {
        console.warn("Error en crypto.subtle, usando hash fallback:", e);
    }
    // Fallback matemático simple si crypto no estuviese disponible
    let h = 0;
    for (let i = 0; i < raw.length; i++) {
        h = ((h << 5) - h) + raw.charCodeAt(i);
        h |= 0;
    }
    const prefix = (tipo === 'carrera') ? 'ALU-CAR' : 'ALU-CUR';
    return `${prefix}-${Math.abs(h).toString(16).toUpperCase().padStart(10, '0').slice(0, 10)}`;
}
window.generarCodigoCertificadoAsync = generarCodigoCertificadoAsync;

async function generarPDFDocumentoAcademico({
    tipo = 'curso',
    nombre = '',
    cedula = '',
    tituloPrograma = '',
    itemId = '',
    codigoVerificacion = null,
    fechaEmision = null
}) {
    if (!window.jspdf || !window.jspdf.jsPDF) {
        if (typeof showToast === 'function') showToast('Cargando librería PDF, por favor intenta en unos momentos...', 'warning');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

    const pageWidth = 297;
    const pageHeight = 210;
    const centerX = pageWidth / 2;

    // 1. Fondo marfil pulido
    doc.setFillColor(254, 254, 255);
    doc.rect(0, 0, pageWidth, pageHeight, 'F');

    // Marco exterior grueso: Azul Noche (#0f2b48)
    doc.setDrawColor(15, 43, 72);
    doc.setLineWidth(3);
    doc.rect(10, 10, 277, 190);

    // Marco interior fino: Oro Imperial (#d4af37)
    doc.setDrawColor(212, 175, 55);
    doc.setLineWidth(1.2);
    doc.rect(13, 13, 271, 184);

    // Esquinas ornamentales en oro
    const corners = [
        [15, 15], [pageWidth - 15, 15],
        [15, pageHeight - 15], [pageWidth - 15, pageHeight - 15]
    ];
    doc.setDrawColor(212, 175, 55);
    doc.setLineWidth(0.6);
    corners.forEach(([cx, cy]) => {
        doc.circle(cx, cy, 3, 'S');
    });

    // 2. Logotipo Institucional
    let logoData = (typeof db !== 'undefined' && db.configuracion && db.configuracion.logo) ? db.configuracion.logo : '';
    if (!logoData || !logoData.startsWith('data:image')) {
        logoData = localStorage.getItem('aluLogo') || '';
    }
    if (logoData && logoData.startsWith('data:image')) {
        try {
            doc.addImage(logoData, 'PNG', 20, 18, 30, 30);
        } catch (e) {
            console.warn("Logo en formato no compatible con jsPDF:", e);
        }
    }

    // 3. Encabezado Institucional
    doc.setTextColor(15, 43, 72);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(26);
    doc.text("UNIVERSIDAD DEL ALUMINIO", centerX, 30, { align: "center" });

    doc.setFontSize(9.5);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(100, 116, 139);
    doc.text("CAMPUS DE FORMACIÓN TÉCNICA E INNOVACIÓN INDUSTRIAL", centerX, 36.5, { align: "center" });

    // Filete divisorio central en oro
    doc.setDrawColor(212, 175, 55);
    doc.setLineWidth(0.8);
    doc.line(centerX - 60, 41, centerX + 60, 41);

    // 4. Tipo de Diploma / Certificado
    const esCarrera = (tipo === 'carrera');
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    if (esCarrera) {
        doc.setTextColor(180, 130, 20); // Oro rico
        doc.text("DIPLOMA DE GRADUACIÓN PROFESIONAL", centerX, 53, { align: "center" });
    } else {
        doc.setTextColor(2, 132, 199); // Cobalto institucional
        doc.text("CERTIFICACIÓN DE COMPETENCIA TÉCNICA", centerX, 53, { align: "center" });
    }

    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.setTextColor(71, 85, 105);
    doc.text("Otorga el presente reconocimiento oficial a:", centerX, 66, { align: "center" });

    // 5. Nombre del Alumno
    doc.setFont("helvetica", "bold");
    doc.setFontSize(24);
    doc.setTextColor(15, 43, 72);
    doc.text((nombre || 'COLABORADOR').toUpperCase(), centerX, 81, { align: "center" });

    // Cédula
    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.setTextColor(100, 116, 139);
    doc.text(`Documento de Identidad: ${cedula || 'N/A'}`, centerX, 90, { align: "center" });

    // 6. Texto de Concesión y Programa
    doc.setFontSize(11);
    doc.setTextColor(51, 65, 85);
    const textoAcreditacion = esCarrera 
        ? "Por haber culminado con distinción la totalidad del plan curricular de la Carrera Profesional de:"
        : "Por haber cursado y aprobado satisfactoriamente todas las lecciones y evaluaciones técnicas del Curso:";
    doc.text(textoAcreditacion, centerX, 108, { align: "center" });

    doc.setFont("helvetica", "bold");
    doc.setFontSize(18);
    if (esCarrera) {
        doc.setTextColor(180, 130, 20);
    } else {
        doc.setTextColor(15, 43, 72);
    }
    doc.text(`« ${tituloPrograma} »`, centerX, 122, { align: "center" });

    // 7. Código de Verificación Único
    let codigo = codigoVerificacion;
    if (!codigo) {
        codigo = await generarCodigoCertificadoAsync(cedula, itemId || tituloPrograma, tipo);
    }

    const fechaTxt = fechaEmision || new Date().toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });

    // 8. Código QR de Verificación
    const host = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const verifyUrl = `${host}${path}/verificar.php?codigo=${codigo}`;

    if (window.QRious) {
        try {
            const qrCanvas = document.createElement('canvas');
            const qr = new window.QRious({
                element: qrCanvas,
                value: verifyUrl,
                size: 160,
                level: 'M'
            });
            const qrData = qr.toDataURL('image/png');
            doc.addImage(qrData, 'PNG', 24, 138, 28, 28);
        } catch (e) {
            console.warn("Error generando código QR con QRious:", e);
        }
    }

    doc.setFontSize(7.5);
    doc.setFont("helvetica", "normal");
    doc.setTextColor(100, 116, 139);
    doc.text("Escanear para verificar validez", 38, 171, { align: "center" });
    doc.setFont("courier", "bold");
    doc.setFontSize(8.5);
    doc.setTextColor(15, 43, 72);
    doc.text(codigo, 38, 176, { align: "center" });

    // 9. Sello institucional de Rectoría al centro
    doc.setDrawColor(212, 175, 55);
    doc.setLineWidth(1.4);
    doc.circle(centerX, 153, 12, 'S');
    doc.setLineWidth(0.5);
    doc.circle(centerX, 153, 10, 'S');
    doc.setFont("helvetica", "bold");
    doc.setFontSize(6.5);
    doc.setTextColor(212, 175, 55);
    doc.text("VALIDEZ OFICIAL", centerX, 152, { align: "center" });
    doc.text("RECTORÍA ACADÉMICA", centerX, 156, { align: "center" });

    // 10. Firma de Rectoría Académica a la derecha
    doc.setDrawColor(100, 116, 139);
    doc.setLineWidth(0.6);
    doc.line(pageWidth - 75, 158, pageWidth - 25, 158);

    doc.setFont("helvetica", "bold");
    doc.setFontSize(10);
    doc.setTextColor(15, 43, 72);
    doc.text("Rectoría Académica", pageWidth - 50, 164, { align: "center" });

    doc.setFont("helvetica", "normal");
    doc.setFontSize(8);
    doc.setTextColor(100, 116, 139);
    doc.text("Universidad del Aluminio", pageWidth - 50, 169, { align: "center" });
    doc.text(`Fecha: ${fechaTxt}`, pageWidth - 50, 174, { align: "center" });

    // 11. Descargar documento
    const safeTitle = tituloPrograma.replace(/[^a-zA-Z0-9_\-]/g, '_');
    const safeName  = nombre.replace(/[^a-zA-Z0-9_\-]/g, '_');
    const nombreArchivo = `${esCarrera ? 'Diploma_Carrera' : 'Certificado_Curso'}_${safeTitle}_${safeName}.pdf`;
    doc.save(nombreArchivo);

    if (typeof showToast === 'function') {
        showToast(`🎓 ${esCarrera ? 'Diploma de Carrera' : 'Certificado de Curso'} descargado con éxito.`, 'success');
    }
}
window.generarPDFDocumentoAcademico = generarPDFDocumentoAcademico;

window.descargarCertificado = async (nombre, cedula, curso, cursoId = '') => {
    if (!cursoId && typeof cursoActualData !== 'undefined' && cursoActualData && cursoActualData.id) {
        cursoId = cursoActualData.id;
    }
    if (!cursoId && Array.isArray(cursos)) {
        const found = cursos.find(c => c.titulo === curso || c.id === curso);
        if (found) cursoId = found.id;
    }

    let codigo = null;
    if (sesion && Array.isArray(sesion.credenciales)) {
        const cred = sesion.credenciales.find(c => c.id === cursoId && c.tipo === 'curso');
        if (cred) codigo = cred.codigo;
    }

    await generarPDFDocumentoAcademico({
        tipo: 'curso',
        nombre: nombre || (sesion ? sesion.nombre : 'Participante'),
        cedula: cedula || (sesion ? sesion.id : ''),
        tituloPrograma: curso,
        itemId: cursoId,
        codigoVerificacion: codigo
    });
};

window.descargarDiplomaCarrera = async (carreraId, nombre = null, cedula = null) => {
    const carObj = (carreras || []).find(c => c.id === carreraId);
    const titulo = carObj ? carObj.nombre : carreraId;
    const userName = nombre || (sesion ? sesion.nombre : 'Participante');
    const userCedula = cedula || (sesion ? sesion.id : '');

    let codigo = null;
    if (sesion && Array.isArray(sesion.credenciales)) {
        const cred = sesion.credenciales.find(c => c.id === carreraId && c.tipo === 'carrera');
        if (cred) codigo = cred.codigo;
    }

    await generarPDFDocumentoAcademico({
        tipo: 'carrera',
        nombre: userName,
        cedula: userCedula,
        tituloPrograma: titulo,
        itemId: carreraId,
        codigoVerificacion: codigo
    });
};

window.copiarEnlaceVerificacion = (codigo) => {
    const host = window.location.origin;
    const path = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
    const url = `${host}${path}/verificar.php?codigo=${encodeURIComponent(codigo)}`;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
            if (typeof showToast === 'function') showToast('📋 Enlace de verificación copiado al portapapeles.', 'success');
        }).catch(() => {
            prompt('Copia este enlace de verificación:', url);
        });
    } else {
        prompt('Copia este enlace de verificación:', url);
    }
};
