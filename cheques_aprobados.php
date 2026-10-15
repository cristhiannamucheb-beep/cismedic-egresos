<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

requerir_roles(['superadmin', 'administrativo']);
require_once 'config/database.php';

$cheques = [];
$total_aprobado = 0.0;

try {
    $sql = "
        SELECT 'Personal' AS tipo, ed.id_entrega AS id_registro, CONCAT(COALESCE(pc.nombre, ''), ' ', COALESCE(pc.apellido, '')) AS destinatario,
               ed.nComprobante AS numero, ed.fecha_entrega AS fecha, ed.monto AS monto,
               COALESCE(ed.descripcion, 'Sin descripción') AS descripcion
        FROM entregas_dinero ed
        LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal
        WHERE ed.estado_aprobacion = 'aprobado'

        UNION ALL

        SELECT 'Proveedor' AS tipo, ep.id_entrega_proveedor AS id_registro, p.nombre AS destinatario,
               ep.nComprobante AS numero, ep.fecha_entrega AS fecha, ep.monto AS monto,
               COALESCE(ep.descripcion, 'Sin descripción') AS descripcion
        FROM entregas_proveedores ep
        LEFT JOIN proveedores p ON p.id = ep.id_proveedor
        WHERE ep.estado_aprobacion = 'aprobado'
        ORDER BY fecha DESC, id_registro DESC
    ";

    $stmt = $pdo->query($sql);
    $cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cheques as $cheque) {
        $total_aprobado += (float) $cheque['monto'];
    }
} catch (Throwable $e) {
    $error = 'No se pudo cargar la información de cheques aprobados.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Cheques Aprobados - CISMEDIC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .panel-hero {
            background: linear-gradient(135deg, #173b2d 0%, #087f5b 55%, #2471a3 100%);
            border-radius: 16px;
            color: #fff;
            padding: 28px;
            position: relative;
            overflow: hidden;
        }
        .panel-hero::after {
            content: "";
            position: absolute;
            right: -80px;
            top: -120px;
            width: 260px;
            height: 260px;
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 50%;
        }
        .panel-kicker {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .12em;
            opacity: .8;
            text-transform: uppercase;
        }
        .panel-title {
            font-size: clamp(1.9rem, 3vw, 2.6rem);
            font-weight: 800;
            margin: 0;
        }
        .metric-card {
            background: #fff;
            border: 1px solid #e1ebe7;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(23,59,45,.06);
            padding: 20px;
            height: 100%;
        }
        .metric-label {
            color: #718078;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .metric-value {
            color: #173b2d;
            font-size: 1.65rem;
            font-weight: 800;
            margin-top: .35rem;
        }
        .table-card {
            background: #fff;
            border: 1px solid #e1ebe7;
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(23,59,45,.06);
            padding: 20px;
        }
        .status-badge {
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            padding: .38rem .7rem;
        }
        .status-personal { background: #e8f5ef; color: #087f5b; }
        .status-proveedor { background: #edf4ff; color: #365fa8; }
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
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?>
                <a href="dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <?php endif; ?>
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
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?>
                <a href="cheques_aprobados.php" class="nav-link active">
                    <i class="bi bi-bank"></i> Cheques Aprobados
                </a>
                <?php endif; ?>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="facturas.php" class="nav-link">
                    <i class="bi bi-file-earmark-text"></i> Transferencias
                </a><?php endif; ?>
                <a href="proveedores.php" class="nav-link">
                    <i class="bi bi-shop"></i> Proveedores
                </a>
                <a href="categorias.php" class="nav-link">
                    <i class="bi bi-tags"></i> Categorías
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="reportes.php" class="nav-link">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a><?php endif; ?>
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

        <main class="main-content">
            <div class="topbar">
                <div class="topbar-brand">
                    <button class="btn-sidebar" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <span>Cheques Aprobados</span>
                </div>
            </div>

            <div class="container-fluid p-4">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <section class="panel-hero mb-4">
                    <div class="panel-kicker">Control financiero</div>
                    <h1 class="panel-title">Cheques aprobados</h1>
                    <p class="mb-0 opacity-75">Revisa todos los movimientos ya validados y autorizados para desembolso.</p>
                </section>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Total aprobado</div>
                            <div class="metric-value">S/. <?= number_format($total_aprobado, 2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Registros</div>
                            <div class="metric-value"><?= count($cheques) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Último movimiento</div>
                            <div class="metric-value"><?= !empty($cheques) ? date('d/m/Y', strtotime($cheques[0]['fecha'])) : '--/--/----' ?></div>
                        </div>
                    </div>
                </div>

                <section class="table-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">Detalle</h4>
                    </div>

                    <?php if (empty($cheques)): ?>
                        <div class="alert alert-light border mb-0" role="alert">
                            <i class="bi bi-bank me-2"></i>No hay cheques aprobados registrados aún.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Destinatario</th>
                                        <th>Comprobante</th>
                                        <th>Fecha</th>
                                        <th>Descripción</th>
                                        <th class="text-end">Monto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cheques as $cheque): ?>
                                        <tr>
                                            <td>
                                                <span class="status-badge <?= $cheque['tipo'] === 'Proveedor' ? 'status-proveedor' : 'status-personal' ?>">
                                                    <?= htmlspecialchars($cheque['tipo']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($cheque['destinatario']) ?></td>
                                            <td><?= htmlspecialchars($cheque['numero']) ?></td>
                                            <td><?= date('d/m/Y', strtotime($cheque['fecha'])) ?></td>
                                            <td><?= htmlspecialchars($cheque['descripcion']) ?></td>
                                            <td class="text-end fw-bold text-success">S/. <?= number_format((float) $cheque['monto'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js?v=20260820-2"></script>
</body>
</html>
