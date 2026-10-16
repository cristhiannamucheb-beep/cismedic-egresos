<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

requerir_roles(['superadmin', 'administrativo', 'caja']);
require_once 'config/database.php';
require_once 'config/aprobaciones.php';
require_once 'config/firma_digital.php';

inicializar_tablas_aprobacion($pdo);
inicializar_tablas_firma($pdo);

$pdo->exec("UPDATE almacenamientos_egresos
    SET estado_aprobacion = 'en_aprobacion'
    WHERE (estado_aprobacion = 'pendiente' OR estado_aprobacion IS NULL)
      AND EXISTS (
          SELECT 1
          FROM detalle_almacenamiento_egresos d
          WHERE d.id_almacenamiento = almacenamientos_egresos.id
      )");

if (isset($_GET['detalle_grupo'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $idGrupoDetalle = filter_input(INPUT_GET, 'detalle_grupo', FILTER_VALIDATE_INT);
    if (!$idGrupoDetalle || $idGrupoDetalle < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'Grupo no válido.']);
        exit;
    }

    $stmtGrupoDetalle = $pdo->prepare('SELECT a.id, a.nombre, a.fecha, a.total, a.estado_aprobacion, a.observacion, u.nombre AS usuario_registra,
        (SELECT COUNT(*) FROM aprobacion_grupos_egresos ag JOIN aprobadores_egresos ap ON ap.id_usuario = ag.id_usuario AND ap.activo = 1 AND ap.es_final = ag.es_final JOIN usuarios ua ON ua.id = ag.id_usuario AND ua.activo = 1 WHERE ag.id_almacenamiento = a.id AND ag.es_final = 0) AS aprobaciones_regulares,
        (SELECT COUNT(*) FROM aprobacion_grupos_egresos ag JOIN aprobadores_egresos ap ON ap.id_usuario = ag.id_usuario AND ap.activo = 1 AND ap.es_final = ag.es_final JOIN usuarios ua ON ua.id = ag.id_usuario AND ua.activo = 1 WHERE ag.id_almacenamiento = a.id AND ag.es_final = 1) AS aprobaciones_finales,
        (SELECT GROUP_CONCAT(u_regular.nombre ORDER BY ag_regular.fecha_aprobacion SEPARATOR ", ")
            FROM aprobacion_grupos_egresos ag_regular
            JOIN aprobadores_egresos ap_regular ON ap_regular.id_usuario = ag_regular.id_usuario AND ap_regular.activo = 1 AND ap_regular.es_final = ag_regular.es_final
            JOIN usuarios u_regular ON u_regular.id = ag_regular.id_usuario
            WHERE ag_regular.id_almacenamiento = a.id AND ag_regular.es_final = 0 AND u_regular.activo = 1) AS nombres_aprobadores_regulares,
        (SELECT GROUP_CONCAT(u_final.nombre ORDER BY ag_final.fecha_aprobacion SEPARATOR ", ")
            FROM aprobacion_grupos_egresos ag_final
            JOIN aprobadores_egresos ap_final ON ap_final.id_usuario = ag_final.id_usuario AND ap_final.activo = 1 AND ap_final.es_final = ag_final.es_final
            JOIN usuarios u_final ON u_final.id = ag_final.id_usuario
            WHERE ag_final.id_almacenamiento = a.id AND ag_final.es_final = 1 AND u_final.activo = 1) AS nombres_aprobadores_finales
        FROM almacenamientos_egresos a
        LEFT JOIN usuarios u ON u.id = a.id_usuario_registra
        WHERE a.id = ? LIMIT 1');
    $stmtGrupoDetalle->execute([$idGrupoDetalle]);
    $grupoDetalle = $stmtGrupoDetalle->fetch(PDO::FETCH_ASSOC);
    if (!$grupoDetalle) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'No se encontró el grupo solicitado.']);
        exit;
    }

    $stmtDetallesGrupo = $pdo->prepare('SELECT d.id, d.fecha, d.documento, d.proveedor_responsable, d.descripcion, d.total, d.fuente_egreso, d.id_egreso, ce.mime AS comprobante_mime, ce.nombre_original AS comprobante_nombre FROM detalle_almacenamiento_egresos d LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso COLLATE utf8mb4_general_ci = d.fuente_egreso AND ce.id_egreso = d.id_egreso WHERE d.id_almacenamiento = ? ORDER BY d.fecha, d.id');
    $stmtDetallesGrupo->execute([$idGrupoDetalle]);
    echo json_encode([
        'ok' => true,
        'grupo' => $grupoDetalle,
        'detalles' => $stmtDetallesGrupo->fetchAll(PDO::FETCH_ASSOC),
        'requisitos' => obtener_requisitos_aprobacion($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$mensaje = '';
$error = '';
$idUsuarioActual = (int) ($_SESSION['usuario_id'] ?? 0);
$puedeAprobar = usuario_es_aprobador_activo($pdo, $idUsuarioActual);
$esSuperAdmin = (($_SESSION['usuario_rol'] ?? '') === 'superadmin');
$aprobadoresActivos = obtener_aprobadores_activos($pdo);
$tiposAprobadoresActivos = [];
foreach ($aprobadoresActivos as $aprobadorActivo) {
    $tiposAprobadoresActivos[(int) $aprobadorActivo['id']] = (int) $aprobadorActivo['es_final'];
}
$tipoAprobadorActual = $tiposAprobadoresActivos[$idUsuarioActual] ?? null;
$grupos = [];
$grupos_por_mes = [];
$grupos_filtrados = [];
$grupos_pendientes = 0;
$aprobados_total = 0.0;
$rechazosRecientes = [];
$anosDisponibles = [];
$anoActual = (int) date('Y');
$mesActual = (int) date('n');
$requisitosAprobacion = ['regular' => 3, 'final' => 1];
$anoSeleccionado = (int) ($_POST['ano'] ?? $_GET['ano'] ?? $anoActual);
$mesSeleccionado = (int) ($_POST['mes'] ?? $_GET['mes'] ?? $mesActual);
$mesesEnEspanol = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['accion'] ?? ''), ['aprobar_grupo', 'rechazar_grupo'], true)) {
    $idGrupo = (int) ($_POST['id_grupo'] ?? 0);

    if (!$puedeAprobar) {
        $error = 'No estás configurado como aprobador activo para este flujo.';
    } elseif ($idGrupo <= 0) {
        $error = 'El grupo de egresos no es válido.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id, nombre, total, estado_aprobacion FROM almacenamientos_egresos WHERE id = ? FOR UPDATE');
            $stmt->execute([$idGrupo]);
            $grupo = $stmt->fetch();

            if (!$grupo) {
                throw new Exception('El grupo de egresos no existe.');
            }
            if ($grupo['estado_aprobacion'] !== 'en_aprobacion') {
                throw new Exception('Este grupo ya no está pendiente de aprobación.');
            }

            $stmtAprobador = $pdo->prepare('SELECT es_final FROM aprobadores_egresos WHERE id_usuario = ? AND activo = 1 LIMIT 1');
            $stmtAprobador->execute([$idUsuarioActual]);
            $tipoAprobador = $stmtAprobador->fetchColumn();
            if ($tipoAprobador === false) {
                throw new Exception('Tu usuario no está configurado como aprobador activo.');
            }
            $esFinal = (int) $tipoAprobador;
            $requisitos = obtener_requisitos_aprobacion($pdo);
            $aprobacionesGrupo = contar_aprobaciones_grupo_por_tipo($pdo, $idGrupo);
            $regulares = $aprobacionesGrupo['regular'];
            $finales = $aprobacionesGrupo['final'];

            if ($esFinal === 1 && $regulares < $requisitos['regular']) {
                throw new Exception('El aprobador final todavía no puede actuar: primero deben aprobar todos los aprobadores regulares.');
            }
            if ($esFinal === 0 && $finales > 0) {
                throw new Exception('La aprobación final ya fue registrada para este grupo.');
            }
            if (usuario_ya_aprobo_grupo($pdo, $idGrupo, $idUsuarioActual)) {
                throw new Exception('Ya registraste una decisión para este grupo.');
            }

            if (($_POST['accion'] ?? '') === 'rechazar_grupo') {
                $motivoRechazo = trim((string) ($_POST['motivo_rechazo'] ?? ''));
                if ($motivoRechazo === '') {
                    throw new Exception('Es obligatorio indicar el motivo del rechazo.');
                }
                rechazar_y_archivar_grupo($pdo, $idGrupo, $idUsuarioActual, $esFinal === 1 ? 'final' : 'regular', $motivoRechazo);
                $mensaje = 'Grupo rechazado y archivado. Sus egresos quedaron disponibles para una nueva agrupación. El rechazo se notificará a los aprobadores.';
            } else {
                // La aprobación solo se registra si el PDF firmado con DNIe se valida en el servidor.
                $firma = registrar_firma_grupo($pdo, $idGrupo, $idUsuarioActual, $esFinal, $_FILES['pdf_firmado'] ?? []);
                $stmt = $pdo->prepare('INSERT INTO aprobacion_grupos_egresos (id_almacenamiento, id_usuario, es_final) VALUES (?, ?, ?)');
                $stmt->execute([$idGrupo, $idUsuarioActual, $esFinal]);
                $regulares += $esFinal === 0 ? 1 : 0;
                $finales += $esFinal === 1 ? 1 : 0;
                if ($regulares >= $requisitos['regular'] && $finales >= $requisitos['final']) {
                    $pdo->prepare("UPDATE almacenamientos_egresos SET estado_aprobacion = 'aprobado' WHERE id = ?")->execute([$idGrupo]);
                    $mensaje = 'El grupo fue aprobado por todos los aprobadores regulares y el aprobador final.';
                } elseif ($esFinal === 0) {
                    $mensaje = 'Aprobación regular registrada. Faltan ' . max(0, $requisitos['regular'] - $regulares) . ' aprobador(es) regular(es); el final podrá actuar después.';
                } else {
                    $mensaje = 'Aprobación final registrada. El grupo quedó aprobado.';
                }
                $mensaje .= ' Firma DNIe verificada: ' . $firma['nombre'] . ' (DNI ' . $firma['dni'] . ').';
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esSuperAdmin) {
    $accionSuperadmin = $_POST['accion'] ?? '';

    try {
        if ($accionSuperadmin === 'revertir_grupo') {
            $idGrupo = (int) ($_POST['id_grupo_revertir'] ?? 0);
            if ($idGrupo <= 0) {
                throw new Exception('Debes seleccionar un grupo para devolver al inicio.');
            }
            revertir_aprobacion_grupo($pdo, $idGrupo);
            $mensaje = 'La aprobación del grupo fue revertida y quedó como si nadie hubiera aprobado.';
        } elseif ($accionSuperadmin === 'quitar_aprobacion_usuario') {
            $idGrupo = (int) ($_POST['id_grupo'] ?? 0);
            $idUsuario = (int) ($_POST['id_usuario_aprobador'] ?? 0);
            if ($idGrupo <= 0 || $idUsuario <= 0) {
                throw new Exception('Selecciona el egreso y el usuario cuya aprobación deseas quitar.');
            }

            $stmtNombreUsuario = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ? LIMIT 1');
            $stmtNombreUsuario->execute([$idUsuario]);
            $nombreUsuario = (string) ($stmtNombreUsuario->fetchColumn() ?: '');
            $stmtQuitarVoto = $pdo->prepare('DELETE FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ? AND id_usuario = ?');
            $stmtQuitarVoto->execute([$idGrupo, $idUsuario]);
            if ($stmtQuitarVoto->rowCount() === 0) {
                throw new Exception('Ese usuario no tiene una aprobación registrada para este egreso.');
            }
            recalcular_estado_aprobacion_grupo($pdo, $idGrupo);
            $mensaje = 'Se quitó la aprobación de ' . ($nombreUsuario ?: 'ese usuario') . ' para el grupo seleccionado.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$esSuperAdmin && in_array(($_POST['accion'] ?? ''), ['revertir_grupo', 'quitar_aprobacion_usuario', 'revertir_todas_aprobaciones'], true)) {
    $error = 'Solo los superadministradores pueden ejecutar acciones de reversión en esta pantalla.';
}

try {
    $requisitosAprobacion = obtener_requisitos_aprobacion($pdo);
    $sqlGrupos = "
        SELECT a.id, a.nombre, a.fecha, a.total, a.estado_aprobacion,
               COALESCE(SUM(CASE WHEN ag.es_final = 0 THEN 1 ELSE 0 END), 0) AS aprobaciones_regulares,
               COALESCE(SUM(CASE WHEN ag.es_final = 1 THEN 1 ELSE 0 END), 0) AS aprobaciones_finales,
               MAX(CASE WHEN ag.id_usuario = ? THEN 1 ELSE 0 END) AS aprobado_por_mi
        FROM almacenamientos_egresos a
        LEFT JOIN aprobacion_grupos_egresos ag ON ag.id_almacenamiento = a.id
        WHERE a.estado_aprobacion IN ('en_aprobacion', 'aprobado')
        GROUP BY a.id, a.nombre, a.fecha, a.total, a.estado_aprobacion
        ORDER BY a.fecha DESC, a.id DESC
    ";
    $stmt = $pdo->prepare($sqlGrupos);
    $stmt->execute([$idUsuarioActual]);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $votosPorGrupo = [];
    $stmtVotos = $pdo->query('SELECT ag.id_almacenamiento, ag.id_usuario, ag.es_final, ag.fecha_aprobacion, u.nombre FROM aprobacion_grupos_egresos ag JOIN usuarios u ON u.id = ag.id_usuario ORDER BY ag.fecha_aprobacion, u.nombre');
    foreach ($stmtVotos->fetchAll(PDO::FETCH_ASSOC) as $voto) {
        $votosPorGrupo[(int) $voto['id_almacenamiento']][] = $voto;
    }
    foreach ($grupos as &$grupoCargado) {
        $votosRegularesActivos = 0;
        $votosFinalesActivos = 0;
        $aprobadoPorUsuarioActual = false;
        foreach ($votosPorGrupo[(int) $grupoCargado['id']] ?? [] as $votoGrupo) {
            $idVotante = (int) $votoGrupo['id_usuario'];
            if (!array_key_exists($idVotante, $tiposAprobadoresActivos) || $tiposAprobadoresActivos[$idVotante] !== (int) $votoGrupo['es_final']) {
                continue;
            }
            if ((int) $votoGrupo['es_final'] === 1) {
                $votosFinalesActivos++;
            } else {
                $votosRegularesActivos++;
            }
            if ($idVotante === $idUsuarioActual) {
                $aprobadoPorUsuarioActual = true;
            }
        }
        $grupoCargado['aprobaciones_regulares'] = $votosRegularesActivos;
        $grupoCargado['aprobaciones_finales'] = $votosFinalesActivos;
        $grupoCargado['aprobado_por_mi'] = $aprobadoPorUsuarioActual ? 1 : 0;
    }
    unset($grupoCargado);
    $stmtRechazos = $pdo->query('SELECT id, id_almacenamiento_original, nombre_grupo, total, motivo, nombre_usuario_rechaza, rol_aprobador, rechazado_en, detalles_json, aprobaciones_json FROM historial_grupos_rechazados ORDER BY rechazado_en DESC LIMIT 100');
    $rechazosRecientes = $stmtRechazos->fetchAll(PDO::FETCH_ASSOC);
    $grupos_pendientes = count(array_filter($grupos, static fn($grupo) => $grupo['estado_aprobacion'] === 'en_aprobacion'));

    $grupos_por_mes = [];
    foreach ($grupos as $grupo) {
        $mes = date('Y-m', strtotime($grupo['fecha']));
        $grupos_por_mes[$mes]['nombre'] = $mesesEnEspanol[(int) date('n', strtotime($grupo['fecha']))] . ' ' . date('Y', strtotime($grupo['fecha']));
        $grupos_por_mes[$mes]['grupos'][] = $grupo;
    }
    krsort($grupos_por_mes);

    $anosDisponibles = [];
    for ($ano = $anoActual - 10; $ano <= $anoActual; $ano++) {
        $anosDisponibles[$ano] = true;
    }
    foreach ($grupos as $grupo) {
        $anosDisponibles[(int) date('Y', strtotime($grupo['fecha']))] = true;
    }
    $anosDisponibles = array_keys($anosDisponibles);
    sort($anosDisponibles, SORT_NUMERIC);

    $anoSeleccionado = in_array($anoSeleccionado, $anosDisponibles, true) ? $anoSeleccionado : $anoActual;
    $mesSeleccionado = in_array($mesSeleccionado, range(1, 12), true) ? $mesSeleccionado : $mesActual;

    $sqlAprobados = "SELECT COALESCE(SUM(total), 0) FROM almacenamientos_egresos WHERE estado_aprobacion = 'aprobado' AND YEAR(fecha) = ? AND MONTH(fecha) = ?";
    $stmtAprobados = $pdo->prepare($sqlAprobados);
    $stmtAprobados->execute([$anoSeleccionado, $mesSeleccionado]);
    $aprobados_total = (float) ($stmtAprobados->fetchColumn() ?: 0);

    $grupos_filtrados = array_values(array_filter(
        $grupos,
        static fn($grupo) => (int) date('Y', strtotime($grupo['fecha'])) === $anoSeleccionado
            && (int) date('n', strtotime($grupo['fecha'])) === $mesSeleccionado
    ));
    $grupos_pendientes = count(array_filter($grupos_filtrados, static fn($grupo) => $grupo['estado_aprobacion'] === 'en_aprobacion'));

    $grupos_por_mes = [];
    foreach ($grupos_filtrados as $grupo) {
        $mes = date('Y-m', strtotime($grupo['fecha']));
        $grupos_por_mes[$mes]['nombre'] = $mesesEnEspanol[(int) date('n', strtotime($grupo['fecha']))] . ' ' . date('Y', strtotime($grupo['fecha']));
        $grupos_por_mes[$mes]['grupos'][] = $grupo;
    }
    krsort($grupos_por_mes);
} catch (Throwable $e) {
    $error = 'No se pudo cargar la información de aprobación.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Aprobación - CISMEDIC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .panel-hero {
            background: linear-gradient(135deg, #173b2d 0%, #0d6f61 52%, #1e5ba8 100%);
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
        .status-badge { align-items: center; border-radius: 999px; display: inline-flex; font-size: .67rem; font-weight: 800; justify-content: center; line-height: 1.1; padding: .46rem .72rem; text-align: center; white-space: nowrap; }
        .status-pendiente { background: #fff3d4; color: #795400; }
        .status-en-curso { background: #e7f3fb; color: #175b88; }
        .status-preaprobado { background: #e3f3e9; color: #08734e; }
        .status-personal { background: #e8f5ef; color: #087f5b; }
        .status-proveedor { background: #edf4ff; color: #365fa8; }
        .approval-progress { background: #e8eeeb; border-radius: 999px; box-shadow: inset 0 1px 2px rgba(24, 51, 39, .08); height: 10px; overflow: hidden; }
        .approval-progress .progress-bar { border-radius: 999px; transition: width .35s ease; }
        .approval-card { background: #fff; border: 1px solid #dce7e1; border-left: 5px solid #d4a02c; border-radius: 12px; box-shadow: 0 5px 18px rgba(29, 55, 43, .07); overflow: hidden; position: relative; transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
        .approval-card::before { background: #d4a02c; content: ""; height: 3px; left: 0; position: absolute; right: 0; top: 0; }
        .approval-card.is-in-progress { background: #fbfdff; border-color: #cfe1ed; border-left-color: #2581b9; }
        .approval-card.is-in-progress::before { background: #2581b9; }
        .approval-card.is-approved { background: #f9fdf9; border-color: #c8e2d2; border-left-color: #16845b; }
        .approval-card.is-approved::before { background: #16845b; }
        .approval-card:hover { box-shadow: 0 13px 28px rgba(29, 55, 43, .13); transform: translateY(-3px); }
        .approval-card .card-body { padding: 1.25rem 1.25rem 1.1rem; }
        .approval-card .card-body > .d-flex:first-child { align-items: center !important; margin-bottom: 1.05rem !important; }
        .approval-card .group-name { color: #183a2c; font-size: 1.03rem; font-weight: 850; line-height: 1.35; margin-top: .18rem; max-width: 42rem; }
        .approval-card .group-meta { color: #718078; font-size: .76rem; }
        .approval-card .amount { background: #f1f7f3; border: 1px solid #dceae1; border-radius: 7px; color: #193a2c; font-size: 1.08rem; font-variant-numeric: tabular-nums; font-weight: 850; padding: .28rem .55rem; }
        .approval-card.is-approved .group-name, .approval-card.is-approved .amount { color: #08734e; }
        .approval-card.is-in-progress .amount { color: #175b88; }
        .approval-card.is-approved .amount { background: #e8f5ec; border-color: #cde5d5; }
        .approval-card.is-in-progress .amount { background: #edf6fc; border-color: #d2e6f2; }
        .approval-card .approval-vote-summary { align-items: center; background: #f7f9f8; border-radius: 5px; display: flex; justify-content: space-between; margin-bottom: .42rem; min-height: 30px; padding: .28rem .55rem; }
        .approval-card.is-in-progress .approval-vote-summary { background: #eff7fc; }
        .approval-card.is-approved .approval-vote-summary { background: #edf7f0; }
        .filter-bar { background: #fff; border: 1px solid #e1ebe7; border-radius: 14px; box-shadow: 0 8px 24px rgba(23, 59, 45, .05); padding: 1rem; }
        .filter-bar label { color: #52655d; font-size: .75rem; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
        .filter-bar .form-select { border-color: #d7e2dc; border-radius: 10px; box-shadow: none; }
        .filter-bar .form-select:focus { border-color: #0d6f61; box-shadow: 0 0 0 .2rem rgba(13, 111, 97, .12); }
        .month-section { border: 1px solid #e2e8e5; border-radius: 18px; background: #fff; box-shadow: 0 8px 24px rgba(23, 59, 45, .05); overflow: hidden; }
        .month-section-header { background: linear-gradient(110deg, #eef6f1, #f4f7f5); border-bottom: 1px solid #dce7e1; padding: 1rem 1.25rem; }
        .month-section-header h4 { color: #173b2d; font-size: 1rem; font-weight: 800; margin: 0; }
        .month-section-header small { color: #5c6f68; }
        .month-total { color: #0d6f61; font-size: .82rem; font-weight: 800; }
        .approval-card .group-name { color: #183a2c; font-size: 1.03rem; font-weight: 850; line-height: 1.35; }
        .approval-card .group-meta { color: #718078; font-size: .75rem; }
        .approval-card .group-actions { border-top: 1px solid #e6ece9; margin-top: .85rem; padding-top: .8rem; }
        .approval-card .group-actions > .btn { border-radius: 7px; font-size: .81rem; font-weight: 750; min-height: 39px; }
        .approval-card .group-actions > .btn-outline-primary { background: #fff; border-color: #9bb8aa; color: #245b43; }
        .approval-card .group-actions > .btn-outline-primary:hover { background: #edf6f0; border-color: #56866d; color: #17472f; }
        .approval-card .group-actions .btn-success { background: #147c53; border-color: #147c53; box-shadow: 0 3px 8px rgba(20, 124, 83, .16); }
        .approval-card .group-actions .btn-success:hover { background: #106843; border-color: #106843; box-shadow: 0 5px 12px rgba(20, 124, 83, .22); }
        .approval-card .group-actions .alert { border-radius: 6px; font-size: .8rem; font-weight: 750; }
        .section-empty { background: #f8faf9; border: 1px dashed #cfe0d9; border-radius: 14px; color: #61736b; padding: 1.5rem; text-align: center; }
        .approval-detail-item { border: 1px solid #dce8e2; border-radius: 10px; padding: 1rem; }
        .approval-detail-item + .approval-detail-item { margin-top: 1rem; }
        .approval-attachment { background: #f6f9f7; border: 1px solid #dce8e2; border-radius: 8px; padding: .75rem; }
        .approval-document-head { background: #fff; border: 1px solid #d9e5df; border-top: 5px solid #087f5b; border-radius: 8px; box-shadow: 0 8px 22px rgba(23, 59, 45, .08); padding: 1.5rem; }
        .approval-document-kicker { color: #6d7b74; font-size: .68rem; font-weight: 800; text-transform: uppercase; }
        .approval-document-title { color: #173b2d; font-size: 1.35rem; font-weight: 800; overflow-wrap: anywhere; }
        .approval-document-meta { color: #55665e; font-size: .84rem; }
        .approval-document-total { color: #087f5b; font-size: 1.25rem; font-weight: 850; white-space: nowrap; }
        .approval-document-fields { border-top: 1px solid #d9e5df; display: grid !important; gap: .85rem 1rem; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 1rem; padding-top: 1rem; }
        .approval-document-field { background: #f7faf8; border: 1px solid #e1eae5; border-radius: 6px; min-width: 0; padding: .75rem; }
        .approval-document-field-label { color: #6d7b74; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
        .approval-document-field-value { color: #263b31; font-size: .82rem; font-weight: 650; margin-top: .25rem; overflow-wrap: anywhere; white-space: pre-wrap; }
        .approval-detail-heading { background: #edf3f0; border: 1px solid #d9e5df; border-radius: 6px; color: #516159; font-size: .68rem; font-weight: 800; padding: .7rem .9rem; text-transform: uppercase; }
        .approval-summary-table-wrap { border: 1px solid #d7e4dd; border-radius: 8px; margin-bottom: 1.5rem; overflow: hidden; }
        .approval-summary-table { margin: 0; min-width: 760px; }
        .approval-summary-table thead th { background: #e8f2ee; border-bottom: 1px solid #a8cbbb; color: #315244; font-size: .68rem; font-weight: 800; padding: .8rem .7rem; text-transform: uppercase; white-space: nowrap; }
        .approval-summary-table tbody td { border-color: #e5ece8; color: #33443c; font-size: .78rem; padding: .8rem .7rem; vertical-align: top; }
        .approval-summary-table tbody tr:nth-child(even) { background: #f7faf8; }
        .approval-summary-table .summary-document { color: #087f5b; font-weight: 750; }
        .approval-summary-table .summary-amount { color: #173b2d; font-weight: 800; text-align: right; white-space: nowrap; }
        .approval-detail-item { background: #fff; border-radius: 6px; padding: 1rem; }
        .approval-detail-item + .approval-detail-item { margin-top: .75rem; }
        .approval-attachment { background: #f7faf8; border-radius: 6px; }
        @media (max-width: 768px) { .approval-document-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 576px) { .approval-document-head { padding: 1rem; } .approval-document-title { font-size: 1.08rem; } .approval-document-summary { gap: .7rem !important; } .approval-document-fields { grid-template-columns: minmax(0, 1fr); } }
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
                <a href="aprobacion.php" class="nav-link active">
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
                    <span>Aprobación</span>
                </div>
            </div>

            <div class="container-fluid p-4">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <section class="panel-hero mb-4">
                    <div class="panel-kicker">Revisión financiera</div>
                    <h1 class="panel-title">Panel de aprobación</h1>
                    <p class="mb-0 opacity-75">Cada grupo requiere la aprobación de todos los aprobadores regulares configurados; después decide un aprobador final.</p>
                </section>

                <?php if (!empty($rechazosRecientes)): ?>
                <section class="alert alert-warning border-warning-subtle mb-4" aria-labelledby="tituloRechazosRecientes">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-bell-fill text-warning"></i>
                        <h2 class="h6 mb-0" id="tituloRechazosRecientes">Aviso de grupos rechazados recientemente</h2>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Grupo rechazado</th><th>Rechazado por</th><th>Motivo</th><th>Fecha</th><th class="text-end">Importe</th></tr></thead>
                            <tbody>
                                <?php foreach ($rechazosRecientes as $rechazo): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rechazo['nombre_grupo']) ?></td>
                                    <td><?= htmlspecialchars($rechazo['nombre_usuario_rechaza']) ?> (<?= htmlspecialchars($rechazo['rol_aprobador']) ?>)</td>
                                    <td><?= htmlspecialchars($rechazo['motivo']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($rechazo['rechazado_en'])) ?></td>
                                    <td class="text-end">S/. <?= number_format((float) $rechazo['total'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="small mt-2">Los egresos rechazados quedaron liberados para agruparse nuevamente. El registro se conserva para auditoría.</div>
                </section>
                <section class="card shadow-sm mb-4" aria-labelledby="tituloHistorialRechazos">
                    <div class="card-header bg-white"><h2 class="h6 mb-0" id="tituloHistorialRechazos"><i class="bi bi-archive me-2 text-secondary"></i>Historial de rechazos</h2></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($rechazosRecientes as $rechazo): ?>
                        <?php
                            $snapshotRechazo = json_decode((string) ($rechazo['detalles_json'] ?? ''), true);
                            $itemsRechazo = is_array($snapshotRechazo['egresos'] ?? null) ? $snapshotRechazo['egresos'] : [];
                            $votosRechazo = json_decode((string) ($rechazo['aprobaciones_json'] ?? ''), true);
                            $votosRechazo = is_array($votosRechazo) ? $votosRechazo : [];
                        ?>
                        <details class="list-group-item">
                            <summary class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                <span><strong><?= htmlspecialchars($rechazo['nombre_grupo']) ?></strong> · Grupo #<?= (int) $rechazo['id_almacenamiento_original'] ?></span>
                                <span class="small text-muted"><?= date('d/m/Y H:i', strtotime($rechazo['rechazado_en'])) ?> · <?= htmlspecialchars($rechazo['nombre_usuario_rechaza']) ?> (<?= htmlspecialchars($rechazo['rol_aprobador']) ?>) · S/. <?= number_format((float) $rechazo['total'], 2) ?></span>
                            </summary>
                            <div class="small mt-3"><strong>Motivo:</strong> <?= nl2br(htmlspecialchars($rechazo['motivo'])) ?></div>
                            <div class="small mt-2"><strong>Votos previos:</strong>
                                <?php if ($votosRechazo): ?>
                                    <?= htmlspecialchars(implode(', ', array_map(static fn($voto) => ($voto['nombre'] ?? 'Usuario') . ' (' . ((int) ($voto['es_final'] ?? 0) === 1 ? 'final' : 'regular') . ', ' . (!empty($voto['fecha_aprobacion']) ? date('d/m/Y H:i', strtotime($voto['fecha_aprobacion'])) : 'sin fecha') . ')', $votosRechazo))) ?>
                                <?php else: ?>Ninguno<?php endif; ?>
                            </div>
                            <?php if ($itemsRechazo): ?>
                            <div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0"><thead><tr><th>Fecha</th><th>Documento</th><th>Responsable / proveedor</th><th>Descripción</th><th class="text-end">Total</th></tr></thead><tbody>
                                <?php foreach ($itemsRechazo as $itemRechazo): ?>
                                <tr><td><?= !empty($itemRechazo['fecha']) ? date('d/m/Y', strtotime($itemRechazo['fecha'])) : '' ?></td><td><?= htmlspecialchars((string) ($itemRechazo['documento'] ?? '')) ?></td><td><?= htmlspecialchars((string) ($itemRechazo['proveedor_responsable'] ?? '')) ?></td><td><?= htmlspecialchars((string) ($itemRechazo['descripcion'] ?? '')) ?></td><td class="text-end">S/. <?= number_format((float) ($itemRechazo['total'] ?? 0), 2) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                            <?php endif; ?>
                        </details>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="card border-0 shadow-sm mb-4" aria-labelledby="tituloPruebaFirmaDnie">
                    <div class="card-body d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-patch-check-fill text-success"></i>
                                <h2 class="h6 mb-0" id="tituloPruebaFirmaDnie">Preparar firma digital DNIe</h2>
                                <span class="badge text-bg-warning">Prueba técnica</span>
                            </div>
                            <p class="small text-muted mb-0">Descarga un PDF de prueba, fírmalo en esta PC con Firma Perú y valida el archivo resultante. Esta prueba no aprueba ni modifica grupos.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-success btn-sm" href="firma_digital_prueba.php">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar PDF de prueba
                            </a>
                            <a class="btn btn-outline-dark btn-sm" href="storage/Firma_Digital/idplug-classic-4.6.1-Peru-es-64bits.msi" download>
                                <i class="bi bi-usb-symbol me-1"></i>Instalar middleware DNIe
                            </a>
                            <a class="btn btn-outline-secondary btn-sm" href="https://apps.firmaperu.gob.pe/web/firmador.xhtml" target="_blank" rel="noopener noreferrer">
                                <i class="bi bi-pen me-1"></i>Abrir Firma Perú
                            </a>
                            <a class="btn btn-outline-secondary btn-sm" href="https://apps.firmaperu.gob.pe/web/validador.xhtml" target="_blank" rel="noopener noreferrer">
                                <i class="bi bi-shield-check me-1"></i>Validar PDF firmado
                            </a>
                        </div>
                    </div>
                    <div class="card-footer bg-light border-0 small text-muted">
                        <i class="bi bi-info-circle me-1"></i>IDPlug Classic habilita el lector/certificados; Firma Perú es quien firma el PDF. Instala ambos y los certificados oficiales en cada PC. No ingreses el PIN del DNIe en CISMEDIC.
                    </div>
                </section>

                <?php if ($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Grupos pendientes</div>
                            <div class="metric-value"><?= $grupos_pendientes ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Aprobaciones registradas</div>
                            <div class="metric-value"><?= array_sum(array_map(static fn($grupo) => (int) $grupo['aprobaciones_regulares'] + (int) $grupo['aprobaciones_finales'], $grupos_filtrados)) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="metric-card">
                            <div class="metric-label">Aprobado acumulado</div>
                            <div class="metric-value">S/. <?= number_format($aprobados_total, 2) ?></div>
                        </div>
                    </div>
                </div>

                <?php if (!$puedeAprobar): ?>
                <div class="alert alert-warning border mb-4">
                    <i class="bi bi-shield-exclamation me-2"></i>No estás configurado como aprobador de egresos. Solo los tres usuarios seleccionados por el superadministrador pueden aprobar.
                </div>
                <?php endif; ?>

                <section class="table-card">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                        <div>
                            <h4 class="mb-1">Grupos de egresos</h4>
                            <small class="text-muted">Periodo: <strong><?= htmlspecialchars($mesesEnEspanol[$mesSeleccionado] . ' ' . (string) $anoSeleccionado) ?></strong> · Se muestran pendientes y aprobados</small>
                        </div>
                        <span class="badge bg-light text-dark border"><?= count($grupos_filtrados) ?> grupo<?= count($grupos_filtrados) === 1 ? '' : 's' ?></span>
                    </div>

                    <form method="GET" class="filter-bar mb-4">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="ano">Año</label>
                                <select class="form-select" id="ano" name="ano" onchange="this.form.submit()">
                                    <?php foreach ($anosDisponibles as $ano): ?>
                                        <option value="<?= (int) $ano ?>" <?= (int) $ano === $anoSeleccionado ? 'selected' : '' ?>><?= htmlspecialchars((string) $ano) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="mes">Mes</label>
                                <select class="form-select" id="mes" name="mes" onchange="this.form.submit()">
                                    <?php foreach ($mesesEnEspanol as $numeroMes => $nombreMes): ?>
                                        <option value="<?= $numeroMes ?>" <?= $numeroMes === $mesSeleccionado ? 'selected' : '' ?>><?= htmlspecialchars($nombreMes) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <a class="btn btn-outline-primary w-100" href="aprobacion.php?ano=<?= $anoActual ?>&mes=<?= $mesActual ?>">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Mes actual
                                </a>
                            </div>
                        </div>
                    </form>

                    <?php if (empty($grupos_filtrados)): ?>
                    <div class="section-empty">
                        <i class="bi bi-calendar-x me-2 text-warning"></i>No hay grupos para el periodo seleccionado.
                    </div>
                    <?php else: ?>
                        <?php foreach ($grupos_por_mes as $mes => $mesData): ?>
                        <section class="month-section mb-4" aria-labelledby="mes-<?= htmlspecialchars($mes) ?>">
                            <div class="month-section-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 id="mes-<?= htmlspecialchars($mes) ?>"><?= htmlspecialchars($mesData['nombre']) ?></h4>
                                    <small><?= count($mesData['grupos']) ?> grupo<?= count($mesData['grupos']) === 1 ? '' : 's' ?> · <?= count(array_filter($mesData['grupos'], static fn($item) => $item['estado_aprobacion'] === 'en_aprobacion')) ?> pendientes</small>
                                </div>
                                <span class="month-total">
                                    Total: S/. <?= number_format(array_sum(array_column($mesData['grupos'], 'total')), 2) ?>
                                </span>
                            </div>
                            <div class="p-3">
                                <div class="row g-3">
                                    <?php foreach ($mesData['grupos'] as $grupo): ?>
                                    <?php
                                        $votosRegularesGrupo = min((int) $grupo['aprobaciones_regulares'], (int) $requisitosAprobacion['regular']);
                                        $votosFinalesGrupo = min((int) $grupo['aprobaciones_finales'], (int) $requisitosAprobacion['final']);
                                        $grupoAprobadoCompleto = $grupo['estado_aprobacion'] === 'aprobado';
                                        $grupoEnCurso = !$grupoAprobadoCompleto && ($votosRegularesGrupo > 0 || $votosFinalesGrupo > 0);
                                        $claseEstadoGrupo = $grupoAprobadoCompleto ? 'is-approved' : ($grupoEnCurso ? 'is-in-progress' : '');
                                        $porcentajeAprobacionGrupo = $grupoAprobadoCompleto ? 100 : min(100, (($votosRegularesGrupo + $votosFinalesGrupo) / ((int) $requisitosAprobacion['regular'] + (int) $requisitosAprobacion['final'])) * 100);
                                        $esVotanteFinal = $tipoAprobadorActual === 1;
                                        $puedeDecidirEtapa = $puedeAprobar
                                            && !$grupoAprobadoCompleto
                                            && !(bool) $grupo['aprobado_por_mi']
                                            && ($esVotanteFinal
                                                ? $votosRegularesGrupo >= (int) $requisitosAprobacion['regular']
                                                : $votosRegularesGrupo < (int) $requisitosAprobacion['regular']);
                                    ?>
                                    <div class="col-xl-6">
                                        <article class="approval-card h-100 <?= $claseEstadoGrupo ?>">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                                    <div>
                                                        <div class="group-meta mb-1"><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($grupo['fecha'])) ?></div>
                                                            <h5 class="group-name mb-0"><?= htmlspecialchars($grupo['nombre']) ?></h5>
                                                    </div>
                                                    <span class="status-badge <?= $grupoAprobadoCompleto ? 'status-preaprobado' : ($grupoEnCurso ? 'status-en-curso' : 'status-pendiente') ?>">
                                                        <?php if ($grupoAprobadoCompleto): ?><i class="bi bi-check-circle-fill me-1"></i>Aprobado<?php elseif ($grupoEnCurso): ?><i class="bi bi-arrow-repeat me-1"></i>En revisión<?php else: ?><i class="bi bi-hourglass-split me-1"></i>Pendiente<?php endif; ?>
                                                    </span>
                                                </div>
                                                <div class="approval-vote-summary">
                                                        <span class="group-meta"><?= $votosRegularesGrupo ?>/<?= (int) $requisitosAprobacion['regular'] ?> regulares · <?= $votosFinalesGrupo ?>/<?= (int) $requisitosAprobacion['final'] ?> final</span>
                                                    <span class="amount">S/. <?= number_format((float) $grupo['total'], 2) ?></span>
                                                </div>
                                                <div class="progress approval-progress" role="progressbar" aria-label="Progreso de aprobación del grupo <?= htmlspecialchars($grupo['nombre']) ?>" aria-valuenow="<?= (int) $porcentajeAprobacionGrupo ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="progress-bar <?= $grupoAprobadoCompleto ? 'bg-success' : ($grupoEnCurso ? 'bg-info' : 'bg-warning') ?>" style="width: <?= $porcentajeAprobacionGrupo ?>%"></div>
                                                </div>
                                                <div class="group-actions">
                                                    <button class="btn btn-outline-primary w-100 mb-2" type="button" data-ver-detalle-grupo="<?= (int) $grupo['id'] ?>">
                                                        <i class="bi bi-eye me-1"></i>Ver egresos y comprobantes
                                                    </button>
                                                    <?php if ($grupoAprobadoCompleto): ?>
                                                    <div class="alert alert-success mb-0 py-2 text-center">
                                                        <i class="bi bi-check-circle-fill me-2"></i>Aprobación completa
                                                    </div>
                                                    <?php elseif ($puedeDecidirEtapa): ?>
                                                    <div class="small text-muted mb-2"><i class="bi bi-pen me-1"></i>Para aprobar: 1) descarga el expediente, 2) fírmalo con tu DNIe en Firma Perú sin modificarlo, 3) sube aquí el PDF firmado.</div>
                                                    <a class="btn btn-outline-success w-100 mb-2" href="expediente_firma.php?id=<?= (int) $grupo['id'] ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i>Descargar expediente para firmar</a>
                                                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('¿Confirmas aprobar este grupo con el PDF firmado?');">
                                                        <input type="hidden" name="accion" value="aprobar_grupo">
                                                        <label class="form-label small text-muted mb-1" for="pdf-firmado-<?= (int) $grupo['id'] ?>">PDF firmado con DNIe</label>
                                                        <input class="form-control form-control-sm mb-2" type="file" id="pdf-firmado-<?= (int) $grupo['id'] ?>" name="pdf_firmado" accept="application/pdf,.pdf" required>
                                                        <input type="hidden" name="id_grupo" value="<?= (int) $grupo['id'] ?>">
                                                        <input type="hidden" name="ano" value="<?= (int) $anoSeleccionado ?>">
                                                        <input type="hidden" name="mes" value="<?= (int) $mesSeleccionado ?>">
                                                        <button class="btn btn-success w-100" type="submit">
                                                            <i class="bi bi-shield-check me-1"></i>Validar firma y aprobar
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="mt-2" onsubmit="return confirm('¿Rechazar el grupo? Se archivará para auditoría y sus egresos quedarán disponibles para una nueva agrupación.');">
                                                        <input type="hidden" name="accion" value="rechazar_grupo">
                                                        <input type="hidden" name="id_grupo" value="<?= (int) $grupo['id'] ?>">
                                                        <input type="hidden" name="ano" value="<?= (int) $anoSeleccionado ?>">
                                                        <input type="hidden" name="mes" value="<?= (int) $mesSeleccionado ?>">
                                                        <label class="form-label small text-muted mb-1" for="motivo-rechazo-<?= (int) $grupo['id'] ?>">Motivo del rechazo</label>
                                                        <textarea class="form-control form-control-sm mb-2" id="motivo-rechazo-<?= (int) $grupo['id'] ?>" name="motivo_rechazo" rows="2" maxlength="2000" required placeholder="Indica por qué se rechaza este grupo"></textarea>
                                                        <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-circle me-1"></i>Rechazar y devolver egresos</button>
                                                    </form>
                                                    <?php elseif ($puedeAprobar && (bool) $grupo['aprobado_por_mi']): ?>
                                                    <div class="alert alert-light border mb-0 py-2">
                                                        <i class="bi bi-check-circle me-2 text-success"></i>Ya registraste tu aprobación.
                                                    </div>
                                                    <?php elseif ($puedeAprobar && $esVotanteFinal && !$grupoAprobadoCompleto): ?>
                                                    <div class="alert alert-info mb-0 py-2"><i class="bi bi-hourglass-split me-2"></i>El aprobador final podrá decidir cuando todos los aprobadores regulares hayan aprobado.</div>
                                                    <?php else: ?>
                                                    <div class="text-muted small text-center">Esperando aprobaciones de los usuarios autorizados.</div>
                                                    <?php endif; ?>
                                                    <?php if ($esSuperAdmin): ?>
                                                    <div class="border-top mt-3 pt-3">
                                                        <div class="small fw-bold text-danger mb-2"><i class="bi bi-shield-lock me-1"></i>Acciones de superadmin</div>
                                                        <div class="d-flex flex-column flex-sm-row gap-2">
                                                            <form method="POST" class="flex-fill" onsubmit="return confirm('¿Devolver este grupo al inicio y borrar todas sus aprobaciones?');">
                                                                <input type="hidden" name="accion" value="revertir_grupo">
                                                                <input type="hidden" name="id_grupo_revertir" value="<?= (int) $grupo['id'] ?>">
                                                                <button class="btn btn-outline-danger btn-sm w-100" type="submit" <?= (int) $grupo['aprobaciones_regulares'] + (int) $grupo['aprobaciones_finales'] === 0 ? 'disabled' : '' ?>>
                                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Revertir egresos
                                                                </button>
                                                            </form>
                                                            <?php if (!empty($votosPorGrupo[(int) $grupo['id']])): ?>
                                                            <form method="POST" class="flex-fill" onsubmit="return confirm('¿Quitar la aprobación seleccionada de este grupo?');">
                                                                <input type="hidden" name="accion" value="quitar_aprobacion_usuario">
                                                                <input type="hidden" name="id_grupo" value="<?= (int) $grupo['id'] ?>">
                                                                <div class="input-group input-group-sm">
                                                                    <select class="form-select" name="id_usuario_aprobador" aria-label="Aprobador cuya aprobación se quitará" required>
                                                                        <option value="">Quitar voto de...</option>
                                                                        <?php foreach ($votosPorGrupo[(int) $grupo['id']] as $voto): ?>
                                                                            <option value="<?= (int) $voto['id_usuario'] ?>"><?= htmlspecialchars($voto['nombre']) ?> · <?= (int) $voto['es_final'] === 1 ? 'Final' : 'Regular' ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <button class="btn btn-outline-warning" type="submit" aria-label="Quitar aprobación del usuario seleccionado" title="Quitar aprobación">
                                                                        <i class="bi bi-person-dash"></i>
                                                                    </button>
                                                                </div>
                                                            </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </article>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <div class="modal fade" id="modalDetalleGrupoAprobacion" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <div>
                        <h5 class="modal-title mb-1">Revisión de egresos agrupados</h5>
                        <small>Información del grupo y comprobantes adjuntos</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="contenidoDetalleGrupoAprobacion">
                    <div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando egresos y comprobantes...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js?v=20260820-2"></script>
    <script>
        const modalDetalleGrupo = document.getElementById('modalDetalleGrupoAprobacion');
        const tituloDetalleGrupo = document.getElementById('tituloDetalleGrupoAprobacion');
        const resumenDetalleGrupo = document.getElementById('resumenDetalleGrupoAprobacion');
        const contenidoDetalleGrupo = document.getElementById('contenidoDetalleGrupoAprobacion');

        function mostrarAdjuntoDetalle(detalle, contenedor) {
            const bloque = document.createElement('div');
            bloque.className = 'approval-attachment mt-3';
            const encabezado = document.createElement('div');
            encabezado.className = 'd-flex align-items-center gap-2 mb-2';
            const icono = document.createElement('i');
            icono.className = detalle.comprobante_mime?.startsWith('image/') ? 'bi bi-image text-success' : 'bi bi-file-earmark-pdf text-danger';
            const nombre = document.createElement('strong');
            nombre.textContent = detalle.comprobante_nombre || 'Comprobante adjunto';
            encabezado.append(icono, nombre);
            bloque.appendChild(encabezado);

            if (!detalle.comprobante_mime || !['personal', 'proveedores'].includes(detalle.fuente_egreso) || !Number(detalle.id_egreso)) {
                const sinArchivo = document.createElement('div');
                sinArchivo.className = 'small text-muted py-2';
                sinArchivo.textContent = 'Este egreso no tiene un archivo digital adjunto.';
                bloque.appendChild(sinArchivo);
                contenedor.appendChild(bloque);
                return;
            }

            const url = new URL('egresos.php', window.location.href);
            url.searchParams.set('ver_comprobante', '1');
            url.searchParams.set('fuente', detalle.fuente_egreso);
            url.searchParams.set('id', String(detalle.id_egreso));
            if (detalle.comprobante_mime.startsWith('image/')) {
                const enlace = document.createElement('a');
                enlace.href = 'comprobante.php?fuente=' + encodeURIComponent(detalle.fuente_egreso) + '&id=' + encodeURIComponent(String(detalle.id_egreso));
                enlace.target = '_blank';
                enlace.rel = 'noopener';
                const imagen = document.createElement('img');
                imagen.src = url.toString();
                imagen.alt = nombre.textContent;
                imagen.className = 'img-fluid d-block mx-auto rounded';
                imagen.style.maxHeight = '480px';
                imagen.style.objectFit = 'contain';
                enlace.appendChild(imagen);
                bloque.appendChild(enlace);
            } else if (detalle.comprobante_mime === 'application/pdf') {
                const pdf = document.createElement('iframe');
                pdf.src = url.toString();
                pdf.title = nombre.textContent;
                pdf.style.width = '100%';
                pdf.style.height = '480px';
                pdf.style.border = '1px solid #d7e0dc';
                pdf.style.borderRadius = '6px';
                bloque.appendChild(pdf);
            } else {
                const tipoNoAdmitido = document.createElement('div');
                tipoNoAdmitido.className = 'small text-muted';
                tipoNoAdmitido.textContent = 'El tipo de archivo no se puede previsualizar.';
                bloque.appendChild(tipoNoAdmitido);
            }
            contenedor.appendChild(bloque);
        }

        document.querySelectorAll('[data-ver-detalle-grupo]').forEach(function (boton) {
            boton.addEventListener('click', async function () {
                const idGrupo = this.dataset.verDetalleGrupo;
                contenidoDetalleGrupo.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando egresos y comprobantes...</div>';
                bootstrap.Modal.getOrCreateInstance(modalDetalleGrupo).show();
                try {
                    const url = new URL('aprobacion.php', window.location.href);
                    url.searchParams.set('detalle_grupo', idGrupo);
                    const respuesta = await fetch(url, { cache: 'no-store', headers: { 'Accept': 'application/json' } });
                    const datos = await respuesta.json();
                    if (!respuesta.ok || !datos.ok) throw new Error(datos.mensaje || 'No se pudo cargar el grupo.');

                    contenidoDetalleGrupo.replaceChildren();
                    const cabeceraDocumento = document.createElement('section');
                    cabeceraDocumento.className = 'approval-document-head mb-4';
                    const filaDocumento = document.createElement('div');
                    filaDocumento.className = 'd-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3';
                    const identidadDocumento = document.createElement('div');
                    const kickerDocumento = document.createElement('div');
                    kickerDocumento.className = 'approval-document-kicker mb-1';
                    kickerDocumento.textContent = 'Grupo de egresos · Revisión financiera';
                    const tituloDocumento = document.createElement('h3');
                    tituloDocumento.className = 'approval-document-title mb-2';
                    tituloDocumento.textContent = datos.grupo.nombre;
                    const fechaDocumento = document.createElement('div');
                    fechaDocumento.className = 'approval-document-meta';
                    fechaDocumento.textContent = 'Fecha de almacenamiento: ' + new Date(datos.grupo.fecha + 'T00:00:00').toLocaleDateString('es-PE');
                    identidadDocumento.append(kickerDocumento, tituloDocumento, fechaDocumento);

                    const resumenDocumento = document.createElement('div');
                    resumenDocumento.className = 'd-flex flex-row flex-md-column align-items-md-end gap-3 approval-document-summary';
                    const estadoDocumento = document.createElement('span');
                    estadoDocumento.className = 'status-badge ' + (datos.grupo.estado_aprobacion === 'aprobado' ? 'status-preaprobado' : 'status-pendiente');
                    estadoDocumento.textContent = datos.grupo.estado_aprobacion === 'aprobado' ? 'Aprobado' : 'En aprobación';
                    const totalDocumento = document.createElement('div');
                    totalDocumento.className = 'approval-document-total';
                    totalDocumento.textContent = 'S/. ' + Number(datos.grupo.total).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const descargarActa = document.createElement('a');
                    descargarActa.className = 'btn btn-outline-success btn-sm';
                    descargarActa.href = 'visor_acta_aprobacion.php?id=' + encodeURIComponent(String(datos.grupo.id));
                    descargarActa.target = '_blank';
                    descargarActa.rel = 'noopener';
                    descargarActa.innerHTML = '<i class="bi bi-file-earmark-pdf me-1"></i>Ver acta PDF';
                    resumenDocumento.append(estadoDocumento, totalDocumento, descargarActa);
                    filaDocumento.append(identidadDocumento, resumenDocumento);

                    const indicadoresDocumento = document.createElement('div');
                    indicadoresDocumento.className = 'approval-document-fields';
                    const agregarIndicadorGrupo = function (etiqueta, valor, icono) {
                        const columna = document.createElement('div');
                        columna.className = 'approval-document-field';
                        const label = document.createElement('div');
                        label.className = 'approval-document-field-label';
                        label.textContent = etiqueta;
                        const dato = document.createElement('div');
                        dato.className = 'approval-document-field-value';
                        dato.textContent = valor || 'No registrado';
                        if (icono) {
                            const iconoDato = document.createElement('i');
                            iconoDato.className = 'bi ' + icono + ' me-2 text-success';
                            label.prepend(iconoDato);
                        }
                        columna.append(label, dato);
                        indicadoresDocumento.appendChild(columna);
                    };
                    agregarIndicadorGrupo('Registrado por', datos.grupo.usuario_registra, 'bi-person');
                    agregarIndicadorGrupo('Egresos incluidos', String(datos.detalles.length), 'bi-receipt');
                    const nombresRegulares = datos.grupo.nombres_aprobadores_regulares || 'Nadie todavía';
                    const nombresFinales = datos.grupo.nombres_aprobadores_finales || 'Nadie todavía';
                    const cantidadRegularesRequerida = Number(datos.requisitos?.regular || 0);
                    const cantidadFinalesRequerida = Number(datos.requisitos?.final || 0);
                    agregarIndicadorGrupo('Aprobaciones regulares', Math.min(Number(datos.grupo.aprobaciones_regulares || 0), cantidadRegularesRequerida) + ' / ' + cantidadRegularesRequerida + '\n' + nombresRegulares, 'bi-check2-circle');
                    agregarIndicadorGrupo('Aprobación final', Math.min(Number(datos.grupo.aprobaciones_finales || 0), cantidadFinalesRequerida) + ' / ' + cantidadFinalesRequerida + ' requerida\n' + nombresFinales, 'bi-award');
                    cabeceraDocumento.append(filaDocumento, indicadoresDocumento);
                    contenidoDetalleGrupo.appendChild(cabeceraDocumento);

                    if (!datos.detalles.length) {
                        const vacio = document.createElement('div');
                        vacio.className = 'section-empty';
                        vacio.textContent = 'Este grupo no tiene egresos registrados.';
                        contenidoDetalleGrupo.appendChild(vacio);
                        return;
                    }

                    const seccionResumen = document.createElement('section');
                    seccionResumen.className = 'mb-4';
                    const tituloResumen = document.createElement('div');
                    tituloResumen.className = 'd-flex justify-content-between align-items-center gap-2 mb-2';
                    const encabezadoResumen = document.createElement('h6');
                    encabezadoResumen.className = 'mb-0 text-uppercase fw-bold';
                    encabezadoResumen.textContent = 'Resumen de egresos del grupo';
                    const cuentaResumen = document.createElement('span');
                    cuentaResumen.className = 'badge bg-light text-dark border';
                    cuentaResumen.textContent = datos.detalles.length + ' registros';
                    tituloResumen.append(encabezadoResumen, cuentaResumen);

                    const contenedorTabla = document.createElement('div');
                    contenedorTabla.className = 'table-responsive approval-summary-table-wrap';
                    const tablaResumen = document.createElement('table');
                    tablaResumen.className = 'table approval-summary-table';
                    const theadResumen = document.createElement('thead');
                    const filaTitulos = document.createElement('tr');
                    ['#', 'Fecha', 'Documento', 'Responsable / proveedor', 'Descripción', 'Total'].forEach(function (texto) {
                        const celda = document.createElement('th');
                        celda.scope = 'col';
                        celda.textContent = texto;
                        filaTitulos.appendChild(celda);
                    });
                    theadResumen.appendChild(filaTitulos);
                    const tbodyResumen = document.createElement('tbody');
                    datos.detalles.forEach(function (detalle, indice) {
                        const fila = document.createElement('tr');
                        const valores = [
                            String(indice + 1),
                            detalle.fecha ? new Date(detalle.fecha + 'T00:00:00').toLocaleDateString('es-PE') : 'Sin fecha',
                            detalle.documento || 'Sin documento',
                            detalle.proveedor_responsable || 'No identificado',
                            detalle.descripcion || 'Sin descripción',
                            'S/. ' + Number(detalle.total || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        ];
                        valores.forEach(function (valor, indiceColumna) {
                            const celda = document.createElement('td');
                            celda.textContent = valor;
                            if (indiceColumna === 2) celda.className = 'summary-document';
                            if (indiceColumna === 5) celda.className = 'summary-amount';
                            fila.appendChild(celda);
                        });
                        tbodyResumen.appendChild(fila);
                    });
                    tablaResumen.append(theadResumen, tbodyResumen);
                    contenedorTabla.appendChild(tablaResumen);
                    seccionResumen.append(tituloResumen, contenedorTabla);
                    contenidoDetalleGrupo.appendChild(seccionResumen);

                    const tituloComprobantes = document.createElement('h6');
                    tituloComprobantes.className = 'text-uppercase fw-bold mb-3';
                    tituloComprobantes.textContent = 'Egresos y comprobantes adjuntos';
                    contenidoDetalleGrupo.appendChild(tituloComprobantes);

                    const encabezadoDetalles = document.createElement('div');
                    encabezadoDetalles.className = 'approval-detail-heading d-none d-md-grid mb-2';
                    encabezadoDetalles.style.gridTemplateColumns = '1.2fr 1fr 1fr auto';
                    ['Comprobante', 'Fecha', 'Responsable / proveedor', 'Monto'].forEach(function (texto) {
                        const celda = document.createElement('span');
                        celda.textContent = texto;
                        encabezadoDetalles.appendChild(celda);
                    });
                    contenidoDetalleGrupo.appendChild(encabezadoDetalles);

                    datos.detalles.forEach(function (detalle, indice) {
                        const tarjeta = document.createElement('article');
                        tarjeta.className = 'approval-detail-item';
                        const cabecera = document.createElement('div');
                        cabecera.className = 'row g-2 align-items-start mb-3';
                        const titulo = document.createElement('h6');
                        titulo.className = 'mb-0 text-success text-break';
                        titulo.textContent = (indice + 1) + '. ' + (detalle.documento || 'Egreso sin comprobante');
                        const columnaTitulo = document.createElement('div');
                        columnaTitulo.className = 'col-md-4';
                        columnaTitulo.appendChild(titulo);
                        const columnaFecha = document.createElement('div');
                        columnaFecha.className = 'col-md-2 small text-muted';
                        columnaFecha.textContent = detalle.fecha ? new Date(detalle.fecha + 'T00:00:00').toLocaleDateString('es-PE') : 'Sin fecha';
                        const columnaResponsable = document.createElement('div');
                        columnaResponsable.className = 'col-md-3 small';
                        columnaResponsable.textContent = detalle.proveedor_responsable || 'No identificado';
                        const monto = document.createElement('strong');
                        monto.className = 'col-md-3 text-md-end text-nowrap';
                        monto.textContent = 'S/. ' + Number(detalle.total || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        cabecera.append(columnaTitulo, columnaFecha, columnaResponsable, monto);
                        const descripcion = document.createElement('div');
                        descripcion.className = 'small text-muted border-top pt-2';
                        descripcion.textContent = detalle.descripcion || 'Sin descripción registrada.';
                        tarjeta.append(cabecera, descripcion);
                        mostrarAdjuntoDetalle(detalle, tarjeta);
                        contenidoDetalleGrupo.appendChild(tarjeta);
                    });
                } catch (error) {
                    contenidoDetalleGrupo.textContent = error.message || 'Ocurrió un error al consultar el grupo.';
                }
            });
        });
    </script>
</body>
</html>
