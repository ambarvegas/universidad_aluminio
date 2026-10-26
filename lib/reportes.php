<?php
/**
 * lib/reportes.php — Reporte de evaluaciones (filtrado, orden y paginación en el servidor)
 * Se carga desde db_mysql.php.
 *
 * Las filas reproducen las reglas que antes calculaba el navegador sobre el progreso de todos
 * los usuarios (js/features/reports.js): módulos aprobados sin evaluación cuentan como
 * acreditación manual al 100 %, las reprobaciones históricas se conservan al habilitar
 * reintentos y las acreditaciones de Rectoría no cuentan reprobaciones.
 */

/** Minúsculas sin acentos, para búsquedas y orden alfabético. */
function reporte_normalizar(string $texto): string {
    $texto = mb_strtolower($texto, 'UTF-8');
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
                          'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
}

function reporte_comparar_texto(string $a, string $b): int {
    return strcmp(reporte_normalizar($a), reporte_normalizar($b));
}

/**
 * Todas las calificaciones de todos los usuarios, una fila por (usuario, curso, módulo).
 */
function db_reporte_evaluaciones_filas(mysqli $conn): array {
    $roles = [];
    $res = $conn->query("SELECT id, nombre FROM `roles_config`");
    while ($res && $r = $res->fetch_assoc()) $roles[] = $r;
    $nombreRol = function ($rolId) use ($roles): string {
        $rolId = (string)$rolId;
        if ($rolId === '') return 'Participante General';
        $buscado = mb_strtolower(trim($rolId), 'UTF-8');
        foreach ($roles as $r) {
            if (mb_strtolower(trim((string)$r['id']), 'UTF-8') === $buscado ||
                mb_strtolower(trim((string)$r['nombre']), 'UTF-8') === $buscado) {
                return (string)$r['nombre'];
            }
        }
        return $rolId;
    };

    $cursos = [];
    $res = $conn->query("SELECT id, titulo FROM `cursos`");
    while ($res && $r = $res->fetch_assoc()) $cursos[$r['id']] = ['titulo' => $r['titulo'], 'modulos' => []];
    // La posición en la lista ordenada es el índice de módulo que usa el progreso
    $res = $conn->query("SELECT curso_id, titulo, max_intentos FROM `curso_modulos` ORDER BY curso_id, orden ASC");
    while ($res && $r = $res->fetch_assoc()) {
        if (isset($cursos[$r['curso_id']])) {
            $cursos[$r['curso_id']]['modulos'][] = ['titulo' => $r['titulo'], 'maxIntentos' => (int)($r['max_intentos'] ?? 0)];
        }
    }

    $usuarios = [];
    $res = $conn->query("SELECT id, nombre, rol FROM `usuarios`");
    while ($res && $r = $res->fetch_assoc()) $usuarios[(string)$r['id']] = $r;

    $filas = [];
    foreach (db_leer_progreso($conn) as $uid => $progUsuario) {
        $u = $usuarios[(string)$uid] ?? null;
        if (!$u) continue;
        $rolNombre = $nombreRol($u['rol']);

        foreach ((array)$progUsuario as $cursoId => $prog) {
            $cursoId = (string)$cursoId;
            $prog = (array)$prog;
            $curso = $cursos[$cursoId] ?? null;
            $cursoTitulo = $curso ? ($curso['titulo'] !== '' ? $curso['titulo'] : $cursoId) : $cursoId;

            $evals = [];
            foreach ((array)($prog['evaluaciones'] ?? []) as $k => $ev) {
                if (is_array($ev) || is_object($ev)) $evals[(string)$k] = (array)$ev;
            }
            $intentosMap = [];
            foreach ((array)($prog['intentos'] ?? []) as $k => $v) $intentosMap[(string)$k] = (int)$v;
            $aprobados = array_map('strval', (array)($prog['modulosAprobados'] ?? []));
            $intentosExtraMap = (array)($prog['intentosExtra'] ?? []);
            $habilitacionesMap = (array)($prog['habilitaciones'] ?? []);

            // Módulo aprobado sin evaluación registrada: acreditado manualmente al 100 %
            foreach ($aprobados as $m) {
                if (!isset($evals[$m])) {
                    $evals[$m] = ['calificacion' => 100, 'aprobado' => true, 'marcadoManual' => true, 'fecha' => ''];
                }
            }

            foreach ($evals as $modNum => $ev) {
                $modNum = (string)$modNum;
                $marcadoManual = !empty($ev['marcadoManual']);
                $calif = is_numeric($ev['calificacion'] ?? null) ? (float)$ev['calificacion']
                       : (is_numeric($ev['nota'] ?? null) ? (float)$ev['nota'] : 0.0);
                // Acreditación artificial de un módulo que ya no está aprobado: no se reporta
                if (!in_array($modNum, $aprobados, true) && $marcadoManual && $calif == 100) continue;

                $esNumerico = is_numeric($modNum);
                $modIdx = $esNumerico ? (int)$modNum : null;
                $moduloObj = ($curso && $esNumerico) ? ($curso['modulos'][$modIdx] ?? null) : null;
                $modTitulo = 'Módulo ' . ($esNumerico ? $modIdx + 1 : $modNum) . ($moduloObj ? ': ' . $moduloObj['titulo'] : '');

                $aprobado = isset($ev['aprobado']) ? (bool)$ev['aprobado'] : ($calif >= 70);
                $intentosReales = $intentosMap[$modNum] ?? 0;
                $vecesReprobado = $aprobado
                    ? ($marcadoManual ? 0 : max(0, $intentosReales - 1))
                    : max($intentosReales, 1);
                $maxBase = $moduloObj ? $moduloObj['maxIntentos'] : 0;
                $extra = (int)($intentosExtraMap[$modNum] ?? 0);
                $maxEfectivo = $maxBase > 0 ? $maxBase + $extra : 0;
                $habilitaciones = array_values((array)($habilitacionesMap[$modNum] ?? []));
                $bloqueado = !$aprobado && $maxEfectivo > 0 && $intentosReales >= $maxEfectivo;
                $fecha = (string)($ev['fecha'] ?? '');
                $ts = $fecha !== '' ? strtotime($fecha) : false;

                $filas[] = [
                    'usuarioId'           => (string)$u['id'],
                    'usuarioNombre'       => (string)$u['nombre'],
                    'usuarioRol'          => (string)$u['rol'],
                    'rolNombre'           => $rolNombre,
                    'cursoId'             => $cursoId,
                    'cursoTitulo'         => (string)$cursoTitulo,
                    'moduloNum'           => $esNumerico ? $modIdx : $modNum,
                    'moduloTitulo'        => $modTitulo,
                    'calificacion'        => $calif,
                    'aprobado'            => $aprobado,
                    'marcadoManual'       => $marcadoManual,
                    'intentos'            => $intentosReales ?: 1,
                    'vecesReprobado'      => $vecesReprobado,
                    'maxIntentosBase'     => $maxBase,
                    'maxIntentosEfectivo' => $maxEfectivo,
                    'intentosExtra'       => $extra,
                    'habilitaciones'      => $habilitaciones,
                    'bloqueado'           => $bloqueado,
                    'reintentoPendiente'  => !$aprobado && $extra > 0 && !$bloqueado,
                    'puedeHabilitar'      => !$aprobado && $intentosReales >= 1,
                    'fecha'               => $fecha,
                    'fechaTimestamp'      => $ts ? $ts * 1000 : 0,
                ];
            }
        }
    }
    return $filas;
}

/** Aplica los filtros de la pantalla (usuario, curso, estado y texto libre). */
function reporte_filtrar_evaluaciones(array $filas, array $f): array {
    $usuario = (string)($f['usuario'] ?? '');
    $curso   = (string)($f['curso'] ?? '');
    $estado  = (string)($f['estado'] ?? '');
    $buscar  = reporte_normalizar(trim((string)($f['search'] ?? '')));

    return array_values(array_filter($filas, function ($e) use ($usuario, $curso, $estado, $buscar) {
        if ($usuario !== '' && $e['usuarioId'] !== $usuario) return false;
        if ($curso !== '' && $e['cursoId'] !== $curso) return false;
        if ($estado === 'aprobado' && !$e['aprobado']) return false;
        if ($estado === 'reprobado' && $e['aprobado']) return false;
        if ($estado === 'manual' && !$e['marcadoManual']) return false;
        if ($estado === 'bloqueado' && !$e['bloqueado']) return false;
        if ($estado === 'habilitado' && count($e['habilitaciones']) === 0) return false;
        if ($buscar !== '') {
            foreach (['usuarioNombre', 'usuarioId', 'cursoTitulo', 'moduloTitulo', 'rolNombre'] as $campo) {
                if (str_contains(reporte_normalizar($e[$campo]), $buscar)) return true;
            }
            return false;
        }
        return true;
    }));
}

function reporte_ordenar_evaluaciones(array &$filas, string $orden): void {
    $porModulo = fn($a, $b) => (int)$a['moduloNum'] <=> (int)$b['moduloNum'];
    usort($filas, function ($a, $b) use ($orden, $porModulo) {
        switch ($orden) {
            case 'calif_desc': return $b['calificacion'] <=> $a['calificacion'];
            case 'calif_asc':  return $a['calificacion'] <=> $b['calificacion'];
            case 'fecha_desc': return $b['fechaTimestamp'] <=> $a['fechaTimestamp'];
            case 'curso_asc':
                return reporte_comparar_texto($a['cursoTitulo'], $b['cursoTitulo'])
                    ?: reporte_comparar_texto($a['usuarioNombre'], $b['usuarioNombre'])
                    ?: $porModulo($a, $b);
            default: // nombre_asc: colaborador → curso → módulo
                return reporte_comparar_texto($a['usuarioNombre'], $b['usuarioNombre'])
                    ?: reporte_comparar_texto($a['cursoTitulo'], $b['cursoTitulo'])
                    ?: $porModulo($a, $b);
        }
    });
}

/** Agrupa por colaborador con sus totales; ordena los grupos según $orden. */
function reporte_agrupar_evaluaciones(array $filas, string $orden): array {
    $grupos = [];
    foreach ($filas as $e) {
        $id = $e['usuarioId'];
        if (!isset($grupos[$id])) {
            $grupos[$id] = [
                'usuarioId' => $id, 'usuarioNombre' => $e['usuarioNombre'], 'usuarioRol' => $e['usuarioRol'],
                'rolNombre' => $e['rolNombre'], 'evaluaciones' => [], 'totalModulos' => 0, 'aprobados' => 0,
                'reprobados' => 0, 'sumaNotas' => 0.0, 'promedio' => 0, 'tasaAprob' => 0,
                'ultimaFecha' => '', 'ultimaFechaTimestamp' => 0,
            ];
        }
        $g = &$grupos[$id];
        $g['evaluaciones'][] = $e;
        $g['totalModulos']++;
        $e['aprobado'] ? $g['aprobados']++ : $g['reprobados']++;
        $g['sumaNotas'] += $e['calificacion'];
        if ($e['fechaTimestamp'] > $g['ultimaFechaTimestamp']) {
            $g['ultimaFechaTimestamp'] = $e['fechaTimestamp'];
            $g['ultimaFecha'] = $e['fecha'];
        }
        unset($g);
    }

    $grupos = array_values($grupos);
    foreach ($grupos as &$g) {
        usort($g['evaluaciones'], fn($a, $b) => reporte_comparar_texto($a['cursoTitulo'], $b['cursoTitulo'])
            ?: (int)$a['moduloNum'] <=> (int)$b['moduloNum']);
        $g['promedio']  = $g['totalModulos'] > 0 ? round($g['sumaNotas'] / $g['totalModulos'], 1) : 0;
        $g['tasaAprob'] = $g['totalModulos'] > 0 ? (int)round($g['aprobados'] / $g['totalModulos'] * 100) : 0;
    }
    unset($g);

    usort($grupos, function ($a, $b) use ($orden) {
        switch ($orden) {
            case 'calif_desc': return $b['promedio'] <=> $a['promedio'];
            case 'calif_asc':  return $a['promedio'] <=> $b['promedio'];
            case 'fecha_desc': return $b['ultimaFechaTimestamp'] <=> $a['ultimaFechaTimestamp'];
            default:           return reporte_comparar_texto($a['usuarioNombre'], $b['usuarioNombre']);
        }
    });
    return $grupos;
}

/**
 * Reporte listo para la pantalla. $f admite: search, usuario, curso, estado, orden,
 * modo ('agrupada' pagina colaboradores, 'plana' pagina calificaciones), page, limit
 * (número o 'all') y todo (sin paginar, para exportar).
 */
function db_reporte_evaluaciones(mysqli $conn, array $f): array {
    $orden = (string)($f['orden'] ?? 'nombre_asc');
    $modo  = ($f['modo'] ?? 'agrupada') === 'plana' ? 'plana' : 'agrupada';

    $filas = reporte_filtrar_evaluaciones(db_reporte_evaluaciones_filas($conn), $f);
    reporte_ordenar_evaluaciones($filas, $orden);
    $grupos = $modo === 'agrupada' ? reporte_agrupar_evaluaciones($filas, $orden) : [];

    $total = count($filas);
    $suma = 0.0; $aprob = 0; $perfectas = 0;
    foreach ($filas as $e) {
        $suma += $e['calificacion'];
        if ($e['aprobado']) $aprob++;
        if ($e['calificacion'] >= 100) $perfectas++;
    }
    $usuariosDistintos = count(array_unique(array_column($filas, 'usuarioId')));

    $respuesta = [
        'modo'          => $modo,
        'total'         => $total,
        'totalUsuarios' => $usuariosDistintos,
        'kpis'          => [
            'total'     => $total,
            'promedio'  => $total > 0 ? round($suma / $total, 1) : 0,
            'tasaAprob' => $total > 0 ? (int)round($aprob / $total * 100) : 0,
            'perfectas' => $perfectas,
        ],
    ];

    $items = $modo === 'agrupada' ? $grupos : $filas;
    $totalItems = count($items);
    $todo = !empty($f['todo']) || ($f['limit'] ?? '') === 'all';
    $limit = $todo ? max(1, $totalItems) : max(1, min(500, (int)($f['limit'] ?? 15) ?: 15));
    $paginas = max(1, (int)ceil($totalItems / $limit));
    $pagina = $todo ? 1 : max(1, min($paginas, (int)($f['page'] ?? 1)));
    $desde = ($pagina - 1) * $limit;

    $respuesta['pagina']     = $pagina;
    $respuesta['porPagina']  = $todo ? 'all' : $limit;
    $respuesta['paginas']    = $paginas;
    $respuesta['totalItems'] = $totalItems;
    $respuesta['desde']      = $totalItems > 0 ? $desde + 1 : 0;
    $respuesta['hasta']      = min($desde + $limit, $totalItems);
    $pag = array_slice($items, $desde, $limit);

    if ($modo === 'agrupada') {
        $respuesta['grupos'] = $pag;
        // Para exportar se entregan también las calificaciones en orden plano
        $respuesta['evaluaciones'] = !empty($f['todo']) ? $filas : [];
    } else {
        $respuesta['grupos'] = [];
        $respuesta['evaluaciones'] = $pag;
    }
    return $respuesta;
}
