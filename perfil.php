<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';

$mensaje = '';
$tipo_mensaje = '';

// Obtener datos del usuario
try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuario = $stmt->fetch();
    
    // Cambiar contraseña
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
        if($_POST['accion'] == 'cambiar_contraseña') {
            if($usuario['contraseña'] == $_POST['contraseña_actual']) {
                if($_POST['nueva_contraseña'] == $_POST['confirmar_contraseña']) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET contraseña = ? WHERE id = ?");
                    $stmt->execute([$_POST['nueva_contraseña'], $_SESSION['usuario_id']]);
                    $mensaje = "Contraseña actualizada exitosamente";
                    $tipo_mensaje = "success";
                } else {
                    $mensaje = "Las contraseñas no coinciden";
                    $tipo_mensaje = "danger";
                }
            } else {
                $mensaje = "Contraseña actual incorrecta";
                $tipo_mensaje = "danger";
            }
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
    <title>Perfil - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
                <a href="categorias.php" class="nav-link">
                    <i class="bi bi-tags"></i> Categorías
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="reportes.php" class="nav-link">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a><?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <a href="perfil.php" class="nav-link active">
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
                    <span>Mi Perfil</span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <?php if($mensaje): ?>
                <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($usuario['nombre']) ?>&background=667eea&color=fff&size=128" alt="Avatar" class="rounded-circle mb-3">
                                <h5 class="card-title"><?= htmlspecialchars($usuario['nombre']) ?></h5>
                                <p class="card-text text-muted"><?= htmlspecialchars($usuario['correo']) ?></p>
                                <span class="badge bg-primary"><?= strtoupper($usuario['rol']) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Cambiar Contraseña</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="cambiar_contraseña">
                                    
                                    <div class="mb-3">
                                        <label for="contraseña_actual" class="form-label">Contraseña Actual</label>
                                        <input type="password" class="form-control" name="contraseña_actual" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="nueva_contraseña" class="form-label">Nueva Contraseña</label>
                                        <input type="password" class="form-control" name="nueva_contraseña" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirmar_contraseña" class="form-label">Confirmar Contraseña</label>
                                        <input type="password" class="form-control" name="confirmar_contraseña" required>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Actualizar Contraseña</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
</body>
</html>
