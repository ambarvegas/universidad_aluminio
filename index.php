<?php
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
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Campus Virtual — Universidad del Aluminio</title>
    <meta name="description" content="Campus virtual de formación técnica de la Universidad del Aluminio. Accede a tus cursos, evalúa tus competencias y certifica tu aprendizaje.">
    <meta name="theme-color" content="#0f2b48">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="UniAluminio">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="icon-192.png" id="favicon-link">
    <link rel="apple-touch-icon" href="icon-192.png">
    <!-- Google Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet" />
    <link rel="stylesheet" href="<?= v_asset('style.css') ?>" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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

    <!-- Navbar Principal -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-mortarboard-fill"></i>
                <span>Universidad del Aluminio</span>
            </a>
            
            <div class="d-flex align-items-center gap-2 order-lg-last">
                <div id="save-indicator" class="save-indicator"></div>
                <div id="nav-user-info" class="d-none d-sm-flex align-items-center gap-2"></div>
                <button class="btn btn-outline-light btn-sm d-flex align-items-center gap-1" onclick="abrirModalPerfil()" title="Mi Perfil y Contraseña">
                    <i class="bi bi-person-gear"></i>
                    <span class="d-none d-md-inline">Mi Perfil</span>
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="logout()">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="d-none d-md-inline">Cerrar Sesión</span>
                </button>
                <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active" id="nav-btn-cursos" href="#" onclick="cambiarVistaCampus('cursos'); return false;">
                            <i class="bi bi-grid-fill me-1"></i>Mis Cursos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="nav-btn-carreras" href="#" onclick="cambiarVistaCampus('carreras'); return false;">
                            <i class="bi bi-diagram-3-fill me-1"></i>Rutas de Carreras
                            <span class="badge bg-warning text-dark rounded-pill ms-1" id="nav-badge-carreras" style="display: none;">0</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="nav-btn-certificados" href="#" onclick="cambiarVistaCampus('certificados'); return false;">
                            <i class="bi bi-award-fill me-1"></i>Mis Certificados
                            <span class="badge bg-success rounded-pill ms-1" id="nav-badge-certificados" style="display: none;">0</span>
                        </a>
                    </li>
                    <li class="nav-item" id="nav-admin-link" style="display: none;">
                        <a class="nav-link text-warning" href="admin.php"><i class="bi bi-speedometer2 me-1"></i>Panel de Control</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Banner con Saludo y Estadísticas -->
    <header class="hero-banner">
        <div class="container position-relative">
            <div class="row align-items-center g-4">
                <div class="col-lg-6">
                    <div id="user-greeting-badge" class="badge-soft-primary d-inline-flex mb-2 bg-white bg-opacity-10 text-white border border-white border-opacity-25 px-3 py-1">
                        <i class="bi bi-person-check-fill me-1"></i> <span id="user-role-label">Estudiante</span>
                    </div>
                    <h1 class="mb-2" id="user-greeting-title">¡Bienvenido a tu Formación!</h1>
                    <p class="mb-0 text-white-50">Explora tus programas académicos, realiza lecciones interactivas y certifica tus competencias técnicas.</p>
                </div>
                <div class="col-lg-6">
                    <div class="row g-3" id="student-kpis">
                        <div class="col-4">
                            <div class="kpi-card p-3">
                                <div class="kpi-icon blue"><i class="bi bi-journal-bookmark-fill"></i></div>
                                <div>
                                    <div class="kpi-value" id="kpi-cursos-total">0</div>
                                    <div class="kpi-label">Disponibles</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="kpi-card p-3">
                                <div class="kpi-icon amber"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="kpi-value" id="kpi-cursos-progreso">0</div>
                                    <div class="kpi-label">En Curso</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="kpi-card p-3">
                                <div class="kpi-icon green"><i class="bi bi-award-fill"></i></div>
                                <div>
                                    <div class="kpi-value" id="kpi-cursos-completados">0</div>
                                    <div class="kpi-label">Completados</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Contenido Principal: Filtros y Galería -->
    <main class="container my-4">
        
        <!-- Selector de Vistas del Campus -->
        <div class="campus-tabs-container mb-4">
            <div class="d-flex gap-2 flex-wrap align-items-center justify-content-between">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="campus-tab-btn active" id="tab-btn-cursos" onclick="cambiarVistaCampus('cursos')">
                        <i class="bi bi-grid-fill me-2"></i>Mis Cursos
                    </button>
                    <button type="button" class="campus-tab-btn" id="tab-btn-carreras" onclick="cambiarVistaCampus('carreras')">
                        <i class="bi bi-diagram-3-fill me-2"></i>Rutas de Carreras
                        <span class="badge bg-primary bg-opacity-25 text-primary rounded-pill ms-2" id="tab-badge-carreras" style="display:none;">0</span>
                    </button>
                    <button type="button" class="campus-tab-btn" id="tab-btn-certificados" onclick="cambiarVistaCampus('certificados')">
                        <i class="bi bi-award-fill me-2"></i>Mis Certificados & Diplomas
                        <span class="badge bg-success bg-opacity-25 text-success rounded-pill ms-2" id="tab-badge-certificados" style="display:none;">0</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- VISTA 1: MIS CURSOS -->
        <div id="vista-campus-cursos">
            <!-- Barra de Búsqueda y Filtros de Categoría -->
            <div class="filter-toolbar">
                <div class="row align-items-center g-3">
                    <div class="col-md-5">
                        <div class="search-input-group">
                            <i class="bi bi-search"></i>
                            <input type="text" id="input-buscar-cursos" class="form-control" placeholder="Buscar cursos por título o tema..." oninput="filtrarGaleriaCursos()">
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex flex-wrap gap-2 justify-content-md-end" id="filtros-categoria">
                            <button type="button" class="filter-pill-btn active" data-filter="todos" onclick="setFiltroGaleria('todos')">Todos</button>
                            <button type="button" class="filter-pill-btn" data-filter="progreso" onclick="setFiltroGaleria('progreso')">En Progreso</button>
                            <button type="button" class="filter-pill-btn" data-filter="completados" onclick="setFiltroGaleria('completados')">Completados</button>
                            <button type="button" class="filter-pill-btn" data-filter="libre" onclick="setFiltroGaleria('libre')">Acceso Libre</button>
                            <button type="button" class="filter-pill-btn" data-filter="especializado" onclick="setFiltroGaleria('especializado')">Especializados</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grid de Cursos -->
            <section id="galeria-cursos">
                <div class="row g-4" id="galeria-cursos-row">
                    <div class="col-12 text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="text-muted mt-2">Cargando catálogo de cursos...</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- VISTA 2: RUTAS DE CARRERAS -->
        <div id="vista-campus-carreras" style="display: none;">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="fw-bold text-primary mb-1"><i class="bi bi-diagram-3-fill me-2"></i>Rutas de Aprendizaje y Carreras</h4>
                    <p class="text-muted small mb-0">Programas de formación curricular diseñados según tu especialización técnica.</p>
                </div>
            </div>
            <div class="row g-4" id="galeria-carreras-row">
                <div class="col-12 text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2">Cargando tus rutas curriculares...</p>
                </div>
            </div>
        </div>

        <!-- VISTA 3: MIS CERTIFICADOS & DIPLOMAS -->
        <div id="vista-campus-certificados" style="display: none;">
            <div class="mb-4">
                <h4 class="fw-bold text-primary mb-1"><i class="bi bi-patch-check-fill text-warning me-2"></i>Vitrina de Credenciales Oficiales</h4>
                <p class="text-muted small mb-0">Descarga tus certificados de cursos y diplomas de carrera con código QR de verificación oficial.</p>
            </div>
            <div class="row g-4" id="vitrina-certificados-row">
                <div class="col-12 text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="text-muted mt-2">Cargando tus credenciales...</p>
                </div>
            </div>
        </div>

    <!-- Modal Mi Perfil y Cambio de Contraseña -->
    <div class="modal fade" id="modal-mi-perfil" tabindex="-1" aria-labelledby="modalMiPerfilLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
                <!-- Header del Modal -->
                <div class="modal-header text-white border-0 py-3 px-4" style="background: linear-gradient(135deg, #0f2b48 0%, #1e3a8a 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-person-badge-fill text-warning fs-4"></i>
                        <h5 class="modal-title fw-bold" id="modalMiPerfilLabel">Mi Perfil Académico</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Tabs del Perfil -->
                <div class="bg-light px-4 pt-3 border-bottom">
                    <ul class="nav nav-tabs border-0" id="perfilTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold text-dark" id="tab-perfil-resumen-btn" data-bs-toggle="tab" data-bs-target="#tab-perfil-resumen" type="button" role="tab">
                                <i class="bi bi-person-lines-fill me-1 text-primary"></i>Ficha de Formación
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold text-dark" id="tab-perfil-seguridad-btn" data-bs-toggle="tab" data-bs-target="#tab-perfil-seguridad" type="button" role="tab">
                                <i class="bi bi-shield-lock-fill me-1 text-primary"></i>Seguridad & Contraseña
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Cuerpo del Modal -->
                <div class="modal-body p-4">
                    <div class="tab-content" id="perfilTabsContent">
                        <!-- Pestaña 1: Resumen del Colaborador -->
                        <div class="tab-pane fade show active" id="tab-perfil-resumen" role="tabpanel">
                            <div class="row g-4 align-items-center mb-4">
                                <div class="col-sm-auto text-center">
                                    <div id="perfil-avatar-lg" class="profile-avatar-lg mx-auto">
                                        UA
                                    </div>
                                </div>
                                <div class="col-sm">
                                    <h4 class="fw-bold text-primary mb-1" id="perfil-nombre">Nombre del Colaborador</h4>
                                    <div class="d-flex flex-wrap gap-2 align-items-center text-muted small mb-2">
                                        <span><i class="bi bi-card-heading me-1"></i>Cédula: <strong id="perfil-cedula" class="text-dark">N/A</strong></span>
                                        <span>•</span>
                                        <span><i class="bi bi-briefcase me-1"></i>Cargo: <strong id="perfil-rol" class="text-dark">Participante</strong></span>
                                        <span>•</span>
                                        <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-2 py-1">Colaborador Activo</span>
                                    </div>
                                    <p class="text-muted small mb-0">Miembro oficial de la plataforma de formación de la Universidad del Aluminio.</p>
                                </div>
                            </div>

                            <!-- 4 Métricas Personales -->
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <div class="profile-metric-box">
                                        <div class="text-primary fs-3 mb-1"><i class="bi bi-journal-bookmark-fill"></i></div>
                                        <div class="metric-val" id="perfil-kpi-disponibles">0</div>
                                        <div class="metric-lbl">Cursos Disponibles</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="profile-metric-box">
                                        <div class="text-warning fs-3 mb-1"><i class="bi bi-hourglass-split"></i></div>
                                        <div class="metric-val" id="perfil-kpi-progreso">0</div>
                                        <div class="metric-lbl">En Curso</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="profile-metric-box">
                                        <div class="text-success fs-3 mb-1"><i class="bi bi-check-circle-fill"></i></div>
                                        <div class="metric-val" id="perfil-kpi-modulos">0</div>
                                        <div class="metric-lbl">Módulos Aprobados</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="profile-metric-box">
                                        <div class="text-info fs-3 mb-1"><i class="bi bi-award-fill"></i></div>
                                        <div class="metric-val" id="perfil-kpi-certificados">0</div>
                                        <div class="metric-lbl">Certificados & Diplomas</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pestaña 2: Seguridad y Cambio de Contraseña -->
                        <div class="tab-pane fade" id="tab-perfil-seguridad" role="tabpanel">
                            <div class="alert alert-info border-0 bg-info bg-opacity-10 d-flex gap-3 align-items-center p-3 rounded-3 mb-4">
                                <i class="bi bi-shield-check fs-2 text-info"></i>
                                <div>
                                    <div class="fw-bold text-info">Gestión Autónoma de Credenciales</div>
                                    <small class="text-muted">Actualiza tu contraseña para mantener la privacidad y seguridad de tu cuenta académica. La nueva clave se guardará encriptada con tecnología bcrypt.</small>
                                </div>
                            </div>

                            <form id="form-cambiar-clave" onsubmit="guardarNuevaClave(event)">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-secondary">Contraseña Actual <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="bi bi-key-fill text-muted"></i></span>
                                        <input type="password" id="input-clave-actual" class="form-control" placeholder="Ingresa tu clave actual" required autocomplete="current-password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="toggleVerClave('input-clave-actual', this)" title="Ver / Ocultar">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Si es tu primer acceso, tu contraseña por defecto suele ser <code>12345</code>.</div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-secondary">Nueva Contraseña <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light"><i class="bi bi-lock-fill text-muted"></i></span>
                                            <input type="password" id="input-clave-nueva" class="form-control" placeholder="Mínimo 4 caracteres" required minlength="4" autocomplete="new-password">
                                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVerClave('input-clave-nueva', this)" title="Ver / Ocultar">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-secondary">Confirmar Nueva Contraseña <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light"><i class="bi bi-shield-lock-fill text-muted"></i></span>
                                            <input type="password" id="input-clave-confirmar" class="form-control" placeholder="Repite la nueva clave" required minlength="4" autocomplete="new-password">
                                            <button class="btn btn-outline-secondary" type="button" onclick="toggleVerClave('input-clave-confirmar', this)" title="Ver / Ocultar">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" id="btn-guardar-clave" class="btn btn-primary px-4 fw-bold shadow-sm">
                                        <i class="bi bi-check2 me-1"></i>Actualizar Contraseña
                                    </button>
                                </div>
                            </form>
                        </div>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
    <script src="<?= v_asset('js/ui/modal.js') ?>"></script>
    <script src="<?= v_asset('js/ui/toast.js') ?>"></script>
    <script src="<?= v_asset('js/auth.js') ?>"></script>
    <script src="<?= v_asset('js/api.js') ?>"></script>
    <script src="<?= v_asset('js/features/images.js') ?>"></script>
    <script src="<?= v_asset('js/features/config.js') ?>"></script>
    <script src="<?= v_asset('script.js') ?>"></script>
</body>
</html>
