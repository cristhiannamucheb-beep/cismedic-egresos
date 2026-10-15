<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
$es_superadmin = ($_SESSION['usuario_rol'] ?? '') === 'superadmin';

$pdo->exec("CREATE TABLE IF NOT EXISTS categoria_global_subcategoria (
    id_categoria_global INT NOT NULL,
    id_subcategoria INT NOT NULL,
    PRIMARY KEY (id_categoria_global, id_subcategoria),
    CONSTRAINT fk_categorias_cgs_global FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE CASCADE,
    CONSTRAINT fk_categorias_cgs_subcategoria FOREIGN KEY (id_subcategoria) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT IGNORE INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) SELECT id_categoria_global, id FROM categorias WHERE id_categoria_global IS NOT NULL");

// Valores por defecto solo si la tabla está vacía
$categorias_globales_iniciales = [
    ['nombre' => 'PAGO PERSONAL', 'color' => '#FF6B6B', 'icono' => 'person-fill'],
    ['nombre' => 'PAGO ESPECIALISTAS', 'color' => '#4ECDC4', 'icono' => 'heart-pulse'],
    ['nombre' => 'SERVICIOS BASICOS', 'color' => '#45B7D1', 'icono' => 'building'],
    ['nombre' => 'PAGO TERCEROS', 'color' => '#FFA07A', 'icono' => 'people-fill'],
    ['nombre' => 'PAGO INFORMATICOS', 'color' => '#6C63FF', 'icono' => 'laptop'],
    ['nombre' => 'ALMACEN MEDICO', 'color' => '#98D8C8', 'icono' => 'bag-heart'],
    ['nombre' => 'EQUIPAMIENTO VARIOS', 'color' => '#FF6584', 'icono' => 'tools'],
    ['nombre' => 'OTROS PAGOS', 'color' => '#95E1D3', 'icono' => 'cash-stack']
];

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM categorias_globales");
    $total_globales = (int) $stmt->fetchColumn();

    if ($total_globales === 0) {
        $stmt = $pdo->prepare("INSERT INTO categorias_globales (nombre, color, icono) VALUES (?, ?, ?)");
        foreach ($categorias_globales_iniciales as $categoria) {
            $stmt->execute([$categoria['nombre'], $categoria['color'], $categoria['icono']]);
        }
    }

    $iconos_categorias_referenciales = [
        'PAGOS TRIBUTARIOS' => 'receipt',
        'PAGO TRIBUTARIO' => 'receipt',
        'SEGUROS' => 'shield-check',
        'PRESTAMOS' => 'cash-coin',
    ];
    $stmt = $pdo->prepare("UPDATE categorias_globales SET icono = ? WHERE UPPER(TRIM(nombre)) = ?");
    foreach ($iconos_categorias_referenciales as $nombre_categoria => $icono_categoria) {
        $stmt->execute([$icono_categoria, $nombre_categoria]);
    }
} catch (Exception $e) {
    $error = "Error al inicializar categorías globales: " . $e->getMessage();
}

// Variables para manejo de operaciones
$action = $_GET['action'] ?? null;
$error = $error ?? null;
$success = null;
$categorias_globales = [];
$subcategorias = [];
$subcategorias_por_global = [];
$subcategorias_sin_global = [];

function icono_subcategoria(string $nombre): string
{
    $nombre_normalizado = strtoupper(trim($nombre));
    $iconos_por_concepto = [
        'PERSONAL' => 'person-badge',
        'SUELDO' => 'wallet2',
        'HONORARIO' => 'cash-stack',
        'TRIBUT' => 'receipt',
        'SEGURO' => 'shield-check',
        'PRESTAM' => 'cash-coin',
        'MEDIC' => 'heart-pulse',
        'FARMAC' => 'capsule-pill',
        'LABORATOR' => 'eyeglasses',
        'LUZ' => 'lightning-charge',
        'AGUA' => 'droplet',
        'INTERNET' => 'wifi',
        'TELEFON' => 'telephone',
        'SERVICIO' => 'gear-wide-connected',
        'MANTEN' => 'tools',
        'EQUIP' => 'pc-display-horizontal',
        'ALMACEN' => 'boxes',
        'LIMPIEZA' => 'stars',
        'TRANSPORTE' => 'truck',
        'COMBUST' => 'fuel-pump',
        'CAPACIT' => 'mortarboard',
        'PUBLIC' => 'megaphone',
    ];

    foreach ($iconos_por_concepto as $concepto => $icono) {
        if (str_contains($nombre_normalizado, $concepto)) {
            return $icono;
        }
    }

    return 'tag';
}

try {
    // ============ OPERACIONES SOBRE CATEGORÍAS GLOBALES ============
    
    // Crear categoría global
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_categoria_global'])) {
        $nombre = trim($_POST['nombre_global'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $color = $_POST['color'] ?? '#667eea';
        $icono = trim($_POST['icono'] ?? '');
        
        if(empty($nombre)) {
            $error = "El nombre de la categoría global es requerido";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM categorias_globales WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$nombre]);

            if($stmt->fetch()) {
                $error = "Ya existe una categoría global con ese nombre";
            } else {
                $stmt = $pdo->prepare("INSERT INTO categorias_globales (nombre, descripcion, color, icono) VALUES (?, ?, ?, ?)");
                if($stmt->execute([$nombre, $descripcion, $color, $icono])) {
                    $success = "Categoría global creada exitosamente";
                } else {
                    $error = "Error al crear la categoría global";
                }
            }
        }
    }
    
    // Eliminar categoría global
    if(isset($_GET['eliminar_global']) && isset($_GET['id_global'])) {
        if (!$es_superadmin) {
            http_response_code(403);
            exit('Acceso denegado: solo el superadmin puede eliminar categorías globales.');
        }
        $id = intval($_GET['id_global']);
        // Primero, desasociar todas las subcategorías
        $stmt = $pdo->prepare("UPDATE categorias SET id_categoria_global = NULL WHERE id_categoria_global = ?");
        $stmt->execute([$id]);
        
        // Luego eliminar la categoría global
        $stmt = $pdo->prepare("DELETE FROM categorias_globales WHERE id = ?");
        if($stmt->execute([$id])) {
            $success = "Categoría global eliminada exitosamente";
        } else {
            $error = "Error al eliminar la categoría global";
        }
    }
    
    // ============ OPERACIONES SOBRE SUB-CATEGORÍAS ============
    
    // Crear subcategoría
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_subcategoria'])) {
        $nombre = trim($_POST['nombre_sub'] ?? '');
        $categorias_globales_seleccionadas = array_values(array_filter(array_map('intval', (array) ($_POST['id_categoria_global'] ?? [])), static fn($id) => $id > 0));
        
        if(empty($nombre)) {
            $error = "El nombre de la subcategoría es requerido";
        } elseif(!$categorias_globales_seleccionadas) {
            $error = "Debe seleccionar al menos una categoría global";
        } else {
            $stmt = $pdo->prepare("INSERT INTO categorias (nombre, id_categoria_global) VALUES (?, ?)");
            if($stmt->execute([$nombre, $categorias_globales_seleccionadas[0]])) {
                $id_subcategoria = (int) $pdo->lastInsertId();
                $relacion = $pdo->prepare("INSERT IGNORE INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) VALUES (?, ?)");
                foreach ($categorias_globales_seleccionadas as $id_global) {
                    $relacion->execute([$id_global, $id_subcategoria]);
                }
                $success = "Subcategoría creada exitosamente";
            } else {
                $error = "Error al crear la subcategoría";
            }
        }
    }
    
    // Actualizar subcategoría (cambiar categoría global)
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_subcategoria'])) {
        $id_subcategoria = intval($_POST['id_subcategoria']);
        $categorias_globales_seleccionadas = array_values(array_filter(array_map('intval', (array) ($_POST['id_categoria_global_update'] ?? [])), static fn($id) => $id > 0));
        
        if($categorias_globales_seleccionadas) {
            $stmt = $pdo->prepare("UPDATE categorias SET id_categoria_global = ? WHERE id = ?");
            if($stmt->execute([$categorias_globales_seleccionadas[0], $id_subcategoria])) {
                $pdo->prepare('DELETE FROM categoria_global_subcategoria WHERE id_subcategoria = ?')->execute([$id_subcategoria]);
                $relacion = $pdo->prepare("INSERT IGNORE INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) VALUES (?, ?)");
                foreach ($categorias_globales_seleccionadas as $id_global) {
                    $relacion->execute([$id_global, $id_subcategoria]);
                }
                $success = "Subcategoría actualizada exitosamente";
            } else {
                $error = "Error al actualizar la subcategoría";
            }
        }
    }
    
    // Eliminar subcategoría
    if(isset($_GET['eliminar_sub']) && isset($_GET['id_sub'])) {
        if (($_SESSION['usuario_rol'] ?? '') === 'caja') {
            http_response_code(403);
            exit('Acceso denegado: el rol caja no puede eliminar subcategorías.');
        }
        $id = intval($_GET['id_sub']);
        $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
        if($stmt->execute([$id])) {
            $success = "Subcategoría eliminada exitosamente";
        } else {
            $error = "Error al eliminar la subcategoría";
        }
    }
    
    // Obtener categorías globales con sus subcategorías
    $stmt = $pdo->query("SELECT * FROM categorias_globales WHERE estado = 1 ORDER BY nombre");
    $categorias_globales = $stmt->fetchAll();
    
    // Obtener todas las subcategorías
    $stmt = $pdo->query("
        SELECT c.*, cg.nombre as nombre_global, cg.color as color_global, GROUP_CONCAT(DISTINCT r.id_categoria_global) AS ids_categorias_globales
        FROM categorias c
        LEFT JOIN categorias_globales cg ON c.id_categoria_global = cg.id
        LEFT JOIN categoria_global_subcategoria r ON r.id_subcategoria = c.id
        GROUP BY c.id
        ORDER BY cg.nombre, c.nombre
    ");
    $subcategorias = $stmt->fetchAll();
    
    // Agrupar subcategorías por categoría global
    $subcategorias_por_global = [];
    $subcategorias_sin_global = [];
    
    foreach($subcategorias as $sub) {
        if($sub['id_categoria_global']) {
            if(!isset($subcategorias_por_global[$sub['id_categoria_global']])) {
                $subcategorias_por_global[$sub['id_categoria_global']] = [
                    'nombre_global' => $sub['nombre_global'],
                    'color_global' => $sub['color_global'],
                    'subcategorias' => []
                ];
            }
            $subcategorias_por_global[$sub['id_categoria_global']]['subcategorias'][] = $sub;
        } else {
            $subcategorias_sin_global[] = $sub;
        }
    }
    
} catch(Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Categorías - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .subcategorias-section { border: 1px solid #dbe7ed; border-radius: 16px; box-shadow: 0 12px 30px rgba(31, 50, 65, .08); overflow: hidden; }
        .subcategorias-section > .card-header { background: linear-gradient(110deg, #073b4c 0%, #087f5b 100%); border: 0; color: #fff; padding: 20px 24px; }
        .subcategorias-section > .card-header h5 { font-weight: 800; }
        .subcategorias-section > .card-header h5 i { color: #8ee6c2; margin-right: 8px; }
        .subcategorias-group-title { align-items: center; display: flex; font-size: .95rem; font-weight: 800; gap: 8px; }
        .subcategorias-group-title i { font-size: 1.1rem; }
        .subcategorias-table { border: 1px solid #e5edf1; border-radius: 10px; overflow: hidden; }
        .subcategorias-table thead th { background: #f6f9fb; border-bottom: 1px solid #dfe8ed; color: #667085; font-size: .68rem; letter-spacing: .06em; padding: 12px 14px; text-transform: uppercase; }
        .subcategorias-table tbody td { border-color: #edf2f5; color: #344054; padding: 13px 14px; vertical-align: middle; }
        .subcategorias-table tbody tr { transition: background-color .18s ease; }
        .subcategorias-table tbody tr:hover { background: #effaf6; }
        .subcategoria-identidad { align-items: center; display: flex; gap: 10px; }
        .subcategoria-icono { align-items: center; background: #e5f6ef; border: 1px solid #b8e7d2; border-radius: 10px; color: #087f5b; display: inline-flex; flex: 0 0 34px; height: 34px; justify-content: center; width: 34px; }
        .subcategoria-nombre { color: #1d2939; font-weight: 750; }
        .subcategoria-id { color: #98a2b3; font-family: "Courier New", monospace; font-size: .76rem; }
        .subcategoria-fecha { color: #667085; font-size: .8rem; white-space: nowrap; }
        .subcategoria-acciones { text-align: right; white-space: nowrap; }
        .subcategoria-acciones .btn { border-radius: 7px; height: 32px; width: 34px; }
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
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a><?php endif; ?>
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
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="facturas.php" class="nav-link">
                    <i class="bi bi-file-earmark-text"></i> Transferencias
                </a><?php endif; ?>
                <a href="proveedores.php" class="nav-link">
                    <i class="bi bi-shop"></i> Proveedores
                </a>
                <a href="categorias.php" class="nav-link active">
                    <i class="bi bi-tags"></i> Categorías
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="reportes.php" class="nav-link">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a><?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <a href="perfil.php" class="nav-link">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario') ?>
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
                    <span>Gestión de Categorías</span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <!-- Mensajes de estado -->
                <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- SECCIÓN 1: CATEGORÍAS GLOBALES -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card subcategorias-section">
                            <div class="card-header bg-primary text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-collection"></i> Categorías Globales (<?= count($categorias_globales) ?>)
                                    </h5>
                                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalNuevaGlobal">
                                        <i class="bi bi-plus-circle"></i> Nueva Categoría Global
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(empty($categorias_globales)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                    No hay categorías globales. <a href="#" data-bs-toggle="modal" data-bs-target="#modalNuevaGlobal">Crear una</a>
                                </div>
                                <?php else: ?>
                                <div class="row">
                                    <?php foreach($categorias_globales as $cat_global): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="card border-left-3 h-100" style="border-left-color: <?= htmlspecialchars($cat_global['color']) ?> !important; border-left-width: 4px;">
                                            <div class="card-body">
                                                <div class="d-flex align-items-center mb-2">
                                                    <?php if($cat_global['icono']): ?>
                                                    <i class="bi bi-<?= htmlspecialchars($cat_global['icono']) ?>" style="font-size: 1.5rem; color: <?= htmlspecialchars($cat_global['color']) ?>; margin-right: 10px;"></i>
                                                    <?php endif; ?>
                                                    <h6 class="mb-0"><?= htmlspecialchars($cat_global['nombre']) ?></h6>
                                                </div>
                                                <?php if($cat_global['descripcion']): ?>
                                                <p class="text-muted small mb-2"><?= htmlspecialchars($cat_global['descripcion']) ?></p>
                                                <?php endif; ?>
                                                
                                                <!-- Contador de subcategorías -->
                                                <?php 
                                                $count_subs = count($subcategorias_por_global[$cat_global['id']]['subcategorias'] ?? []);
                                                ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-tag"></i> <?= $count_subs ?> subcategoría<?= $count_subs != 1 ? 's' : '' ?>
                                                </small>
                                                
                                                <?php if ($es_superadmin): ?>
                                                <div class="mt-2 d-flex gap-1">
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarGlobal<?= $cat_global['id'] ?>">
                                                        <i class="bi bi-pencil"></i> Editar
                                                    </button>
                                                    <a href="?eliminar_global=1&id_global=<?= $cat_global['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar esta categoría global?')">
                                                        <i class="bi bi-trash"></i> Eliminar
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SECCIÓN 2: SUB-CATEGORÍAS POR CATEGORÍA GLOBAL -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-diagram-3"></i> Sub-categorías por Categoría Global
                                    </h5>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaSubcategoria">
                                        <i class="bi bi-plus-circle"></i> Nueva Sub-categoría
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(empty($subcategorias_por_global) && empty($subcategorias_sin_global)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                    No hay sub-categorías registradas
                                </div>
                                <?php else: ?>
                                
                                <!-- Mostrar sub-categorías agrupadas -->
                                <?php foreach($subcategorias_por_global as $id_global => $group): ?>
                                <div class="mb-4">
                                    <h6 class="mb-3 subcategorias-group-title" style="color: <?= htmlspecialchars($group['color_global']) ?>; border-left: 4px solid <?= htmlspecialchars($group['color_global']) ?>; padding-left: 12px;">
                                        <i class="bi bi-tag"></i> <?= htmlspecialchars($group['nombre_global']) ?>
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm mb-0 subcategorias-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Nombre</th>
                                                    <th>Fecha Creación</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($group['subcategorias'] as $sub): ?>
                                                <tr>
                                                    <td class="subcategoria-id">#<?= $sub['id'] ?></td>
                                                    <td><div class="subcategoria-identidad"><span class="subcategoria-icono"><i class="bi bi-<?= htmlspecialchars(icono_subcategoria($sub['nombre'])) ?>"></i></span><span class="subcategoria-nombre"><?= htmlspecialchars($sub['nombre']) ?></span></div></td>
                                                    <td class="subcategoria-fecha"><?= date('d/m/Y', strtotime($sub['created_at'])) ?></td>
                                                    <td class="subcategoria-acciones">
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarSubcategoria<?= $sub['id'] ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="?eliminar_sub=1&id_sub=<?= $sub['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar esta subcategoría?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a><?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Mostrar sub-categorías sin categoría global -->
                                <?php if(!empty($subcategorias_sin_global)): ?>
                                <div class="mb-4">
                                    <h6 class="mb-3" style="border-left: 4px solid #999; padding-left: 12px; color: #999;">
                                        <i class="bi bi-question-circle"></i> Sin Categoría Global
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-hover table-sm mb-0 subcategorias-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Nombre</th>
                                                    <th>Fecha Creación</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach($subcategorias_sin_global as $sub): ?>
                                                <tr>
                                                    <td class="subcategoria-id">#<?= $sub['id'] ?></td>
                                                    <td><div class="subcategoria-identidad"><span class="subcategoria-icono"><i class="bi bi-<?= htmlspecialchars(icono_subcategoria($sub['nombre'])) ?>"></i></span><span class="subcategoria-nombre"><?= htmlspecialchars($sub['nombre']) ?></span></div></td>
                                                    <td class="subcategoria-fecha"><?= date('d/m/Y', strtotime($sub['created_at'])) ?></td>
                                                    <td class="subcategoria-acciones">
                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarSubcategoria<?= $sub['id'] ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="?eliminar_sub=1&id_sub=<?= $sub['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar esta subcategoría?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a><?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- MODAL: Nueva Categoría Global -->
    <div class="modal fade" id="modalNuevaGlobal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="needs-validation">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nueva Categoría Global</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_global" class="form-label">Nombre *</label>
                            <input type="text" class="form-control" id="nombre_global" name="nombre_global" required>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="color" class="form-label">Color</label>
                                <input type="color" class="form-control form-control-color" id="color" name="color" value="#667eea">
                            </div>
                            <div class="col-md-6">
                                <label for="icono" class="form-label">Icono (Bootstrap Icons)</label>
                                <input type="text" class="form-control" id="icono" name="icono" placeholder="people-fill">
                                <small class="text-muted">Ver <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a></small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_categoria_global" class="btn btn-primary">Crear Categoría</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- MODAL: Nueva Sub-categoría -->
    <div class="modal fade" id="modalNuevaSubcategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="needs-validation">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nueva Sub-categoría</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="id_categoria_global" class="form-label">Categoría Global *</label>
                            <select class="form-select" id="id_categoria_global" name="id_categoria_global[]" multiple required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach($categorias_globales as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Puedes seleccionar varias categorías globales con Ctrl o Cmd.</small>
                        </div>
                        <div class="mb-3">
                            <label for="nombre_sub" class="form-label">Nombre de Sub-categoría *</label>
                            <input type="text" class="form-control" id="nombre_sub" name="nombre_sub" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_subcategoria" class="btn btn-primary">Crear Sub-categoría</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- MODALES DE EDICIÓN PARA CATEGORÍAS GLOBALES -->
    <?php foreach($categorias_globales as $cat_global): ?>
    <div class="modal fade" id="modalEditarGlobal<?= $cat_global['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Editar Categoría Global</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>Nombre:</strong> <?= htmlspecialchars($cat_global['nombre']) ?></p>
                    <?php if($cat_global['descripcion']): ?>
                    <p><strong>Descripción:</strong> <?= htmlspecialchars($cat_global['descripcion']) ?></p>
                    <?php endif; ?>
                    <p><strong>Color:</strong> <span style="display: inline-block; width: 30px; height: 30px; background-color: <?= htmlspecialchars($cat_global['color']) ?>; border-radius: 4px;"></span></p>
                    <p class="text-muted"><small>Nota: La edición de categorías globales está limitada para mantener la integridad de datos. Elimine y cree una nueva si es necesario.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- MODALES DE EDICIÓN PARA SUB-CATEGORÍAS -->
    <?php foreach($subcategorias as $sub): ?>
    <div class="modal fade" id="modalEditarSubcategoria<?= $sub['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" class="needs-validation">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Editar Sub-categoría</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($sub['nombre']) ?>" disabled>
                            <small class="text-muted">El nombre no puede editarse</small>
                        </div>
                        <div class="mb-3">
                            <label for="id_categoria_global_update<?= $sub['id'] ?>" class="form-label">Categoría Global *</label>
                            <select class="form-select" id="id_categoria_global_update<?= $sub['id'] ?>" name="id_categoria_global_update[]" multiple required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach($categorias_globales as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= in_array((string) $cat['id'], explode(',', (string) ($sub['ids_categorias_globales'] ?? '')), true) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="id_subcategoria" value="<?= $sub['id'] ?>">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="actualizar_subcategoria" class="btn btn-primary">Actualizar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        // Auto-cerrar alertas
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    </script>
</body>
</html>
