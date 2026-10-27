/**
 * js/features/reportes-evaluaciones-exportar.js
 * Exportación del reporte de evaluaciones: XLSX, CSV e impresión/PDF.
 * Usa el estado y los filtros de reportes-evaluaciones.js.
 */

// ============================================================
// EXPORTACIÓN DE EVALUACIONES (XLSX, CSV, IMPRESIÓN/PDF)
// ============================================================

/**
 * Exporta las evaluaciones filtradas a archivo Excel (XLSX)
 * Garantiza orden consecutivo agrupado por Colaborador
 */
async function exportarEvaluacionesXLSX() {
    if (typeof XLSX === 'undefined') {
        alert('Cargando librería Excel, por favor reintenta en unos momentos...');
        return;
    }

    const datos = await _cargarEvaluacionesParaExportar();
    if (!datos) return;
    const _evaluacionesFiltradas = datos.evaluaciones;

    // Ordenar por Colaborador -> Curso -> Módulo
    const dataOrdenada = [..._evaluacionesFiltradas].sort((a, b) => {
        const cmpU = a.usuarioNombre.localeCompare(b.usuarioNombre);
        if (cmpU !== 0) return cmpU;
        const cmpC = a.cursoTitulo.localeCompare(b.cursoTitulo);
        if (cmpC !== 0) return cmpC;
        return a.moduloNum - b.moduloNum;
    });

    const dataExcel = dataOrdenada.map((e, idx) => ({
        '#': idx + 1,
        'Cédula / ID': e.usuarioId,
        'Colaborador': e.usuarioNombre,
        'Cargo / Rol': e.rolNombre,
        'Código Curso': e.cursoId,
        'Título Curso': e.cursoTitulo,
        'Módulo': e.moduloTitulo,
        'Calificación (pts)': e.calificacion,
        'Estado': e.aprobado ? 'APROBADO' : 'REPROBADO',
        'Intentos': e.intentos,
        'Veces Reprobado': e.vecesReprobado,
        'Reintentos Habilitados': e.habilitaciones.length,
        'Intentos Extra Vigentes': e.intentosExtra,
        'Modalidad': e.marcadoManual ? 'Marcado Manual (Rectoría)' : 'Examen Online',
        'Fecha': e.fecha || 'Sin registro'
    }));

    try {
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.json_to_sheet(dataExcel);

        // Anchos de columna óptimos
        ws['!cols'] = [
            { wch: 6 },   // #
            { wch: 16 },  // Cédula
            { wch: 28 },  // Nombre
            { wch: 24 },  // Rol
            { wch: 20 },  // Código Curso
            { wch: 36 },  // Título Curso
            { wch: 32 },  // Módulo
            { wch: 18 },  // Calificación
            { wch: 14 },  // Estado
            { wch: 10 },  // Intentos
            { wch: 16 },  // Veces Reprobado
            { wch: 22 },  // Reintentos Habilitados
            { wch: 22 },  // Intentos Extra Vigentes
            { wch: 26 },  // Modalidad
            { wch: 22 }   // Fecha
        ];

        XLSX.utils.book_append_sheet(wb, ws, "Registro Evaluaciones");
        const hoy = new Date().toISOString().slice(0, 10);
        XLSX.writeFile(wb, `Reporte_Evaluaciones_Universidad_Aluminio_${hoy}.xlsx`);

        if (typeof showToast === 'function') {
            showToast('📊 Reporte de Evaluaciones XLSX exportado exitosamente.', 'success');
        }
    } catch (err) {
        console.error('Error al exportar XLSX:', err);
        alert('Error al exportar Excel: ' + err.message);
    }
}

/**
 * Exporta las evaluaciones filtradas a archivo CSV compatible
 * Ordenado por Colaborador
 */
async function exportarEvaluacionesCSV() {
    const datos = await _cargarEvaluacionesParaExportar();
    if (!datos) return;
    const _evaluacionesFiltradas = datos.evaluaciones;

    const dataOrdenada = [..._evaluacionesFiltradas].sort((a, b) => {
        const cmpU = a.usuarioNombre.localeCompare(b.usuarioNombre);
        if (cmpU !== 0) return cmpU;
        const cmpC = a.cursoTitulo.localeCompare(b.cursoTitulo);
        if (cmpC !== 0) return cmpC;
        return a.moduloNum - b.moduloNum;
    });

    const headers = ['#', 'Cedula', 'Colaborador', 'Cargo', 'Codigo_Curso', 'Curso', 'Modulo', 'Calificacion', 'Estado', 'Intentos', 'Veces_Reprobado', 'Reintentos_Habilitados', 'Intentos_Extra_Vigentes', 'Modalidad', 'Fecha'];
    const rows = dataOrdenada.map((e, idx) => [
        idx + 1,
        `"${e.usuarioId}"`,
        `"${e.usuarioNombre.replace(/"/g, '""')}"`,
        `"${e.rolNombre.replace(/"/g, '""')}"`,
        `"${e.cursoId}"`,
        `"${e.cursoTitulo.replace(/"/g, '""')}"`,
        `"${e.moduloTitulo.replace(/"/g, '""')}"`,
        e.calificacion,
        e.aprobado ? 'APROBADO' : 'REPROBADO',
        e.intentos,
        e.vecesReprobado,
        e.habilitaciones.length,
        e.intentosExtra,
        e.marcadoManual ? 'Manual' : 'Online',
        `"${(e.fecha || '').replace(/"/g, '""')}"`
    ]);

    const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(r => r.join(','))].join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const hoy = new Date().toISOString().slice(0, 10);
    link.setAttribute('href', url);
    link.setAttribute('download', `Reporte_Evaluaciones_Universidad_Aluminio_${hoy}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    if (typeof showToast === 'function') {
        showToast('📄 Archivo CSV descargado con éxito.', 'success');
    }
}

/**
 * Genera vista de impresión / PDF profesional con soporte para formato agrupado
 */
async function imprimirReporteEvaluaciones() {
    // La ventana se abre antes de esperar al servidor para que no la bloquee el navegador
    const w = window.open('', '_blank');
    const datos = await _cargarEvaluacionesParaExportar();
    if (!datos) {
        if (w) w.close();
        return;
    }
    const _evaluacionesFiltradas = datos.evaluaciones;
    const _usuariosAgrupados = datos.grupos;
    const hoy = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });

    let bodyHtml = '';

    if (_modoVistaEval === 'agrupada' && _usuariosAgrupados.length > 0) {
        // Modo Agrupado por Colaborador
        _usuariosAgrupados.forEach((u, uIdx) => {
            let modRows = '';
            u.evaluaciones.forEach((e, mIdx) => {
                modRows += `
                <tr>
                    <td style="text-align:center; width:30px;">${mIdx + 1}</td>
                    <td><strong>${escapeHtml(e.cursoTitulo)}</strong></td>
                    <td>${escapeHtml(e.moduloTitulo)}</td>
                    <td style="text-align:center; font-weight:bold; color:${e.aprobado ? '#059669' : '#dc2626'};">${e.calificacion.toFixed(1)} pts</td>
                    <td style="text-align:center;">${e.aprobado ? '<span style="color:#059669; font-weight:bold;">Aprobado</span>' : '<span style="color:#dc2626; font-weight:bold;">Reprobado</span>'}</td>
                    <td style="text-align:center;">${e.intentos}${e.vecesReprobado > 0 ? `<br><small style="color:#b91c1c;">${e.vecesReprobado} reprob.${e.habilitaciones.length ? ' · ' + e.habilitaciones.length + ' habilit.' : ''}</small>` : ''}</td>
                    <td>${e.marcadoManual ? 'Manual (Rectoría)' : 'Online'}</td>
                    <td style="font-size:11px;">${e.fecha || '—'}</td>
                </tr>`;
            });

            bodyHtml += `
            <div style="margin-bottom: 22px; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; page-break-inside: avoid;">
                <div style="background-color: #f1f5f9; padding: 10px 14px; border-bottom: 1px solid #cbd5e1; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 14px; color: #0f2b48;">${uIdx + 1}. ${escapeHtml(u.usuarioNombre)}</strong>
                        <span style="color: #64748b; font-size: 12px; margin-left: 8px;">(CI: ${escapeHtml(u.usuarioId)} • ${escapeHtml(u.rolNombre)})</span>
                    </div>
                    <div style="font-size: 12px;">
                        <strong>Promedio:</strong> <span style="color: ${u.promedio >= 70 ? '#059669' : '#dc2626'}; font-weight: bold;">${u.promedio} pts</span> •
                        <strong>Módulos cursados:</strong> ${u.totalModulos} (${u.aprobados} aprobados)
                    </div>
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f8fafc; font-size: 11px; color: #475569; text-transform: uppercase;">
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">#</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Curso</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Módulo Evaluado</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Calificación</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Estado</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Intentos</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Modalidad</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${modRows}
                    </tbody>
                </table>
            </div>`;
        });
    } else {
        // Modo Lista Plana Tradicional
        let rowsHtml = '';
        _evaluacionesFiltradas.forEach((e, idx) => {
            rowsHtml += `
            <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${escapeHtml(e.usuarioNombre)}</strong><br><small style="color:#666;">CI: ${escapeHtml(e.usuarioId)} • ${escapeHtml(e.rolNombre)}</small></td>
                <td>${escapeHtml(e.cursoTitulo)}</td>
                <td>${escapeHtml(e.moduloTitulo)}</td>
                <td style="text-align:center; font-weight:bold; color:${e.aprobado ? '#059669' : '#dc2626'};">${e.calificacion.toFixed(1)} pts</td>
                <td style="text-align:center;">${e.aprobado ? '<span style="color:#059669; font-weight:bold;">Aprobado</span>' : '<span style="color:#dc2626; font-weight:bold;">Reprobado</span>'}</td>
                <td style="text-align:center;">${e.intentos}${e.vecesReprobado > 0 ? `<br><small style="color:#b91c1c;">${e.vecesReprobado} reprob.${e.habilitaciones.length ? ' · ' + e.habilitaciones.length + ' habilit.' : ''}</small>` : ''}</td>
                <td>${e.marcadoManual ? 'Manual (Rectoría)' : 'Online'}</td>
                <td style="font-size:11px;">${e.fecha || '—'}</td>
            </tr>`;
        });

        bodyHtml = `
        <table>
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">#</th>
                    <th>Colaborador</th>
                    <th>Curso</th>
                    <th>Módulo</th>
                    <th style="text-align:center;">Calificación</th>
                    <th style="text-align:center;">Estado</th>
                    <th style="text-align:center;">Intentos</th>
                    <th>Modalidad</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                ${rowsHtml}
            </tbody>
        </table>`;
    }

    const docHtml = `
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Reporte de Evaluaciones — Universidad del Aluminio</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1e293b; margin: 25px; }
            .header { border-bottom: 2px solid #0f2b48; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
            .title { font-size: 20px; font-weight: bold; color: #0f2b48; }
            .subtitle { font-size: 13px; color: #64748b; margin-top: 4px; }
            table { width: 100%; border-collapse: collapse; margin-top: 5px; }
            th { background-color: #0f2b48; color: white; text-align: left; padding: 7px 8px; font-size: 11px; text-transform: uppercase; }
            td { border-bottom: 1px solid #e2e8f0; padding: 6px 8px; }
            tr:nth-child(even) { background-color: #f8fafc; }
            @media print {
                .no-print { display: none; }
                body { margin: 0; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 8px 16px; background: #0f2b48; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                🖨️ Imprimir / Guardar como PDF
            </button>
            <span style="margin-left: 10px; color: #64748b;">(Usa Ctrl+P o el botón para guardar como PDF)</span>
        </div>
        <div class="header">
            <div>
                <div class="title">Universidad del Aluminio • Panel Rectoral</div>
                <div class="subtitle">Reporte de Calificaciones ${(_modoVistaEval === 'agrupada' ? 'Agrupado por Colaborador' : 'Detallado')}</div>
            </div>
            <div style="text-align: right; font-size: 11px; color: #64748b;">
                Fecha de Emisión: ${hoy}<br>
                ${_modoVistaEval === 'agrupada' ? `Total Colaboradores: ${_usuariosAgrupados.length} • ` : ''}Total Calificaciones: ${_evaluacionesFiltradas.length}
            </div>
        </div>
        ${bodyHtml}
    </body>
    </html>`;

    w.document.write(docHtml);
    w.document.close();
}


window.exportarEvaluacionesXLSX      = exportarEvaluacionesXLSX;
window.exportarEvaluacionesCSV       = exportarEvaluacionesCSV;
window.imprimirReporteEvaluaciones   = imprimirReporteEvaluaciones;
