// script.js - Inicializador principal
document.addEventListener('DOMContentLoaded', async () => {
    await cargarDatosDelServidor();

    verificarProteccion();

    renderListaCursosCarrera();

    actualizarTablas();

    const form = document.getElementById('form-curso');
    if (form) {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const idEdit = document.getElementById('edit-id').value;

            if (tempModulos.length === 0) {
                alert("Error: El curso debe tener al menos un módulo.");
                return;
            }
            const todasTienenLecciones = tempModulos.every(m => m.lecciones && m.lecciones.length > 0);
            if (!todasTienenLecciones) {
                alert("Error: Cada módulo debe contener al menos una lección.");
                return;
            }
            
            const nuevoCurso = {
                id: idEdit ? idEdit : "CUR-" + Date.now(),
                titulo: document.getElementById('titulo').value,
                imagen: tempImagenPortada,
                descripcion: document.getElementById('descripcion').value,
                modulos: tempModulos
            };

            try {
                if (idEdit) {
                    const idx = cursos.findIndex(c => c.id == idEdit);
                    cursos[idx] = nuevoCurso;
                } else {
                    cursos.push(nuevoCurso);
                }
                guardar();
                location.reload();
            } catch (err) {
                console.error("Error de almacenamiento:", err);
                alert("No se pudo guardar el curso. Es muy probable que la imagen de portada sea demasiado pesada para la memoria del navegador. Intenta con una imagen más pequeña o sin imagen.");
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    const cursoId = urlParams.get('id');
    
    if (document.getElementById('contenido-curso')) {
        mostrarDetalleCurso(cursoId);
    }
});