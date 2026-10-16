<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();
requerir_superadmin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/aprobaciones.php';
require_once __DIR__ . '/config/firma_digital.php';

inicializar_tablas_aprobacion($pdo);
inicializar_tablas_firma($pdo);

$mensaje = '';
$error = '';
$roles = ['superadmin', 'administrativo', 'caja'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'crear_usuario') {
            $nombre = trim($_POST['nombre'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $rol = $_POST['rol'] ?? '';
            $contrasena = $_POST['contrasena'] ?? '';
            if ($nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || !in_array($rol, $roles, true) || strlen($contrasena) < 10) {
                throw new Exception('Completa los datos y usa una contraseña de al menos 10 caracteres.');
            }
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE correo = ? LIMIT 1');
            $stmt->execute([$correo]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe un usuario con ese correo.');
            }
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, correo, contraseña, rol, activo, debe_cambiar_contrasena, intentos_fallidos) VALUES (?, ?, ?, ?, 1, 1, 0)');
            $stmt->execute([$nombre, $correo, password_hash($contrasena, PASSWORD_DEFAULT), $rol]);
            $mensaje = 'Usuario creado correctamente.';
        } elseif ($accion === 'actualizar_usuario') {
            $id = (int) ($_POST['id'] ?? 0);
            // El formulario de permisos no envía nombre ni correo: se conservan los actuales.
            $stmt = $pdo->prepare('SELECT nombre, correo FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
            $usuarioActual = $stmt->fetch() ?: ['nombre' => '', 'correo' => ''];
            $nombre = trim($_POST['nombre'] ?? $usuarioActual['nombre']);
            $correo = trim($_POST['correo'] ?? $usuarioActual['correo']);
            $rol = $_POST['rol'] ?? '';
            $activo = isset($_POST['activo']) ? 1 : 0;
            $contrasena = $_POST['contrasena'] ?? '';
            if ($id <= 0 || $nombre === '' || $correo === '' || strlen($correo) > 150 || !in_array($rol, $roles, true)) {
                throw new Exception('Datos de usuario no válidos.');
            }
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE correo = ? AND id <> ? LIMIT 1');
            $stmt->execute([$correo, $id]);
            if ($stmt->fetch()) {
                throw new Exception('El correo de acceso ya pertenece a otro usuario.');
            }
            if ($id === (int) $_SESSION['usuario_id'] && (!$activo || $rol !== 'superadmin')) {
                throw new Exception('No puedes quitarte tu propio acceso de superadministrador.');
            }
            if ($rol !== 'superadmin' || !$activo) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'superadmin' AND activo = 1");
                if ((int) $stmt->fetchColumn() <= 1) {
                    $stmt = $pdo->prepare("SELECT rol, activo FROM usuarios WHERE id = ?");
                    $stmt->execute([$id]);
                    $actual = $stmt->fetch();
                    if ($actual && $actual['rol'] === 'superadmin' && (int) $actual['activo'] === 1) {
                        throw new Exception('Debe permanecer al menos un superadministrador activo.');
                    }
                }
            }
            if ($contrasena !== '') {
                if (strlen($contrasena) < 10) {
                    throw new Exception('La nueva contraseña debe tener al menos 10 caracteres.');
                }
                $stmt = $pdo->prepare('UPDATE usuarios SET nombre = ?, correo = ?, rol = ?, activo = ?, contraseña = ?, debe_cambiar_contrasena = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?');
                $stmt->execute([$nombre, $correo, $rol, $activo, password_hash($contrasena, PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE usuarios SET nombre = ?, correo = ?, rol = ?, activo = ? WHERE id = ?');
                $stmt->execute([$nombre, $correo, $rol, $activo, $id]);
            }
            $mensaje = 'Permisos del usuario actualizados correctamente.';
        } elseif ($accion === 'actualizar_dni') {
            $id = (int) ($_POST['id'] ?? 0);
            $dni = trim((string) ($_POST['dni'] ?? ''));
            if ($id <= 0 || ($dni !== '' && !preg_match('/^\d{8}$/', $dni))) {
                throw new Exception('El DNI debe tener 8 dígitos.');
            }
            $pdo->prepare('UPDATE usuarios SET dni = ? WHERE id = ?')->execute([$dni === '' ? null : $dni, $id]);
            $mensaje = 'DNI actualizado. Se usará para validar la firma digital del usuario.';
        } elseif ($accion === 'actualizar_aprobadores') {
            $aprobadoresRegulares = array_values(array_filter(array_map('intval', $_POST['aprobadores'] ?? []), static fn($id) => $id > 0));
            $aprobadoresFinales = array_values(array_filter(array_map('intval', $_POST['aprobadores_finales'] ?? []), static fn($id) => $id > 0));
            $aprobadoresRegulares = array_values(array_unique($aprobadoresRegulares));
            $aprobadoresFinales = array_values(array_unique($aprobadoresFinales));

            $ids = array_values(array_unique(array_merge($aprobadoresRegulares, $aprobadoresFinales)));
            if (count($aprobadoresRegulares) < 3 || count($aprobadoresRegulares) > 5) {
                throw new Exception('Debes seleccionar entre 3 y 5 aprobadores regulares.');
            }
            if (count($aprobadoresFinales) !== 2) {
                throw new Exception('Debes seleccionar exactamente 2 aprobadores finales; basta con que uno apruebe cada grupo.');
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE id IN (' . $in . ') AND activo = 1');
            $stmt->execute($ids);
            $usuariosActivos = array_map('intval', array_column($stmt->fetchAll(), 'id'));
            sort($ids);
            sort($usuariosActivos);
            if (count($usuariosActivos) !== count($ids) || $ids !== $usuariosActivos) {
                throw new Exception('Los aprobadores deben ser usuarios activos y distintos.');
            }

            $interseccion = array_values(array_intersect($aprobadoresRegulares, $aprobadoresFinales));
            if (!empty($interseccion)) {
                throw new Exception('Un usuario no puede estar a la vez en aprobadores regulares y aprobadores finales.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM aprobadores_egresos')->execute();
            $stmt = $pdo->prepare('INSERT INTO aprobadores_egresos (id_usuario, activo, es_final) VALUES (?, 1, ?)');
            foreach ($aprobadoresRegulares as $idUsuario) {
                $stmt->execute([$idUsuario, 0]);
            }
            foreach ($aprobadoresFinales as $idUsuario) {
                $stmt->execute([$idUsuario, 1]);
            }
            $pdo->commit();
            $mensaje = 'La configuración de aprobadores fue actualizada correctamente.';
        } elseif ($accion === 'revertir_grupo') {
            $idGrupo = (int) ($_POST['id_grupo_revertir'] ?? 0);
            if ($idGrupo <= 0) {
                throw new Exception('Debes seleccionar un grupo para revertir.');
            }
            revertir_aprobacion_grupo($pdo, $idGrupo);
            $mensaje = 'La aprobación del grupo fue revertida y quedó en estado inicial.';
        } elseif ($accion === 'quitar_aprobacion_usuario') {
            $idUsuario = (int) ($_POST['id_usuario_aprobador'] ?? 0);
            if ($idUsuario <= 0) {
                throw new Exception('Debes seleccionar un usuario aprobador.');
            }
            $nombreUsuario = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ? LIMIT 1');
            $nombreUsuario->execute([$idUsuario]);
            $nombreUsuario = $nombreUsuario->fetchColumn();
            $votosEliminados = quitar_aprobacion_usuario($pdo, $idUsuario);
            $mensaje = 'Se quitaron ' . $votosEliminados . ' aprobaciones del usuario ' . ($nombreUsuario ?: 'seleccionado') . '.';
        } elseif ($accion === 'revertir_todas_aprobaciones') {
            revertir_todas_las_aprobaciones($pdo);
            $mensaje = 'Se reinició la aprobación de todos los grupos y se eliminaron todas las aprobaciones registradas.';
        } elseif ($accion === 'convertir_entrega_personal') {
            $id_entrega = (int) ($_POST['id_entrega_personal'] ?? 0);
            $id_proveedor = (int) ($_POST['id_proveedor'] ?? 0);
            if ($id_entrega <= 0 || $id_proveedor <= 0) {
                throw new Exception('Selecciona la entrega y el proveedor de destino.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id_entrega, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion, fecha_registro, estado_aprobacion FROM entregas_dinero WHERE id_entrega = ? FOR UPDATE');
            $stmt->execute([$id_entrega]);
            $entrega = $stmt->fetch();
            if (!$entrega) {
                throw new Exception('La entrega a personal ya no existe.');
            }

            $stmt = $pdo->prepare('SELECT id, nombre FROM proveedores WHERE id = ? AND activo = 1 LIMIT 1');
            $stmt->execute([$id_proveedor]);
            $proveedor = $stmt->fetch();
            if (!$proveedor) {
                throw new Exception('El proveedor seleccionado no existe o está inactivo.');
            }

            $stmt = $pdo->prepare('SELECT id_entrega_proveedor FROM entregas_proveedores WHERE id_proveedor = ? AND tipo_comprobante = ? AND nComprobante = ? LIMIT 1');
            $stmt->execute([$id_proveedor, $entrega['tipo_comprobante'], $entrega['nComprobante']]);
            if ($stmt->fetch()) {
                throw new Exception('Ya existe una entrega para ese proveedor con el mismo comprobante.');
            }

            $stmt = $pdo->prepare('INSERT INTO entregas_proveedores (id_proveedor, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion, fecha_registro, estado_aprobacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id_proveedor, $entrega['id_usuario'], $entrega['id_categoria'], $entrega['tipo_comprobante'], $entrega['nComprobante'], $entrega['fecha_entrega'], $entrega['monto'], $entrega['descripcion'], $entrega['fecha_registro'], $entrega['estado_aprobacion']]);
            $nuevo_id = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("UPDATE detalle_almacenamiento_egresos SET fuente_egreso = 'proveedores', id_egreso = ? WHERE fuente_egreso = 'personal' AND id_egreso = ?");
            $stmt->execute([$nuevo_id, $id_entrega]);
            $stmt = $pdo->prepare('DELETE FROM entregas_dinero WHERE id_entrega = ?');
            $stmt->execute([$id_entrega]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('La entrega no pudo convertirse.');
            }
            $pdo->commit();
            $mensaje = 'La entrega fue convertida a proveedor correctamente.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$proveedores = $pdo->query('SELECT id, nombre, ruc FROM proveedores WHERE activo = 1 ORDER BY nombre')->fetchAll();
$busqueda_entrega = trim($_GET['buscar_entrega'] ?? '');
$patron_entrega = '%' . $busqueda_entrega . '%';
$stmt = $pdo->prepare("SELECT ed.id_entrega, ed.tipo_comprobante, ed.nComprobante, ed.fecha_entrega, ed.monto, CONCAT(COALESCE(pc.nombre, ''), ' ', COALESCE(pc.apellido, '')) AS personal FROM entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal WHERE (? = '' OR CAST(ed.id_entrega AS CHAR) LIKE ? OR ed.nComprobante LIKE ? OR CONCAT(pc.nombre, ' ', pc.apellido) LIKE ?) ORDER BY ed.fecha_entrega DESC, ed.id_entrega DESC LIMIT 100");
$stmt->execute([$busqueda_entrega, $patron_entrega, $patron_entrega, $patron_entrega]);
$entregas_personal = $stmt->fetchAll();
$usuarios = $pdo->query('SELECT id, nombre, correo, dni, rol, activo, debe_cambiar_contrasena, intentos_fallidos, bloqueado_hasta, ultimo_acceso FROM usuarios ORDER BY nombre')->fetchAll();
$aprobadores = $pdo->query('SELECT id_usuario FROM aprobadores_egresos WHERE activo = 1 AND es_final = 0')->fetchAll(PDO::FETCH_COLUMN, 0);
$aprobadores = array_map('intval', $aprobadores);
$aprobadores_finales = $pdo->query('SELECT id_usuario FROM aprobadores_egresos WHERE activo = 1 AND es_final = 1')->fetchAll(PDO::FETCH_COLUMN, 0);
$aprobadores_finales = array_map('intval', $aprobadores_finales);
$aprobadores_configurados = $pdo->query('SELECT a.id_usuario, u.nombre, u.correo, a.es_final, a.activo FROM aprobadores_egresos a JOIN usuarios u ON u.id = a.id_usuario ORDER BY u.nombre')->fetchAll();
$grupos_aprobacion = $pdo->query("SELECT id, nombre, total, estado_aprobacion FROM almacenamientos_egresos WHERE estado_aprobacion IN ('en_aprobacion', 'aprobado') ORDER BY fecha DESC, id DESC LIMIT 200")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="icon" type="image/png" href="storage/iconos/LOGO.png"><title>Panel Superadmin - CISMEDIC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"><link rel="stylesheet" href="assets/css/style.css">
<style>
.admin-hero{background:linear-gradient(135deg,#162d3d,#087f5b);border-radius:14px;color:#fff;padding:24px}.user-card{border:1px solid #dce7e2;border-radius:12px}.user-card h6{color:#173b2d}.role-badge{font-size:.7rem;letter-spacing:.04em;text-transform:uppercase}.security-note{background:#f0f8f4;border-left:4px solid #198754}
</style>
</head>
<body><div class="wrapper">
<aside class="sidebar bg-dark text-white"><div class="sidebar-header"><h3><i class="bi bi-hospital"></i> CISMEDIC</h3><p>Control de Egresos</p></div><?php $nav_egresos_abierto = true; ?><nav class="navbar-nav mt-3"><a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a><div class="nav-section"><a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>"><i class="bi bi-receipt"></i> Egresos</a><div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>"><a href="entregas_personal.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_personal.php') ? 'active' : '' ?>"><i class="bi bi-person-vcard"></i> Entregas a Personal</a><a href="entregas_proveedores.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_proveedores.php') ? 'active' : '' ?>"><i class="bi bi-shop"></i> Entregas a Proveedores</a></div></div><a href="almacenamiento_egresos.php" class="nav-link"><i class="bi bi-inboxes"></i> Preaprobación</a><a href="aprobacion.php" class="nav-link"><i class="bi bi-check2-circle"></i> Aprobación</a><a href="cheques_aprobados.php" class="nav-link"><i class="bi bi-bank"></i> Cheques Aprobados</a><a href="superadmin.php" class="nav-link active"><i class="bi bi-shield-lock"></i> Panel de seguridad</a></nav><div class="sidebar-footer"><a href="perfil.php" class="nav-link"><i class="bi bi-person"></i> <?= escapar($_SESSION['usuario_nombre']) ?></a><a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a></div></aside>
<main class="main-content"><div class="topbar"><div class="topbar-brand"><button class="btn-sidebar" id="sidebarToggle"><i class="bi bi-list"></i></button><span>Panel exclusivo de superadministrador</span></div></div><div class="container-fluid p-4">
<?php if ($mensaje): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= escapar($mensaje) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= escapar($error) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<section class="admin-hero mb-4"><div class="small text-uppercase opacity-75">Control de acceso</div><h2 class="mb-1">Panel de seguridad</h2><p class="mb-0 opacity-75">Administra usuarios, roles, estado y credenciales del sistema.</p></section>
<div class="alert security-note mb-4"><strong><i class="bi bi-shield-check me-2"></i>Protección activa</strong><br><span class="small">El acceso a este panel está validado en el servidor y requiere rol superadmin. Las contraseñas se almacenan cifradas y cinco intentos fallidos bloquean la cuenta durante 15 minutos.</span></div>
<div class="card shadow-sm mb-4"><div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Convertir entrega a proveedor</h5></div><div class="card-body"><p class="small text-muted">Esta operación está disponible únicamente para el superadministrador. Conserva el comprobante, monto, fecha, categoría, descripción y estado de preaprobación.</p><form method="GET" class="row g-2 mb-3"><div class="col-md-9"><label class="form-label small" for="buscar_entrega">Buscar entrega personal</label><input class="form-control" id="buscar_entrega" name="buscar_entrega" value="<?= escapar($busqueda_entrega) ?>" placeholder="ID, número de comprobante o nombre"></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-search me-1"></i>Buscar</button></div></form><form method="POST" class="row g-2 align-items-end" onsubmit="return confirm('¿Convertir esta entrega a proveedor? La entrega original a personal será reemplazada.');"><input type="hidden" name="accion" value="convertir_entrega_personal"><div class="col-md-6"><label class="form-label small" for="id_entrega_personal">Entrega a convertir</label><select class="form-select" name="id_entrega_personal" id="id_entrega_personal" required><option value="">Selecciona una entrega</option><?php foreach ($entregas_personal as $entrega): ?><option value="<?= (int) $entrega['id_entrega'] ?>">#<?= (int) $entrega['id_entrega'] ?> · <?= escapar(trim($entrega['personal'])) ?> · <?= escapar($entrega['tipo_comprobante'] . ' ' . $entrega['nComprobante']) ?> · S/. <?= number_format((float) $entrega['monto'], 2) ?> · <?= date('d/m/Y', strtotime($entrega['fecha_entrega'])) ?></option><?php endforeach; ?></select><?php if (!$entregas_personal): ?><div class="form-text text-danger">No se encontraron entregas personales.</div><?php endif; ?></div><div class="col-md-4"><label class="form-label small" for="id_proveedor">Proveedor de destino</label><select class="form-select" name="id_proveedor" id="id_proveedor" required><option value="">Selecciona un proveedor</option><?php foreach ($proveedores as $proveedor): ?><option value="<?= (int) $proveedor['id'] ?>"><?= escapar($proveedor['nombre']) ?><?= !empty($proveedor['ruc']) ? ' · RUC ' . escapar((string) $proveedor['ruc']) : '' ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-warning w-100" type="submit" <?= !$entregas_personal || !$proveedores ? 'disabled' : '' ?>><i class="bi bi-arrow-repeat me-1"></i>Convertir</button></div></form></div></div>
<div class="card shadow-sm mb-4"><div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-check2-circle me-2 text-primary"></i>Usuarios aprobadores de egresos</h5></div><div class="card-body"><p class="small text-muted mb-3">Selecciona entre 3 y 5 aprobadores regulares y exactamente 2 aprobadores finales. Para completar cada grupo se requieren 3 votos regulares y basta con que apruebe uno de los dos finales.</p><form method="POST"><input type="hidden" name="accion" value="actualizar_aprobadores"><div class="mb-4"><h6 class="mb-2 text-success">Aprobadores regulares</h6><div class="row g-3"><?php $usuarios_activos = array_values(array_filter($usuarios, static fn($usuario) => (int) $usuario['activo'] === 1)); if (empty($usuarios_activos)): ?><div class="col-12"><div class="alert alert-warning mb-0">No hay usuarios activos para asignar como aprobadores.</div></div><?php else: foreach ($usuarios_activos as $usuario): $usuario_id = (int) $usuario['id']; $es_aprobador = in_array($usuario_id, $aprobadores, true); ?><div class="col-md-4"><div class="form-check border rounded p-3 h-100 <?= $es_aprobador ? 'border-success bg-light' : '' ?>"><input class="form-check-input" type="checkbox" name="aprobadores[]" value="<?= $usuario_id ?>" id="aprobador_<?= $usuario_id ?>" <?= $es_aprobador ? 'checked' : '' ?>><label class="form-check-label d-block ms-2" for="aprobador_<?= $usuario_id ?>"><strong><?= escapar($usuario['nombre']) ?></strong><br><span class="small text-muted"><?= escapar($usuario['correo']) ?></span><br><span class="badge bg-secondary mt-1"><?= ucfirst(escapar($usuario['rol'])) ?></span></label></div></div><?php endforeach; endif; ?></div></div><div><h6 class="mb-2 text-warning">Aprobadores finales</h6><div class="row g-3"><?php if (empty($usuarios_activos)): ?><div class="col-12"><div class="alert alert-warning mb-0">No hay usuarios activos para asignar como aprobadores finales.</div></div><?php else: foreach ($usuarios_activos as $usuario): $usuario_id = (int) $usuario['id']; $es_aprobador_final = in_array($usuario_id, array_filter($aprobadores, static fn($id) => false), true); if (isset($aprobadores_finales)) { $es_aprobador_final = in_array($usuario_id, $aprobadores_finales, true); } ?><div class="col-md-4"><div class="form-check border rounded p-3 h-100 <?= $es_aprobador_final ? 'border-warning bg-light' : '' ?>"><input class="form-check-input" type="checkbox" name="aprobadores_finales[]" value="<?= $usuario_id ?>" id="aprobador_final_<?= $usuario_id ?>" <?= $es_aprobador_final ? 'checked' : '' ?>><label class="form-check-label d-block ms-2" for="aprobador_final_<?= $usuario_id ?>"><strong><?= escapar($usuario['nombre']) ?></strong><br><span class="small text-muted"><?= escapar($usuario['correo']) ?></span><br><span class="badge bg-warning text-dark mt-1">Final</span></label></div></div><?php endforeach; endif; ?></div></div><div class="mt-3"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Guardar aprobadores</button></div></form></div></div>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2 text-danger"></i>Revertir aprobación de egresos</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-5">
                <form method="POST" onsubmit="return confirm('¿Revertir esta aprobación? El grupo volverá al estado inicial y se borrarán sus votos.');">
                    <input type="hidden" name="accion" value="revertir_grupo">
                    <label class="form-label" for="id_grupo_revertir">Grupo a revertir</label>
                    <select class="form-select" id="id_grupo_revertir" name="id_grupo_revertir" required>
                        <option value="">Selecciona un grupo</option>
                        <?php foreach ($grupos_aprobacion as $grupo): ?>
                            <option value="<?= (int) $grupo['id'] ?>"><?= (int) $grupo['id'] ?> · <?= escapar($grupo['nombre']) ?> · S/. <?= number_format((float) $grupo['total'], 2) ?> · <?= htmlspecialchars($grupo['estado_aprobacion']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-danger mt-3 w-100" type="submit"><i class="bi bi-arrow-counterclockwise me-1"></i>Revertir grupo</button>
                </form>
            </div>
            <div class="col-lg-4">
                <form method="POST" onsubmit="return confirm('¿Quitar todas las aprobaciones del usuario seleccionado?');">
                    <input type="hidden" name="accion" value="quitar_aprobacion_usuario">
                    <label class="form-label" for="id_usuario_aprobador">Quitar aprobación de un aprobador</label>
                    <select class="form-select" id="id_usuario_aprobador" name="id_usuario_aprobador" required>
                        <option value="">Selecciona un usuario</option>
                        <?php foreach ($aprobadores_configurados as $aprobador): ?>
                            <option value="<?= (int) $aprobador['id_usuario'] ?>"><?= escapar($aprobador['nombre']) ?> (<?= $aprobador['es_final'] ? 'Final' : 'Regular' ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-warning mt-3 w-100 text-dark" type="submit"><i class="bi bi-person-dash me-1"></i>Quitar aprobación del usuario</button>
                </form>
            </div>
            <div class="col-lg-3">
                <form method="POST" onsubmit="return confirm('¿Revertir todas las aprobaciones del sistema? Esto dejará todos los grupos sin votos registrados.');">
                    <input type="hidden" name="accion" value="revertir_todas_aprobaciones">
                    <label class="form-label">Reset total</label>
                    <div class="d-grid gap-2">
                        <button class="btn btn-dark" type="submit"><i class="bi bi-trash3 me-1"></i>Quitar todas</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4"><div class="card-header bg-white"><h5 class="mb-0"><i class="bi bi-person-plus me-2 text-success"></i>Crear usuario</h5></div><div class="card-body"><form method="POST" class="row g-3"><input type="hidden" name="accion" value="crear_usuario"><div class="col-md-3"><label class="form-label">Nombre</label><input class="form-control" name="nombre" required></div><div class="col-md-3"><label class="form-label">Correo</label><input class="form-control" type="email" name="correo" required></div><div class="col-md-2"><label class="form-label">Rol</label><select class="form-select" name="rol" required><?php foreach ($roles as $rol): ?><option value="<?= $rol ?>"><?= ucfirst($rol) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Contraseña inicial</label><input class="form-control" type="password" name="contrasena" minlength="10" required></div><div class="col-md-1 d-flex align-items-end"><button class="btn btn-success w-100" title="Crear usuario"><i class="bi bi-plus-lg"></i></button></div></form></div></div>
<div class="row g-3"><?php foreach ($usuarios as $usuario): $usuario_id = (int) $usuario['id']; $es_aprobador_regular = in_array($usuario_id, $aprobadores, true); $es_aprobador_final = in_array($usuario_id, $aprobadores_finales, true); ?><div class="col-xl-6"><div class="user-card bg-white p-3 h-100"><div class="d-flex justify-content-between align-items-start gap-3"><div><h6 class="mb-1"><?= escapar($usuario['nombre']) ?></h6><div class="small text-muted"><?= escapar($usuario['correo']) ?></div></div><span class="badge <?= $usuario['rol'] === 'superadmin' ? 'bg-dark' : ($usuario['rol'] === 'administrativo' ? 'bg-primary' : 'bg-warning text-dark') ?> role-badge"><?= escapar($usuario['rol']) ?></span></div><hr><form method="POST" class="row g-2 align-items-end"><input type="hidden" name="accion" value="actualizar_usuario"><input type="hidden" name="id" value="<?= (int) $usuario['id'] ?>"><div class="col-sm-4"><label class="form-label small">Rol</label><select class="form-select form-select-sm" name="rol"><?php foreach ($roles as $rol): ?><option value="<?= $rol ?>" <?= $usuario['rol'] === $rol ? 'selected' : '' ?>><?= ucfirst($rol) ?></option><?php endforeach; ?></select></div><div class="col-sm-3"><label class="form-label small">Nueva contraseña</label><input class="form-control form-control-sm" type="password" name="contrasena" minlength="10" placeholder="Opcional"></div><div class="col-sm-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="activo<?= (int) $usuario['id'] ?>" <?= $usuario['activo'] ? 'checked' : '' ?>><label class="form-check-label small" for="activo<?= (int) $usuario['id'] ?>">Activo</label></div></div><div class="col-sm-3"><button class="btn btn-outline-primary btn-sm w-100" type="submit"><i class="bi bi-save me-1"></i>Guardar permisos</button></div></form><form method="POST" class="row g-2 align-items-end mt-1"><input type="hidden" name="accion" value="actualizar_dni"><input type="hidden" name="id" value="<?= (int) $usuario['id'] ?>"><div class="col-sm-9"><label class="form-label small" for="dni<?= (int) $usuario['id'] ?>">DNI para firma digital</label><input class="form-control form-control-sm" id="dni<?= (int) $usuario['id'] ?>" name="dni" value="<?= escapar((string) ($usuario['dni'] ?? '')) ?>" inputmode="numeric" pattern="\d{8}" maxlength="8" placeholder="8 dígitos"></div><div class="col-sm-3"><button class="btn btn-outline-secondary btn-sm w-100" type="submit"><i class="bi bi-person-vcard me-1"></i>Guardar DNI</button></div></form><div class="small text-muted mt-3"><i class="bi bi-clock me-1"></i>Último acceso: <?= $usuario['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($usuario['ultimo_acceso'])) : 'Nunca' ?><?php if ($usuario['debe_cambiar_contrasena']): ?> · Cambio de contraseña pendiente<?php endif; ?><?php if ($usuario['bloqueado_hasta'] && strtotime($usuario['bloqueado_hasta']) > time()): ?> · Cuenta bloqueada<?php endif; ?></div></div></div><?php endforeach; ?></div>
</div></main></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/main.js?v=20260820-2"></script></body></html>
