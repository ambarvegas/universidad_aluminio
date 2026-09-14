<?php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!function_exists('v_asset')) {
    function v_asset($path) {
        $file = __DIR__ . '/' . ltrim($path, '/');
        $v = file_exists($file) ? filemtime($file) : '1';
        return $path . '?v=' . $v;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control Académico — Universidad del Aluminio</title>
    <meta name="theme-color" content="#0f2b48">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="UniAluminio Admin">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icon-192.png" id="favicon-link">
    <link rel="apple-touch-icon" href="icon-192.png">
    <!-- Google Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= v_asset('style.css') ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        .btn-xs {
            padding: 0.15rem 0.4rem;
            font-size: 0.75rem;
            line-height: 1.2;
        }

        .toast-container {
            z-index: 9999;
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-loading .spinner-border {
            display: inline-block !important;
        }
    </style>
</head>

<body>
    <!-- Spinner de Carga Inicial -->
    <div id="admin-loading-screen" class="admin-loading-screen">
        <div class="admin-loading-content">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <h6 class="fw-bold text-primary mb-1">Universidad del Aluminio</h6>
            <p class="text-muted small mb-0"><i class="bi bi-cloud-arrow-down me-1"></i>Sincronizando datos del servidor...</p>
        </div>
    </div>
    <!-- Navbar del Administrador -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid px-lg-4 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <a class="navbar-brand mb-0" href="admin.php">
                    <i class="bi bi-shield-check"></i>
                    <span>Universidad del Aluminio | Rectoría</span>
                </a>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div id="save-indicator" class="save-indicator"></div>
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-grid-fill me-1"></i>Ir a Mis Cursos
                </a>
                <button class="btn btn-danger btn-sm" onclick="logout()">
                    <i class="bi bi-box-arrow-right me-1"></i>Cerrar Sesión
                </button>
            </div>
        </div>
    </nav>

    <!-- Contenedor Principal del Panel de Administración -->
    <main class="container-fluid px-lg-4 my-4">
        
        <!-- Header del Dashboard & KPIs -->
        <div class="mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="fw-bold text-primary mb-1">Panel de Control Académico</h2>
                    <p class="text-muted small mb-0">Gestión de cursos, colaboradores, estructura curricular y métricas institucionales.</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="window.forzarHardReset(this)" title="Limpiar caché del navegador, desregistrar Service Worker y recargar última versión">
                        <i class="bi bi-arrow-repeat me-1"></i>Limpiar Caché / Forzar Actualización
                    </button>
                    <button class="btn btn-outline-primary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Sincronizar todo con el servidor">
                        <i class="bi bi-arrow-clockwise me-1"></i>Sincronizar Servidor
                    </button>
                </div>
            </div>

            <!-- 4 KPI Cards Resumen -->
            <div class="row g-3">
                <div class="col-sm-6 col-xl-3">
                    <div class="kpi-card p-3">
                        <div class="kpi-icon blue"><i class="bi bi-journal-bookmark-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="admin-kpi-cursos">0</div>
                            <div class="kpi-label">Cursos Registrados</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="kpi-card p-3">
                        <div class="kpi-icon purple"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="admin-kpi-usuarios">0</div>
                            <div class="kpi-label">Colaboradores Activos</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="kpi-card p-3">
                        <div class="kpi-icon amber"><i class="bi bi-bell-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="admin-kpi-solicitudes">0</div>
                            <div class="kpi-label">Solicitudes Pendientes</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="kpi-card p-3">
                        <div class="kpi-icon green"><i class="bi bi-award-fill"></i></div>
                        <div>
                            <div class="kpi-value" id="admin-kpi-certificados">0</div>
                            <div class="kpi-label">Certificados Emitidos</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestañas de Navegación del Administrador -->
        <ul class="nav nav-pills-admin mb-4" id="adminTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-cursos">
                    <i class="bi bi-journal-bookmark-fill me-1"></i>Cursos
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-usuarios">
                    <i class="bi bi-people-fill me-1"></i>Colaboradores
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-carreras">
                    <i class="bi bi-diagram-3-fill me-1"></i>Carreras
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reportes">
                    <i class="bi bi-bar-chart-line-fill me-1"></i>Reportes de Desempeño
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link position-relative" data-bs-toggle="tab" data-bs-target="#tab-solicitudes">
                    <i class="bi bi-bell-fill me-1"></i>Solicitudes
                    <span class="badge bg-danger rounded-pill ms-1" id="badge-solicitudes-total" style="display: none;">0</span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-roles">
                    <i class="bi bi-shield-lock-fill me-1"></i>Roles & Permisos
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-config">
                    <i class="bi bi-gear-fill me-1"></i>Configuración
                </button>
            </li>
        </ul>

        <!-- Contenido de las Pestañas -->
        <div class="tab-content table-card p-4">
            
            <!-- Pestaña 1: Cursos -->
            <div class="tab-pane fade show active" id="tab-cursos">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Gestión de Catálogo de Cursos</h4>
                        <p class="text-muted small mb-0">Crea, edita módulos, asigna prelaciones y configura evaluaciones.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar catálogo">
                            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                        </button>
                        <div class="search-input-group position-relative" style="width: 230px;">
                            <i class="bi bi-search"></i>
                            <input type="search" id="search-admin-cursos" name="search_no_autofill_cursos" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" class="form-control form-control-sm" placeholder="Buscar curso..." oninput="filtrarTablaCursosAdmin()">
                            <button type="button" class="search-clear-btn" id="btn-clear-search-cursos" onclick="document.getElementById('search-admin-cursos').value=''; filtrarTablaCursosAdmin();" title="Limpiar búsqueda">&times;</button>
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="prepararFormulario()" data-bs-toggle="modal" data-bs-target="#cursoModal">
                            <i class="bi bi-plus-circle me-1"></i>Nuevo Curso
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-custom-header">
                            <tr>
                                <th style="width: 140px;">Código</th>
                                <th>Título y Clasificación</th>
                                <th>Estructura</th>
                                <th>Prelación</th>
                                <th class="text-end" style="width: 180px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-cursos-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- Pestaña 2: Usuarios -->
            <div class="tab-pane fade" id="tab-usuarios">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Gestión de Usuarios y Talento</h4>
                        <p class="text-muted small mb-0">Administra cuentas, asigna roles académicos y supervisa avances.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar colaboradores">
                            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                        </button>
                        <div class="search-input-group position-relative" style="width: 230px;">
                            <i class="bi bi-search"></i>
                            <input type="search" id="search-admin-usuarios" name="search_no_autofill_usuarios" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" class="form-control form-control-sm" placeholder="Buscar por nombre o cédula..." oninput="filtrarTablaUsuariosAdmin()">
                            <button type="button" class="search-clear-btn" id="btn-clear-search-usuarios" onclick="document.getElementById('search-admin-usuarios').value=''; filtrarTablaUsuariosAdmin();" title="Limpiar búsqueda">&times;</button>
                        </div>
                        <button class="btn btn-outline-success btn-sm" onclick="exportarUsuariosCSV()">
                            <i class="bi bi-file-earmark-spreadsheet-fill me-1"></i>Exportar CSV
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="prepararFormularioUsuario()" data-bs-toggle="modal" data-bs-target="#userModal">
                            <i class="bi bi-person-plus-fill me-1"></i>Nuevo Colaborador
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-custom-header">
                            <tr>
                                <th>Colaborador</th>
                                <th>Cédula</th>
                                <th>Rol y Carreras Asignadas</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones Rápidas</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-usuarios-body"></tbody>
                    </table>
                </div>

                <!-- Controles de Paginación de Usuarios -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 mt-2 border-top" id="usuarios-paginacion-container">
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-muted small mb-0" for="usuarios-per-page">Filas por página:</label>
                        <select id="usuarios-per-page" class="form-select form-select-sm" style="width: auto;" onchange="cambiarLimiteUsuarios(this.value)">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span class="text-muted small ms-2" id="usuarios-info-paginacion">Mostrando 0 de 0</span>
                    </div>
                    <nav aria-label="Navegación de colaboradores">
                        <ul class="pagination pagination-sm mb-0" id="usuarios-paginacion-nav"></ul>
                    </nav>
                </div>
            </div>

            <!-- Pestaña 3: Carreras -->
            <div class="tab-pane fade" id="tab-carreras">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card p-4 border bg-light rounded-3">
                            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-plus-square me-2"></i>Nueva Carrera o Plan Formativo</h5>
                            <form onsubmit="crearCarrera(event)">
                                <input type="hidden" id="edit-career-id">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Nombre de la Carrera</label>
                                    <input type="text" id="career-name" class="form-control" placeholder="Ej: Especialista en Perfilería" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Seleccionar Cursos del Itinerario:</label>
                                    <input type="text" class="form-control form-control-sm mb-2" placeholder="Filtrar cursos..." onkeyup="filtrarCursosCarrera(this.value)">
                                    <div id="career-courses-list" class="border rounded p-2 bg-white" style="max-height: 220px; overflow-y: auto;">
                                    </div>
                                </div>
                                <button class="btn btn-success w-100" type="submit" id="btn-guardar-carrera">
                                    <i class="bi bi-save me-1"></i>Guardar Carrera
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-primary mb-0"><i class="bi bi-diagram-3 me-2"></i>Carreras y Programas Actuales</h5>
                            <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar carreras">
                                <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-custom-header">
                                    <tr>
                                        <th>Nombre del Programa</th>
                                        <th>Cursos Asociados</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tabla-carreras-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pestaña 4: Reportes -->
            <div class="tab-pane fade" id="tab-reportes">

                <!-- Sub-navegación de Reportes -->
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Reportes de Desempeño</h4>
                        <p class="text-muted small mb-0">Análisis de avance, brechas de aprendizaje, calificaciones y evaluaciones.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar reportes">
                            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                        </button>
                        <button class="btn btn-sm btn-primary" id="btn-vista-evaluaciones" onclick="cambiarVistaReporte('evaluaciones')">
                            <i class="bi bi-card-checklist me-1"></i>Registro de Evaluaciones
                        </button>
                        <button class="btn btn-sm btn-outline-primary" id="btn-vista-learners" onclick="cambiarVistaReporte('learners')">
                            <i class="bi bi-trophy-fill me-1"></i>Top Learners
                        </button>
                        <button class="btn btn-sm btn-outline-primary" id="btn-vista-brechas" onclick="cambiarVistaReporte('brechas')">
                            <i class="bi bi-clipboard2-x-fill me-1"></i>Brechas de Aprendizaje
                        </button>
                    </div>
                </div>

                <!-- Vista 3: Reporte Detallado de Evaluaciones -->
                <div id="vista-evaluaciones">
                    <!-- KPIs Resumen de Evaluaciones -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm p-3 bg-light rounded-3 h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle p-2 bg-primary bg-opacity-10 text-primary">
                                        <i class="bi bi-card-checklist fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold text-primary" id="kpi-eval-total">0</div>
                                        <div class="text-muted small">Total Evaluaciones</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm p-3 bg-light rounded-3 h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle p-2 bg-success bg-opacity-10 text-success">
                                        <i class="bi bi-graph-up-arrow fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold text-success" id="kpi-eval-promedio">0.0</div>
                                        <div class="text-muted small">Promedio General</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm p-3 bg-light rounded-3 h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle p-2 bg-info bg-opacity-10 text-info">
                                        <i class="bi bi-patch-check-fill fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold text-info" id="kpi-eval-tasa-aprob">0%</div>
                                        <div class="text-muted small">Tasa de Aprobación</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm p-3 bg-light rounded-3 h-100">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle p-2 bg-warning bg-opacity-10 text-warning">
                                        <i class="bi bi-star-fill fs-4"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold text-warning" id="kpi-eval-perfectas">0</div>
                                        <div class="text-muted small">Puntaje Perfecto (100)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Barra de Filtros y Controles -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="search" id="filtro-eval-search" name="search_no_autofill_eval" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" class="form-control" placeholder="Buscar colaborador, cédula, curso..." oninput="filtrarTablaEvaluaciones()">
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="filtro-eval-usuario" class="form-select form-select-sm" onchange="filtrarTablaEvaluaciones()">
                                    <option value="">— Colaborador —</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="filtro-eval-curso" class="form-select form-select-sm" onchange="filtrarTablaEvaluaciones()">
                                    <option value="">— Curso —</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="filtro-eval-estado" class="form-select form-select-sm" onchange="filtrarTablaEvaluaciones()">
                                    <option value="">— Todos los Estados —</option>
                                    <option value="aprobado">Aprobados</option>
                                    <option value="reprobado">Reprobados</option>
                                    <option value="manual">Marcados Manualmente</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <select id="filtro-eval-orden" class="form-select form-select-sm" onchange="filtrarTablaEvaluaciones()">
                                    <option value="nombre_asc" selected>Colaborador → Curso → Módulo</option>
                                    <option value="calif_desc">Mayor Calificación</option>
                                    <option value="calif_asc">Menor Calificación</option>
                                    <option value="fecha_desc">Más Recientes</option>
                                    <option value="curso_asc">Curso (A-Z)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Cabecera de Tabla, Modos de Vista y Exportación -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h5 class="fw-bold text-primary mb-0"><i class="bi bi-person-lines-fill me-2"></i>Calificaciones de Módulos</h5>
                            <span class="badge bg-primary text-white fw-semibold" id="evaluaciones-count-badge">0 colaboradores</span>
                            
                            <!-- Selector de Modo de Vista -->
                            <div class="btn-group btn-group-sm ms-sm-2 shadow-sm" role="group">
                                <button type="button" class="btn btn-primary fw-semibold" id="btn-eval-vista-agrupada" onclick="cambiarModoVistaEvaluaciones('agrupada')" title="Agrupar módulos cursados por Colaborador">
                                    <i class="bi bi-people-fill me-1"></i>Agrupado por Colaborador
                                </button>
                                <button type="button" class="btn btn-outline-primary fw-semibold" id="btn-eval-vista-plana" onclick="cambiarModoVistaEvaluaciones('plana')" title="Ver lista plana de evaluaciones">
                                    <i class="bi bi-table me-1"></i>Lista Plana
                                </button>
                            </div>

                            <!-- Botones de Colapso (Solo en vista agrupada) -->
                            <div id="eval-collapse-controls" class="btn-group btn-group-sm ms-1">
                                <button type="button" class="btn btn-outline-secondary" onclick="expandirTodosColaboradores(true)" title="Expandir todos los colaboradores">
                                    <i class="bi bi-arrows-expand me-1"></i>Expandir
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="expandirTodosColaboradores(false)" title="Colapsar todos los colaboradores">
                                    <i class="bi bi-arrows-collapse me-1"></i>Colapsar
                                </button>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-success shadow-sm" onclick="exportarEvaluacionesXLSX()" title="Exportar reporte detallado a Excel">
                                <i class="bi bi-file-earmark-excel-fill me-1"></i>Exportar XLSX
                            </button>
                            <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="exportarEvaluacionesCSV()" title="Descargar archivo CSV">
                                <i class="bi bi-filetype-csv me-1"></i>CSV
                            </button>
                            <button class="btn btn-sm btn-outline-dark shadow-sm" onclick="imprimirReporteEvaluaciones()" title="Imprimir o guardar como PDF">
                                <i class="bi bi-printer-fill me-1"></i>Imprimir / PDF
                            </button>
                        </div>
                    </div>

                    <!-- Vista 1: Contenedor de Evaluaciones Agrupadas por Colaborador -->
                    <div id="contenedor-evaluaciones-agrupadas" class="mb-3">
                        <div class="text-center py-5 text-muted bg-white rounded-3 border">
                            <div class="spinner-border spinner-border-sm text-primary me-2"></div>Cargando evaluaciones agrupadas por colaborador...
                        </div>
                    </div>

                    <!-- Vista 2: Tabla Plana de Evaluaciones (Oculta en modo agrupado) -->
                    <div class="table-responsive rounded-3 border mb-3" id="contenedor-tabla-evaluaciones" style="display: none;">
                        <table class="table table-hover align-middle mb-0" id="tabla-reporte-evaluaciones">
                            <thead class="table-custom-header">
                                <tr>
                                    <th style="width: 45px;">#</th>
                                    <th>Colaborador</th>
                                    <th>Curso</th>
                                    <th>Módulo</th>
                                    <th style="width: 170px;">Calificación</th>
                                    <th style="width: 110px;">Estado</th>
                                    <th style="width: 95px;">Intentos</th>
                                    <th>Modalidad</th>
                                    <th>Fecha</th>
                                </tr>
                            </thead>
                            <tbody id="tabla-evaluaciones-body">
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <div class="spinner-border spinner-border-sm text-primary me-2"></div>Cargando registro de evaluaciones...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación y Controles Inferiores -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3 px-1">
                        <div class="text-muted small" id="evaluaciones-paginacion-info">Mostrando 0 de 0 registros</div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="small text-muted mb-0">Mostrar:</label>
                            <select id="filtro-eval-per-page" class="form-select form-select-sm" style="width: 85px;" onchange="cambiarFilasPorPaginaEval(this.value)">
                                <option value="15" selected>15</option>
                                <option value="30">30</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">Todas</option>
                            </select>
                            <div class="btn-group btn-group-sm" id="eval-pagination-buttons">
                                <button class="btn btn-outline-secondary" id="btn-eval-prev" onclick="paginaAnteriorEval()" disabled><i class="bi bi-chevron-left"></i></button>
                                <button class="btn btn-outline-secondary" id="btn-eval-next" onclick="paginaSiguienteEval()"><i class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vista 1: Top Learners -->
                <div id="vista-learners" style="display: none;">
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold text-primary mb-0"><i class="bi bi-trophy me-2"></i>Top Learners — Desempeño Integral</h5>
                                <span class="badge bg-primary bg-opacity-10 text-primary">Actualizado en tiempo real</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="tabla-top-learners"></table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <h5 class="fw-bold text-primary mb-3"><i class="bi bi-pie-chart-fill me-2"></i>Cumplimiento por Cargo</h5>
                            <div id="chart-cumplimiento" class="border rounded p-3 bg-light"></div>
                        </div>
                    </div>
                </div>

                <!-- Vista 2: Brechas de Aprendizaje -->
                <div id="vista-brechas" style="display:none;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 p-3 bg-light rounded-3 border">
                        <div class="d-flex align-items-center gap-3">
                            <button class="btn btn-sm btn-outline-primary shadow-sm" onclick="cambiarVistaReporte('learners')" title="Volver al Reporte Principal">
                                <i class="bi bi-arrow-left me-1"></i>Volver al Reporte Principal
                            </button>
                            <div>
                                <h5 class="fw-bold text-primary mb-0"><i class="bi bi-clipboard2-x-fill me-2"></i>Brechas de Aprendizaje</h5>
                                <p class="text-muted small mb-0">Cursos, módulos y lecciones pendientes por colaborador.</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-warning bg-opacity-15 text-warning fw-semibold" id="brechas-total-count">Calculando...</span>
                            <select class="form-select form-select-sm" id="filtro-usuario-brechas" onchange="renderBrechasAprendizaje()" style="width: 220px;">
                                <option value="">— Todos los colaboradores —</option>
                            </select>
                        </div>
                    </div>
                    <div id="brechas-container">
                        <div class="text-center text-muted py-5">
                            <div class="spinner-border spinner-border-sm text-primary me-2"></div>Calculando brechas...
                        </div>
                    </div>
                </div>

            </div>

            <!-- Pestaña 5: Solicitudes -->
            <div class="tab-pane fade" id="tab-solicitudes">
                <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Gestión de Solicitudes Pendientes</h4>
                        <p class="text-muted small mb-0">Revisa y aprueba accesos a nuevas cuentas o solicitudes de cursos.</p>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar solicitudes">
                        <i class="bi bi-arrow-clockwise me-1"></i>Actualizar Solicitudes
                    </button>
                </div>
                <div class="mb-5">
                    <h5 class="fw-bold text-primary mb-3">
                        <i class="bi bi-person-badge me-2"></i>Solicitudes de Nuevas Cuentas de Acceso
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-custom-header">
                                <tr>
                                    <th>Cédula</th>
                                    <th>Nombre del Aspirante</th>
                                    <th>Cargo Solicitado</th>
                                    <th class="text-end">Decisión</th>
                                </tr>
                            </thead>
                            <tbody id="tabla-solicitudes-registro"></tbody>
                        </table>
                    </div>
                </div>
                
                <hr class="my-4">

                <div>
                    <h5 class="fw-bold text-amber mb-3">
                        <i class="bi bi-key-fill me-2"></i>Solicitudes de Acceso a Cursos Especializados
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-custom-header">
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Curso Solicitado</th>
                                    <th>Fecha de Solicitud</th>
                                    <th class="text-end">Decisión</th>
                                </tr>
                            </thead>
                            <tbody id="tabla-solicitudes-cursos"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pestaña 6: Gestión de Roles -->
            <div class="tab-pane fade" id="tab-roles">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Gestión de Roles y Permisos</h4>
                        <p class="text-muted small mb-0">Define la carga académica obligatoria o sugerida según el cargo del colaborador.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar roles">
                            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="prepararFormularioRol()" data-bs-toggle="modal" data-bs-target="#roleModal">
                            <i class="bi bi-shield-plus me-1"></i>Nuevo Rol
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-custom-header">
                            <tr>
                                <th>Identificador (Código)</th>
                                <th>Nombre Público del Cargo</th>
                                <th>Carga Académica Asignada</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-roles-body"></tbody>
                    </table>
                </div>
            </div>

            <!-- Pestaña 7: Configuración -->
            <div class="tab-pane fade" id="tab-config">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h4 class="fw-bold text-primary mb-0">Configuración de la Plataforma</h4>
                        <p class="text-muted small mb-0">Personaliza la identidad visual, los colores y los parámetros operativos del sistema.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm shadow-sm" onclick="refrescarDatosAdmin(this)" title="Refrescar configuración">
                            <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                        </button>
                        <button class="btn btn-success btn-sm" onclick="guardarTodasLasConfiguraciones()" id="btn-guardar-config">
                            <i class="bi bi-floppy-fill me-1"></i>Guardar Todo
                        </button>
                    </div>
                </div>

                <div class="row g-4">

                    <!-- Card 1: Identidad Institucional -->
                    <div class="col-lg-4">
                        <div class="config-card h-100">
                            <div class="config-card-header">
                                <div class="config-card-icon" style="background: rgba(2,132,199,0.12); color: #0284c7;">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0">Identidad Institucional</h6>
                                    <p class="text-muted small mb-0">Nombre, logo y marca</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Nombre de la Universidad</label>
                                <input type="text" class="form-control" id="cfg-nombre-universidad"
                                    placeholder="Ej: Universidad del Aluminio"
                                    oninput="previsualizarNombreUniversidad(this.value)">
                                <p class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>Se reflejará en el navbar, footer y página de inicio.</p>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Logo Institucional</label>
                                <div id="logo-preview-container" class="logo-preview-box mb-2 p-2 border rounded d-flex align-items-center justify-content-between bg-light" style="display:none;">
                                    <img id="logo-preview-img" src="" alt="Logo Institucional" class="img-fluid rounded" style="max-height:60px; max-width:180px; object-fit:contain;">
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="eliminarLogo()" title="Eliminar Logo">
                                        <i class="bi bi-trash me-1"></i>Eliminar
                                    </button>
                                </div>
                                <label class="btn btn-outline-primary btn-sm w-100" id="btn-cargar-logo" style="cursor:pointer;">
                                    <i class="bi bi-upload me-1"></i>Subir Logo (PNG / JPG)
                                    <input type="file" hidden onchange="cargarLogoInstitucion(event)" accept="image/*" id="input-logo">
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: Colores de la Plataforma -->
                    <div class="col-lg-4">
                        <div class="config-card h-100">
                            <div class="config-card-header">
                                <div class="config-card-icon" style="background: rgba(147,51,234,0.12); color: #9333ea;">
                                    <i class="bi bi-palette-fill"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0">Colores de la Plataforma</h6>
                                    <p class="text-muted small mb-0">Tema visual e identidad</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Color Primario (Fondo/Navbar)</label>
                                <div class="color-picker-row">
                                    <input type="color" class="color-swatch-input" id="cfg-color-primario" oninput="previewColores()">
                                    <input type="text" class="form-control form-control-sm font-mono" id="cfg-color-primario-hex" placeholder="#0f2b48"
                                        oninput="sincronizarColorHex('cfg-color-primario', this.value)" maxlength="7">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Color de Acento (Botones/Links)</label>
                                <div class="color-picker-row">
                                    <input type="color" class="color-swatch-input" id="cfg-color-acento" oninput="previewColores()">
                                    <input type="text" class="form-control form-control-sm font-mono" id="cfg-color-acento-hex" placeholder="#0284c7"
                                        oninput="sincronizarColorHex('cfg-color-acento', this.value)" maxlength="7">
                                </div>
                            </div>

                            <div class="color-preview-bar mb-3">
                                <div id="preview-bar-primary" class="color-preview-segment" title="Color primario"></div>
                                <div id="preview-bar-accent" class="color-preview-segment" title="Color de acento"></div>
                                <div class="color-preview-segment" style="background: #10b981;" title="Éxito (fijo)"></div>
                                <div class="color-preview-segment" style="background: #f59e0b;" title="Advertencia (fijo)"></div>
                            </div>

                            <button class="btn btn-outline-secondary btn-sm w-100" onclick="restablecerColores()">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer colores originales
                            </button>
                        </div>
                    </div>

                    <!-- Card 3: Parámetros del Sistema -->
                    <div class="col-lg-4">
                        <div class="config-card h-100">
                            <div class="config-card-header">
                                <div class="config-card-icon" style="background: rgba(16,185,129,0.12); color: #10b981;">
                                    <i class="bi bi-sliders"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0">Parámetros del Sistema</h6>
                                    <p class="text-muted small mb-0">Evaluaciones y operación</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">% Mínimo de Aprobación</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="cfg-min-aprobacion"
                                        min="1" max="100" onchange="actualizarMinAprobacionGlobal(this.value)">
                                    <span class="input-group-text fw-bold">%</span>
                                </div>
                                <p class="text-muted small mt-1">Estándar mínimo para aprobar evaluaciones de módulos.</p>
                            </div>

                            <div class="mb-4 pt-3 border-top">
                                <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Mensaje de Bienvenida</label>
                                <textarea class="form-control" id="cfg-mensaje-bienvenida" rows="3"
                                    placeholder="Ej: Bienvenido a tu plataforma de formación continua."
                                    oninput="actualizarMensajeBienvenida(this.value)"></textarea>
                                <p class="text-muted small mt-1">Aparece en el banner principal del portal del alumno.</p>
                            </div>

                            <div class="pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <p class="fw-semibold small mb-0">Modo de Mantenimiento</p>
                                        <p class="text-muted small mb-0">Suspende nuevos registros temporalmente</p>
                                    </div>
                                    <div class="form-check form-switch ms-3">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            id="cfg-modo-mantenimiento" style="width:2.5rem; height:1.3rem;"
                                            onchange="actualizarModoMantenimiento(this.checked)">
                                    </div>
                                </div>
                                <div id="mantenimiento-alert" class="alert alert-warning small mt-2 py-2 px-3" style="display:none;">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                    <strong>Modo activo:</strong> Los nuevos registros están deshabilitados.
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Fila 2: Notificaciones por Correo (SMTP) y Respaldos Automatizados -->
                <div class="row g-4 mt-1">

                    <!-- Card 4: Notificaciones y Servidor SMTP -->
                    <div class="col-lg-6">
                        <div class="config-card h-100">
                            <div class="config-card-header">
                                <div class="config-card-icon" style="background: rgba(2,132,199,0.12); color: #0284c7;">
                                    <i class="bi bi-envelope-at-fill"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0">Servidor de Correo & Notificaciones (SMTP)</h6>
                                    <p class="text-muted small mb-0">Envío automático de accesos, certificados y alertas</p>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Servidor SMTP (Host)</label>
                                    <input type="text" class="form-control form-control-sm" id="cfg-smtp-host" placeholder="mail.ejemplo.com o smtp.gmail.com">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Puerto</label>
                                    <input type="number" class="form-control form-control-sm" id="cfg-smtp-port" placeholder="587" value="587">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Usuario / Cuenta SMTP</label>
                                    <input type="text" class="form-control form-control-sm" id="cfg-smtp-user" placeholder="rectoria@universidaddelaluminio.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Contraseña SMTP</label>
                                    <input type="password" class="form-control form-control-sm" id="cfg-smtp-pass" placeholder="••••••••••••">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Seguridad</label>
                                    <select class="form-select form-select-sm" id="cfg-smtp-secure">
                                        <option value="tls">TLS / STARTTLS (587)</option>
                                        <option value="ssl">SSL (465)</option>
                                        <option value="none">Sin cifrado (25)</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold small text-uppercase text-muted ls-1">Email del Administrador (Alertas)</label>
                                    <input type="email" class="form-control form-control-sm" id="cfg-email-admin" placeholder="admin@universidaddelaluminio.com">
                                </div>
                            </div>

                            <!-- Panel de Prueba de Correo -->
                            <div class="p-3 bg-light rounded-3 border mt-3">
                                <label class="form-label fw-bold small text-dark mb-1">
                                    <i class="bi bi-send-check-fill text-primary me-1"></i>Probar Envío de Correo
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="email" id="cfg-test-email-dest" class="form-control" placeholder="destinatario@correo.com">
                                    <button type="button" class="btn btn-outline-primary" id="btn-probar-email" onclick="probarConfiguracionEmail()">
                                        <i class="bi bi-send me-1"></i>Enviar Prueba
                                    </button>
                                </div>
                                <div id="test-email-result" class="small mt-2" style="display:none;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 5: Respaldos Automatizados & Diagnóstico -->
                    <div class="col-lg-6">
                        <div class="config-card h-100">
                            <div class="config-card-header">
                                <div class="config-card-icon" style="background: rgba(16,185,129,0.12); color: #10b981;">
                                    <i class="bi bi-database-check"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0">Respaldos Automatizados & Mantenimiento</h6>
                                    <p class="text-muted small mb-0">Copias de seguridad del sistema y diagnóstico integral</p>
                                </div>
                            </div>

                            <!-- Acciones Rápidas -->
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-primary shadow-sm" id="btn-generar-backup" onclick="ejecutarBackupManual()">
                                    <i class="bi bi-cloud-arrow-up-fill me-1"></i>Generar Respaldo Ahora
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary shadow-sm" onclick="abrirModalDiagnostico()">
                                    <i class="bi bi-activity me-1"></i>Diagnóstico del Sistema
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info shadow-sm" onclick="cargarListaRespaldos()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Refrescar
                                </button>
                            </div>

                            <!-- Historial de Respaldos Recientes -->
                            <div class="table-responsive rounded-3 border" style="max-height: 220px; overflow-y: auto;">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.82rem;">
                                    <thead class="table-light sticky-top">
                                        <tr>
                                            <th>Archivo de Respaldo</th>
                                            <th>Fecha</th>
                                            <th>Tamaño</th>
                                            <th>Tipo</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabla-respaldos-body">
                                        <tr>
                                            <td colspan="4" class="text-center py-3 text-muted">
                                                <div class="spinner-border spinner-border-sm text-primary me-1"></div>Cargando lista de respaldos...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 p-2 bg-light rounded border text-muted small d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="bi bi-shield-lock-fill text-success me-1"></i>
                                    <strong>Protección Activa:</strong> Retención de últimas 7 copias en carpeta protegida.
                                </div>
                                <span class="badge bg-secondary" id="badge-total-respaldos">0 copias</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <!-- Modal de Edición/Creación de Curso -->
    <div class="modal fade" id="cursoModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form id="form-curso" onsubmit="guardarCurso(event)" autocomplete="off">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalTitulo">Nuevo Curso</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" id="edit-id" autocomplete="off">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Título del Curso</label>
                                <input type="text" id="titulo" class="form-control" placeholder="Ej: Extrusión y Aleaciones" required autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Tipo de Acceso</label>
                                <select id="curso-tipo" class="form-select">
                                    <option value="especializado">Especializado (Por Carrera)</option>
                                    <option value="publico">Acceso Libre (Público)</option>
                                    <option value="pruebas">🧪 Solo Pruebas (Exclusivo Administrador)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold small">Prelación</label>
                                <select id="curso-prelacion" class="form-select">
                                    <option value="">Ninguno</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div id="vista-previa-portada" class="mb-3" style="display: none;">
                                    <label class="form-label fw-bold small">Vista Previa de Portada:</label>
                                    <div class="border rounded p-2 bg-light d-flex align-items-center justify-content-between">
                                        <img id="img-vista-previa" src="" alt="Vista Previa" class="img-fluid rounded" style="max-height: 140px; object-fit: cover;">
                                        <button type="button" class="btn btn-outline-danger btn-sm ms-3" onclick="eliminarImagenPortada()">
                                            <i class="bi bi-trash me-1"></i>Eliminar Portada
                                        </button>
                                    </div>
                                </div>
                                <label class="form-label fw-bold small">Imagen de Portada</label>
                                <input type="file" id="input-portada" class="form-control" onchange="cargarImagenPortada(event)" accept="image/*">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch p-3 bg-light rounded border">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="curso-en-construccion">
                                    <label class="form-check-label fw-bold text-dark" for="curso-en-construccion">
                                        <i class="bi bi-cone-striped text-warning me-1"></i>Marcar este Curso como "En Construcción"
                                    </label>
                                    <div class="form-text text-muted small ms-4">Si se activa, los estudiantes verán una etiqueta "En Construcción" y no podrán ingresar al contenido del curso hasta que sea publicado.</div>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small">Descripción General</label>
                                <textarea id="descripcion" class="form-control" rows="2" placeholder="Resumen del contenido y objetivos de aprendizaje..." autocomplete="off"></textarea>
                            </div>
                            <div class="col-12">
                                <hr>
                                <h5 class="fw-bold text-primary">Estructura de Módulos y Lecciones</h5>
                                <div id="contenedor-modulos-editor"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success px-5" id="btn-guardar-curso">Guardar Curso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Gestión de Usuario Integral -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form id="form-usuario-integral" onsubmit="guardarUsuario(event)" autocomplete="off">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="userModalTitle">Perfil de Usuario</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Identificación (Cédula)</label>
                                <input type="text" id="u-id" class="form-control" required autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Nombre Completo</label>
                                <input type="text" id="u-nombre" class="form-control" required autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Rol Académico</label>
                                <select id="u-rol" class="form-select"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Estatus</label>
                                <select id="u-estado" class="form-select">
                                    <option value="activo">Activo</option>
                                    <option value="suspendido">Suspendido</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Contraseña</label>
                                <input type="password" id="u-clave" class="form-control" autocomplete="new-password" placeholder="Dejar en blanco para no cambiar">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Correo Electrónico (Notificaciones)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" id="u-email" class="form-control" placeholder="usuario@correo.com">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Teléfono / Celular</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="tel" id="u-telefono" class="form-control" placeholder="Ej: 0414-1234567">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Fecha de Nacimiento</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar-event"></i></span>
                                    <input type="date" id="u-fecha-nacimiento" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light d-flex justify-content-between">
                        <div>
                            <button type="button" class="btn btn-outline-info btn-sm" id="btn-modal-user-invite" onclick="abrirModalInvitacionDesdeEditor()">
                                <i class="bi bi-send-check-fill me-1"></i> Generar / Enviar Enlace de Acceso
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary px-4" id="btn-guardar-usuario">Guardar Cambios</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Enlace de Acceso / Invitación / Restablecimiento -->
    <div class="modal fade" id="invitacionAccesoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fs-6 fw-bold" id="invitacionModalTitle">
                        <i class="bi bi-send-check-fill me-2"></i>Enlace de Acceso / Invitación
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 px-3 small mb-3 border-0" style="background-color: #eff6ff; color: #1e40af;">
                        <div class="fw-bold" id="inv-user-nombre">Colaborador</div>
                        <div class="text-muted small">C.I: <span id="inv-user-id" class="fw-bold text-dark"></span> | Correo: <span id="inv-user-email" class="fw-bold text-dark"></span></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Tipo de Enlace</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="inv-tipo-radio" id="inv-tipo-invitacion" value="invitacion" checked onchange="actualizarLinkInvitacionActual()">
                            <label class="btn btn-outline-primary btn-sm" for="inv-tipo-invitacion">Invitación / Activación</label>
                            <input type="radio" class="btn-check" name="inv-tipo-radio" id="inv-tipo-reset" value="reset" onchange="actualizarLinkInvitacionActual()">
                            <label class="btn btn-outline-primary btn-sm" for="inv-tipo-reset">Restablecer Contraseña</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Vigencia del Enlace</label>
                        <select id="inv-duracion" class="form-select form-select-sm" onchange="actualizarLinkInvitacionActual()">
                            <option value="24">24 horas (1 día)</option>
                            <option value="48" selected>48 horas (2 días)</option>
                            <option value="168">7 días (1 semana)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Enlace Generado</label>
                        <div class="input-group">
                            <input type="text" id="inv-link-input" class="form-control form-control-sm font-monospace" readonly placeholder="Generando enlace...">
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="btn-copiar-inv-link" onclick="copiarLinkInvitacion()" title="Copiar al portapapeles">
                                <i class="bi bi-clipboard me-1" id="icon-copiar-inv"></i> Copiar
                            </button>
                        </div>
                        <small class="text-muted" style="font-size: 11px;">Expira el: <span id="inv-expira-txt" class="fw-bold text-dark">—</span></small>
                    </div>

                    <div id="inv-email-status" class="alert small mb-0" style="display: none;"></div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-sm btn-primary px-3" id="btn-reenviar-inv-email" onclick="actualizarLinkInvitacionActual(true)">
                        <i class="bi bi-envelope-at-fill me-1"></i> Reenviar por Correo
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Gestión de Roles -->
    <div class="modal fade" id="roleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form id="form-rol-integral" onsubmit="guardarRol(event)">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="roleModalTitle">Nuevo Rol</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">ID del Rol (Ej: asesor_ventas)</label>
                                <input type="text" id="r-id" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Nombre del Rol</label>
                                <input type="text" id="r-nombre" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <hr>
                                <label class="form-label fw-bold text-primary">Cursos Incluidos en el Rol</label>
                                <div id="r-lista-cursos" class="border rounded p-3 bg-light overflow-auto" style="max-height: 220px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-guardar-rol">Guardar Rol</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Gestión de Evaluación de Módulo -->
    <div class="modal fade" id="moduloEvaluacionModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <form onsubmit="event.preventDefault(); guardarEvaluacionModulo()">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="modalModuloEvaluacionTitulo">Evaluación del Módulo</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" id="edit-modulo-idx">

                        <!-- Configuración General del Examen y Banco de Preguntas -->
                        <div class="card bg-light border p-3 mb-4 rounded-3 shadow-sm">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-gear-fill text-primary me-2"></i>Configuración de la Evaluación</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold" for="eval-tipo">Modo de Evaluación:</label>
                                    <select id="eval-tipo" class="form-select form-select-sm" onchange="window.tempModuloEvaluacion.tipo = this.value; window.actualizarInfoBanco();">
                                        <option value="fijo">Formulario Fijo (todas las preguntas en orden)</option>
                                        <option value="aleatorio">Dinámico / Banco Aleatorio (preguntas al azar)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold" for="eval-num-preguntas">Preguntas a Presentar:</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text"><i class="bi bi-card-checklist"></i></span>
                                        <input type="number" id="eval-num-preguntas" class="form-control" min="0" placeholder="0 = Todas las del banco" oninput="window.tempModuloEvaluacion.numPreguntas = parseInt(this.value) || 0; window.actualizarInfoBanco();">
                                    </div>
                                    <div class="form-text text-muted" style="font-size: 0.75rem;" id="eval-banco-info">0 o vacío para mostrar todas las preguntas del banco.</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="eval-mezclar-opciones" onchange="window.tempModuloEvaluacion.mezclarOpciones = this.checked;">
                                        <label class="form-check-label small fw-bold" for="eval-mezclar-opciones">
                                            <i class="bi bi-shuffle me-1 text-primary"></i>Mezclar orden de las opciones de respuesta aleatoriamente en cada intento
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="fw-bold text-primary mb-0">Banco de Preguntas</h5>
                                <p class="text-muted small mb-0">Añade preguntas y opciones de respuesta. Puedes adjuntar miniaturas a enunciados y opciones.</p>
                            </div>
                            <span class="badge bg-primary px-3 py-2" id="badge-total-preguntas">0 preguntas</span>
                        </div>
                        <div id="contenedor-preguntas-modulo-editor"></div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success px-5" id="btn-guardar-evaluacion">Guardar Evaluación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Restablecer Avance -->
    <div class="modal fade" id="restablecerAvanceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title fw-bold"><i class="bi bi-arrow-counterclockwise me-2"></i>Restablecer Avance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="restablecer-user-id">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Usuario:</label>
                        <input type="text" id="restablecer-user-nombre" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Curso:</label>
                        <select id="restablecer-curso-select" class="form-select" onchange="cambiarCarreraRestablecer()">
                            <option value="">-- Seleccionar Curso --</option>
                        </select>
                    </div>
                    <div id="restablecer-modulos-container" style="display: none;">
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="restablecer-select-all" onchange="seleccionarTodosModulosRestablecer(this.checked)">
                                <label class="form-check-label fw-bold" for="restablecer-select-all">Seleccionar todos los módulos</label>
                            </div>
                        </div>
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">✓</th>
                                    <th>Curso</th>
                                    <th>Módulo</th>
                                </tr>
                            </thead>
                            <tbody id="restablecer-modulos-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btn-restablecer-seleccionados" onclick="confirmarRestablecerAvance(false)">Restablecer Seleccionados</button>
                    <button type="button" class="btn btn-warning" id="btn-restablecer-todo" onclick="confirmarRestablecerAvance(true)">Restablecer Todo</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Marcar Módulos como Completados -->
    <div class="modal fade" id="marcarCompletadoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-check-circle-fill me-2"></i>Marcar Módulos como Completados</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="marcar-user-id">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Usuario:</label>
                        <input type="text" id="marcar-user-nombre" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Curso:</label>
                        <select id="marcar-curso-select" class="form-select" onchange="cargarModulosParaMarcar()">
                            <option value="">-- Seleccionar Curso --</option>
                        </select>
                    </div>
                    <div id="marcar-modulos-container" style="display: none;">
                        <!-- Selector de Calificación a Asignar -->
                        <div class="row g-2 align-items-center mb-3 p-3 bg-light rounded-3 border">
                            <div class="col-md-7">
                                <label class="form-label fw-bold small text-dark mb-1">
                                    <i class="bi bi-award-fill text-warning me-1"></i>Calificación a Asignar al Completar:
                                </label>
                                <div class="text-muted small" id="marcar-nota-ayuda" style="font-size: 0.775rem;">
                                    Mínimo institucional: <strong id="marcar-min-aprob-badge">70</strong> pts (Rango válido: <span id="marcar-rango-badge" class="fw-bold">70 - 100</span> pts).
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-white fw-bold"><i class="bi bi-pencil-fill text-primary"></i></span>
                                    <input type="number" id="marcar-nota-input" class="form-control text-center fw-bold fs-6" min="70" max="100" value="100" step="1">
                                    <span class="input-group-text bg-white small">/ 100 pts</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="marcar-select-all" onchange="seleccionarTodosModulosParaMarcar(this.checked)">
                                <label class="form-check-label fw-bold" for="marcar-select-all">Seleccionar todos los módulos</label>
                            </div>
                        </div>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-1"></i> Marcar un módulo como completado aprobará su evaluación con la nota elegida y otorgará la medalla correspondiente.
                        </div>
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">✓</th>
                                    <th>Curso</th>
                                    <th>Módulo</th>
                                    <th>Estado Actual</th>
                                </tr>
                            </thead>
                            <tbody id="marcar-modulos-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btn-marcar-completado" onclick="confirmarMarcarCompletado()">Marcar como Completados</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Diagnóstico y Salud del Sistema -->
    <div class="modal fade" id="modalDiagnostico" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="bi bi-activity me-2"></i>Estado Integral del Sistema & Diagnóstico</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="modal-diagnostico-body">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border text-primary mb-2"></div>
                        <p class="mb-0">Consultando métricas de salud del servidor en tiempo real...</p>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="window.forzarHardReset(this)"><i class="bi bi-trash3 me-1"></i>Purgar Caché y Forzar Hard Reset</button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" onclick="cargarEstadoSistemaModal()"><i class="bi bi-arrow-clockwise me-1"></i>Actualizar Métricas</button>
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="py-4 text-center mt-5">
        <div class="container">
            <p class="mb-0 text-white-50">© 2026 Universidad del Aluminio • Desarrollado por Ambar Vegas</p>
        </div>
    </footer>

    <!-- Scripts Esenciales con auto-versionado -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= v_asset('js/ui/modal.js') ?>"></script>
    <script src="<?= v_asset('js/ui/toast.js') ?>"></script>
    <script src="<?= v_asset('js/ui/spinner.js') ?>"></script>
    <script src="<?= v_asset('js/auth.js') ?>"></script>
    <script src="<?= v_asset('js/api.js') ?>"></script>
    <script src="<?= v_asset('js/core/state.js') ?>"></script>
    <script src="<?= v_asset('js/features/images.js') ?>"></script>
    <script src="<?= v_asset('js/features/config.js') ?>"></script>
    <script src="<?= v_asset('js/features/solicitudes.js') ?>"></script>
    <script src="<?= v_asset('js/features/reports.js') ?>"></script>
    <script src="<?= v_asset('js/admin/admin-courses.js') ?>"></script>
    <script src="<?= v_asset('js/admin/admin-users.js') ?>"></script>
    <script src="<?= v_asset('js/admin/admin-careers-roles.js') ?>"></script>
    <script src="<?= v_asset('js/admin/admin-dashboard.js') ?>"></script>
    <script src="<?= v_asset('js/campus/diploma.js') ?>"></script>
    <script src="<?= v_asset('script.js') ?>"></script>
</body>

</html>
