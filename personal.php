<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';

$mensaje = '';
$tipo_mensaje = '';
$personal = [];
$categorias_empleado = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni = trim($_POST['dni'] ?? '');
            $tipo_personal = $_POST['tipo_personal'] ?? 'interno';
            $empresa = trim($_POST['empresa'] ?? '');
            $cargo = trim($_POST['cargo'] ?? '');
            $id_categoria = (int) ($_POST['id_categoria'] ?? 0);

            if ($nombre === '' || $apellido === '' || !preg_match('/^\d{8}$/', $dni) || $id_categoria <= 0) {
                throw new Exception('El DNI debe contener exactamente 8 dígitos.');
            }

            if ($nombre === '' || $apellido === '' || $id_categoria <= 0) {
                throw new Exception('Completa nombre, apellido, DNI y función del personal.');
            }

            $stmt = $pdo->prepare('INSERT INTO personal_clinica (nombre, apellido, dni, tipo_personal, empresa, id_categoria, cargo, activo) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
            $stmt->execute([$nombre, $apellido, $dni, $tipo_personal, $empresa ?: null, $id_categoria, $cargo ?: null]);
            $mensaje = 'Personal registrado correctamente.';
            $tipo_mensaje = 'success';
        } elseif ($accion === 'editar') {
            $id_personal = (int) ($_POST['id_personal'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni = trim($_POST['dni'] ?? '');
            $tipo_personal = $_POST['tipo_personal'] ?? 'interno';
            $empresa = trim($_POST['empresa'] ?? '');
            $cargo = trim($_POST['cargo'] ?? '');
            $id_categoria = (int) ($_POST['id_categoria'] ?? 0);

            if ($id_personal <= 0 || $nombre === '' || $apellido === '' || !preg_match('/^\d{8}$/', $dni) || $id_categoria <= 0) {
                throw new Exception('El DNI debe contener exactamente 8 dígitos.');
            }

            if ($id_personal <= 0 || $nombre === '' || $apellido === '' || $id_categoria <= 0) {
                throw new Exception('Completa nombre, apellido, DNI y función del personal.');
            }

            $stmt = $pdo->prepare('UPDATE personal_clinica SET nombre = ?, apellido = ?, dni = ?, tipo_personal = ?, empresa = ?, id_categoria = ?, cargo = ? WHERE id_personal = ?');
            $stmt->execute([$nombre, $apellido, $dni, $tipo_personal, $empresa ?: null, $id_categoria, $cargo ?: null, $id_personal]);
            $mensaje = 'Datos del personal actualizados correctamente.';
            $tipo_mensaje = 'success';
        } elseif ($accion === 'desactivar' || $accion === 'reactivar') {
            $id_personal = (int) ($_POST['id_personal'] ?? 0);
            $activo = $accion === 'reactivar' ? 1 : 0;

            if ($id_personal <= 0) {
                throw new Exception('Personal no válido.');
            }

            $stmt = $pdo->prepare('UPDATE personal_clinica SET activo = ? WHERE id_personal = ?');
            $stmt->execute([$activo, $id_personal]);
            $mensaje = $activo ? 'Personal reactivado correctamente.' : 'Personal desactivado. Su historial se conserva.';
            $tipo_mensaje = 'success';
        }
    } catch (PDOException $e) {
        $mensaje = $e->getCode() === '23000' ? 'El DNI ya está registrado.' : 'No se pudo completar la operación.';
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $mensaje = $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

try {
    $stmt = $pdo->query('SELECT id_categoria, nombre FROM categorias_empleado ORDER BY nombre');
    $categorias_empleado = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT pc.*, ce.nombre AS categoria_nombre,
        (SELECT COUNT(*) FROM entregas_dinero ed WHERE ed.id_personal = pc.id_personal) AS total_entregas
        FROM personal_clinica pc
        LEFT JOIN categorias_empleado ce ON pc.id_categoria = ce.id_categoria
        ORDER BY pc.activo DESC, ce.nombre, pc.apellido, pc.nombre");
    $personal = $stmt->fetchAll();
} catch (Exception $e) {
    $mensaje = 'No se pudo cargar el panel de personal.';
    $tipo_mensaje = 'danger';
}

$activos = count(array_filter($personal, static fn($persona) => (int) $persona['activo'] === 1));
$inactivos = count($personal) - $activos;
$total_entregas = array_sum(array_map(static fn($persona) => (int) $persona['total_entregas'], $personal));
$personal_activo = array_values(array_filter($personal, static fn($persona) => (int) $persona['activo'] === 1));
$personal_inactivo = array_values(array_filter($personal, static fn($persona) => (int) $persona['activo'] !== 1));
$personal_por_categoria = [];
foreach ($personal_activo as $persona) {
    $categoria = trim($persona['categoria_nombre'] ?? '') ?: 'Sin categoría';
    $personal_por_categoria[$categoria][] = $persona;
}
uksort($personal_por_categoria, 'strnatcasecmp');
$grupos_personal = [];
foreach ($personal_por_categoria as $categoria => $registros) {
    $grupos_personal[] = [
        'titulo' => $categoria,
        'descripcion' => 'Personal activo de esta categoría funcional',
        'icono' => 'bi-diagram-3',
        'color' => 'success',
        'registros' => $registros,
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Personal - CISMEDIC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .personal-hero { background: linear-gradient(135deg, #087f5b, #20c997); border-radius: 14px; color: white; padding: 26px; }
        .metric-card { border: 0; border-left: 4px solid #198754; }
        .avatar-personal { align-items: center; background: #d1e7dd; border-radius: 50%; color: #087f5b; display: inline-flex; font-weight: 700; height: 42px; justify-content: center; margin-right: 10px; width: 42px; }
        .table > :not(caption) > * > * { vertical-align: middle; }
        .table-personal th { color: #53606b; font-size: .76rem; letter-spacing: .04em; text-transform: uppercase; }
        .acciones-personal { white-space: nowrap; }
        .baja-alerta .modal-header { background: #b02a37; color: #fff; }
        .baja-alerta .alert { border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
<div class="wrapper">
    <aside class="sidebar bg-dark text-white">
        <div class="sidebar-header"><h3><i class="bi bi-hospital"></i> CISMEDIC</h3><p>Control de Egresos</p></div>
        <?php $nav_egresos_abierto = true; ?>
        <nav class="navbar-nav mt-3">
            <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a><?php endif; ?>
            <div class="nav-section">
                <a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>"><i class="bi bi-receipt"></i> Egresos</a>
                <div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>">
                    <a href="entregas_personal.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_personal.php') ? 'active' : '' ?>"><i class="bi bi-cash-coin"></i> Entregas a Personal</a>
                    <a href="entregas_proveedores.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_proveedores.php') ? 'active' : '' ?>"><i class="bi bi-shop"></i> Entregas a Proveedores</a>
                </div>
            </div>
            <a href="aprobacion.php" class="nav-link"><i class="bi bi-check2-circle"></i> Aprobación</a>
            <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="cheques_aprobados.php" class="nav-link"><i class="bi bi-bank"></i> Cheques Aprobados</a><?php endif; ?>
            <a href="personal.php" class="nav-link active"><i class="bi bi-people"></i> Personal</a>
            <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="facturas.php" class="nav-link"><i class="bi bi-file-earmark-text"></i> Transferencias</a><?php endif; ?>
            <a href="proveedores.php" class="nav-link"><i class="bi bi-building"></i> Proveedores</a>
            <a href="categorias.php" class="nav-link"><i class="bi bi-tags"></i> Categorías</a>
            <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="reportes.php" class="nav-link"><i class="bi bi-bar-chart"></i> Reportes</a><?php endif; ?>
        </nav>
        <div class="sidebar-footer"><a href="perfil.php" class="nav-link"><i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></a><a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a></div>
    </aside>

    <main class="main-content">
        <div class="topbar"><div class="topbar-brand"><button class="btn-sidebar" id="sidebarToggle"><i class="bi bi-list"></i></button><span>Gestión de Personal</span></div></div>
        <div class="container-fluid p-4">
            <?php if ($mensaje): ?><div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show"><?= htmlspecialchars($mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <section class="personal-hero mb-4 d-flex justify-content-between align-items-center gap-3">
                <div><div class="text-uppercase small opacity-75">Directorio institucional</div><h2 class="mb-1">Personal de CISMEDIC</h2><p class="mb-0 opacity-75">Administra funciones y conserva el historial de entregas.</p></div>
                <button class="btn btn-light text-success fw-semibold" data-bs-toggle="modal" data-bs-target="#modalNuevoPersonal"><i class="bi bi-person-plus"></i> Nuevo personal</button>
            </section>

            <div class="row g-3 mb-4">
                <div class="col-md-4"><div class="card metric-card h-100"><div class="card-body"><small class="text-muted">Personal activo</small><h3 class="mb-0"><?= $activos ?></h3></div></div></div>
                <div class="col-md-4"><div class="card metric-card h-100"><div class="card-body"><small class="text-muted">Personal inactivo</small><h3 class="mb-0"><?= $inactivos ?></h3></div></div></div>
                <div class="col-md-4"><div class="card metric-card h-100"><div class="card-body"><small class="text-muted">Entregas históricas vinculadas</small><h3 class="mb-0"><?= $total_entregas ?></h3></div></div></div>
            </div>

            <div class="row g-4">
            <?php foreach ($grupos_personal as $grupo): ?>
            <div class="col-12">
            <div class="card shadow-sm personal-group-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><div><h5 class="mb-1"><i class="bi <?= $grupo['icono'] ?> text-<?= $grupo['color'] ?> me-2"></i><?= $grupo['titulo'] ?></h5><small class="text-muted"><?= $grupo['descripcion'] ?></small></div><span class="badge bg-<?= $grupo['color'] ?>-subtle text-<?= $grupo['color'] ?>"><?= count($grupo['registros']) ?> registros</span></div>
                <div class="table-responsive">
                    <table class="table table-hover table-personal mb-0">
                        <thead class="table-light"><tr><th>Personal</th><th>Función / categoría</th><th>DNI</th><th>Entregas</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($grupo['registros'] as $persona): ?>
                            <tr>
                                <td><span class="avatar-personal"><?= strtoupper(substr($persona['nombre'], 0, 1) . substr($persona['apellido'], 0, 1)) ?></span><strong><?= htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre']) ?></strong><?php if ($persona['empresa']): ?><small class="d-block text-muted ms-5"><?= htmlspecialchars($persona['empresa']) ?></small><?php endif; ?></td>
                                <td><?= htmlspecialchars($persona['cargo'] ?: ($persona['categoria_nombre'] ?: 'Sin función')) ?><small class="d-block text-muted"><?= htmlspecialchars($persona['categoria_nombre'] ?: '') ?></small></td>
                                <td><?= htmlspecialchars($persona['dni']) ?></td>
                                <td><span class="badge bg-light text-dark"><i class="bi bi-cash-stack"></i> <?= $persona['total_entregas'] ?></span></td>
                                <td><?= $persona['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                                <td class="acciones-personal">
                                    <button type="button" class="btn btn-sm btn-outline-primary" title="Editar personal" data-bs-toggle="modal" data-bs-target="#modalEditarPersonal" data-id="<?= $persona['id_personal'] ?>" data-nombre="<?= htmlspecialchars($persona['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-apellido="<?= htmlspecialchars($persona['apellido'], ENT_QUOTES, 'UTF-8') ?>" data-dni="<?= htmlspecialchars($persona['dni'], ENT_QUOTES, 'UTF-8') ?>" data-tipo="<?= htmlspecialchars($persona['tipo_personal'], ENT_QUOTES, 'UTF-8') ?>" data-empresa="<?= htmlspecialchars($persona['empresa'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-cargo="<?= htmlspecialchars($persona['cargo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-categoria="<?= $persona['id_categoria'] ?>"><i class="bi bi-pencil-square"></i></button>
                                    <?php if ($persona['activo']): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirmarBaja(this, '<?= htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre'], ENT_QUOTES, 'UTF-8') ?>', <?= (int) $persona['total_entregas'] ?>);"><input type="hidden" name="accion" value="desactivar"><input type="hidden" name="id_personal" value="<?= $persona['id_personal'] ?>"><button class="btn btn-sm btn-outline-danger" title="Dar de baja"><i class="bi bi-person-dash"></i></button></form>
                                    <?php else: ?>
                                    <form method="POST" class="d-inline"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id_personal" value="<?= $persona['id_personal'] ?>"><button class="btn btn-sm btn-outline-success" title="Reactivar"><i class="bi bi-person-check"></i></button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if ($personal_inactivo): ?>
            <div class="card shadow-sm mt-4 border-secondary">
                <div class="card-header bg-light d-flex justify-content-between align-items-center"><h5 class="mb-0 text-secondary"><i class="bi bi-archive"></i> Personal dado de baja</h5><span class="badge bg-secondary"><?= count($personal_inactivo) ?> registros</span></div>
                <div class="table-responsive">
                    <table class="table table-hover table-personal mb-0">
                        <thead class="table-light"><tr><th>Personal</th><th>Función</th><th>DNI</th><th>Entregas conservadas</th><th>Estado</th><th>Acciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($personal_inactivo as $persona): ?>
                            <tr class="table-secondary">
                                <td><span class="avatar-personal bg-secondary-subtle text-secondary"><?= strtoupper(substr($persona['nombre'], 0, 1) . substr($persona['apellido'], 0, 1)) ?></span><strong><?= htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre']) ?></strong><?php if ($persona['empresa']): ?><small class="d-block text-muted ms-5"><?= htmlspecialchars($persona['empresa']) ?></small><?php endif; ?></td>
                                <td><?= htmlspecialchars($persona['cargo'] ?: ($persona['categoria_nombre'] ?: 'Sin función')) ?><small class="d-block text-muted"><?= htmlspecialchars($persona['categoria_nombre'] ?: '') ?></small></td>
                                <td><?= htmlspecialchars($persona['dni']) ?></td>
                                <td><span class="badge bg-warning-subtle text-dark"><i class="bi bi-shield-lock"></i> <?= $persona['total_entregas'] ?></span></td>
                                <td><span class="badge bg-secondary">Inactivo</span></td>
                                <td class="acciones-personal"><button type="button" class="btn btn-sm btn-outline-primary" title="Editar personal" data-bs-toggle="modal" data-bs-target="#modalEditarPersonal" data-id="<?= $persona['id_personal'] ?>" data-nombre="<?= htmlspecialchars($persona['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-apellido="<?= htmlspecialchars($persona['apellido'], ENT_QUOTES, 'UTF-8') ?>" data-dni="<?= htmlspecialchars($persona['dni'], ENT_QUOTES, 'UTF-8') ?>" data-tipo="<?= htmlspecialchars($persona['tipo_personal'], ENT_QUOTES, 'UTF-8') ?>" data-empresa="<?= htmlspecialchars($persona['empresa'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-cargo="<?= htmlspecialchars($persona['cargo'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-categoria="<?= $persona['id_categoria'] ?>"><i class="bi bi-pencil-square"></i></button> <form method="POST" class="d-inline"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id_personal" value="<?= $persona['id_personal'] ?>"><button class="btn btn-sm btn-outline-success" title="Reactivar"><i class="bi bi-person-check"></i></button></form></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal fade" id="modalNuevoPersonal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-person-plus"></i> Registrar nuevo personal</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><input type="hidden" name="accion" value="crear"><div class="modal-body"><div class="alert alert-success-subtle small"><i class="bi bi-shield-check"></i> El personal se registra activo y podrá recibir entregas desde el panel correspondiente.</div><div class="row g-3"><div class="col-md-6"><label class="form-label">Nombre *</label><input class="form-control" name="nombre" required></div><div class="col-md-6"><label class="form-label">Apellido *</label><input class="form-control" name="apellido" required></div><div class="col-md-6"><label class="form-label">DNI *</label><input class="form-control" name="dni" required></div><div class="col-md-6"><label class="form-label">Tipo de personal</label><select class="form-select" name="tipo_personal"><option value="interno">Interno</option><option value="externo">Externo</option></select></div><div class="col-md-6"><label class="form-label">Función / cargo</label><input class="form-control" name="cargo"></div><div class="col-md-6"><label class="form-label">Categoría funcional *</label><select class="form-select" name="id_categoria" required><option value="">Seleccionar categoría</option><?php foreach ($categorias_empleado as $categoria): ?><option value="<?= $categoria['id_categoria'] ?>"><?= htmlspecialchars($categoria['nombre']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Empresa, si corresponde</label><input class="form-control" name="empresa"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success"><i class="bi bi-check2-circle"></i> Guardar personal</button></div></form></div></div></div>
<div class="modal fade" id="modalEditarPersonal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar personal</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id_personal" id="editar_id"><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Nombre *</label><input class="form-control" name="nombre" id="editar_nombre" required></div><div class="col-md-6"><label class="form-label">Apellido *</label><input class="form-control" name="apellido" id="editar_apellido" required></div><div class="col-md-6"><label class="form-label">DNI *</label><input class="form-control" name="dni" id="editar_dni" required></div><div class="col-md-6"><label class="form-label">Tipo de personal</label><select class="form-select" name="tipo_personal" id="editar_tipo"><option value="interno">Interno</option><option value="externo">Externo</option></select></div><div class="col-md-6"><label class="form-label">Función / cargo</label><input class="form-control" name="cargo" id="editar_cargo"></div><div class="col-md-6"><label class="form-label">Categoría funcional *</label><select class="form-select" name="id_categoria" id="editar_categoria" required><option value="">Seleccionar categoría</option><?php foreach ($categorias_empleado as $categoria): ?><option value="<?= $categoria['id_categoria'] ?>"><?= htmlspecialchars($categoria['nombre']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Empresa, si corresponde</label><input class="form-control" name="empresa" id="editar_empresa"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Guardar cambios</button></div></form></div></div></div>

<div class="modal fade baja-alerta" id="modalBajaPersonal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-octagon-fill me-2"></i> Confirmar baja de personal</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><h5 id="bajaNombre" class="mb-3"></h5><div class="alert alert-danger"><strong><i class="bi bi-shield-exclamation me-1"></i> Atención</strong><p class="mb-0 mt-2">Este personal será marcado como inactivo. Sus entregas de dinero no se eliminarán bajo ninguna circunstancia y permanecerán en el historial.</p></div><p class="mb-0">Entregas vinculadas: <strong id="bajaEntregas"></strong></p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><form method="POST"><input type="hidden" name="accion" value="desactivar"><input type="hidden" name="id_personal" id="bajaId"><button type="submit" class="btn btn-danger"><i class="bi bi-person-dash me-1"></i> Confirmar baja</button></form></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/main.js"></script><script>
document.querySelectorAll('input[name="dni"]').forEach(function (campo) {
    campo.maxLength = 8;
    campo.minLength = 8;
    campo.pattern = '[0-9]{8}';
    campo.inputMode = 'numeric';
    campo.title = 'El DNI debe contener exactamente 8 dígitos';
    campo.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 8);
    });
    campo.addEventListener('paste', function () {
        const campoDni = this;
        setTimeout(function () {
            campoDni.value = campoDni.value.replace(/\D/g, '').slice(0, 8);
        }, 0);
    });
});
document.querySelectorAll('[data-bs-target="#modalEditarPersonal"]').forEach(function (boton) {
    boton.addEventListener('click', function () {
        document.getElementById('editar_id').value = this.dataset.id;
        document.getElementById('editar_nombre').value = this.dataset.nombre;
        document.getElementById('editar_apellido').value = this.dataset.apellido;
        document.getElementById('editar_dni').value = this.dataset.dni;
        document.getElementById('editar_tipo').value = this.dataset.tipo;
        document.getElementById('editar_empresa').value = this.dataset.empresa;
        document.getElementById('editar_cargo').value = this.dataset.cargo;
        document.getElementById('editar_categoria').value = this.dataset.categoria;
    });
});
function confirmarBaja(formulario, nombre, entregas) {
    document.getElementById('bajaNombre').textContent = nombre;
    document.getElementById('bajaEntregas').textContent = entregas;
    document.getElementById('bajaId').value = formulario.querySelector('[name="id_personal"]').value;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBajaPersonal')).show();
    return false;
}
</script>
</body>
</html>
