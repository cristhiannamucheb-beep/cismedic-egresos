<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';

$mensaje = '';
$tipo_mensaje = '';
$proveedores = [];
$rol_actual = strtolower(trim((string) ($_SESSION['usuario_rol'] ?? '')));
$puede_eliminar_permanente = in_array($rol_actual, ['superadmin', 'administrativo', 'administrador', 'admin'], true);

// Crear proveedor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] === 'crear') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO proveedores (nombre, ruc, direccion, contacto, email, responsable)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_POST['nombre'],
                $_POST['ruc'],
                $_POST['direccion'],
                $_POST['contacto'],
                $_POST['email'],
                $_POST['responsable']
            ]);
            
            $mensaje = "Proveedor registrado exitosamente";
            $tipo_mensaje = "success";
        } catch(Exception $e) {
            $mensaje = "Error: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } elseif (in_array($_POST['accion'], ['desactivar', 'reactivar'], true)) {
        try {
            $id_proveedor = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT);
            if (!$id_proveedor || $id_proveedor < 1) {
                throw new Exception('Proveedor no válido.');
            }
            $activo = $_POST['accion'] === 'reactivar' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE proveedores SET activo = ? WHERE id = ?');
            $stmt->execute([$activo, $id_proveedor]);
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare('SELECT id, activo FROM proveedores WHERE id = ? LIMIT 1');
                $stmt->execute([$id_proveedor]);
                $actual = $stmt->fetch();
                if (!$actual || (int) $actual['activo'] !== $activo) {
                    throw new Exception('No se encontró el proveedor para actualizar su estado.');
                }
            }
            $mensaje = $activo ? 'Proveedor reactivado correctamente.' : 'Proveedor desactivado. Sus movimientos históricos se conservaron.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = 'No se pudo actualizar el proveedor: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    } elseif ($_POST['accion'] === 'eliminar_permanente') {
        try {
            if (!$puede_eliminar_permanente) {
                http_response_code(403);
                throw new Exception('Solo un usuario administrativo puede eliminar proveedores permanentemente.');
            }
            $id_proveedor = filter_input(INPUT_POST, 'id_proveedor', FILTER_VALIDATE_INT);
            if (!$id_proveedor || $id_proveedor < 1) {
                throw new Exception('Proveedor no válido.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, nombre FROM proveedores WHERE id = ? FOR UPDATE');
            $stmt->execute([$id_proveedor]);
            $proveedor_eliminar = $stmt->fetch();
            if (!$proveedor_eliminar) {
                throw new Exception('No se encontró el proveedor.');
            }

            $stmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME <> ?');
            $stmt->execute(['id_proveedor', 'proveedores']);
            $tablas_referencias = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $usos = [];
            foreach ($tablas_referencias as $tabla_referencia) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $tabla_referencia)) {
                    continue;
                }
                $stmt_conteo = $pdo->prepare('SELECT COUNT(*) FROM `' . $tabla_referencia . '` WHERE id_proveedor = ?');
                $stmt_conteo->execute([$id_proveedor]);
                $cantidad_usos = (int) $stmt_conteo->fetchColumn();
                if ($cantidad_usos > 0) {
                    $usos[] = $tabla_referencia . ' (' . $cantidad_usos . ')';
                }
            }
            if ($usos) {
                throw new Exception('No se puede eliminar permanentemente porque tiene movimientos vinculados en: ' . implode(', ', $usos) . '. Desactívalo para conservar su historial.');
            }

            $stmt = $pdo->prepare('DELETE FROM proveedores WHERE id = ?');
            $stmt->execute([$id_proveedor]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('No se pudo eliminar el proveedor.');
            }
            $pdo->commit();
            $mensaje = 'Proveedor eliminado permanentemente.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = 'No se pudo eliminar el proveedor: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Obtener proveedores
try {
    $stmt = $pdo->query("SELECT * FROM proveedores ORDER BY nombre");
    $proveedores = $stmt->fetchAll();
} catch(Exception $e) {
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = "danger";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Proveedores - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
                <a href="personal.php" class="nav-link">
                    <i class="bi bi-people"></i> Personal
                </a>
                <a href="proveedores.php" class="nav-link active">
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
                    <span>Gestión de Proveedores</span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <?php if($mensaje): ?>
                <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Botón Nuevo Proveedor -->
                <div class="mb-4">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProveedor">
                        <i class="bi bi-plus-circle"></i> Nuevo Proveedor
                    </button>
                </div>
                
                <!-- Tabla de Proveedores -->
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">Proveedores Registrados <span class="text-muted fs-6" id="contadorProveedores">(<?= count($proveedores) ?>)</span></h5>
                        <span class="small text-muted" id="estadoFiltroProveedores" aria-live="polite"><?= count($proveedores) ?> proveedores</span>
                    </div>
                    <div class="card-body border-bottom">
                        <label for="filtroProveedores" class="form-label fw-semibold">Buscar por nombre o RUC</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="filtroProveedores" placeholder="Escribe un nombre o número de RUC" autocomplete="off" aria-controls="tablaProveedores">
                            <button type="button" class="btn btn-outline-secondary" id="limpiarFiltroProveedores" title="Limpiar búsqueda" aria-label="Limpiar búsqueda"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="tablaProveedores">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>RUC</th>
                                    <th>Contacto</th>
                                    <th>Email</th>
                                    <th>Responsable</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($proveedores as $proveedor): ?>
                                <tr class="fila-proveedor">
                                    <td><strong><?= htmlspecialchars($proveedor['nombre']) ?></strong></td>
                                    <td><?= htmlspecialchars($proveedor['ruc']) ?></td>
                                    <td><?= htmlspecialchars($proveedor['contacto'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($proveedor['email'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($proveedor['responsable'] ?? '-') ?></td>
                                    <td>
                                        <?php if($proveedor['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                        <?php else: ?>
                                        <span class="badge bg-danger">Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" title="Ver proveedor" aria-label="Ver proveedor <?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#modalVerProveedor<?= (int) $proveedor['id'] ?>">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($proveedor['activo']): ?>
                                        <form method="POST" class="d-inline form-desactivar-proveedor" data-proveedor-nombre="<?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="desactivar">
                                            <input type="hidden" name="id_proveedor" value="<?= (int) $proveedor['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Desactivar proveedor" aria-label="Desactivar <?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-person-dash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="accion" value="reactivar">
                                            <input type="hidden" name="id_proveedor" value="<?= (int) $proveedor['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Reactivar proveedor" aria-label="Reactivar <?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-person-check"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($puede_eliminar_permanente): ?>
                                        <form method="POST" class="d-inline form-eliminar-proveedor" data-proveedor-nombre="<?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="eliminar_permanente">
                                            <input type="hidden" name="id_proveedor" value="<?= (int) $proveedor['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Eliminar permanentemente" aria-label="Eliminar permanentemente <?= htmlspecialchars($proveedor['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr id="sinResultadosProveedores" hidden>
                                    <td colspan="7" class="text-center text-muted py-4">No se encontraron proveedores con ese nombre o RUC.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php foreach ($proveedores as $proveedor): ?>
                <div class="modal fade" id="modalVerProveedor<?= (int) $proveedor['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <div><h5 class="modal-title mb-1"><i class="bi bi-shop me-2"></i><?= htmlspecialchars($proveedor['nombre']) ?></h5><small>Ficha del proveedor</small></div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4">RUC</dt><dd class="col-sm-8"><?= htmlspecialchars($proveedor['ruc'] ?: '-') ?></dd>
                                    <dt class="col-sm-4">Dirección</dt><dd class="col-sm-8"><?= htmlspecialchars($proveedor['direccion'] ?: '-') ?></dd>
                                    <dt class="col-sm-4">Contacto</dt><dd class="col-sm-8"><?= htmlspecialchars($proveedor['contacto'] ?: '-') ?></dd>
                                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= htmlspecialchars($proveedor['email'] ?: '-') ?></dd>
                                    <dt class="col-sm-4">Responsable</dt><dd class="col-sm-8"><?= htmlspecialchars($proveedor['responsable'] ?: '-') ?></dd>
                                    <dt class="col-sm-4">Estado</dt><dd class="col-sm-8"><?= $proveedor['activo'] ? 'Activo' : 'Inactivo' ?></dd>
                                </dl>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <div class="modal fade" id="modalConfirmarDesactivacion" tabindex="-1" aria-labelledby="tituloConfirmarDesactivacion" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-danger text-white">
                    <div class="d-flex align-items-center gap-3">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white bg-opacity-25" style="width:42px;height:42px"><i class="bi bi-person-dash fs-5"></i></span>
                        <div><h5 class="modal-title mb-1" id="tituloConfirmarDesactivacion">Desactivar proveedor</h5><small class="opacity-75">Confirmación requerida</small></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">¿Confirmas que deseas desactivar este proveedor?</p>
                    <div class="border rounded bg-light p-3 mb-3">
                        <small class="text-muted d-block">Proveedor seleccionado</small>
                        <strong id="nombreProveedorDesactivar" class="text-break"></strong>
                    </div>
                    <div class="alert alert-info d-flex gap-2 align-items-start mb-0" role="note">
                        <i class="bi bi-info-circle-fill mt-1"></i>
                        <span>El proveedor dejará de estar disponible para nuevos registros. Sus facturas y egresos históricos se conservarán, y podrás reactivarlo después.</span>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="confirmarDesactivacionProveedor"><i class="bi bi-person-dash me-1"></i>Desactivar proveedor</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalConfirmarEliminacionPermanente" tabindex="-1" aria-labelledby="tituloEliminarPermanente" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white">
                    <div class="d-flex align-items-center gap-3">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger" style="width:42px;height:42px"><i class="bi bi-trash3 fs-5"></i></span>
                        <div><h5 class="modal-title mb-1" id="tituloEliminarPermanente">Eliminación permanente</h5><small class="opacity-75">Acción irreversible</small></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4">
                    <p>Vas a eliminar definitivamente este proveedor:</p>
                    <div class="border rounded bg-light p-3 mb-3"><small class="text-muted d-block">Proveedor</small><strong id="nombreProveedorEliminarPermanente" class="text-break"></strong></div>
                    <div class="alert alert-danger d-flex gap-2 align-items-start mb-0" role="alert"><i class="bi bi-exclamation-triangle-fill mt-1"></i><span>Esta acción no se puede deshacer. Solo se permitirá si no existen facturas, entregas ni otros registros vinculados. Si tiene movimientos, desactívalo para conservar su historial.</span></div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="confirmarEliminarProveedor"><i class="bi bi-trash3 me-1"></i>Eliminar permanentemente</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nuevo Proveedor -->
    <div class="modal fade" id="modalProveedor" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Registrar Nuevo Proveedor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="nombre" class="form-label">Nombre</label>
                                <input type="text" class="form-control" name="nombre" required>
                            </div>
                            <div class="col-md-6">
                                <label for="ruc" class="form-label">RUC</label>
                                <input type="text" class="form-control" name="ruc" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="direccion" class="form-label">Dirección</label>
                            <input type="text" class="form-control" name="direccion">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="contacto" class="form-label">Contacto</label>
                                <input type="text" class="form-control" name="contacto">
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="responsable" class="form-label">Responsable</label>
                            <input type="text" class="form-control" name="responsable">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Proveedor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        let formularioDesactivarPendiente = null;
        const modalDesactivacion = document.getElementById('modalConfirmarDesactivacion');
        const instanciaModalDesactivacion = bootstrap.Modal.getOrCreateInstance(modalDesactivacion);

        document.querySelectorAll('.form-desactivar-proveedor').forEach(function (formulario) {
            formulario.addEventListener('submit', function (evento) {
                evento.preventDefault();
                formularioDesactivarPendiente = formulario;
                document.getElementById('nombreProveedorDesactivar').textContent = formulario.dataset.proveedorNombre || 'Proveedor seleccionado';
                instanciaModalDesactivacion.show();
            });
        });

        document.getElementById('confirmarDesactivacionProveedor').addEventListener('click', function () {
            if (!formularioDesactivarPendiente) return;
            this.disabled = true;
            formularioDesactivarPendiente.submit();
        });
        modalDesactivacion.addEventListener('hidden.bs.modal', function () {
            formularioDesactivarPendiente = null;
            document.getElementById('confirmarDesactivacionProveedor').disabled = false;
        });

        let formularioEliminarPendiente = null;
        const modalEliminarPermanente = document.getElementById('modalConfirmarEliminacionPermanente');
        const instanciaModalEliminar = bootstrap.Modal.getOrCreateInstance(modalEliminarPermanente);
        document.querySelectorAll('.form-eliminar-proveedor').forEach(function (formulario) {
            formulario.addEventListener('submit', function (evento) {
                evento.preventDefault();
                formularioEliminarPendiente = formulario;
                document.getElementById('nombreProveedorEliminarPermanente').textContent = formulario.dataset.proveedorNombre || 'Proveedor seleccionado';
                instanciaModalEliminar.show();
            });
        });
        document.getElementById('confirmarEliminarProveedor').addEventListener('click', function () {
            if (!formularioEliminarPendiente) return;
            this.disabled = true;
            formularioEliminarPendiente.submit();
        });
        modalEliminarPermanente.addEventListener('hidden.bs.modal', function () {
            formularioEliminarPendiente = null;
            document.getElementById('confirmarEliminarProveedor').disabled = false;
        });

        (function () {
            const campo = document.getElementById('filtroProveedores');
            const filas = Array.from(document.querySelectorAll('#tablaProveedores tbody tr.fila-proveedor'));
            const contador = document.getElementById('contadorProveedores');
            const estado = document.getElementById('estadoFiltroProveedores');
            const sinResultados = document.getElementById('sinResultadosProveedores');

            function normalizar(valor) {
                return String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
            }

            function filtrarProveedores() {
                const consulta = normalizar(campo.value);
                let visibles = 0;
                filas.forEach(function (fila) {
                    const nombre = normalizar(fila.cells[0]?.textContent);
                    const ruc = normalizar(fila.cells[1]?.textContent).replace(/\s+/g, '');
                    const terminoRuc = consulta.replace(/\s+/g, '');
                    const coincide = !consulta || nombre.includes(consulta) || ruc.includes(terminoRuc);
                    fila.hidden = !coincide;
                    if (coincide) visibles++;
                });
                sinResultados.hidden = visibles !== 0;
                contador.textContent = '(' + visibles + ' de ' + filas.length + ')';
                estado.textContent = consulta
                    ? visibles + (visibles === 1 ? ' proveedor encontrado' : ' proveedores encontrados')
                    : filas.length + (filas.length === 1 ? ' proveedor registrado' : ' proveedores registrados');
            }

            campo.addEventListener('input', filtrarProveedores);
            document.getElementById('limpiarFiltroProveedores').addEventListener('click', function () {
                campo.value = '';
                filtrarProveedores();
                campo.focus();
            });
        }());
    </script>
</body>
</html>
