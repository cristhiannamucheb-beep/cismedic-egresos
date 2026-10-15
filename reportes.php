<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

requerir_roles(['superadmin', 'administrativo']);

require_once 'config/database.php';

$mes_categoria = $_GET['mes_categoria'] ?? '';
$ano_categoria = $_GET['ano_categoria'] ?? date('Y');
$categorias_globales_reporte = [];
$categorias_sub_reporte = [];
$categorias_sin_clasificar = ['cantidad' => 0, 'total' => 0];
$egresos_por_mes = [];
$top_proveedores = [];

// Obtener datos para reportes
try {
    // Egresos por mes
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(fecha, '%Y-%m') as mes, SUM(total) as total
        FROM facturas
        WHERE YEAR(fecha) = YEAR(NOW())
        GROUP BY DATE_FORMAT(fecha, '%Y-%m')
        ORDER BY mes DESC
    ");
    $egresos_por_mes = $stmt->fetchAll();
    
    // Top proveedores
    $stmt = $pdo->query("
        SELECT p.nombre, COUNT(f.id) as cantidad, SUM(f.total) as total
        FROM facturas f
        LEFT JOIN proveedores p ON f.id_proveedor = p.id
        WHERE p.id IS NOT NULL
        GROUP BY f.id_proveedor
        ORDER BY total DESC
        LIMIT 10
    ");
    $top_proveedores = $stmt->fetchAll();

    $filtro_categoria = '';
    $parametros_categoria = [];
    if ($ano_categoria !== '') {
        $filtro_categoria .= ' AND YEAR(fecha_movimiento) = ?';
        $parametros_categoria[] = (int) $ano_categoria;
    }
    if ($mes_categoria !== '') {
        $filtro_categoria .= ' AND MONTH(fecha_movimiento) = ?';
        $parametros_categoria[] = (int) $mes_categoria;
    }
    $consulta_categorias = "SELECT id_global, global_nombre, global_color, sub_id, sub_nombre, origen, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total FROM (
        SELECT cg.id AS id_global, cg.nombre AS global_nombre, cg.color AS global_color, c.id AS sub_id, c.nombre AS sub_nombre, 'Entregas a personal' AS origen, ed.fecha_entrega AS fecha_movimiento, ed.monto AS monto FROM entregas_dinero ed LEFT JOIN categorias c ON c.id = ed.id_categoria LEFT JOIN categorias_globales cg ON cg.id = c.id_categoria_global WHERE ed.id_categoria IS NOT NULL
        UNION ALL
        SELECT cg.id, cg.nombre, cg.color, c.id, c.nombre, 'Compras a proveedores', ep.fecha_entrega, ep.monto FROM entregas_proveedores ep LEFT JOIN categorias c ON c.id = ep.id_categoria LEFT JOIN categorias_globales cg ON cg.id = c.id_categoria_global WHERE ep.id_categoria IS NOT NULL
        UNION ALL
        SELECT cg.id, cg.nombre, cg.color, c.id, c.nombre, 'Transferencias', f.fecha, df.subtotal_con_igv FROM detalle_factura df INNER JOIN facturas f ON f.id = df.id_factura LEFT JOIN categorias c ON c.id = df.id_categoria LEFT JOIN categorias_globales cg ON cg.id = c.id_categoria_global WHERE df.id_categoria IS NOT NULL
    ) AS movimientos WHERE id_global IS NOT NULL $filtro_categoria GROUP BY id_global, global_nombre, global_color, sub_id, sub_nombre, origen ORDER BY global_nombre, sub_nombre, origen";
    $stmt = $pdo->prepare($consulta_categorias);
    $stmt->execute($parametros_categoria);
    foreach ($stmt->fetchAll() as $fila) {
        $global_id = (int) $fila['id_global'];
        $sub_id = (int) $fila['sub_id'];
        if (!isset($categorias_globales_reporte[$global_id])) {
            $categorias_globales_reporte[$global_id] = ['nombre' => $fila['global_nombre'], 'color' => $fila['global_color'] ?: '#087f5b', 'cantidad' => 0, 'total' => 0, 'origenes' => []];
        }
        $categorias_globales_reporte[$global_id]['cantidad'] += (int) $fila['cantidad'];
        $categorias_globales_reporte[$global_id]['total'] += (float) $fila['total'];
        $clave_sub = $global_id . '-' . $sub_id;
        if (!isset($categorias_sub_reporte[$clave_sub])) {
            $categorias_sub_reporte[$clave_sub] = ['global' => $fila['global_nombre'], 'global_color' => $fila['global_color'] ?: '#087f5b', 'nombre' => $fila['sub_nombre'], 'cantidad' => 0, 'total' => 0, 'origenes' => []];
        }
        $categorias_sub_reporte[$clave_sub]['cantidad'] += (int) $fila['cantidad'];
        $categorias_sub_reporte[$clave_sub]['total'] += (float) $fila['total'];
        $categorias_sub_reporte[$clave_sub]['origenes'][$fila['origen']] = ['cantidad' => (int) $fila['cantidad'], 'total' => (float) $fila['total']];
    }
    $categorias_por_global = [];
    foreach ($categorias_sub_reporte as $subcategoria) {
        $clave_global = $subcategoria['global'];
        if (!isset($categorias_por_global[$clave_global])) {
            $categorias_por_global[$clave_global] = ['nombre' => $subcategoria['global'], 'color' => $subcategoria['global_color'], 'cantidad' => 0, 'total' => 0, 'subcategorias' => []];
        }
        $categorias_por_global[$clave_global]['cantidad'] += $subcategoria['cantidad'];
        $categorias_por_global[$clave_global]['total'] += $subcategoria['total'];
        $categorias_por_global[$clave_global]['subcategorias'][] = $subcategoria;
    }
} catch(Exception $e) {
    $error = "Error: " . $e->getMessage();
    $categorias_por_global = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Reportes - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .report-hero{background:linear-gradient(135deg,#173b2d 0%,#087f5b 55%,#2471a3 100%);border-radius:16px;color:#fff;padding:28px;position:relative;overflow:hidden}.report-hero:after{content:"";border:1px solid rgba(255,255,255,.16);border-radius:50%;height:260px;position:absolute;right:-80px;top:-150px;width:260px}.report-kicker{font-size:.72rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;opacity:.75}.report-title{font-size:2rem;font-weight:800}.analysis-card{background:#fff;border:1px solid #e1ebe7;border-radius:14px;box-shadow:0 8px 24px rgba(23,59,45,.07);height:100%;padding:20px;transition:box-shadow .25s ease,transform .25s ease}.analysis-card:hover{box-shadow:0 14px 32px rgba(23,59,45,.12);transform:translateY(-2px)}.analysis-card h5{color:#173b2d;font-size:1rem;font-weight:800}.analysis-caption{color:#7b8982;font-size:.8rem}.report-chart{height:62vh;min-height:420px;position:relative}.global-badge{border-radius:999px;font-size:.7rem;font-weight:700;padding:.35rem .65rem}.sub-row td{background:#fbfdfc;transition:background .2s}.sub-row:hover td{background:#eaf7f1}.sub-name{color:#31584a;font-weight:700}.origin-mini{font-size:.72rem;line-height:1.7}.origin-mini strong{color:#173b2d}.category-total{color:#087f5b;font-weight:800;white-space:nowrap}.report-kpi{background:#fff;border:1px solid #e1ebe7;border-radius:13px;box-shadow:0 6px 18px rgba(23,59,45,.06);padding:18px;position:relative;overflow:hidden}.report-kpi:before{background:var(--kpi-color,#087f5b);content:"";height:100%;left:0;position:absolute;top:0;width:4px}.report-kpi-label{color:#718078;font-size:.68rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.report-kpi-value{color:#173b2d;font-size:1.45rem;font-weight:800;margin-top:.35rem}.report-kpi-icon{color:var(--kpi-color,#087f5b);font-size:1.25rem}.filter-status{background:#eef8f3;border:1px solid #cdebdc;border-radius:9px;color:#087f5b;font-size:.78rem;padding:.65rem .85rem}.filter-status button{border:0;background:none;color:#087f5b;font-weight:700}.report-animated{animation:reportRise .65s ease both}@keyframes reportRise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
        .container-fluid>.row>*,.analysis-card,.report-hero>div{min-width:0}.analysis-card .table-responsive{overflow-x:auto}.analysis-card table{min-width:760px}.analysis-card th{white-space:nowrap}.analysis-card td{vertical-align:top}.origin-mini div+div{border-top:1px solid #edf2ef;margin-top:.25rem;padding-top:.25rem}.report-hero form{position:relative;z-index:1}.report-hero select{min-width:125px}#chartCategoriasGlobales,#chartCategoriasDona{cursor:pointer}
        .global-panel{border-top:4px solid var(--global-color);padding:22px}.global-panel-header{align-items:flex-start;display:flex;justify-content:space-between;margin-bottom:20px;gap:18px}.global-panel-title{color:#173b2d;font-size:1.35rem;font-weight:800;margin:.25rem 0}.global-panel-number{color:var(--global-color);display:block;font-size:1.15rem;margin-top:.35rem;white-space:nowrap}.global-chart{height:285px;position:relative}.global-chart canvas{cursor:pointer;position:relative;z-index:2}.global-donut-fallback{align-items:center;background:conic-gradient(#087f5b 0 25%,#2471a3 25% 50%,#d97706 50% 75%,#c2415d 75% 100%);border:10px solid #fff;border-radius:50%;display:flex;height:190px;justify-content:center;left:50%;position:absolute;top:20px;transform:translateX(-50%);width:190px}.global-donut-fallback:after{background:#fff;border-radius:50%;content:"";height:105px;position:absolute;width:105px}.global-donut-fallback span{color:#173b2d;font-size:.75rem;font-weight:800;position:relative;text-align:center;width:75px;z-index:1}.global-detail-table{min-width:560px}.global-detail-table thead th{background:#f5f8f7;color:#53635b;font-size:.7rem;letter-spacing:.04em;text-transform:uppercase}.global-detail-table tbody tr{border-bottom:1px solid #edf2ef}.selector-box{background:linear-gradient(135deg,#f4fff8 0%,#edf6ff 100%);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:.55rem .7rem;min-width:280px;box-shadow:0 8px 18px rgba(16,185,129,.08)}.selector-box label{font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#4d6d66}.selector-box .form-select{border:0;background:transparent;box-shadow:none;color:#173b2d;font-weight:700;padding:0 .2rem}.selector-box .form-select:focus{box-shadow:none}.selector-box .form-select option{background:#fff;color:#173b2d}.category-detail-shell{background:#fff;border:1px solid #e3ece8;border-radius:16px;box-shadow:0 10px 28px rgba(23,59,45,.06);padding:20px}.category-detail-head{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem}.category-detail-title{color:#173b2d;font-size:1.2rem;font-weight:800;margin:0}.category-detail-sub{color:#718078;font-size:.86rem}.category-metric{display:flex;align-items:center;gap:.65rem;padding:.5rem .8rem;border:1px solid rgba(8,127,91,.12);border-radius:10px;background:rgba(8,127,91,.03)}.category-metric strong{font-size:1.1rem;color:#173b2d}.category-metric span{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:#718078}.category-charts{margin-top:1rem}.chart-panel{background:#f8fbfa;border:1px solid #e5eeea;border-radius:12px;padding:14px;height:310px}.chart-panel h6{font-size:.72rem;font-weight:800;letter-spacing:.08em;color:#718078;text-transform:uppercase;margin-bottom:.75rem}.category-table-wrap{margin-top:1.25rem;border:1px solid #edf2ef;border-radius:12px;overflow:hidden}.globales-separadas~.row.g-4.mb-4.report-animated,.globales-separadas~section.analysis-card.mb-4.report-animated{display:none}
        @media(max-width:767px){.report-title{font-size:1.55rem}.report-chart{height:270px}.report-hero form{width:100%}.report-hero form>div{flex:1}.report-hero form button{width:100%}.report-hero{padding:20px}.report-kpi-value{font-size:1.25rem}.selector-box{min-width:100%}.chart-panel{height:260px}}
    </style>
</head>
<body>
    <div class="wrapper">
        <aside class="sidebar bg-dark text-white">
            <div class="sidebar-header">
                <h3><i class="bi bi-hospital"></i> CISMEDIC</h3>
                <p>Control de Egresos</p>
            </div>
            
            <?php $nav_egresos_abierto = true; ?>
            <nav class="navbar-nav mt-3">
                <a href="dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <div class="nav-section">
                    <a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>">
                        <i class="bi bi-receipt"></i> Egresos
                    </a>
                    <div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>">
                        <a href="entregas_personal.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_personal.php') ? 'active' : '' ?>">
                            <i class="bi bi-person-vcard"></i> Entregas a Personal
                        </a>
                        <a href="entregas_proveedores.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_proveedores.php') ? 'active' : '' ?>">
                            <i class="bi bi-shop"></i> Entregas a Proveedores
                        </a>
                    </div>
                </div>
                <a href="aprobacion.php" class="nav-link">
                    <i class="bi bi-check2-circle"></i> Aprobación
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?>
                <a href="cheques_aprobados.php" class="nav-link">
                    <i class="bi bi-bank"></i> Cheques Aprobados
                </a>
                <?php endif; ?>
                <a href="facturas.php" class="nav-link">
                    <i class="bi bi-file-earmark-text"></i> Transferencias
                </a>
                <a href="proveedores.php" class="nav-link">
                    <i class="bi bi-shop"></i> Proveedores
                </a>
                <a href="categorias.php" class="nav-link">
                    <i class="bi bi-tags"></i> Categorías
                </a>
                <a href="reportes.php" class="nav-link active">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="perfil.php" class="nav-link">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                </a>
                <a href="logout.php" class="nav-link text-danger">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </aside>
        
        <main class="main-content">
            <div class="topbar">
                <div class="topbar-brand">
                    <button class="btn-sidebar" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <span>Reportes y Análisis</span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <section class="report-hero mb-4"><div class="report-kicker">Análisis estructurado</div><div class="d-flex flex-wrap justify-content-between align-items-end gap-3"><div><h1 class="report-title mb-2">Egresos por categorías</h1><p class="mb-0 opacity-75">Visualiza el gasto por categoría global y profundiza hasta cada subcategoría registrada.</p></div><form method="GET" class="d-flex flex-wrap gap-2 align-items-end"><div><label class="small d-block mb-1 opacity-75" for="mes_categoria">Mes</label><select class="form-select form-select-sm" name="mes_categoria" id="mes_categoria"><option value="">Todo el año</option><?php foreach(['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'] as $numero_mes => $nombre_mes): ?><option value="<?= $numero_mes ?>" <?= $mes_categoria === $numero_mes ? 'selected' : '' ?>><?= $nombre_mes ?></option><?php endforeach; ?></select></div><div><label class="small d-block mb-1 opacity-75" for="ano_categoria">Año</label><select class="form-select form-select-sm" name="ano_categoria" id="ano_categoria"><?php for($ano = (int) date('Y'); $ano >= (int) date('Y') - 5; $ano--): ?><option value="<?= $ano ?>" <?= (int) $ano_categoria === $ano ? 'selected' : '' ?>><?= $ano ?></option><?php endfor; ?></select></div><button class="btn btn-light btn-sm" type="submit"><i class="bi bi-filter me-1"></i>Consultar</button></form></div></section>
                <?php $total_categorias = array_sum(array_column($categorias_globales_reporte, 'total')); $total_registros_categoria = array_sum(array_column($categorias_globales_reporte, 'cantidad')); ?>
                <div class="row g-3 mb-4 report-animated"><div class="col-sm-6 col-xl-3"><div class="report-kpi" style="--kpi-color:#087f5b"><div class="d-flex justify-content-between"><span class="report-kpi-label">Gasto clasificado</span><i class="bi bi-cash-stack report-kpi-icon"></i></div><div class="report-kpi-value">S/. <?= number_format($total_categorias, 2) ?></div><small class="text-muted">Para el período elegido</small></div></div><div class="col-sm-6 col-xl-3"><div class="report-kpi" style="--kpi-color:#2471a3"><div class="d-flex justify-content-between"><span class="report-kpi-label">Registros</span><i class="bi bi-receipt report-kpi-icon"></i></div><div class="report-kpi-value"><?= (int) $total_registros_categoria ?></div><small class="text-muted">Con categoría asignada</small></div></div><div class="col-sm-6 col-xl-3"><div class="report-kpi" style="--kpi-color:#d97706"><div class="d-flex justify-content-between"><span class="report-kpi-label">Subcategorías</span><i class="bi bi-diagram-3 report-kpi-icon"></i></div><div class="report-kpi-value"><?= count($categorias_sub_reporte) ?></div><small class="text-muted">Desglosadas en el período</small></div></div><div class="col-sm-6 col-xl-3"><div class="report-kpi" style="--kpi-color:#c2415d"><div class="d-flex justify-content-between"><span class="report-kpi-label">Promedio por registro</span><i class="bi bi-calculator report-kpi-icon"></i></div><div class="report-kpi-value">S/. <?= number_format($total_registros_categoria > 0 ? $total_categorias / $total_registros_categoria : 0, 2) ?></div><small class="text-muted">Monto medio clasificado</small></div></div></div>
                <section class="analysis-card mb-4 report-animated">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <div class="report-kicker">Resumen ejecutivo</div>
                            <h5 class="mb-1">Comparativo total de categorías globales</h5>
                            <div class="analysis-caption">Vista general con todas las categorías del período actual.</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="global-badge bg-success-subtle text-success"><?= count($categorias_globales_reporte) ?> categorías</span>
                            <div class="selector-box" style="min-width:190px; padding:.45rem .7rem;">
                                <label for="tipoResumenChart">Vista</label>
                                <select id="tipoResumenChart" class="form-select form-select-sm" aria-label="Tipo de gráfico del resumen">
                                    <option value="bar">Barras</option>
                                    <option value="doughnut">Torta</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="report-chart" style="height:360px; min-height:300px;"><canvas id="chartCategoriasResumen"></canvas></div>
                </section>
                <div id="estadoFiltroCategoria" class="filter-status d-none mb-3"><i class="bi bi-funnel me-1"></i>Mostrando detalle de <strong id="nombreFiltroCategoria"></strong><button type="button" id="limpiarFiltroCategoria" class="float-end">Ver todas</button></div>
                <div class="globales-separadas mb-4"><?php if (!$categorias_por_global): ?><section class="analysis-card text-center text-muted py-5"><i class="bi bi-bar-chart fs-2 d-block mb-2"></i>No hay estadísticas categorizadas para el período seleccionado.</section><?php else: ?><section class="analysis-card mb-4 report-animated"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div><div class="report-kicker">Categorías globales</div><h5 class="mb-1">Seleccione una categoría para analizar</h5></div><div class="selector-box"><label for="categoriaGlobalSelect">Categoría global</label><select id="categoriaGlobalSelect" class="form-select form-select-sm" aria-label="Categoría global"><option value="">Seleccione una categoría</option><?php foreach($categorias_por_global as $global): ?><option value="<?= htmlspecialchars($global['nombre']) ?>"><?= htmlspecialchars($global['nombre']) ?></option><?php endforeach; ?></select></div></div><div id="categoriaGlobalDetalle"></div></section><?php endif; ?></div>
                <div class="row g-4 mb-4 report-animated"><div class="col-xl-7"><section class="analysis-card"><div class="d-flex justify-content-between align-items-start mb-3"><div><h5 class="mb-1">Distribución por categoría global</h5><div class="analysis-caption">Haz clic en una barra para filtrar el detalle inferior.</div></div><i class="bi bi-bar-chart-line text-success fs-4"></i></div><div class="report-chart"><canvas id="chartCategoriasGlobales"></canvas></div></section></div><div class="col-xl-5"><section class="analysis-card"><div class="mb-3"><h5 class="mb-1">Participación del gasto</h5><div class="analysis-caption">Selecciona una sección para explorar esa categoría.</div></div><div class="report-chart"><canvas id="chartCategoriasDona"></canvas></div></section></div></div>
                <section class="analysis-card mb-4 report-animated"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h5 class="mb-1">Detalle por categoría global y subcategoría</h5><div class="analysis-caption">Cada categoría global reúne sus subcategorías y muestra el aporte por origen.</div></div><span class="global-badge bg-success-subtle text-success"><?= count($categorias_globales_reporte) ?> categorías globales</span></div><div class="table-responsive"><table id="tablaCategorias" class="table align-middle mb-0"><thead class="table-light"><tr><th>Categoría global</th><th>Subcategoría</th><th>Origen</th><th class="text-center">Registros</th><th class="text-end">Total</th></tr></thead><tbody><?php if (!$categorias_sub_reporte): ?><tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-bar-chart fs-2 d-block mb-2"></i>No hay egresos categorizados para el período seleccionado.</td></tr><?php else: foreach($categorias_sub_reporte as $subcategoria): ?><tr class="sub-row" data-categoria-global="<?= htmlspecialchars($subcategoria['global']) ?>"><td><span class="global-badge" style="background:<?= htmlspecialchars($subcategoria['global_color']) ?>22;color:<?= htmlspecialchars($subcategoria['global_color']) ?>"><?= htmlspecialchars($subcategoria['global']) ?></span></td><td class="sub-name"><i class="bi bi-tag me-1 text-muted"></i><?= htmlspecialchars($subcategoria['nombre']) ?></td><td><div class="origin-mini"><?php foreach($subcategoria['origenes'] as $origen => $datos): ?><div><span class="text-muted"><?= htmlspecialchars($origen) ?>:</span> <strong><?= (int) $datos['cantidad'] ?></strong> · S/. <?= number_format($datos['total'], 2) ?></div><?php endforeach; ?></div></td><td class="text-center"><?= (int) $subcategoria['cantidad'] ?></td><td class="text-end category-total">S/. <?= number_format($subcategoria['total'], 2) ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Egresos por Mes (<?= date('Y') ?>)</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Mes</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($egresos_por_mes as $mes): ?>
                                        <tr>
                                            <td><?= date('M/Y', strtotime($mes['mes'].'-01')) ?></td>
                                            <td><strong>S/. <?= number_format($mes['total'], 2) ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Top 10 Proveedores</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Proveedor</th>
                                            <th>Facturas</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($top_proveedores as $prov): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($prov['nombre']) ?></td>
                                            <td><?= $prov['cantidad'] ?></td>
                                            <td><strong>S/. <?= number_format($prov['total'], 2) ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        const categoriasGlobales = <?= json_encode(array_values($categorias_globales_reporte), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const categoriasSeparadas = <?= json_encode(array_values($categorias_por_global), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const paletteEjecutiva = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#22c55e', '#0ea5e9'];
        const coloresCategorias = categoriasGlobales.map((categoria, index) => categoria.color || paletteEjecutiva[index % paletteEjecutiva.length]);
        const formatoMonto = valor => 'S/. ' + Number(valor).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const formatoPorcentaje = valor => (Number(valor) * 100).toFixed(1) + '%';
        const palette = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#22c55e', '#0ea5e9'];

        const categoriaGlobalSelect = document.getElementById('categoriaGlobalSelect');
        const categoriaGlobalDetalle = document.getElementById('categoriaGlobalDetalle');

        function renderCategoriaSeleccionada(nombreCategoria) {
            if (!categoriaGlobalDetalle) return;
            const categoria = categoriasSeparadas.find(item => item.nombre === nombreCategoria) || categoriasSeparadas[0];
            if (!categoria) return;

            const totalCategoria = Number(categoria.total || 0);
            const subcategorias = [...(categoria.subcategorias || [])].sort((a, b) => Number(b.total || 0) - Number(a.total || 0));
            const totalSubcategorias = subcategorias.reduce((suma, subcategoria) => suma + Number(subcategoria.total || 0), 0) || 1;
            const colorBase = categoria.color || '#087f5b';
            const filas = subcategorias.map(subcategoria => {
                const totalSub = Number(subcategoria.total || 0);
                const origenes = Object.entries(subcategoria.origenes || {}).map(([origen, dato]) => `${origen}: ${Number(dato.cantidad || 0)} · ${formatoMonto(dato.total || 0)}`).join('<br>');
                return `
                    <tr>
                        <td class="sub-name">${subcategoria.nombre}</td>
                        <td><div class="origin-mini">${origenes || '<span class="text-muted">Sin origen</span>'}</div></td>
                        <td class="text-center">${Number(subcategoria.cantidad || 0)}</td>
                        <td class="text-end category-total">${formatoMonto(totalSub)}</td>
                    </tr>
                `;
            }).join('') || '<tr><td colspan="4" class="text-center text-muted py-4">Sin subcategorías registradas.</td></tr>';

            const tabla = `
                <div class="category-detail-shell" style="border-top: 4px solid ${colorBase}; overflow:hidden;">
                    <div class="category-detail-head" style="padding-bottom:14px; margin-bottom:14px; border-bottom:1px solid #edf2ef;">
                        <div style="min-width:0; flex:1;">
                            <div class="report-kicker" style="color:${colorBase};">Categoría global</div>
                            <h3 class="category-detail-title" style="margin-top:6px;">${categoria.nombre}</h3>
                            <div class="category-detail-sub" style="margin-top:5px;">Estadística exclusiva de esta categoría y su desglose por subcategoría.</div>
                        </div>
                        <div class="d-flex gap-3 flex-wrap" style="align-items:stretch;">
                            <div class="category-metric" style="background:#f7faf8; border:1px solid #e4ece8; border-radius:12px; padding:10px 12px; min-width:150px; display:flex; flex-direction:column; justify-content:center;">
                                <span style="font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; color:#718078; font-weight:700;">Registros</span>
                                <strong style="font-size:1.05rem; color:#173b2d; margin-top:5px;">${Number(categoria.cantidad || 0)}</strong>
                            </div>
                            <div class="category-metric" style="background:#f7faf8; border:1px solid #e4ece8; border-radius:12px; padding:10px 12px; min-width:170px; display:flex; flex-direction:column; justify-content:center;">
                                <span style="font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; color:#718078; font-weight:700;">Total</span>
                                <strong style="font-size:1.05rem; color:#173b2d; margin-top:5px;">${formatoMonto(totalCategoria)}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="row category-charts g-3" style="align-items:stretch;">
                        <div class="col-xl-5">
                            <div class="chart-panel" style="background:#f9fbfa; border:1px solid #e8efec; border-radius:14px; padding:14px 14px 10px; height:100%; display:flex; flex-direction:column;">
                                <h6 style="margin:0 0 12px; font-size:.75rem; letter-spacing:.12em; text-transform:uppercase; color:#4e645d; font-weight:800;">Participación por subcategoría</h6>
                                <div style="position:relative; flex:1; min-height:380px; height:420px;"><canvas id="chartCategoriaGlobalDona"></canvas></div>
                            </div>
                        </div>
                        <div class="col-xl-7">
                            <div class="chart-panel" style="background:#f9fbfa; border:1px solid #e8efec; border-radius:14px; padding:14px 14px 10px; height:100%; display:flex; flex-direction:column;">
                                <h6 style="margin:0 0 12px; font-size:.75rem; letter-spacing:.12em; text-transform:uppercase; color:#4e645d; font-weight:800;">Consumo por subcategoría</h6>
                                <div style="position:relative; flex:1; min-height:380px; height:420px;"><canvas id="chartCategoriaGlobalBar"></canvas></div>
                            </div>
                        </div>
                    </div>

                    <div class="category-table-wrap mt-3" style="overflow:hidden; border-radius:10px; border:1px solid #edf2ef;">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 global-detail-table">
                                <thead>
                                    <tr>
                                        <th>Subcategoría</th>
                                        <th>Origen</th>
                                        <th class="text-center">Registros</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>${filas}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;

            categoriaGlobalDetalle.innerHTML = tabla;

            if (window.chartCategoriaGlobalDona && typeof window.chartCategoriaGlobalDona.destroy === 'function') {
                window.chartCategoriaGlobalDona.destroy();
            }
            if (window.chartCategoriaGlobalBar && typeof window.chartCategoriaGlobalBar.destroy === 'function') {
                window.chartCategoriaGlobalBar.destroy();
            }

            const labels = subcategorias.map(item => item.nombre);
            const datos = subcategorias.map(item => Number(item.total || 0));
            const colores = subcategorias.map((_, indice) => palette[indice % palette.length]);

            window.chartCategoriaGlobalDona = new Chart(document.getElementById('chartCategoriaGlobalDona'), {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: datos,
                        backgroundColor: colores,
                        borderColor: '#fff',
                        borderWidth: 4,
                        hoverOffset: 18,
                        offset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    layout: { padding: { top: 8, right: 8, bottom: 4, left: 8 } },
                    animation: { duration: 1200, easing: 'easeOutQuart' },
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10, padding: 14, color: '#49645d', font: { weight: '600', size: 11 } } },
                        tooltip: {
                            backgroundColor: 'rgba(23,59,45,0.96)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#dfe9e5',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                label: contexto => ` ${contexto.label}: ${formatoMonto(contexto.raw)}`,
                                afterLabel: contexto => ` ${formatoPorcentaje(Number(contexto.raw) / totalSubcategorias)} del total`
                            }
                        }
                    }
                }
            });

            window.chartCategoriaGlobalBar = new Chart(document.getElementById('chartCategoriaGlobalBar'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Monto',
                        data: datos,
                        backgroundColor: colores,
                        borderRadius: 8,
                        borderSkipped: false,
                        maxBarThickness: 36,
                        hoverBackgroundColor: colores.map(color => color + 'CC')
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    layout: { padding: { top: 8, right: 12, bottom: 8, left: 12 } },
                    animation: { duration: 1100, easing: 'easeOutQuart' },
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(23,59,45,0.96)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#dfe9e5',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: { label: contexto => ` ${formatoMonto(contexto.raw)}` }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(23,59,45,.08)' },
                            ticks: { callback: valor => 'S/. ' + Number(valor).toLocaleString('es-PE'), font: { size: 11 } }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11 },
                                padding: 8,
                                autoSkip: false,
                                maxRotation: 0,
                                minRotation: 0
                            }
                        }
                    }
                }
            });
        }

        if (categoriaGlobalSelect && categoriasSeparadas.length) {
            categoriaGlobalSelect.innerHTML = '<option value="">Seleccione una categoría</option>' + categoriasSeparadas.map(categoria => `<option value="${categoria.nombre}">${categoria.nombre}</option>`).join('');
            categoriaGlobalSelect.addEventListener('change', (event) => {
                renderCategoriaSeleccionada(event.target.value);
            });
            categoriaGlobalSelect.value = categoriasSeparadas[0].nombre;
            renderCategoriaSeleccionada(categoriasSeparadas[0].nombre);
        }

        const graficoGlobal = document.getElementById('chartCategoriasGlobales');
        const graficoResumen = document.getElementById('chartCategoriasResumen');
        const selectorResumenChart = document.getElementById('tipoResumenChart');
        const totalGraficos = categoriasGlobales.reduce((acumulado, categoria) => acumulado + Number(categoria.total), 0);
        let chartCategoriasResumen = null;
        const mostrarCategoria = function (nombre) { document.querySelectorAll('#tablaCategorias tbody .sub-row').forEach(fila => { fila.classList.toggle('d-none', fila.dataset.categoriaGlobal !== nombre); }); document.getElementById('nombreFiltroCategoria').textContent = nombre; document.getElementById('estadoFiltroCategoria').classList.remove('d-none'); };
        const limpiarCategoria = function () { document.querySelectorAll('#tablaCategorias tbody .sub-row').forEach(fila => fila.classList.remove('d-none')); document.getElementById('estadoFiltroCategoria').classList.add('d-none'); };

        function renderResumenEjecutivo(tipo = 'bar') {
            if (!graficoResumen) return;
            if (chartCategoriasResumen && typeof chartCategoriasResumen.destroy === 'function') {
                chartCategoriasResumen.destroy();
            }

            const configBase = {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 1200, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: 'rgba(255,255,255,0.08)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: contexto => ' S/. ' + Number(contexto.raw).toLocaleString('es-PE', { minimumFractionDigits: 2 }),
                            afterLabel: contexto => ' ' + ((Number(contexto.raw) / totalGraficos) * 100).toFixed(1) + '% del total'
                        }
                    }
                }
            };

            if (tipo === 'doughnut') {
                chartCategoriasResumen = new window.Chart(graficoResumen, {
                    type: 'doughnut',
                    data: {
                        labels: categoriasGlobales.map(categoria => categoria.nombre),
                        datasets: [{
                            data: categoriasGlobales.map(categoria => categoria.total),
                            backgroundColor: coloresCategorias,
                            borderColor: '#ffffff',
                            borderWidth: 4,
                            hoverOffset: 24,
                            hoverBorderColor: '#ffffff',
                            offset: 6
                        }]
                    },
                    options: {
                        ...configBase,
                        cutout: '56%',
                        onClick: (evento, elementos) => {
                            if (elementos.length) {
                                const nombre = categoriasGlobales[elementos[0].index].nombre;
                                if (categoriaGlobalSelect) categoriaGlobalSelect.value = nombre;
                                mostrarCategoria(nombre);
                            }
                        },
                        plugins: {
                            ...configBase.plugins,
                            legend: {
                                position: 'bottom',
                                labels: { usePointStyle: true, boxWidth: 12, padding: 14, color: '#49645d', font: { weight: '700', size: 11 } }
                            }
                        }
                    }
                });
                return;
            }

            chartCategoriasResumen = new window.Chart(graficoResumen, {
                type: 'bar',
                data: {
                    labels: categoriasGlobales.map(categoria => categoria.nombre),
                    datasets: [{
                        label: 'Monto total',
                        data: categoriasGlobales.map(categoria => categoria.total),
                        backgroundColor: coloresCategorias.map(color => color + 'E6'),
                        borderColor: coloresCategorias,
                        borderWidth: 2,
                        borderRadius: 10,
                        borderSkipped: false,
                        maxBarThickness: 46,
                        hoverBackgroundColor: coloresCategorias.map(color => color + 'CC'),
                        hoverBorderColor: '#ffffff'
                    }]
                },
                options: {
                    ...configBase,
                    onClick: (evento, elementos) => {
                        if (elementos.length) {
                            const nombre = categoriasGlobales[elementos[0].index].nombre;
                            if (categoriaGlobalSelect) categoriaGlobalSelect.value = nombre;
                            mostrarCategoria(nombre);
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(23,59,45,.08)' },
                            ticks: { callback: valor => 'S/. ' + Number(valor).toLocaleString('es-PE') }
                        },
                        y: {
                            grid: { display: false },
                            ticks: { autoSkip: false, maxRotation: 0, minRotation: 0 }
                        }
                    }
                }
            });
        }

        if (selectorResumenChart) {
            selectorResumenChart.addEventListener('change', (event) => {
                renderResumenEjecutivo(event.target.value);
            });
        }

        renderResumenEjecutivo(selectorResumenChart ? selectorResumenChart.value : 'bar');
        if (graficoGlobal) new window.Chart(graficoGlobal, { type: 'bar', data: { labels: categoriasGlobales.map(categoria => categoria.nombre), datasets: [{ label: 'Monto', data: categoriasGlobales.map(categoria => categoria.total), backgroundColor: coloresCategorias, borderRadius: 7, borderSkipped: false, maxBarThickness: 42 }] }, options: { responsive: true, maintainAspectRatio: false, onClick: (evento, elementos) => { if (elementos.length) mostrarCategoria(categoriasGlobales[elementos[0].index].nombre); }, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: contexto => ' S/. ' + Number(contexto.raw).toLocaleString('es-PE', { minimumFractionDigits: 2 }), afterLabel: contexto => ' ' + ((Number(contexto.raw) / totalGraficos) * 100).toFixed(1) + '% del total' } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(23,59,45,.08)' }, ticks: { callback: valor => 'S/. ' + Number(valor).toLocaleString('es-PE') } }, x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, padding: 8, callback: etiqueta => String(etiqueta).split(' ') } } } } });
        const graficoDona = document.getElementById('chartCategoriasDona');
        if (graficoDona) new window.Chart(graficoDona, { type: 'doughnut', data: { labels: categoriasGlobales.map(categoria => categoria.nombre), datasets: [{ data: categoriasGlobales.map(categoria => categoria.total), backgroundColor: coloresCategorias, borderColor: '#fff', borderWidth: 4, hoverOffset: 12 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '66%', onClick: (evento, elementos) => { if (elementos.length) mostrarCategoria(categoriasGlobales[elementos[0].index].nombre); }, animation: { animateRotate: true, duration: 1300 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 9 } }, tooltip: { callbacks: { label: contexto => ' S/. ' + Number(contexto.raw).toLocaleString('es-PE', { minimumFractionDigits: 2 }), afterLabel: contexto => ' ' + ((Number(contexto.raw) / totalGraficos) * 100).toFixed(1) + '% del total' } } } } });
        document.getElementById('limpiarFiltroCategoria')?.addEventListener('click', limpiarCategoria);
        document.querySelectorAll('.report-animated').forEach((elemento, indice) => { elemento.style.animationDelay = (indice * 90) + 'ms'; });
    </script>
</body>
</html>
