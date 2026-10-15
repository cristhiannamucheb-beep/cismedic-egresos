<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

// Verificar si está autenticado
if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

requerir_roles(['superadmin', 'administrativo']);

require_once 'config/database.php';

$mes_transferencia = $_GET['mes_transferencia'] ?? date('m');
$ano_transferencia = $_GET['ano_transferencia'] ?? date('Y');
$transferencias_mes = ['cantidad' => 0, 'total' => 0];
$transferencias_anuales = ['cantidad' => 0, 'total' => 0];
$evolucion_transferencias = [];

// Obtener estadísticas unificadas
try {
    // Total de egresos de todas las fuentes
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM entregas_dinero) +
            (SELECT COUNT(*) FROM entregas_proveedores) as total
    ");
    $total_egresos = $stmt->fetch()['total'];
    
    // Suma total de egresos este mes (desde todas las fuentes)
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total), 0) as suma FROM (
            SELECT SUM(monto) as total FROM entregas_dinero 
            WHERE MONTH(fecha_entrega) = MONTH(NOW()) AND YEAR(fecha_entrega) = YEAR(NOW())
            
            UNION ALL
            
            SELECT SUM(monto) FROM entregas_proveedores 
            WHERE MONTH(fecha_entrega) = MONTH(NOW()) AND YEAR(fecha_entrega) = YEAR(NOW())
            
        ) as egresos_mes
    ");
    $egresos_mes = $stmt->fetch()['suma'];
    
    // Últimos egresos de todas las fuentes
    $stmt = $pdo->query("
        SELECT 
            ed.id_entrega as id,
            ed.fecha_entrega as fecha,
            ed.monto as total,
            CONCAT(pc.apellido, ', ', pc.nombre) as concepto,
            'Entrega a Personal' as tipo,
            ed.nComprobante as comprobante
        FROM entregas_dinero ed
        LEFT JOIN personal_clinica pc ON ed.id_personal = pc.id_personal
        
        UNION ALL
        
        SELECT 
            ep.id_entrega_proveedor,
            ep.fecha_entrega,
            ep.monto,
            prov.nombre,
            'Compra a Proveedor',
            ep.nComprobante
        FROM entregas_proveedores ep
        LEFT JOIN proveedores prov ON ep.id_proveedor = prov.id
        
        ORDER BY fecha DESC
        LIMIT 5
    ");
    $ultimos_egresos = $stmt->fetchAll();
    
    // Resumen por tipo de egreso este mes
    $stmt = $pdo->query("SELECT 'Entregas a Personal' AS tipo, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS total FROM entregas_dinero WHERE MONTH(fecha_entrega) = MONTH(NOW()) AND YEAR(fecha_entrega) = YEAR(NOW()) UNION ALL SELECT 'Compras a Proveedores', COUNT(*), COALESCE(SUM(monto), 0) FROM entregas_proveedores WHERE MONTH(fecha_entrega) = MONTH(NOW()) AND YEAR(fecha_entrega) = YEAR(NOW())");
    $resumen_por_tipo = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total FROM facturas WHERE MONTH(fecha) = ? AND YEAR(fecha) = ?");
    $stmt->execute([(int) $mes_transferencia, (int) $ano_transferencia]);
    $transferencias_mes = $stmt->fetch() ?: ['cantidad' => 0, 'total' => 0];
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total FROM facturas WHERE YEAR(fecha) = ?");
    $stmt->execute([(int) $ano_transferencia]);
    $transferencias_anuales = $stmt->fetch() ?: ['cantidad' => 0, 'total' => 0];
    $stmt = $pdo->prepare("SELECT MONTH(fecha) AS mes, COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total FROM facturas WHERE YEAR(fecha) = ? GROUP BY MONTH(fecha) ORDER BY mes");
    $stmt->execute([(int) $ano_transferencia]);
    $evolucion_transferencias = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') AS periodo, DATE_FORMAT(fecha, '%b %Y') AS etiqueta, SUM(total) AS total, COUNT(*) AS cantidad FROM (SELECT fecha_entrega AS fecha, monto AS total FROM entregas_dinero UNION ALL SELECT fecha_entrega, monto FROM entregas_proveedores) AS movimientos WHERE fecha >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY periodo, etiqueta ORDER BY periodo");
    $evolucion_mensual = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT estado, COUNT(*) AS cantidad, SUM(total) AS total FROM (SELECT COALESCE(estado_aprobacion, 'pendiente') AS estado, monto AS total FROM entregas_dinero UNION ALL SELECT COALESCE(estado_aprobacion, 'pendiente'), monto FROM entregas_proveedores) AS estados GROUP BY estado ORDER BY FIELD(estado, 'pendiente', 'en_aprobacion', 'aprobado')");
    $resumen_estados = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT SUM(pendientes) AS pendientes, SUM(en_aprobacion) AS en_aprobacion, SUM(aprobados) AS aprobados FROM (SELECT SUM(estado_aprobacion = 'pendiente') AS pendientes, SUM(estado_aprobacion = 'en_aprobacion') AS en_aprobacion, SUM(estado_aprobacion = 'aprobado') AS aprobados FROM entregas_dinero WHERE MONTH(fecha_entrega) = MONTH(CURDATE()) AND YEAR(fecha_entrega) = YEAR(CURDATE()) UNION ALL SELECT SUM(estado_aprobacion = 'pendiente'), SUM(estado_aprobacion = 'en_aprobacion'), SUM(estado_aprobacion = 'aprobado') FROM entregas_proveedores WHERE MONTH(fecha_entrega) = MONTH(CURDATE()) AND YEAR(fecha_entrega) = YEAR(CURDATE())) AS indicadores");
    $indicadores_operativos = $stmt->fetch() ?: ['pendientes' => 0, 'en_aprobacion' => 0, 'aprobados' => 0];
    
} catch(Exception $e) {
    $error = "Error al cargar estadísticas: " . $e->getMessage();
    $total_egresos = 0;
    $egresos_mes = 0;
    $ultimos_egresos = [];
    $resumen_por_tipo = [];
    $evolucion_mensual = [];
    $resumen_estados = [];
    $indicadores_operativos = ['pendientes' => 0, 'en_aprobacion' => 0, 'aprobados' => 0];
    $transferencias_mes = ['cantidad' => 0, 'total' => 0];
    $transferencias_anuales = ['cantidad' => 0, 'total' => 0];
    $evolucion_transferencias = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Dashboard - Sistema de Egresos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-shell{background:linear-gradient(145deg,#f7fbfa 0%,#eef4f7 100%);border:1px solid #dce8e5;border-radius:18px;padding:26px;position:relative;overflow:hidden}
        .dashboard-shell:after{content:"";position:absolute;width:280px;height:280px;border:1px solid rgba(8,127,91,.12);border-radius:50%;right:-90px;top:-150px}
        .dashboard-kicker{color:#087f5b;font-size:.72rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.dashboard-title{color:#173b2d;font-size:clamp(1.7rem,3vw,2.5rem);font-weight:800;letter-spacing:0}.dashboard-subtitle{color:#667085;max-width:650px}
        .executive-card{background:#fff;border:1px solid #e1ebe7;border-radius:14px;box-shadow:0 8px 24px rgba(23,59,45,.06);height:100%;overflow:hidden;position:relative;transition:transform .25s ease,box-shadow .25s ease}.executive-card:hover{box-shadow:0 14px 30px rgba(23,59,45,.12);transform:translateY(-3px)}.executive-card .card-body{padding:20px}.metric-label{color:#718078;font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.metric-value{color:#173b2d;font-size:1.65rem;font-weight:800;margin:.35rem 0}.metric-icon{align-items:center;background:#e7f5ef;border-radius:12px;color:#087f5b;display:flex;font-size:1.25rem;height:42px;justify-content:center;width:42px}.chart-card{background:#fff;border:1px solid #e1ebe7;border-radius:14px;box-shadow:0 8px 24px rgba(23,59,45,.06);height:100%;padding:20px}.chart-heading{color:#173b2d;font-size:1rem;font-weight:800;margin:0}.chart-caption{color:#84918b;font-size:.78rem}.chart-wrap{height:285px;position:relative}.status-pill{border-radius:999px;font-size:.72rem;font-weight:700;padding:.38rem .7rem}.status-pendiente{background:#fff4d6;color:#956b00}.status-aprobacion{background:#e7f0ff;color:#365fa8}.status-aprobado{background:#e5f6ed;color:#087f5b}
        @keyframes dashboardRise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:767px){.dashboard-shell{padding:20px}.chart-wrap{height:235px}.dashboard-shell .btn{width:100%}}
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <aside class="sidebar bg-dark text-white">
            <div class="sidebar-header">
                <h3><i class="bi bi-hospital"></i> CISMEDIC</h3>
                <p>Control de Egresos</p>
            </div>
            
            <?php $nav_egresos_abierto = true; ?>
            <nav class="navbar-nav mt-3">
                <a href="dashboard.php" class="nav-link active">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <div class="nav-section">
                    <a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>">
                        <i class="bi bi-receipt"></i> Egresos
                    </a>
                    <div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>">
                        <a href="almacenamiento_egresos.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'almacenamiento_egresos.php') ? 'active' : '' ?>">
                            <i class="bi bi-inboxes"></i> Preaprobación
                        </a>
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
                <a href="reportes.php" class="nav-link">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
                <a href="superadmin.php" class="nav-link">
                    <i class="bi bi-shield-lock"></i> Panel de seguridad
                </a>
                <?php endif; ?>
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
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="topbar">
                <div class="topbar-brand">
                    <button class="btn-sidebar" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <span>Dashboard</span>
                </div>
                <div class="topbar-user">
                    <span class="me-3"><?= date('d/m/Y h:i a') ?></span>
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['usuario_nombre']) ?>&background=667eea&color=fff" alt="Avatar" class="avatar">
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <section class="dashboard-shell mb-4 dashboard-reveal">
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 position-relative" style="z-index:1">
                        <div><div class="dashboard-kicker">Centro de control financiero</div><h1 class="dashboard-title mb-2">Visión general de egresos</h1><p class="dashboard-subtitle mb-0">Monitorea el comportamiento del gasto, el flujo de aprobación y la actividad reciente de CISMEDIC.</p></div>
                        <a href="reportes.php" class="btn btn-success"><i class="bi bi-file-earmark-bar-graph me-2"></i>Ver reportes</a>
                    </div>
                </section>
                <div class="row g-3 mb-4 dashboard-reveal">
                    <div class="col-sm-6 col-xl-3"><div class="executive-card"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="metric-label">Gasto del mes</div><div class="metric-value">S/. <?= number_format($egresos_mes, 2) ?></div><small class="text-muted">Acumulado vigente</small></div><div class="metric-icon"><i class="bi bi-wallet2"></i></div></div></div></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="executive-card"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="metric-label">Pendientes</div><div class="metric-value"><?= (int) $indicadores_operativos['pendientes'] ?></div><small class="text-muted">Requieren atención</small></div><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div></div></div></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="executive-card"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="metric-label">En aprobación</div><div class="metric-value"><?= (int) $indicadores_operativos['en_aprobacion'] ?></div><small class="text-muted">En proceso de revisión</small></div><div class="metric-icon"><i class="bi bi-arrow-repeat"></i></div></div></div></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="executive-card"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="metric-label">Registros totales</div><div class="metric-value"><?= (int) $total_egresos ?></div><small class="text-muted">Histórico del sistema</small></div><div class="metric-icon"><i class="bi bi-stack"></i></div></div></div></div></div>
                </div>
                <div class="row g-4 mb-4 dashboard-reveal">
                    <div class="col-xl-8"><section class="chart-card"><div class="d-flex justify-content-between align-items-start mb-3"><div><h2 class="chart-heading">Evolución del gasto</h2><div class="chart-caption">Monto acumulado y volumen de registros por mes</div></div><span class="status-pill status-aprobado"><i class="bi bi-graph-up-arrow me-1"></i>Últimos 12 meses</span></div><div class="chart-wrap"><canvas id="chartEvolucion"></canvas></div></section></div>
                    <div class="col-xl-4"><section class="chart-card"><div class="mb-3"><h2 class="chart-heading">Distribución por origen</h2><div class="chart-caption">Participación del gasto durante el mes</div></div><div class="chart-wrap"><canvas id="chartOrigen"></canvas></div></section></div>
                </div>
                <div class="row g-4 mb-4 dashboard-reveal">
                    <div class="col-xl-7"><section class="chart-card"><div class="mb-3"><h2 class="chart-heading">Flujo de aprobación</h2><div class="chart-caption">Estado actual de todos los egresos registrados</div></div><div class="chart-wrap"><canvas id="chartEstados"></canvas></div></section></div>
                    <div class="col-xl-5"><section class="chart-card"><div class="d-flex justify-content-between align-items-start mb-3"><div><h2 class="chart-heading">Lectura rápida</h2><div class="chart-caption">Indicadores para priorizar la gestión</div></div><i class="bi bi-activity text-success fs-4"></i></div><div class="d-flex justify-content-between align-items-center border-bottom py-3"><span class="text-muted">Promedio diario</span><strong class="text-dark">S/. <?= number_format($egresos_mes / max(date('d'), 1), 2) ?></strong></div><div class="d-flex justify-content-between align-items-center border-bottom py-3"><span class="text-muted">Aprobados del mes</span><strong class="text-success"><?= (int) $indicadores_operativos['aprobados'] ?></strong></div><div class="d-flex justify-content-between align-items-center py-3"><span class="text-muted">Tipos de movimiento</span><strong class="text-dark"><?= count($resumen_por_tipo) ?></strong></div><a href="egresos.php" class="btn btn-outline-success w-100 mt-2"><i class="bi bi-search me-2"></i>Explorar egresos</a></section></div>
                </div>
                <!-- Tarjetas de Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">Total Egresos</h6>
                                <h3 class="card-value">S/. <?= number_format($egresos_mes, 2) ?></h3>
                                <p class="card-subtitle">Este mes</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">Total Registros</h6>
                                <h3 class="card-value"><?= $total_egresos ?></h3>
                                <p class="card-subtitle">Egresos registrados</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">Promedio Diario</h6>
                                <h3 class="card-value">S/. <?= number_format($egresos_mes / max(date('d'), 1), 2) ?></h3>
                                <p class="card-subtitle">Este mes</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">Estado</h6>
                                <h3 class="card-value" style="color: #28a745;">Activo</h3>
                                <p class="card-subtitle">Sistema funcionando</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Resumen por Tipo de Egreso -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Resumen de Egresos por Tipo (Este Mes)</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Tipo de Egreso</th>
                                            <th>Cantidad</th>
                                            <th>Total</th>
                                            <th>Porcentaje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $suma_total = array_sum(array_column($resumen_por_tipo, 'total'));
                                        foreach($resumen_por_tipo as $fila): 
                                            $porcentaje = $suma_total > 0 ? ($fila['total'] / $suma_total) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($fila['tipo']) ?></strong></td>
                                            <td><?= $fila['cantidad'] ?> registros</td>
                                            <td><strong>S/. <?= number_format($fila['total'], 2) ?></strong></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" role="progressbar" style="width: <?= $porcentaje ?>%">
                                                        <?= number_format($porcentaje, 1) ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <section class="chart-card mb-4 dashboard-reveal">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div><div class="dashboard-kicker">Reporte independiente</div><h2 class="chart-heading mt-1">Estadística de transferencias</h2><div class="chart-caption">Consulta el comportamiento mensual y anual sin mezclarlo con entregas de dinero.</div></div>
                        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end"><div><label class="metric-label d-block mb-1" for="mes_transferencia">Mes</label><select class="form-select form-select-sm" name="mes_transferencia" id="mes_transferencia"><?php foreach(['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'] as $numero_mes => $nombre_mes): ?><option value="<?= $numero_mes ?>" <?= $mes_transferencia === $numero_mes ? 'selected' : '' ?>><?= $nombre_mes ?></option><?php endforeach; ?></select></div><div><label class="metric-label d-block mb-1" for="ano_transferencia">Año</label><select class="form-select form-select-sm" name="ano_transferencia" id="ano_transferencia"><?php for($ano = (int) date('Y'); $ano >= (int) date('Y') - 5; $ano--): ?><option value="<?= $ano ?>" <?= (int) $ano_transferencia === $ano ? 'selected' : '' ?>><?= $ano ?></option><?php endfor; ?></select></div><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-filter me-1"></i>Consultar</button></form>
                    </div>
                    <div class="row g-3 align-items-stretch"><div class="col-md-4"><div class="executive-card h-100" style="background:#f7fbff;border-color:#d8e6f5"><div class="card-body"><div class="metric-label">Mes seleccionado</div><div class="metric-value" style="color:#2471a3">S/. <?= number_format($transferencias_mes['total'], 2) ?></div><small class="text-muted"><?= (int) $transferencias_mes['cantidad'] ?> operaciones en <?= htmlspecialchars(['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'][$mes_transferencia] ?? $mes_transferencia) ?></small></div></div></div><div class="col-md-4"><div class="executive-card h-100"><div class="card-body"><div class="metric-label">Acumulado anual</div><div class="metric-value">S/. <?= number_format($transferencias_anuales['total'], 2) ?></div><small class="text-muted"><?= (int) $transferencias_anuales['cantidad'] ?> operaciones durante <?= (int) $ano_transferencia ?></small></div></div></div><div class="col-md-4"><div class="executive-card h-100"><div class="card-body"><div class="metric-label">Promedio por transferencia</div><div class="metric-value" style="color:#d97706">S/. <?= number_format((float) $transferencias_anuales['cantidad'] > 0 ? $transferencias_anuales['total'] / $transferencias_anuales['cantidad'] : 0, 2) ?></div><small class="text-muted">Calculado sobre el año seleccionado</small></div></div></div></div>
                    <div class="chart-wrap mt-4"><canvas id="chartTransferencias"></canvas></div>
                </section>
                <!-- Gráficos y Tablas -->
                <div class="row">
                    <div class="col-md-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Últimos Egresos</h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Comprobante</th>
                                            <th>Concepto/Beneficiario</th>
                                            <th>Total</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(!empty($ultimos_egresos)): ?>
                                            <?php foreach($ultimos_egresos as $egreso): ?>
                                            <tr>
                                                <td><?= date('d/m/Y', strtotime($egreso['fecha'])) ?></td>
                                                <td><small class="badge bg-info"><?= htmlspecialchars($egreso['tipo']) ?></small></td>
                                                <td><code><?= htmlspecialchars($egreso['comprobante']) ?></code></td>
                                                <td><?= htmlspecialchars($egreso['concepto'] ?? 'N/A') ?></td>
                                                <td><strong>S/. <?= number_format($egreso['total'], 2) ?></strong></td>
                                                <td>
                                                    <a href="egresos.php" class="btn btn-sm btn-outline-primary">Ver</a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">Sin egresos registrados</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Acciones Rápidas</h5>
                            </div>
                            <div class="card-body">
                                <a href="egresos.php?nuevo=1" class="btn btn-primary w-100 mb-2">
                                    <i class="bi bi-plus-circle"></i> Nuevo Egreso
                                </a>
                                <a href="facturas.php?nuevo=1" class="btn btn-success w-100 mb-2">
                                    <i class="bi bi-bank"></i> Nueva Transferencia
                                </a>
                                <a href="proveedores.php?nuevo=1" class="btn btn-info w-100 mb-2">
                                    <i class="bi bi-person-plus"></i> Nuevo Proveedor
                                </a>
                                <a href="reportes.php" class="btn btn-warning w-100">
                                    <i class="bi bi-file-pdf"></i> Generar Reporte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        const evolucionMensual = <?= json_encode($evolucion_mensual, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const resumenTipos = <?= json_encode($resumen_por_tipo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const resumenEstados = <?= json_encode($resumen_estados, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const paleta = ['#087f5b', '#2471a3', '#d97706', '#c2415d', '#6b7280'];
        window.Chart.defaults.font.family = "'Segoe UI', sans-serif";
        window.Chart.defaults.color = '#718078';
        const tooltipMoneda = { callbacks: { label: context => ' S/. ' + Number(context.raw || 0).toLocaleString('es-PE', { minimumFractionDigits: 2 }) } };
        const chartEvolucion = document.getElementById('chartEvolucion');
        if (chartEvolucion) new window.Chart(chartEvolucion, { type: 'line', data: { labels: evolucionMensual.map(item => item.etiqueta), datasets: [{ label: 'Monto', data: evolucionMensual.map(item => Number(item.total)), borderColor: '#087f5b', backgroundColor: 'rgba(8,127,91,.12)', fill: true, tension: .38, pointRadius: 4, pointHoverRadius: 7, pointBackgroundColor: '#fff', pointBorderWidth: 2 }, { label: 'Registros', data: evolucionMensual.map(item => Number(item.cantidad)), borderColor: '#d97706', backgroundColor: 'transparent', yAxisID: 'y1', tension: .38, pointRadius: 3, pointHoverRadius: 6, borderDash: [5, 5] }] }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }, tooltip: { callbacks: { label: context => context.datasetIndex === 0 ? ' S/. ' + Number(context.raw).toLocaleString('es-PE', { minimumFractionDigits: 2 }) : ' ' + context.raw + ' registros' } } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(23,59,45,.08)' }, ticks: { callback: value => 'S/. ' + Number(value).toLocaleString('es-PE') } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }, x: { grid: { display: false } } } } });
        const chartOrigen = document.getElementById('chartOrigen');
        if (chartOrigen) new window.Chart(chartOrigen, { type: 'doughnut', data: { labels: resumenTipos.map(item => item.tipo), datasets: [{ data: resumenTipos.map(item => Number(item.total)), backgroundColor: paleta, borderColor: '#fff', borderWidth: 4, hoverOffset: 8 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '68%', animation: { animateRotate: true, duration: 1300 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, boxWidth: 9 } }, tooltip: tooltipMoneda } } });
        const chartEstados = document.getElementById('chartEstados');
        if (chartEstados) new window.Chart(chartEstados, { type: 'bar', data: { labels: resumenEstados.map(item => String(item.estado).replace('_', ' ').replace(/\b\w/g, letra => letra.toUpperCase())), datasets: [{ label: 'Registros', data: resumenEstados.map(item => Number(item.cantidad)), backgroundColor: ['#f3c969', '#8eb4e3', '#72c49b'], borderRadius: 7, borderSkipped: false, barThickness: 30 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 1100, easing: 'easeOutQuart' }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => ' ' + context.raw + ' registros' } } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(23,59,45,.08)' } }, y: { grid: { display: false } } } } });
        const mesesTransferencia = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        const datosTransferencia = Array.from({ length: 12 }, (_, indice) => { const registro = <?= json_encode($evolucion_transferencias, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>.find(item => Number(item.mes) === indice + 1); return registro ? Number(registro.total) : 0; });
        const chartTransferencias = document.getElementById('chartTransferencias');
        if (chartTransferencias) new window.Chart(chartTransferencias, { type: 'bar', data: { labels: mesesTransferencia, datasets: [{ label: 'Transferencias', data: datosTransferencia, backgroundColor: 'rgba(36,113,163,.78)', borderColor: '#2471a3', borderWidth: 1, borderRadius: 6, maxBarThickness: 38 }] }, options: { responsive: true, maintainAspectRatio: false, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { display: false }, tooltip: tooltipMoneda }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(36,113,163,.1)' }, ticks: { callback: value => 'S/. ' + Number(value).toLocaleString('es-PE') } }, x: { grid: { display: false } } } } });
        document.querySelectorAll('.dashboard-reveal').forEach((element, index) => { element.style.animation = 'dashboardRise .65s ease both'; element.style.animationDelay = (index * 90) + 'ms'; });
    </script>
</body>
</html>
