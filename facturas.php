<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

date_default_timezone_set('America/Lima');

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

requerir_roles(['superadmin', 'administrativo']);

require_once 'config/database.php';

function prefijoTipoComprobanteFactura(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));

    return match ($tipo) {
        'FACTURA' => 'F',
        'RHE' => 'E',
        default => '',
    };
}

function formatearComprobanteFactura(string $tipo, string $valor): string
{
    $tipo = strtoupper(trim($tipo));
    $valor = strtoupper(trim((string) $valor));
    if (in_array($tipo, ['FACTURA', 'RHE'], true)) {
        $compacto = preg_replace('/[^A-Z0-9]/', '', $valor);
        $serie = substr($compacto, 0, 4);
        $correlativo = substr(preg_replace('/[^0-9]/', '', substr($compacto, 4)), 0, 8);
        return strlen($serie) < 4 ? $serie : $serie . '-' . $correlativo;
    }

    return mb_substr($valor, 0, 100);
}

function esComprobanteValidoFactura(string $tipo, string $valor): bool
{
    $tipo = strtoupper(trim($tipo));
    $valor = strtoupper(trim((string) $valor));
    $valor = preg_replace('/\s+/', '', $valor);
    if (in_array($tipo, ['FACTURA', 'RHE'], true)) {
        return (bool) preg_match('/^[A-Z0-9]{4}-[0-9]{1,8}$/', $valor);
    }

    return $valor !== '' && mb_strlen($valor) <= 100 && !preg_match('/[\x00-\x1F\x7F]/', $valor);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS categoria_global_subcategoria (
    id_categoria_global INT NOT NULL,
    id_subcategoria INT NOT NULL,
    PRIMARY KEY (id_categoria_global, id_subcategoria),
    CONSTRAINT fk_facturas_cgs_global FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE CASCADE,
    CONSTRAINT fk_facturas_cgs_subcategoria FOREIGN KEY (id_subcategoria) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT IGNORE INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) SELECT id_categoria_global, id FROM categorias WHERE id_categoria_global IS NOT NULL");
$pdo->exec('ALTER TABLE facturas MODIFY total DECIMAL(14,4) NOT NULL, MODIFY igv DECIMAL(14,4) NOT NULL');
$pdo->exec('ALTER TABLE detalle_factura MODIFY cantidad DECIMAL(14,4) NOT NULL, MODIFY precio_unitario DECIMAL(14,4) NOT NULL, MODIFY subtotal DECIMAL(14,4) NOT NULL, MODIFY igv DECIMAL(14,4) NOT NULL, MODIFY subtotal_con_igv DECIMAL(14,4) NOT NULL');

$facturas = [];
$detalles_por_factura = [];
$stats = ['total' => 0, 'igv' => 0];
$mes_filtro = $_GET['mes'] ?? date('m');
$ano_filtro = $_GET['ano'] ?? date('Y');
$comprobante_filtro = trim($_GET['comprobante'] ?? '');
$anos_disponibles = [];
$error = null;
$datos_pdf = [];
$mensaje = null;
$proveedores = [];
$categorias = [];
$categorias_globales = [];

if (($_GET['ajax'] ?? '') === 'proveedor_sugerencias') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $busqueda_proveedor = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($busqueda_proveedor) < 2) {
            echo json_encode(['ok' => true, 'proveedores' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare('SELECT id, nombre, ruc FROM proveedores WHERE nombre LIKE ? OR ruc LIKE ? ORDER BY nombre LIMIT 15');
        $termino_proveedor = '%' . $busqueda_proveedor . '%';
        $stmt->execute([$termino_proveedor, $termino_proveedor]);
        echo json_encode(['ok' => true, 'proveedores' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudieron buscar proveedores.']);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'reporte_proveedor') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $id_proveedor_reporte = filter_input(INPUT_GET, 'proveedor', FILTER_VALIDATE_INT);
        if (!$id_proveedor_reporte || $id_proveedor_reporte < 1) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'mensaje' => 'Selecciona un proveedor válido.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, nombre, ruc FROM proveedores WHERE id = ? LIMIT 1');
        $stmt->execute([$id_proveedor_reporte]);
        $proveedor_reporte = $stmt->fetch();
        if (!$proveedor_reporte) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'mensaje' => 'No se encontró el proveedor.']);
            exit;
        }

        $ano_reporte = trim((string) ($_GET['ano_reporte'] ?? ''));
        if ($ano_reporte !== '' && !preg_match('/^\d{4}$/', $ano_reporte)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'mensaje' => 'El año seleccionado no es válido.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT DISTINCT YEAR(fecha) AS ano FROM facturas WHERE id_proveedor = ? AND fecha IS NOT NULL ORDER BY ano DESC');
        $stmt->execute([$id_proveedor_reporte]);
        $anos_proveedor = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $pdo->prepare('SELECT YEAR(fecha) AS ano, COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(igv), 0) AS igv FROM facturas WHERE id_proveedor = ? AND fecha IS NOT NULL GROUP BY YEAR(fecha) ORDER BY ano DESC');
        $stmt->execute([$id_proveedor_reporte]);
        $resumenes_anuales = array_map(static function ($fila) {
            return ['ano' => (int) $fila['ano'], 'cantidad' => (int) $fila['cantidad'], 'total' => (float) $fila['total'], 'igv' => (float) $fila['igv']];
        }, $stmt->fetchAll());

        $condicion_ano = $ano_reporte === '' ? '' : ' AND YEAR(fecha) = ?';
        $parametros_reporte = $ano_reporte === '' ? [$id_proveedor_reporte] : [$id_proveedor_reporte, (int) $ano_reporte];
        $stmt = $pdo->prepare('SELECT COUNT(*) AS cantidad, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(igv), 0) AS igv, MIN(fecha) AS primera_fecha, MAX(fecha) AS ultima_fecha FROM facturas WHERE id_proveedor = ?' . $condicion_ano);
        $stmt->execute($parametros_reporte);
        $resumen_proveedor = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT id, tipo_comprobante, numero_comprobante, fecha, descripcion, total, igv FROM facturas WHERE id_proveedor = ?' . $condicion_ano . ' ORDER BY fecha DESC, id DESC');
        $stmt->execute($parametros_reporte);
        $facturas_proveedor = $stmt->fetchAll();
        $ids_facturas_proveedor = array_column($facturas_proveedor, 'id');
        $detalles_reporte = [];
        $categorias_reporte = [];
        if ($ids_facturas_proveedor) {
            $marcadores_facturas = implode(',', array_fill(0, count($ids_facturas_proveedor), '?'));
            $stmt = $pdo->prepare("SELECT d.id_factura, d.producto, d.cantidad, d.precio_unitario, d.subtotal, d.igv, d.subtotal_con_igv AS total, COALESCE(c.nombre, 'Sin categoría') AS categoria FROM detalle_factura d LEFT JOIN categorias c ON c.id = d.id_categoria WHERE d.id_factura IN ($marcadores_facturas) ORDER BY d.id_factura, d.id");
            $stmt->execute($ids_facturas_proveedor);
            foreach ($stmt->fetchAll() as $detalle_reporte) {
                $detalle_reporte['cantidad'] = (float) $detalle_reporte['cantidad'];
                $detalle_reporte['precio_unitario'] = (float) $detalle_reporte['precio_unitario'];
                $detalle_reporte['subtotal'] = (float) $detalle_reporte['subtotal'];
                $detalle_reporte['igv'] = (float) $detalle_reporte['igv'];
                $detalle_reporte['total'] = (float) $detalle_reporte['total'];
                $detalles_reporte[$detalle_reporte['id_factura']][] = $detalle_reporte;
                $nombre_categoria = $detalle_reporte['categoria'];
                if (!isset($categorias_reporte[$nombre_categoria])) {
                    $categorias_reporte[$nombre_categoria] = ['nombre' => $nombre_categoria, 'total' => 0, 'lineas' => 0];
                }
                $categorias_reporte[$nombre_categoria]['total'] += $detalle_reporte['total'];
                $categorias_reporte[$nombre_categoria]['lineas']++;
            }
        }

        foreach ($facturas_proveedor as &$factura_reporte) {
            $factura_reporte['id'] = (int) $factura_reporte['id'];
            $factura_reporte['total'] = (float) $factura_reporte['total'];
            $factura_reporte['igv'] = (float) $factura_reporte['igv'];
            $factura_reporte['detalles'] = $detalles_reporte[$factura_reporte['id']] ?? [];
        }
        unset($factura_reporte);
        usort($categorias_reporte, static fn($a, $b) => $b['total'] <=> $a['total']);

        echo json_encode([
            'ok' => true,
            'proveedor' => $proveedor_reporte,
            'ano_seleccionado' => $ano_reporte,
            'anos' => $anos_proveedor,
            'resumenes_anuales' => $resumenes_anuales,
            'resumen' => [
                'cantidad' => (int) $resumen_proveedor['cantidad'],
                'total' => (float) $resumen_proveedor['total'],
                'igv' => (float) $resumen_proveedor['igv'],
                'promedio' => (int) $resumen_proveedor['cantidad'] > 0 ? (float) $resumen_proveedor['total'] / (int) $resumen_proveedor['cantidad'] : 0,
                'primera_fecha' => $resumen_proveedor['primera_fecha'],
                'ultima_fecha' => $resumen_proveedor['ultima_fecha']
            ],
            'categorias' => array_values($categorias_reporte),
            'facturas' => $facturas_proveedor
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'mensaje' => 'No se pudo generar el análisis del proveedor.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['crear_transferencia', 'editar_transferencia'], true)) {
    try {
    $accion_transferencia = $_POST['accion'];
    $id_factura_edicion = (int) ($_POST['id_factura'] ?? 0);
        $tipo_comprobante = trim($_POST['tipo_comprobante'] ?? '');
        $numero_comprobante = formatearComprobanteFactura($tipo_comprobante, $_POST['numero_comprobante'] ?? '');
        $fecha = trim($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $id_proveedor = (int) ($_POST['id_proveedor'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $productos = $_POST['producto'] ?? [];
        $cantidades = $_POST['cantidad'] ?? [];
        $precios = $_POST['precio_unitario'] ?? [];
        $categorias_linea = $_POST['id_categoria'] ?? [];
        $modo_categoria = $_POST['modo_categoria'] ?? 'individual';
        $categorias_globales_seleccionadas = array_values(array_filter(array_map('intval', (array) ($_POST['categoria_global'] ?? [])), static fn($id) => $id > 0));
        $categoria_global = $categorias_globales_seleccionadas[0] ?? 0;
        $subcategoria_global = (int) ($_POST['subcategoria_global'] ?? 0);
        $sin_igv = isset($_POST['sin_igv']) && $_POST['sin_igv'] === '1';

        $tipos_comprobante_validos = ['FACTURA', 'RECIBO', 'RECIBO DE EGRESO', 'RHE', 'BOLETA', 'NOTA DE CRÉDITO'];
        $fecha_valida = DateTime::createFromFormat('Y-m-d', $fecha);
        if (!in_array($tipo_comprobante, $tipos_comprobante_validos, true)) {
            throw new Exception('Selecciona un tipo de comprobante.');
        }
        if (!esComprobanteValidoFactura($tipo_comprobante, $numero_comprobante)) {
            throw new Exception(in_array($tipo_comprobante, ['FACTURA', 'RHE'], true)
                ? 'Formato inválido. Para FACTURA y RHE usa 4 caracteres alfanuméricos, un guion y un correlativo de 1 a 8 dígitos. La F o E inicial es opcional.'
                : 'El número de comprobante es obligatorio y debe tener como máximo 100 caracteres.');
        }
        if ($id_proveedor <= 0) {
            throw new Exception('Selecciona un proveedor.');
        }
        if (!$fecha_valida || $fecha_valida->format('Y-m-d') !== $fecha) {
            throw new Exception('Selecciona una fecha válida.');
        }
        if (!is_array($productos) || count($productos) === 0) {
            throw new Exception('Agrega al menos una línea de transferencia.');
        }
        if (!in_array($modo_categoria, ['individual', 'global'], true)) {
            throw new Exception('Selecciona un modo de asignación de categoría válido.');
        }
        if ($modo_categoria === 'global' && $categoria_global <= 0) {
            throw new Exception('Selecciona la categoría general de la transferencia.');
        }
        if ($accion_transferencia === 'crear_transferencia' && !$categorias_globales_seleccionadas) {
            throw new Exception('Selecciona una categoría global para cargar sus subcategorías.');
        }
        if ($accion_transferencia === 'crear_transferencia') {
            $marcadores_globales = implode(',', array_fill(0, count($categorias_globales_seleccionadas), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM categorias_globales WHERE estado = 1 AND id IN ($marcadores_globales)");
            $stmt->execute($categorias_globales_seleccionadas);
            if ((int) $stmt->fetchColumn() !== count($categorias_globales_seleccionadas)) {
                throw new Exception('La categoría global seleccionada no es válida.');
            }
        }
        if ($accion_transferencia === 'crear_transferencia' && $modo_categoria === 'global') {
            $stmt = $pdo->prepare('SELECT 1 FROM categoria_global_subcategoria WHERE id_subcategoria = ? AND id_categoria_global IN (' . implode(',', array_fill(0, count($categorias_globales_seleccionadas), '?')) . ') LIMIT 1');
            $stmt->execute(array_merge([$subcategoria_global], $categorias_globales_seleccionadas));
            if (!$stmt->fetch()) {
                throw new Exception('Selecciona una subcategoría válida para toda la transferencia.');
            }
        }

        $consulta_duplicado = 'SELECT f.id, f.tipo_comprobante, f.numero_comprobante, f.fecha, p.nombre AS proveedor FROM facturas f LEFT JOIN proveedores p ON p.id = f.id_proveedor WHERE f.id_proveedor = ? AND f.tipo_comprobante = ? AND f.numero_comprobante = ?';
        $parametros_duplicado = [$id_proveedor, $tipo_comprobante, $numero_comprobante];
        if ($accion_transferencia === 'editar_transferencia') {
            if ($id_factura_edicion <= 0) {
                throw new Exception('Transferencia no válida para editar.');
            }
            $consulta_duplicado .= ' AND f.id <> ?';
            $parametros_duplicado[] = $id_factura_edicion;
        }
        $consulta_duplicado .= ' LIMIT 1';
        $stmt = $pdo->prepare($consulta_duplicado);
        $stmt->execute($parametros_duplicado);
        $duplicado = $stmt->fetch();
        if ($duplicado) {
            $fecha_existente = $duplicado['fecha'] ? date('d/m/Y', strtotime($duplicado['fecha'])) : 'sin fecha';
            throw new Exception('No se puede registrar: el comprobante ' . $numero_comprobante . ' ya existe para el proveedor ' . ($duplicado['proveedor'] ?: 'seleccionado') . '. Registro existente: ID #' . $duplicado['id'] . ', tipo ' . $duplicado['tipo_comprobante'] . ', fecha ' . $fecha_existente . '.');
        }

        $lineas = [];
        $total = 0;
        $igv_total = 0;
        foreach ($productos as $indice => $producto) {
            $producto = trim($producto);
            $cantidad = (float) ($cantidades[$indice] ?? 0);
            $precio = (float) ($precios[$indice] ?? 0);
            $id_categoria = $modo_categoria === 'global' ? $subcategoria_global : (int) ($categorias_linea[$indice] ?? 0);
            if ($producto === '' || $cantidad <= 0 || $precio < 0 || $id_categoria <= 0) {
                throw new Exception('Cada línea debe tener producto, cantidad, precio y categoría.');
            }
            if ($accion_transferencia === 'crear_transferencia' && $modo_categoria !== 'global') {
                $stmt = $pdo->prepare('SELECT 1 FROM categoria_global_subcategoria WHERE id_subcategoria = ? AND id_categoria_global IN (' . implode(',', array_fill(0, count($categorias_globales_seleccionadas), '?')) . ') LIMIT 1');
                $stmt->execute(array_merge([$id_categoria], $categorias_globales_seleccionadas));
                if (!$stmt->fetch()) {
                    throw new Exception('Cada subcategoría debe pertenecer a la categoría global seleccionada.');
                }
            }
            $subtotal = round($cantidad * $precio, 4);
            $igv = $sin_igv ? 0 : round($subtotal * 0.18, 4);
            $lineas[] = [$id_categoria, $producto, $cantidad, $precio, $subtotal, $igv, $subtotal + $igv];
            $total += $subtotal + $igv;
            $igv_total += $igv;
        }

        $pdo->beginTransaction();
        if ($accion_transferencia === 'editar_transferencia') {
            $id_factura = $id_factura_edicion;
            $stmt = $pdo->prepare('UPDATE facturas SET tipo_comprobante = ?, numero_comprobante = ?, descripcion = ?, id_proveedor = ?, fecha = ?, total = ?, igv = ? WHERE id = ?');
            $stmt->execute([$tipo_comprobante, $numero_comprobante, $descripcion ?: null, $id_proveedor, $fecha, $total, $igv_total, $id_factura]);
            $pdo->prepare('DELETE FROM detalle_factura WHERE id_factura = ?')->execute([$id_factura]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO facturas (tipo_comprobante, numero_comprobante, descripcion, id_proveedor, id_usuario, fecha, total, igv) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$tipo_comprobante, $numero_comprobante, $descripcion ?: null, $id_proveedor, $_SESSION['usuario_id'], $fecha, $total, $igv_total]);
            $id_factura = (int) $pdo->lastInsertId();
        }
        $stmt = $pdo->prepare('INSERT INTO detalle_factura (id_factura, id_categoria, producto, cantidad, precio_unitario, subtotal, igv, subtotal_con_igv) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lineas as $linea) {
            $stmt->execute([$id_factura, ...$linea]);
        }
        $pdo->commit();
        $mensaje = $accion_transferencia === 'editar_transferencia' ? 'Transferencia editada correctamente.' : 'Transferencia registrada correctamente.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof PDOException && $e->getCode() === '23000'
            ? 'No se puede registrar: el número de comprobante ya existe para ese proveedor. Revisa el registro existente antes de continuar.'
            : 'No se pudo registrar la transferencia: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_transferencia') {
    try {
        $id_factura = (int) ($_POST['id_factura'] ?? 0);
        if ($id_factura <= 0) {
            throw new Exception('Transferencia no válida.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM detalle_factura WHERE id_factura = ?')->execute([$id_factura]);
        $pdo->prepare('DELETE FROM facturas WHERE id = ?')->execute([$id_factura]);
        $pdo->commit();
        $mensaje = 'Transferencia eliminada junto con sus detalles.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'No se pudo eliminar la transferencia: ' . $e->getMessage();
    }
}

// Obtener facturas
try {
    $proveedores = $pdo->query('SELECT id, nombre, ruc FROM proveedores WHERE activo = 1 ORDER BY nombre')->fetchAll();
    $categorias_globales = $pdo->query('SELECT id, nombre FROM categorias_globales WHERE estado = 1 ORDER BY nombre')->fetchAll();
    $categorias = $pdo->query("SELECT c.id, c.nombre, GROUP_CONCAT(DISTINCT r.id_categoria_global ORDER BY r.id_categoria_global) AS ids_categorias_globales, GROUP_CONCAT(DISTINCT cg.nombre ORDER BY cg.nombre SEPARATOR ' / ') AS nombres_categorias_globales FROM categorias c INNER JOIN categoria_global_subcategoria r ON r.id_subcategoria = c.id INNER JOIN categorias_globales cg ON cg.id = r.id_categoria_global AND cg.estado = 1 GROUP BY c.id, c.nombre ORDER BY c.nombre")->fetchAll();
    $condiciones = [];
    $parametros = [];
    if ($comprobante_filtro !== '') {
        $condiciones[] = '(f.numero_comprobante LIKE ? OR REPLACE(REPLACE(LOWER(f.numero_comprobante), " ", ""), "-", "") LIKE ?)';
        $parametros[] = '%' . $comprobante_filtro . '%';
        $parametros[] = '%' . str_replace([' ', '-'], '', strtolower($comprobante_filtro)) . '%';
    } elseif ($mes_filtro !== '' && $ano_filtro !== '') {
        $condiciones[] = 'MONTH(f.fecha) = ? AND YEAR(f.fecha) = ?';
        $parametros[] = $mes_filtro;
        $parametros[] = $ano_filtro;
    }
    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $stmt = $pdo->prepare("
        SELECT f.*, p.nombre as proveedor, p.ruc, u.nombre as usuario
        FROM facturas f
        LEFT JOIN proveedores p ON f.id_proveedor = p.id
        LEFT JOIN usuarios u ON f.id_usuario = u.id
        $where
        ORDER BY f.fecha DESC, f.id DESC
    ");
    $stmt->execute($parametros);
    $facturas = $stmt->fetchAll();

    // Obtener el detalle de las facturas mostradas
    $detalles_por_factura = [];
    if (!empty($facturas)) {
        $ids_facturas = array_column($facturas, 'id');
        $marcadores = implode(',', array_fill(0, count($ids_facturas), '?'));
        $stmt = $pdo->prepare("SELECT d.*, c.nombre AS categoria_nombre FROM detalle_factura d LEFT JOIN categorias c ON c.id = d.id_categoria WHERE d.id_factura IN ($marcadores) ORDER BY d.id_factura, d.id");
        $stmt->execute($ids_facturas);
        foreach ($stmt->fetchAll() as $detalle) {
            $detalles_por_factura[$detalle['id_factura']][] = $detalle;
        }
    }
    
    // Estadísticas
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(f.total), 0) as total, COALESCE(SUM(f.igv), 0) as igv FROM facturas f $where");
    $stmt->execute($parametros);
    $stats = $stmt->fetch() ?: $stats;

    foreach ($facturas as $factura) {
        $datos_pdf[] = [
            'id' => (int) $factura['id'],
            'tipo' => $factura['tipo_comprobante'] ?? '',
            'numero' => $factura['numero_comprobante'] ?? '',
            'fecha' => $factura['fecha'] ?? '',
            'id_proveedor' => (int) ($factura['id_proveedor'] ?? 0),
            'proveedor' => $factura['proveedor'] ?? 'N/A',
            'ruc' => $factura['ruc'] ?? '',
            'usuario' => $factura['usuario'] ?? 'No identificado',
            'descripcion' => $factura['descripcion'] ?? '',
            'igv' => (float) ($factura['igv'] ?? 0),
            'total' => (float) ($factura['total'] ?? 0),
            'detalles' => array_map(static function ($detalle) {
                return [
                    'producto' => $detalle['producto'],
                    'id_categoria' => (int) ($detalle['id_categoria'] ?? 0),
                    'categoria' => $detalle['categoria_nombre'] ?? 'Sin categoría',
                    'cantidad' => (float) $detalle['cantidad'],
                    'precio_unitario' => (float) $detalle['precio_unitario'],
                    'subtotal' => (float) $detalle['subtotal'],
                    'igv' => (float) $detalle['igv'],
                    'total' => (float) $detalle['subtotal_con_igv']
                ];
            }, $detalles_por_factura[$factura['id']] ?? [])
        ];
    }
    $stmt = $pdo->query("SELECT DISTINCT YEAR(fecha) as ano FROM facturas WHERE fecha IS NOT NULL ORDER BY ano DESC");
    $anos_disponibles = $stmt->fetchAll();
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
    <title>Facturas - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        #modalNuevaTransferencia .modal-dialog {
            height: calc(100vh - 2rem) !important;
            margin: 1rem auto;
            max-width: 1200px;
            min-height: 0;
            display: flex;
        }
        #modalNuevaTransferencia .modal-content {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 24px 70px rgba(18, 38, 63, .24);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #modalNuevaTransferencia .modal-header {
            background: linear-gradient(135deg, #087f5b, #20a477);
            border: 0;
            padding: 1.15rem 1.5rem;
        }
        #modalNuevaTransferencia .modal-title {
            color: #fff;
            font-size: 1.1rem;
            letter-spacing: .01em;
        }
        #modalNuevaTransferencia .modal-header small {
            color: rgba(255, 255, 255, .82);
        }
        #modalNuevaTransferencia .modal-body {
            background: #f8fafb;
            flex: 1 1 auto;
            padding: 1.5rem;
        }
        #modalNuevaTransferencia .form-label {
            color: #344054;
            font-size: .82rem;
            margin-bottom: .4rem;
        }
        #modalNuevaTransferencia .form-control,
        #modalNuevaTransferencia .form-select {
            border-color: #d8e0e8;
            min-height: 42px;
        }
        #modalNuevaTransferencia .form-control:focus,
        #modalNuevaTransferencia .form-select:focus {
            border-color: #20a477;
            box-shadow: 0 0 0 .2rem rgba(32, 164, 119, .14);
        }
        #modalNuevaTransferencia .alert-success-subtle {
            background: #eaf8f1;
            color: #176b4e;
        }
        #modalNuevaTransferencia .border.rounded.p-3.bg-light {
            background: #fff !important;
            border-color: #dfe7ee !important;
            box-shadow: 0 2px 8px rgba(18, 38, 63, .04);
        }
        #modalNuevaTransferencia .table-responsive.border.rounded {
            background: #fff;
            border-color: #dfe7ee !important;
            box-shadow: 0 2px 8px rgba(18, 38, 63, .04);
        }
        #modalNuevaTransferencia .table thead th {
            background: #f1f5f8;
            color: #52606d;
            font-size: .72rem;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        #modalNuevaTransferencia .table tbody td {
            padding: .7rem .6rem;
        }
        #modalNuevaTransferencia .modal-footer {
            padding: .9rem 1.5rem;
            position: relative;
            z-index: 3;
        }
        #modalNuevaTransferencia #totalTransferencia {
            color: #087f5b !important;
        }
        #modalNuevaTransferencia .modal-content {
            max-height: calc(100vh - 2rem);
            height: calc(100vh - 2rem) !important;
            min-height: 0;
        }
        #modalNuevaTransferencia .modal-body {
            display: block;
            flex: 1 1 auto;
            height: 0 !important;
            min-height: 0;
            max-height: none;
            overflow-y: scroll !important;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-color: #20a477 #eef2f5;
            scrollbar-width: auto;
        }
        #modalNuevaTransferencia .modal-body::-webkit-scrollbar { width: 12px; }
        #modalNuevaTransferencia .modal-body::-webkit-scrollbar-track { background: #eef2f5; border-radius: 8px; }
        #modalNuevaTransferencia .modal-body::-webkit-scrollbar-thumb { background: #20a477; border: 3px solid #eef2f5; border-radius: 8px; }
        #modalNuevaTransferencia .modal-footer {
            background: #fff;
            border-top: 1px solid #dee2e6;
            flex: 0 0 auto;
            flex-shrink: 0;
            display: flex;
        }
        #modalNuevaTransferencia .modal-header { flex: 0 0 auto; }

        #modalEditarTransferencia .modal-dialog {
            height: calc(100vh - 2rem) !important;
            margin: 1rem auto;
            max-width: 1200px;
            min-height: 0;
            display: flex;
        }
        #modalEditarTransferencia .modal-content {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 24px 70px rgba(18, 38, 63, .24);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            max-height: calc(100vh - 2rem);
            height: calc(100vh - 2rem) !important;
        }
        #modalEditarTransferencia .modal-header {
            background: linear-gradient(135deg, #d6a500, #f2b800);
            border: 0;
            padding: 1.15rem 1.5rem;
            flex: 0 0 auto;
        }
        #modalEditarTransferencia .modal-title {
            color: #fff;
            font-size: 1.1rem;
            letter-spacing: .01em;
        }
        #modalEditarTransferencia .modal-header small {
            color: rgba(255, 255, 255, .82);
        }
        #modalEditarTransferencia .modal-body {
            background: #f8fafb;
            flex: 1 1 auto;
            padding: 1.5rem;
            height: auto !important;
            max-height: calc(100vh - 18rem) !important;
            min-height: 220px !important;
            overflow-y: auto !important;
            overflow-x: hidden;
            overscroll-behavior: contain;
            scrollbar-color: #f2b800 #eef2f5;
            scrollbar-width: auto;
        }
        #modalEditarTransferencia .modal-body::-webkit-scrollbar { width: 12px; }
        #modalEditarTransferencia .modal-body::-webkit-scrollbar-track { background: #eef2f5; border-radius: 8px; }
        #modalEditarTransferencia .modal-body::-webkit-scrollbar-thumb { background: #f2b800; border: 3px solid #eef2f5; border-radius: 8px; }
        #modalEditarTransferencia .modal-footer {
            background: #fff;
            border-top: 1px solid #dee2e6;
            flex: 0 0 auto;
            display: flex;
        }
        #modalEditarTransferencia .table-responsive {
            max-height: 360px;
            overflow-y: auto;
        }
        #modalEditarTransferencia .table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f1f5f8;
            color: #52606d;
            font-size: .72rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .filtros-acciones { display: flex; gap: .5rem; }
        .filtros-acciones .btn { flex: 1 1 0; white-space: nowrap; }
        @media (min-width: 768px) and (max-width: 1100px) {
            .filtros-acciones .btn { font-size: .8rem; padding-left: .45rem; padding-right: .45rem; }
        }
        #modalNuevaTransferencia thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
        }
        @media (max-width: 576px) {
            #modalNuevaTransferencia .modal-dialog {
                height: calc(100vh - .5rem);
                margin: .25rem;
            }
            #modalNuevaTransferencia .modal-content { max-height: calc(100vh - .5rem); }
            #modalNuevaTransferencia .modal-body {
                padding: .75rem;
            }
            #modalNuevaTransferencia .table {
                min-width: 850px;
            }
        }
        /* El desplazamiento pertenece al contenido; el pie queda fuera del scroll. */
        #modalNuevaTransferencia .modal-dialog {
            height: auto !important;
            min-height: 0 !important;
        }
        #modalNuevaTransferencia .modal-content {
            height: auto !important;
            max-height: calc(100vh - 2rem) !important;
        }
        #modalNuevaTransferencia .modal-body {
            height: auto !important;
            flex: 0 1 auto !important;
            max-height: calc(100vh - 17rem) !important;
            min-height: 220px !important;
            overflow-y: scroll !important;
        }
        .provider-analysis { background: #fff; border: 1px solid #dce7e2; border-radius: 8px; box-shadow: 0 8px 24px rgba(22, 61, 48, .07); overflow: visible; }
        .provider-analysis-head { background: #f2f8f5; border-bottom: 1px solid #dce7e2; border-left: 5px solid #087f5b; padding: 18px 22px; }
        .provider-analysis-title { color: #173b2d; font-size: 1.08rem; font-weight: 750; margin: 0; }
        .provider-analysis-body { padding: 20px 22px; }
        .provider-search-wrap { max-width: 680px; position: relative; }
        .provider-suggestions { box-shadow: 0 12px 28px rgba(22, 45, 36, .15); left: 0; max-height: 300px; overflow-y: auto; position: absolute; right: 0; top: calc(100% + 4px); z-index: 30; }
        .provider-suggestion { align-items: center; background: #fff; border: 0; border-bottom: 1px solid #e8eeeb; color: #24332d; display: flex; justify-content: space-between; padding: 11px 14px; text-align: left; width: 100%; }
        .provider-suggestion:hover,.provider-suggestion:focus { background: #eff8f3; }
        .provider-suggestion:last-child { border-bottom: 0; }
        .provider-kpi { background: #fff; border: 1px solid #e1e9e5; border-radius: 7px; height: 100%; min-height: 104px; padding: 14px 16px; }
        .provider-kpi-label { color: #64736c; font-size: .72rem; font-weight: 700; text-transform: uppercase; }
        .provider-kpi-value { color: #173b2d; font-size: 1.25rem; font-weight: 800; margin-top: 7px; overflow-wrap: anywhere; }
        .provider-total-kpi { background: #087f5b; border-color: #087f5b; }
        .provider-total-kpi .provider-kpi-label,.provider-total-kpi .provider-kpi-value { color: #fff; }
        .provider-category-row { display: grid; gap: 4px 12px; grid-template-columns: minmax(120px, 1fr) auto; margin-bottom: 13px; }
        .provider-category-name { color: #34443c; font-size: .87rem; font-weight: 650; overflow-wrap: anywhere; }
        .provider-category-amount { color: #173b2d; font-size: .87rem; font-weight: 750; white-space: nowrap; }
        .provider-category-track { background: #e9efec; border-radius: 99px; grid-column: 1 / -1; height: 7px; overflow: hidden; }
        .provider-category-fill { background: #16815f; border-radius: inherit; height: 100%; }
        .provider-invoice-table { min-width: 760px; }
        .provider-invoice-table th { background: #f5f8f7; color: #56655e; font-size: .72rem; text-transform: uppercase; white-space: nowrap; }
        .provider-invoice-table details summary { color: #087f5b; cursor: pointer; font-size: .82rem; font-weight: 650; }
        .provider-invoice-details { background: #f7faf8; border-top: 1px solid #e3ebe6; padding: 16px; }
        .provider-invoice-detail-summary { display: grid; gap: 8px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 12px; }
        .provider-invoice-detail-kpi { background: #fff; border: 1px solid #e2eae6; border-radius: 5px; padding: 9px 11px; }
        .provider-invoice-detail-kpi span { color: #69776f; display: block; font-size: .66rem; font-weight: 700; text-transform: uppercase; }
        .provider-invoice-detail-kpi strong { color: #20382d; display: block; font-size: .86rem; margin-top: 3px; }
        .provider-invoice-lines { min-width: 700px; }
        .provider-invoice-lines th { background: #eaf2ed; color: #475a50; font-size: .68rem; text-transform: uppercase; }
        .provider-invoice-lines td { font-size: .78rem; }
        .provider-year-panel { background: #f7faf8; border: 1px solid #e3ebe6; border-radius: 6px; padding: 15px; }
        .provider-year-bars { align-items: end; display: flex; gap: 10px; min-height: 154px; overflow-x: auto; padding: 8px 2px 0; }
        .provider-year-bar { align-items: center; display: flex; flex: 1 0 100px; flex-direction: column; gap: 6px; min-height: 145px; justify-content: end; }
        .provider-year-bar-button { align-items: center; background: transparent; border: 0; color: #34443c; display: flex; flex-direction: column; gap: 6px; justify-content: end; min-height: 132px; padding: 0 4px; width: 100%; }
        .provider-year-bar-button:hover .provider-year-bar-fill,.provider-year-bar-button.active .provider-year-bar-fill { background: #087f5b; }
        .provider-year-bar-button.active .provider-year-label { color: #087f5b; font-weight: 800; }
        .provider-year-value { font-size: .68rem; font-weight: 750; white-space: nowrap; }
        .provider-year-track { align-items: end; background: #e6eeea; border-radius: 4px 4px 0 0; display: flex; height: 86px; justify-content: center; overflow: hidden; width: min(100%, 58px); }
        .provider-year-bar-fill { background: #68a990; border-radius: 4px 4px 0 0; min-height: 4px; transition: height .25s ease, background .2s ease; width: 100%; }
        .provider-year-label { font-size: .78rem; }
        .provider-report-loading { align-items: center; color: #64736c; display: flex; gap: 10px; min-height: 100px; justify-content: center; }
        @media (max-width: 575px) { .provider-analysis-head,.provider-analysis-body { padding: 15px; } .provider-kpi { min-height: 88px; padding: 12px; } .provider-kpi-value { font-size: 1.05rem; } .provider-invoice-details { padding: 10px; } .provider-invoice-detail-summary { grid-template-columns: 1fr; } }
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
                <a href="facturas.php" class="nav-link active">
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
                    <span>Gestión de transferencias</span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <?php if($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end mb-4">
                    <button type="button" class="btn btn-success px-4" data-bs-toggle="modal" data-bs-target="#modalNuevaTransferencia">
                        <i class="bi bi-plus-circle me-2"></i>Registrar transferencia
                    </button>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Buscar y filtrar transferencias</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end" id="formFiltroTransferencias">
                            <div class="col-md-3">
                                <label for="comprobante" class="form-label">Número de comprobante</label>
                                <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="comprobante" name="comprobante" value="<?= htmlspecialchars($comprobante_filtro) ?>" placeholder="Busca en todos los meses" autocomplete="off"></div>
                                <small class="text-success" id="estadoBusqueda"><?= $comprobante_filtro !== '' ? 'Búsqueda global activa' : 'Escribe para buscar en todo el histórico' ?></small>
                            </div>
                            <div class="col-md-3">
                                <label for="mes" class="form-label">Mes</label>
                                <select class="form-select" id="mes" name="mes">
                                    <option value="">Todos los meses</option>
                                    <?php
                                    $meses = [
                                        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
                                        '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
                                        '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
                                        '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
                                    ];
                                    foreach($meses as $numero => $nombre):
                                    ?>
                                    <option value="<?= $numero ?>" <?= $mes_filtro === $numero ? 'selected' : '' ?>><?= $nombre ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="ano" class="form-label">Año</label>
                                <select class="form-select" id="ano" name="ano">
                                    <option value="">Todos los años</option>
                                    <?php foreach($anos_disponibles as $ano): ?>
                                    <option value="<?= $ano['ano'] ?>" <?= $ano_filtro === (string) $ano['ano'] ? 'selected' : '' ?>><?= $ano['ano'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 filtros-acciones">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
                                <a href="facturas.php?mes=&ano=&comprobante=" class="btn btn-outline-secondary"><i class="bi bi-list"></i> Todas</a>
                            </div>
                        </form>
                    </div>
                </div>

                <section class="provider-analysis mb-4" aria-labelledby="analisisProveedorTitulo">
                    <div class="provider-analysis-head d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div><h2 class="provider-analysis-title" id="analisisProveedorTitulo"><i class="bi bi-graph-up-arrow me-2 text-success"></i>Análisis de transferencias por proveedor</h2><small class="text-muted">Consulta el historial completo, el monto acumulado y la distribución del gasto.</small></div>
                        <span class="badge text-bg-light border"><i class="bi bi-arrow-repeat me-1 text-success"></i>Consulta en vivo</span>
                    </div>
                    <div class="provider-analysis-body">
                        <div class="provider-search-wrap">
                            <label for="analisisProveedorBusqueda" class="form-label fw-semibold">Buscar proveedor por nombre o RUC</label>
                            <div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span><input type="search" class="form-control" id="analisisProveedorBusqueda" placeholder="Escribe al menos 2 caracteres" autocomplete="off" aria-controls="analisisProveedorSugerencias" aria-autocomplete="list"><button class="btn btn-outline-secondary" type="button" id="limpiarAnalisisProveedor" title="Limpiar búsqueda" aria-label="Limpiar búsqueda"><i class="bi bi-x-lg"></i></button></div>
                            <div class="provider-suggestions list-group d-none" id="analisisProveedorSugerencias" role="listbox"></div>
                            <small class="d-block mt-2 text-muted" id="estadoAnalisisProveedor" aria-live="polite">Busca y selecciona un proveedor para consultar su historial total.</small>
                        </div>
                        <div class="d-none mt-4" id="resultadoAnalisisProveedor" aria-live="polite">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3"><div><div class="text-uppercase small text-muted fw-bold">Proveedor seleccionado</div><h3 class="h5 mb-1 mt-1" id="nombreProveedorReporte"></h3><small class="text-muted" id="rucProveedorReporte"></small></div><div class="d-flex flex-wrap align-items-end gap-2"><div><label class="form-label small fw-semibold mb-1" for="anoReporteProveedor">Filtrar detalle por año</label><select class="form-select form-select-sm" id="anoReporteProveedor" style="min-width:170px"><option value="">Todos los años</option></select></div><span class="badge bg-success-subtle text-success border border-success-subtle align-self-center" id="periodoProveedorReporte"></span></div></div>
                            <div class="provider-year-panel mb-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h4 class="h6 mb-1">Transferencias por año</h4><small class="text-muted">Selecciona una barra para filtrar el análisis detallado.</small></div><small class="text-muted" id="notaComparacionAnual"></small></div><div class="provider-year-bars" id="comparacionAnualProveedor"><div class="provider-report-loading">La comparación anual aparecerá al elegir proveedor.</div></div></div>
                            <div class="row g-2 mb-4">
                                <div class="col-6 col-xl-3"><div class="provider-kpi provider-total-kpi"><div class="provider-kpi-label">Total transferido</div><div class="provider-kpi-value" id="totalProveedorReporte">S/. 0.00</div></div></div>
                                <div class="col-6 col-xl-3"><div class="provider-kpi"><div class="provider-kpi-label">Transferencias</div><div class="provider-kpi-value" id="cantidadProveedorReporte">0</div></div></div>
                                <div class="col-6 col-xl-3"><div class="provider-kpi"><div class="provider-kpi-label">IGV acumulado</div><div class="provider-kpi-value" id="igvProveedorReporte">S/. 0.00</div></div></div>
                                <div class="col-6 col-xl-3"><div class="provider-kpi"><div class="provider-kpi-label">Promedio por transferencia</div><div class="provider-kpi-value" id="promedioProveedorReporte">S/. 0.00</div></div></div>
                            </div>
                            <div class="border rounded-2 p-3 mb-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h4 class="h6 mb-1">¿En qué se distribuyó el dinero?</h4><small class="text-muted">Acumulado de las líneas de detalle, agrupado por categoría.</small></div><span class="small text-muted" id="cantidadCategoriasProveedor"></span></div>
                                <div id="categoriasProveedorReporte"><p class="text-muted small mb-0">No hay categorías detalladas para este proveedor.</p></div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h4 class="h6 mb-0">Historial completo de transferencias</h4><span class="small text-muted" id="cantidadFacturasProveedor"></span></div>
                            <div class="table-responsive border rounded-2"><table class="table table-hover align-middle mb-0 provider-invoice-table"><thead><tr><th>Fecha</th><th>Comprobante</th><th>Descripción</th><th class="text-end">Total</th><th class="text-end">IGV</th><th>Detalle</th></tr></thead><tbody id="facturasProveedorReporte"><tr><td colspan="6" class="text-center text-muted py-4">Selecciona un proveedor.</td></tr></tbody></table></div>
                        </div>
                    </div>
                </section>

                <!-- Estadísticas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">Total Facturado</h6>
                                <h3 class="card-value">S/. <?= number_format($stats['total'] ?? 0, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h6 class="card-title text-muted">IGV Total</h6>
                                <h3 class="card-value">S/. <?= number_format($stats['igv'] ?? 0, 2) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabla de Transferencias -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> Facturas Registradas (<?= count($facturas) ?>)</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>N° Comprobante</th>
                                    <th>Proveedor</th>
                                    <th>Registrado por</th>
                                    <th>Total</th>
                                    <th>IGV</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($facturas as $factura): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($factura['fecha'])) ?></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($factura['tipo_comprobante']) ?></span></td>
                                    <td><?= htmlspecialchars($factura['numero_comprobante']) ?></td>
                                    <td><?= htmlspecialchars($factura['proveedor'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($factura['usuario'] ?? 'No identificado') ?></td>
                                    <td>S/. <?= number_format($factura['total'], 2) ?></td>
                                    <td>S/. <?= number_format($factura['igv'], 2) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalFactura<?= $factura['id'] ?>">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-warning" onclick="abrirEdicionTransferencia(<?= $factura['id'] ?>)" title="Editar transferencia"><i class="bi bi-pencil-square"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="abrirEliminacionTransferencia(<?= $factura['id'] ?>, '<?= htmlspecialchars($factura['numero_comprobante'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($factura['proveedor'] ?? 'Proveedor no identificado', ENT_QUOTES, 'UTF-8') ?>')" title="Eliminar transferencia"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal fade transferencia-modal" id="modalNuevaTransferencia" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <div><h5 class="modal-title mb-1"><i class="bi bi-arrow-left-right me-2"></i>Registrar nueva transferencia</h5><small>Completa el comprobante y clasifica cada línea por categoría.</small></div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" id="formNuevaTransferencia">
                                <input type="hidden" name="accion" value="crear_transferencia">
                                <div class="modal-body">
                                    <div class="alert alert-success-subtle border-success mb-4"><i class="bi bi-shield-check me-2"></i>La transferencia quedará vinculada al usuario <?= htmlspecialchars($_SESSION['usuario_nombre']) ?> y cada concepto conservará su categoría.</div>
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-3"><label class="form-label fw-semibold" for="tipoComprobanteNuevo">Tipo de comprobante *</label><select class="form-select" name="tipo_comprobante" id="tipoComprobanteNuevo" required><option value="">Seleccionar</option><option>FACTURA</option><option>RECIBO</option><option>RECIBO DE EGRESO</option><option>RHE</option><option>BOLETA</option><option>NOTA DE CRÉDITO</option></select></div>
                                        <div class="col-md-3"><label class="form-label fw-semibold" for="numeroComprobanteNuevo">N° de comprobante *</label><input class="form-control comprobante-sin-espacios" id="numeroComprobanteNuevo" name="numero_comprobante" required maxlength="100" autocomplete="off" aria-describedby="ayudaComprobanteNuevo"><small class="form-text text-muted" id="ayudaComprobanteNuevo">Selecciona el tipo para aplicar su formato.</small></div>
                                        <div class="col-md-3"><label class="form-label fw-semibold">Proveedor o RUC *</label><input class="form-control" id="proveedorBusqueda" list="proveedoresDisponibles" placeholder="Escribe nombre o RUC" autocomplete="off" required><input type="hidden" name="id_proveedor" id="id_proveedor_transferencia" required><datalist id="proveedoresDisponibles"><?php foreach ($proveedores as $proveedor): ?><option value="<?= htmlspecialchars($proveedor['nombre'] . ' - RUC ' . ($proveedor['ruc'] ?: 'Sin RUC'), ENT_QUOTES, 'UTF-8') ?>" data-id="<?= $proveedor['id'] ?>" data-ruc="<?= htmlspecialchars($proveedor['ruc'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></option><?php endforeach; ?></datalist><small class="text-muted" id="estadoProveedor">Busca por nombre o RUC y selecciona una coincidencia.</small></div>
                                        <div class="col-md-3"><label class="form-label fw-semibold">Fecha *</label><input type="date" class="form-control" name="fecha" value="<?= date('Y-m-d') ?>" required></div>
                                        <div class="col-12"><label class="form-label fw-semibold">Descripción general <span class="text-muted fw-normal">(opcional)</span></label><textarea class="form-control" name="descripcion" rows="2" maxlength="1000" placeholder="Motivo u observación de la transferencia (opcional)"></textarea></div>
                                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input selector-sin-igv" type="checkbox" name="sin_igv" value="1" id="sinIgvNueva"><label class="form-check-label fw-semibold" for="sinIgvNueva">Registrar transferencia sin IGV</label><small class="d-block text-muted">El total será igual al subtotal y el IGV quedará en S/. 0.00.</small></div></div>
                                    </div>
                                    <div class="border rounded p-3 mb-3 bg-light"><div class="d-flex justify-content-between align-items-center mb-2"><strong><i class="bi bi-tags text-success me-2"></i>Asignación de categorías</strong><span class="badge bg-success-subtle text-success">Flexible</span></div><div class="row g-2"><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="radio" name="modo_categoria" id="modoCategoriaGlobal" value="global"><label class="form-check-label" for="modoCategoriaGlobal"><strong>Una categoría para toda la transferencia</strong><small class="d-block text-muted">Ideal cuando todos los conceptos pertenecen al mismo rubro.</small></label></div></div><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="radio" name="modo_categoria" id="modoCategoriaIndividual" value="individual" checked><label class="form-check-label" for="modoCategoriaIndividual"><strong>Una categoría por línea</strong><small class="d-block text-muted">Úsalo cuando la transferencia mezcla diferentes rubros.</small></label></div></div></div><div id="categoriaGlobalPanel" class="mt-3 d-none"><label class="form-label fw-semibold mb-1" for="categoria_global" id="categoriaGlobalLabel">Categoría global de las líneas *</label><select class="form-select" name="categoria_global" id="categoria_global"><option value="">Seleccionar categoría general</option><?php foreach ($categorias as $categoria): ?><option value="<?= $categoria['id'] ?>"><?= htmlspecialchars($categoria['nombre']) ?></option><?php endforeach; ?></select></div></div>
                                    <div class="d-flex justify-content-between align-items-center mb-2"><div><h6 class="mb-1"><i class="bi bi-list-check text-success me-2"></i>Detalle de la transferencia</h6><small class="text-muted" id="ayudaCategoriaLinea">Cada línea debe tener una categoría.</small></div><button type="button" class="btn btn-outline-success btn-sm" id="agregarLinea"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button></div>
                                    <div class="table-responsive border rounded"><table class="table align-middle mb-0"><thead class="table-light"><tr><th style="min-width:220px">Producto / concepto *</th><th style="min-width:210px">Categoría *</th><th style="width:120px">Cantidad *</th><th style="width:150px">Precio unitario *</th><th style="width:150px">Subtotal</th><th style="width:55px"></th></tr></thead><tbody id="lineasTransferencia"><tr class="linea-transferencia"><td><input class="form-control" name="producto[]" required placeholder="Ej. Insumos médicos"></td><td><select class="form-select" name="id_categoria[]" required><option value="">Seleccionar categoría</option><?php foreach ($categorias as $categoria): ?><option value="<?= $categoria['id'] ?>"><?= htmlspecialchars($categoria['nombre']) ?></option><?php endforeach; ?></select></td><td><input class="form-control cantidad-linea" type="number" name="cantidad[]" min="0.01" step="0.01" value="1" required></td><td><input class="form-control precio-linea" type="number" name="precio_unitario[]" min="0" step="0.01" required></td><td class="text-end fw-semibold subtotal-linea">S/. 0.00</td><td><button type="button" class="btn btn-sm btn-outline-danger quitar-linea" title="Quitar línea" disabled><i class="bi bi-trash"></i></button></td></tr></tbody></table></div>
                                    <div class="d-flex justify-content-end mt-3"><div class="text-end"><small class="text-muted d-block">Total con IGV (18%)</small><strong class="fs-4 text-success" id="totalTransferencia">S/. 0.00</strong></div></div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success px-4"><i class="bi bi-check2-circle me-1"></i>Guardar transferencia</button></div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="modalEditarTransferencia" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
                        <div class="modal-header bg-warning"><div><h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar transferencia</h5><small>Revisa cuidadosamente los cambios antes de guardarlos.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <form method="POST"><input type="hidden" name="accion" value="editar_transferencia"><input type="hidden" name="id_factura" id="editarTransferenciaId"><div class="modal-body"><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Esta modificación actualizará la cabecera y todos los detalles de la transferencia.</div><div class="row g-3 mb-4"><div class="col-md-3"><label class="form-label">Tipo de comprobante *</label><select class="form-select" name="tipo_comprobante" id="editarTipoComprobante" required><option>FACTURA</option><option>RECIBO</option><option>RECIBO DE EGRESO</option><option>RHE</option><option>BOLETA</option><option>NOTA DE CRÉDITO</option></select></div><div class="col-md-3"><label class="form-label">N° de comprobante *</label><input class="form-control comprobante-sin-espacios" name="numero_comprobante" id="editarNumeroComprobante" required maxlength="100" pattern="^[a-zA-Z0-9]*-?[a-zA-Z0-9]*$" title="Solo números, letras y UN guion (-). Ejemplo: 001-001 o ABC123"></div><div class="col-md-3"><label class="form-label">Proveedor *</label><select class="form-select" name="id_proveedor" id="editarProveedor" required><option value="">Seleccionar proveedor</option><?php foreach ($proveedores as $proveedor): ?><option value="<?= $proveedor['id'] ?>"><?= htmlspecialchars($proveedor['nombre'] . ($proveedor['ruc'] ? ' - RUC ' . $proveedor['ruc'] : '')) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Fecha *</label><input type="date" class="form-control" name="fecha" id="editarFecha" required></div><div class="col-12"><label class="form-label">Descripción general (opcional)</label><textarea class="form-control" name="descripcion" id="editarDescripcion" rows="2"></textarea></div></div><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0"><i class="bi bi-list-check text-success me-2"></i>Detalle de transferencia</h6><button type="button" class="btn btn-sm btn-outline-success" id="agregarLineaEdicion"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button></div><div class="table-responsive border rounded"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Producto / concepto *</th><th>Categoría *</th><th>Cantidad *</th><th>Precio unitario *</th><th>Subtotal</th><th></th></tr></thead><tbody id="lineasEdicion"></tbody></table></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button></div></form>
                    </div></div>
                </div>

                <div class="modal fade" id="modalEliminarTransferencia" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-danger"><div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-exclamation-octagon-fill me-2"></i>ELIMINACIÓN DEFINITIVA</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="alert alert-danger border-danger"><strong>Atención: esta acción no se puede deshacer.</strong><p class="mb-0 mt-2">Se eliminará la transferencia y todos sus detalles registrados.</p></div><dl class="row mb-0"><dt class="col-sm-4">Comprobante</dt><dd class="col-sm-8" id="eliminarComprobante"></dd><dt class="col-sm-4">Proveedor</dt><dd class="col-sm-8" id="eliminarProveedor"></dd></dl><p class="text-danger fw-semibold mt-3 mb-0"><i class="bi bi-shield-exclamation me-1"></i>Confirma únicamente si estás seguro.</p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><form method="POST"><input type="hidden" name="accion" value="eliminar_transferencia"><input type="hidden" name="id_factura" id="eliminarTransferenciaId"><button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Eliminar definitivamente</button></form></div></div></div></div>
                <div class="modal fade" id="modalConfirmarEdicion" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-warning"><div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Confirmar edición</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="alert alert-warning"><strong>Revisa los datos antes de continuar.</strong><p class="mb-0 mt-2">Podrás modificar la cabecera y todos los detalles de esta transferencia.</p></div><dl class="row mb-0"><dt class="col-sm-4">Comprobante</dt><dd class="col-sm-8" id="confirmarEdicionComprobante"></dd><dt class="col-sm-4">Proveedor</dt><dd class="col-sm-8" id="confirmarEdicionProveedor"></dd></dl></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-warning" id="continuarEdicion"><i class="bi bi-arrow-right me-1"></i>Continuar editando</button></div></div></div></div>

                <?php foreach($facturas as $factura): ?>
                <div class="modal fade" id="modalFactura<?= $factura['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <div>
                                    <h5 class="modal-title mb-1"><i class="bi bi-receipt"></i> Detalle de factura</h5>
                                    <small><?= htmlspecialchars(($factura['tipo_comprobante'] ?? '') . ' ' . ($factura['numero_comprobante'] ?? '')) ?></small>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-3"><strong>Proveedor</strong><br><?= htmlspecialchars($factura['proveedor'] ?? 'N/A') ?></div>
                                    <div class="col-md-3"><strong>Fecha</strong><br><?= $factura['fecha'] ? date('d/m/Y', strtotime($factura['fecha'])) : 'N/A' ?></div>
                                    <div class="col-md-3"><strong>Registrado por</strong><br><?= htmlspecialchars($factura['usuario'] ?? 'No identificado') ?></div>
                                    <div class="col-md-3"><strong>Descripción</strong><br><?= htmlspecialchars($factura['descripcion'] ?? 'Sin descripción') ?></div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Producto / Concepto</th>
                                                <th>Categoría</th>
                                                <th class="text-end">Cantidad</th>
                                                <th class="text-end">Precio unitario</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">IGV</th>
                                                <th class="text-end">Total línea</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(!empty($detalles_por_factura[$factura['id']])): ?>
                                                <?php foreach($detalles_por_factura[$factura['id']] as $detalle): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($detalle['producto']) ?></td>
                                                    <td><span class="badge bg-success-subtle text-success"><?= htmlspecialchars($detalle['categoria_nombre'] ?? 'Sin categoría') ?></span></td>
                                                    <td class="text-end"><?= number_format($detalle['cantidad'], 2) ?></td>
                                                    <td class="text-end">S/. <?= number_format($detalle['precio_unitario'], 2) ?></td>
                                                    <td class="text-end">S/. <?= number_format($detalle['subtotal'], 2) ?></td>
                                                    <td class="text-end">S/. <?= number_format($detalle['igv'], 2) ?></td>
                                                    <td class="text-end">S/. <?= number_format($detalle['subtotal_con_igv'], 2) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="7" class="text-center text-muted">Esta transferencia no tiene líneas de detalle registradas.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="row justify-content-end mt-3">
                                    <div class="col-md-4">
                                        <div class="d-flex justify-content-between"><span>IGV:</span><strong>S/. <?= number_format($factura['igv'], 2) ?></strong></div>
                                        <div class="d-flex justify-content-between fs-5 border-top pt-2 mt-2"><span>Total:</span><strong>S/. <?= number_format($factura['total'], 2) ?></strong></div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-success btn-descargar-pdf" data-factura-id="<?= $factura['id'] ?>">
                                    <i class="bi bi-file-earmark-pdf"></i> Descargar / Guardar PDF
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
    <script>
        const campoBusquedaComprobante = document.getElementById('comprobante');
        const estadoBusqueda = document.getElementById('estadoBusqueda');
        let temporizadorBusqueda;
        campoBusquedaComprobante.addEventListener('input', function () {
            clearTimeout(temporizadorBusqueda);
            estadoBusqueda.textContent = this.value.trim() ? 'Buscando en todos los meses...' : 'Cargando el filtro seleccionado...';
            temporizadorBusqueda = setTimeout(function () {
                document.getElementById('formFiltroTransferencias').submit();
            }, 450);
        });

        const proveedorBusqueda = document.getElementById('proveedorBusqueda');
        const proveedorTransferencia = document.getElementById('id_proveedor_transferencia');
        const estadoProveedor = document.getElementById('estadoProveedor');
        const opcionesProveedores = Array.from(document.querySelectorAll('#proveedoresDisponibles option'));
        function normalizarProveedor(valor) {
            return valor.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        function resolverProveedor() {
            const valor = normalizarProveedor(proveedorBusqueda.value.trim());
            const opcion = opcionesProveedores.find(function (item) {
                const nombreConRuc = normalizarProveedor(item.value);
                const ruc = normalizarProveedor(item.dataset.ruc || '');
                return nombreConRuc === valor || (ruc !== '' && ruc === valor);
            });
            proveedorTransferencia.value = opcion ? opcion.dataset.id : '';
            if (opcion) {
                estadoProveedor.textContent = 'Proveedor seleccionado correctamente.';
                estadoProveedor.className = 'text-success';
            } else {
                estadoProveedor.textContent = valor ? 'Selecciona una coincidencia válida de la lista.' : 'Busca por nombre o RUC y selecciona una coincidencia.';
                estadoProveedor.className = 'text-muted';
            }
        }
        proveedorBusqueda.addEventListener('input', resolverProveedor);
        proveedorBusqueda.addEventListener('change', resolverProveedor);

        function obtenerGlobalesPorCategoria(idCategoria) {
            const categoria = window.categoriasTransferencia.find(function (item) {
                return Number(item.id) === Number(idCategoria);
            });
            if (!categoria || !categoria.ids_categorias_globales) return [];
            return String(categoria.ids_categorias_globales).split(',').map(Number).filter(function (valor) { return !Number.isNaN(valor) && valor > 0; });
        }

        function obtenerGlobalesSeleccionadosEdicion() {
            const seleccionados = document.querySelectorAll('#panelCategoriasGlobalesEdicion .categoria-edicion-check:checked');
            return Array.from(seleccionados).map(function (check) { return Number(check.value); }).filter(function (valor) { return valor > 0; });
        }

        function llenarSelectCategoriasEdicion(selector, valorSeleccionado, globalIds) {
            const idsGlobales = Array.isArray(globalIds) && globalIds.length ? globalIds : [];
            selector.innerHTML = '<option value="">Seleccionar categoría</option>';
            if (!idsGlobales.length) {
                window.categoriasTransferencia.forEach(function (item) {
                    const opcion = document.createElement('option');
                    opcion.value = item.id;
                    opcion.textContent = item.nombre;
                    opcion.selected = Number(item.id) === Number(valorSeleccionado);
                    selector.add(opcion);
                });
                return;
            }
            idsGlobales.forEach(function (idGlobal) {
                const grupo = window.categoriasGlobalesTransferencia.find(function (item) {
                    return Number(item.id) === Number(idGlobal);
                });
                const subcategorias = window.categoriasTransferencia.filter(function (item) {
                    const ids = String(item.ids_categorias_globales || '').split(',').map(Number).filter(function (valor) { return !Number.isNaN(valor) && valor > 0; });
                    return ids.includes(Number(idGlobal));
                });
                if (!grupo || !subcategorias.length) return;
                const optgroup = document.createElement('optgroup');
                optgroup.label = '▸ ' + grupo.nombre;
                subcategorias.forEach(function (item) {
                    const opcion = document.createElement('option');
                    opcion.value = item.id;
                    opcion.textContent = item.nombre;
                    opcion.selected = Number(item.id) === Number(valorSeleccionado);
                    optgroup.appendChild(opcion);
                });
                selector.appendChild(optgroup);
            });
            if (!selector.value && valorSeleccionado) {
                selector.value = String(valorSeleccionado);
            }
        }

        function actualizarSelectsCategoriasEdicion() {
            const globales = obtenerGlobalesSeleccionadosEdicion();
            document.querySelectorAll('#lineasEdicion select[name="id_categoria[]"]').forEach(function (selector) {
                const valorActual = selector.value || selector.dataset.valorSeleccionado || '';
                selector.dataset.valorSeleccionado = valorActual;
                llenarSelectCategoriasEdicion(selector, valorActual, globales);
            });
        }

        function crearFilaEdicion(detalle) {
            const fila = document.createElement('tr');
            fila.innerHTML = '<td><input class="form-control" name="producto[]" required></td><td><select class="form-select" name="id_categoria[]" required></select></td><td><input class="form-control cantidad-linea" type="number" name="cantidad[]" min="0.01" step="0.01" required></td><td><input class="form-control precio-linea" type="number" name="precio_unitario[]" min="0" step="0.01" required></td><td class="text-end subtotal-edicion">S/. 0.00</td><td><button type="button" class="btn btn-sm btn-outline-danger quitar-edicion" title="Quitar línea"><i class="bi bi-trash"></i></button></td>';
            const producto = fila.querySelector('[name="producto[]"]');
            const categoria = fila.querySelector('[name="id_categoria[]"]');
            const cantidad = fila.querySelector('[name="cantidad[]"]');
            const precio = fila.querySelector('[name="precio_unitario[]"]');
            producto.value = detalle.producto || '';
            cantidad.value = detalle.cantidad || 1;
            precio.value = detalle.precio_unitario || 0;
            precio.step = '0.0001';
            cantidad.step = '0.0001';
            const idsGlobales = obtenerGlobalesPorCategoria(detalle.id_categoria);
            const seleccionados = obtenerGlobalesSeleccionadosEdicion();
            const globales = seleccionados.length ? seleccionados : idsGlobales;
            categoria.dataset.valorSeleccionado = detalle.id_categoria ? String(detalle.id_categoria) : '';
            llenarSelectCategoriasEdicion(categoria, detalle.id_categoria || '', globales);
            function actualizarSubtotal() { fila.querySelector('.subtotal-edicion').textContent = 'S/. ' + ((Number(cantidad.value) || 0) * (Number(precio.value) || 0)).toFixed(2); }
            cantidad.addEventListener('input', actualizarSubtotal);
            precio.addEventListener('input', actualizarSubtotal);
            actualizarSubtotal();
            return fila;
        }

        function crearPanelCategoriasEditadas(factura) {
            const modal = document.getElementById('modalEditarTransferencia');
            const body = modal.querySelector('.modal-body');
            let panel = body.querySelector('#panelCategoriasGlobalesEdicion');
            if (!panel) {
                panel = document.createElement('div');
                panel.id = 'panelCategoriasGlobalesEdicion';
                panel.className = 'row g-2 p-3 border rounded bg-light mb-3';
                body.insertBefore(panel, body.querySelector('.table-responsive'));
            }

            const idsGlobalesMarcados = new Set();
            (factura.detalles || []).forEach(function (detalle) {
                obtenerGlobalesPorCategoria(detalle.id_categoria).forEach(function (idGlobal) {
                    idsGlobalesMarcados.add(Number(idGlobal));
                });
            });

            panel.innerHTML = '<div class="col-12"><label class="form-label fw-semibold mb-2"><i class="bi bi-tags me-2 text-warning"></i>Categorías actuales del comprobante</label></div>';

            if (window.categoriasGlobalesTransferencia && window.categoriasGlobalesTransferencia.length) {
                window.categoriasGlobalesTransferencia.forEach(function (item) {
                    const columna = document.createElement('div');
                    columna.className = 'col-sm-6 col-lg-4';
                    const idGlobal = Number(item.id);
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.className = 'form-check-input categoria-edicion-check';
                    input.value = idGlobal;
                    input.checked = idsGlobalesMarcados.has(idGlobal);
                    input.id = 'categoria_edicion_' + idGlobal;
                    input.addEventListener('change', actualizarSelectsCategoriasEdicion);

                    const label = document.createElement('label');
                    label.className = 'd-flex align-items-center gap-2 border rounded px-3 py-2 h-100 bg-white';
                    label.setAttribute('for', input.id);
                    label.innerHTML = '<span class="small fw-semibold"></span>';
                    label.querySelector('span').textContent = item.nombre;
                    label.prepend(input);

                    columna.appendChild(label);
                    panel.appendChild(columna);
                });
            }

            actualizarSelectsCategoriasEdicion();
        }

        let transferenciaPendienteEdicion = null;

        function cargarEditorTransferencia(id) {
            const factura = window.facturasParaPdf.find(function (item) { return item.id === id; });
            if (!factura) return;
            document.getElementById('editarTransferenciaId').value = factura.id;
            document.getElementById('editarTipoComprobante').value = factura.tipo;
            document.getElementById('editarNumeroComprobante').value = factura.numero;
            formatearComprobanteEnVivo(document.getElementById('editarNumeroComprobante'), document.getElementById('editarTipoComprobante'), document.getElementById('ayudaComprobanteEditar'), false);
            document.getElementById('editarProveedor').value = factura.id_proveedor;
            document.getElementById('editarFecha').value = factura.fecha;
            document.getElementById('editarDescripcion').value = factura.descripcion || '';
            const contenedor = document.getElementById('lineasEdicion');
            contenedor.innerHTML = '';
            (factura.detalles || []).forEach(function (detalle) { contenedor.appendChild(crearFilaEdicion(detalle)); });
            crearPanelCategoriasEditadas(factura);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarTransferencia')).show();
        }

        function abrirEdicionTransferencia(id) {
            const factura = window.facturasParaPdf.find(function (item) { return item.id === id; });
            if (!factura) return;
            transferenciaPendienteEdicion = id;
            document.getElementById('confirmarEdicionComprobante').textContent = factura.tipo + ' ' + factura.numero;
            document.getElementById('confirmarEdicionProveedor').textContent = factura.proveedor || 'Proveedor no identificado';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConfirmarEdicion')).show();
        }

        document.getElementById('continuarEdicion').addEventListener('click', function () {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConfirmarEdicion')).hide();
            cargarEditorTransferencia(transferenciaPendienteEdicion);
        });

        document.getElementById('agregarLineaEdicion').addEventListener('click', function () {
            document.getElementById('lineasEdicion').appendChild(crearFilaEdicion({ producto: '', cantidad: 1, precio_unitario: 0, id_categoria: 0 }));
        });
        document.getElementById('lineasEdicion').addEventListener('click', function (evento) {
            const boton = evento.target.closest('.quitar-edicion');
            if (boton) boton.closest('tr').remove();
        });

        function abrirEliminacionTransferencia(id, comprobante, proveedor) {
            document.getElementById('eliminarTransferenciaId').value = id;
            document.getElementById('eliminarComprobante').textContent = comprobante;
            document.getElementById('eliminarProveedor').textContent = proveedor;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminarTransferencia')).show();
        }

        window.categoriasTransferencia = <?= json_encode($categorias, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.categoriasGlobalesTransferencia = <?= json_encode($categorias_globales, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function formatearComprobanteEnVivo(campo, tipo, ayuda, sugerirPrefijo) {
            const tipoActual = String(tipo.value || '').toUpperCase();
            const estructurado = ['FACTURA', 'RHE'].includes(tipoActual);
            const prefijo = tipoActual === 'FACTURA' ? 'F' : (tipoActual === 'RHE' ? 'E' : '');
            let valor = String(campo.value || '').toUpperCase();

            if (estructurado) {
                const compacto = valor.replace(/[^A-Z0-9]/g, '');
                const serie = compacto.slice(0, 4);
                const correlativo = compacto.slice(4).replace(/[^0-9]/g, '').slice(0, 8);
                valor = serie.length === 4 ? serie + '-' + correlativo : serie;
                if (ayuda) ayuda.textContent = 'Serie: 4 caracteres alfanuméricos; correlativo: hasta 8 dígitos. ' + (prefijo ? 'Se sugiere ' + prefijo + ' como inicio, pero puedes borrarla.' : '');
            } else {
                valor = valor.slice(0, 100);
                if (ayuda) ayuda.textContent = 'Formato libre para este tipo; se convierte automáticamente a mayúsculas.';
            }

            campo.value = valor;
            campo.maxLength = 100;
            campo.removeAttribute('pattern');
            campo.setCustomValidity('');
            return valor;
        }

        function conectarFormatoComprobante(tipo, campo, ayuda) {
            if (!tipo || !campo) return;
            if (!ayuda) {
                ayuda = document.createElement('small');
                ayuda.className = 'form-text text-muted';
                ayuda.id = campo.id + 'AyudaFormato';
                campo.insertAdjacentElement('afterend', ayuda);
                campo.setAttribute('aria-describedby', ayuda.id);
            }
            campo.removeAttribute('pattern');
            let prefijoSugerido = '';
            campo.addEventListener('input', function () {
                if (campo.value.toUpperCase() !== prefijoSugerido) prefijoSugerido = '';
                formatearComprobanteEnVivo(campo, tipo, ayuda, false);
            });
            campo.addEventListener('change', function () {
                formatearComprobanteEnVivo(campo, tipo, ayuda, false);
            });
            tipo.addEventListener('change', function () {
                const tipoNuevo = String(tipo.value).toUpperCase();
                const prefijoNuevo = tipoNuevo === 'FACTURA' ? 'F' : (tipoNuevo === 'RHE' ? 'E' : '');
                const valorActual = campo.value.toUpperCase();
                if (prefijoSugerido && valorActual === prefijoSugerido) {
                    campo.value = prefijoNuevo;
                    prefijoSugerido = prefijoNuevo;
                } else if (valorActual.trim() === '' && prefijoNuevo) {
                    campo.value = prefijoNuevo;
                    prefijoSugerido = prefijoNuevo;
                } else {
                    prefijoSugerido = '';
                }
                formatearComprobanteEnVivo(campo, tipo, ayuda, true);
            });
            formatearComprobanteEnVivo(campo, tipo, ayuda, false);
        }

        conectarFormatoComprobante(
            document.getElementById('tipoComprobanteNuevo'),
            document.getElementById('numeroComprobanteNuevo'),
            document.getElementById('ayudaComprobanteNuevo')
        );
        conectarFormatoComprobante(
            document.getElementById('editarTipoComprobante'),
            document.getElementById('editarNumeroComprobante'),
            document.getElementById('ayudaComprobanteEditar')
        );

        const contenedorLineas = document.getElementById('lineasTransferencia');
        const totalTransferencia = document.getElementById('totalTransferencia');
        const categoriaGlobalPanel = document.getElementById('categoriaGlobalPanel');
        const categoriaGlobal = document.getElementById('categoria_global');
        const categoriaGlobalLabel = document.getElementById('categoriaGlobalLabel');
        const ayudaCategoriaLinea = document.getElementById('ayudaCategoriaLinea');
        const encabezadoCategoria = document.querySelector('#lineasTransferencia')?.closest('table')?.querySelector('thead th:nth-child(2)');
        if (encabezadoCategoria) encabezadoCategoria.textContent = 'Subcategoría · Categoría global';
        document.querySelectorAll('#formNuevaTransferencia input[type="number"][name="cantidad[]"], #formNuevaTransferencia input[type="number"][name="precio_unitario[]"]').forEach(function (campo) {
            campo.step = '0.0001';
        });
        const subcategoriaGlobalPanel = document.getElementById('subcategoriaGlobalPanel') || document.createElement('div');
        let subcategoriaGlobal = document.getElementById('subcategoria_global');

        function cargarCategoriasGlobales() {
            let panel = document.getElementById('panelCategoriasGlobales');
            if (!panel) {
                panel = document.createElement('div');
                panel.id = 'panelCategoriasGlobales';
                panel.className = 'row g-2 p-2 border rounded bg-white';
                categoriaGlobal.parentElement.insertBefore(panel, categoriaGlobal);
            }
            panel.innerHTML = '';
            window.categoriasGlobalesTransferencia.forEach(function (item) {
                const columna = document.createElement('div');
                columna.className = 'col-sm-6 col-lg-4';
                columna.innerHTML = '<label class="d-flex align-items-center gap-2 border rounded px-3 py-2 h-100" style="cursor:pointer"><input class="form-check-input mt-0 categoria-global-check" type="checkbox" name="categoria_global[]" value="' + item.id + '"><span class="small fw-semibold"></span></label>';
                columna.querySelector('span').textContent = item.nombre;
                panel.appendChild(columna);
            });
            categoriaGlobal.hidden = true;
            categoriaGlobal.removeAttribute('name');
            categoriaGlobal.required = false;
        }

        function obtenerGlobalesSeleccionados() {
            return Array.from(document.querySelectorAll('.categoria-global-check:checked')).map(function (opcion) { return Number(opcion.value); }).filter(Boolean);
        }

        function cargarSubcategorias(selector, valor, globalIds) {
            const globales = Array.isArray(globalIds) ? globalIds : [Number(globalIds)];
            selector.innerHTML = '<option value="">Seleccionar subcategoría</option>';
            globales.forEach(function (globalId) {
                const global = window.categoriasGlobalesTransferencia.find(function (item) {
                    return Number(item.id) === Number(globalId);
                });
                const subcategorias = window.categoriasTransferencia.filter(function (item) {
                    return String(item.ids_categorias_globales || '').split(',').map(Number).includes(Number(globalId));
                });
                if (!global || !subcategorias.length) return;
                const grupo = document.createElement('optgroup');
                grupo.label = '▸ ' + global.nombre;
                subcategorias.forEach(function (item) {
                    const opcion = document.createElement('option');
                    opcion.value = item.id;
                    opcion.textContent = item.nombre;
                    opcion.selected = Number(item.id) === Number(valor);
                    grupo.appendChild(opcion);
                });
                selector.appendChild(grupo);
            });
            selector.disabled = !globales.length;
        }

        if (!subcategoriaGlobal) {
            subcategoriaGlobal = document.createElement('select');
            subcategoriaGlobal.className = 'form-select';
            subcategoriaGlobal.name = 'subcategoria_global';
            subcategoriaGlobal.id = 'subcategoria_global';
            subcategoriaGlobal.required = true;
            const grupoSubcategoria = document.createElement('div');
            grupoSubcategoria.className = 'mt-3 d-none';
            grupoSubcategoria.id = 'subcategoriaGlobalPanel';
            grupoSubcategoria.innerHTML = '<label class="form-label fw-semibold mb-1" for="subcategoria_global">Subcategoría para toda la transferencia *</label>';
            grupoSubcategoria.appendChild(subcategoriaGlobal);
            categoriaGlobalPanel.appendChild(grupoSubcategoria);
        }
        const panelSubcategoria = document.getElementById('subcategoriaGlobalPanel');
        cargarCategoriasGlobales();
        categoriaGlobal.addEventListener('change', function () {
            const globales = obtenerGlobalesSeleccionados();
            cargarSubcategorias(subcategoriaGlobal, '', globales);
            actualizarModoCategoria();
        });
        document.getElementById('panelCategoriasGlobales').addEventListener('change', function () {
            const globales = obtenerGlobalesSeleccionados();
            cargarSubcategorias(subcategoriaGlobal, '', globales);
            actualizarModoCategoria();
        });

        function actualizarModoCategoria() {
            const modoGlobal = document.getElementById('modoCategoriaGlobal').checked;
            categoriaGlobalPanel.classList.remove('d-none');
            categoriaGlobal.required = false;
            panelSubcategoria.classList.toggle('d-none', !modoGlobal);
            subcategoriaGlobal.required = modoGlobal;
            const globales = obtenerGlobalesSeleccionados();
            categoriaGlobalLabel.textContent = modoGlobal
                ? 'Categoría de toda la transferencia *'
                : 'Categoría global de las líneas *';
            ayudaCategoriaLinea.textContent = modoGlobal
                ? 'Selecciona la categoría global y la subcategoría que se aplicará a todas las líneas.'
                : 'Selecciona una categoría global; cada línea tendrá sus subcategorías disponibles.';
            contenedorLineas.querySelectorAll('select[name="id_categoria[]"]').forEach(function (selector) {
                selector.disabled = modoGlobal;
                selector.required = !modoGlobal;
                if (modoGlobal) selector.value = '';
                if (!modoGlobal) cargarSubcategorias(selector, selector.value, globales);
            });
        }

        document.querySelectorAll('input[name="modo_categoria"]').forEach(function (radio) {
            radio.addEventListener('change', actualizarModoCategoria);
        });

        function actualizarTotalesTransferencia() {
            let totalSubtotal = 0;
            const sinIgv = document.getElementById('sinIgvNueva').checked;
            contenedorLineas.querySelectorAll('.linea-transferencia').forEach(function (linea) {
                const cantidad = Number(linea.querySelector('.cantidad-linea').value) || 0;
                const precio = Number(linea.querySelector('.precio-linea').value) || 0;
                const subtotal = cantidad * precio;
                totalSubtotal += subtotal;
                linea.querySelector('.subtotal-linea').textContent = 'S/. ' + subtotal.toFixed(4);
            });
            const totalFinal = sinIgv ? totalSubtotal : totalSubtotal * 1.18;
            const etiqueta = sinIgv ? 'Total' : 'Total con IGV (18%)';
            totalTransferencia.textContent = 'S/. ' + totalFinal.toFixed(4);
            totalTransferencia.previousElementSibling.textContent = etiqueta;
        }

        document.getElementById('agregarLinea').addEventListener('click', function () {
            const nuevaLinea = contenedorLineas.querySelector('.linea-transferencia').cloneNode(true);
            nuevaLinea.querySelector('input[name="producto[]"]').value = '';
            nuevaLinea.querySelector('.cantidad-linea').value = '1';
            nuevaLinea.querySelector('.cantidad-linea').step = '0.0001';
            nuevaLinea.querySelector('.precio-linea').value = '';
            nuevaLinea.querySelector('.precio-linea').step = '0.0001';
            nuevaLinea.querySelector('select[name="id_categoria[]"]').value = '';
            nuevaLinea.querySelector('.quitar-linea').disabled = false;
            contenedorLineas.appendChild(nuevaLinea);
            actualizarModoCategoria();
            actualizarTotalesTransferencia();
        });

        contenedorLineas.addEventListener('click', function (evento) {
            const boton = evento.target.closest('.quitar-linea');
            if (boton && contenedorLineas.querySelectorAll('.linea-transferencia').length > 1) {
                boton.closest('.linea-transferencia').remove();
                actualizarTotalesTransferencia();
            }
        });
        contenedorLineas.addEventListener('input', actualizarTotalesTransferencia);
        
        // Escuchar cambios en el checkbox "sin_igv" para actualizar totales
        document.getElementById('sinIgvNueva').addEventListener('change', actualizarTotalesTransferencia);
        
        actualizarModoCategoria();
        actualizarTotalesTransferencia();

        window.facturasParaPdf = <?= json_encode($datos_pdf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        document.querySelectorAll('.btn-descargar-pdf').forEach(function (button) {
            button.addEventListener('click', function () {
                descargarFacturaPdf(Number(button.dataset.facturaId));
            });
        });

        function descargarFacturaPdf(idFactura) {
            const factura = window.facturasParaPdf.find(function (item) { return item.id === idFactura; });
            if (!factura || !window.jspdf) {
                alert('No se pudo preparar el PDF.');
                return;
            }

            const doc = new window.jspdf.jsPDF();
            const verde = [25, 135, 84];
            const moneda = function (valor) { return 'S/. ' + Number(valor).toFixed(2); };
            const fecha = factura.fecha ? factura.fecha.split('-').reverse().join('/') : 'N/A';

            doc.setTextColor.apply(doc, verde);
            doc.setFontSize(20);
            doc.setFont(undefined, 'bold');
            doc.text('CISMEDIC', 14, 18);
            doc.setFont(undefined, 'normal');
            doc.setFontSize(10);
            doc.setTextColor(90, 98, 108);
            doc.text('Sistema de Control de Egresos', 14, 25);
            doc.text('Comprobante de compra', 14, 31);
            doc.setDrawColor.apply(doc, verde);
            doc.setLineWidth(0.8);
            doc.line(14, 36, 196, 36);

            doc.setTextColor(35, 42, 50);
            doc.setFontSize(10);
            doc.text('Comprobante: ' + factura.tipo + ' ' + factura.numero, 14, 46);
            doc.text('Fecha: ' + fecha, 14, 52);
            doc.text('Proveedor: ' + factura.proveedor, 14, 60);
            if (factura.ruc) doc.text('RUC: ' + factura.ruc, 14, 66);
            doc.text('Registrado por: ' + factura.usuario, 14, 72);

            const filas = factura.detalles.map(function (detalle) {
                return [detalle.producto, detalle.cantidad.toFixed(2), moneda(detalle.precio_unitario), moneda(detalle.subtotal), moneda(detalle.igv), moneda(detalle.total)];
            });
            doc.autoTable({
                startY: 80,
                head: [['Producto / Concepto', 'Cantidad', 'P. unitario', 'Subtotal', 'IGV', 'Total']],
                body: filas.length ? filas : [['Sin líneas de detalle registradas.', '', '', '', '', '']],
                theme: 'grid',
                headStyles: { fillColor: verde },
                styles: { fontSize: 8, cellPadding: 3 },
                columnStyles: { 0: { cellWidth: 65 }, 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' } }
            });

            const yTotal = doc.lastAutoTable.finalY + 12;
            doc.setFont(undefined, 'bold');
            doc.text('IGV: ' + moneda(factura.igv), 145, yTotal);
            doc.setTextColor.apply(doc, verde);
            doc.setFontSize(13);
            doc.text('Total: ' + moneda(factura.total), 145, yTotal + 8);
            doc.setFont(undefined, 'normal');
            doc.setFontSize(8);
            doc.setTextColor(110, 118, 128);
            doc.text('Documento generado por CISMEDIC - ' + new window.Date().toLocaleString('es-PE', { timeZone: 'America/Lima' }), 14, 285);
            doc.save('factura-' + factura.numero.replace(/[^a-z0-9-_]/gi, '_') + '.pdf');
        }
    </script>
    <script>
        (function () {
            const busqueda = document.getElementById('analisisProveedorBusqueda');
            const sugerencias = document.getElementById('analisisProveedorSugerencias');
            const estado = document.getElementById('estadoAnalisisProveedor');
            const resultado = document.getElementById('resultadoAnalisisProveedor');
            const selectorAno = document.getElementById('anoReporteProveedor');
            let temporizador;
            let controladorBusqueda;
            let solicitudActual = 0;
            let proveedorActual = null;

            function escaparHtml(valor) {
                return String(valor ?? '').replace(/[&<>"']/g, function (caracter) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[caracter];
                });
            }

            function dinero(valor) {
                return 'S/. ' + new Intl.NumberFormat('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(valor) || 0);
            }

            function fecha(valor) {
                if (!valor) return 'Sin fecha';
                const partes = String(valor).slice(0, 10).split('-');
                return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : escaparHtml(valor);
            }

            async function obtenerJson(parametros, signal) {
                const url = new URL(window.location.href);
                Object.entries(parametros).forEach(function (entrada) { url.searchParams.set(entrada[0], entrada[1]); });
                const respuesta = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store', signal: signal});
                const datos = await respuesta.json();
                if (!respuesta.ok || !datos.ok) throw new Error(datos.mensaje || 'No se pudo completar la consulta.');
                return datos;
            }

            function mostrarSugerencias(lista) {
                sugerencias.replaceChildren();
                if (!lista.length) {
                    sugerencias.classList.add('d-none');
                    estado.textContent = 'No se encontraron proveedores con ese nombre o RUC.';
                    return;
                }
                lista.forEach(function (proveedor) {
                    const opcion = document.createElement('button');
                    opcion.type = 'button';
                    opcion.className = 'provider-suggestion';
                    opcion.setAttribute('role', 'option');
                    opcion.dataset.proveedorId = proveedor.id;
                    opcion.dataset.proveedorNombre = proveedor.nombre;
                    opcion.dataset.proveedorRuc = proveedor.ruc || '';
                    const nombre = document.createElement('span');
                    nombre.className = 'fw-semibold';
                    nombre.textContent = proveedor.nombre;
                    const ruc = document.createElement('small');
                    ruc.className = 'text-muted ms-3';
                    ruc.textContent = proveedor.ruc ? 'RUC ' + proveedor.ruc : '';
                    opcion.append(nombre, ruc);
                    sugerencias.appendChild(opcion);
                });
                sugerencias.classList.remove('d-none');
                estado.textContent = 'Selecciona el proveedor para cargar todas sus transferencias.';
            }

            async function buscarProveedores() {
                const termino = busqueda.value.trim();
                if (termino.length < 2) {
                    sugerencias.classList.add('d-none');
                    sugerencias.replaceChildren();
                    estado.textContent = 'Busca y selecciona un proveedor para consultar su historial total.';
                    return;
                }
                if (controladorBusqueda) controladorBusqueda.abort();
                controladorBusqueda = new AbortController();
                const solicitud = ++solicitudActual;
                estado.textContent = 'Buscando proveedores...';
                try {
                    const datos = await obtenerJson({ajax: 'proveedor_sugerencias', q: termino}, controladorBusqueda.signal);
                    if (solicitud === solicitudActual) mostrarSugerencias(datos.proveedores || []);
                } catch (error) {
                    if (error.name !== 'AbortError' && solicitud === solicitudActual) estado.textContent = error.message;
                }
            }

            function pintarCategorias(categorias) {
                const contenedor = document.getElementById('categoriasProveedorReporte');
                const maximo = Math.max(0, ...categorias.map(function (categoria) { return Number(categoria.total) || 0; }));
                document.getElementById('cantidadCategoriasProveedor').textContent = categorias.length ? categorias.length + ' categorías' : '';
                if (!categorias.length) {
                    contenedor.innerHTML = '<p class="text-muted small mb-0">No hay categorías detalladas para este proveedor.</p>';
                    return;
                }
                contenedor.innerHTML = categorias.map(function (categoria) {
                    const porcentaje = maximo > 0 ? (Number(categoria.total) / maximo) * 100 : 0;
                    return '<div class="provider-category-row"><span class="provider-category-name">' + escaparHtml(categoria.nombre) + '</span><span class="provider-category-amount">' + dinero(categoria.total) + ' <small class="text-muted fw-normal">· ' + Number(categoria.lineas) + ' líneas</small></span><div class="provider-category-track"><div class="provider-category-fill" style="width:' + porcentaje.toFixed(2) + '%"></div></div></div>';
                }).join('');
            }

            function pintarResumenAnual(resumenes, anoSeleccionado) {
                const contenedor = document.getElementById('comparacionAnualProveedor');
                const nota = document.getElementById('notaComparacionAnual');
                selectorAno.replaceChildren(new Option('Todos los años', ''));
                (resumenes || []).forEach(function (resumen) {
                    selectorAno.add(new Option(String(resumen.ano), String(resumen.ano)));
                });
                selectorAno.value = anoSeleccionado || '';
                if (!resumenes || !resumenes.length) {
                    contenedor.innerHTML = '<div class="text-muted small py-4">Este proveedor no tiene transferencias con fecha registrada.</div>';
                    nota.textContent = '';
                    return;
                }
                const maximo = Math.max(...resumenes.map(function (resumen) { return Number(resumen.total) || 0; }), 0);
                nota.textContent = 'Monto acumulado por año';
                contenedor.innerHTML = resumenes.map(function (resumen) {
                    const altura = maximo > 0 ? Math.max(5, (Number(resumen.total) / maximo) * 100) : 5;
                    const activo = String(resumen.ano) === String(anoSeleccionado) ? ' active' : '';
                    return '<div class="provider-year-bar"><button type="button" class="provider-year-bar-button' + activo + '" data-ano-proveedor="' + Number(resumen.ano) + '" aria-pressed="' + (activo !== '' ? 'true' : 'false') + '" title="' + Number(resumen.cantidad) + ' transferencias · ' + dinero(resumen.total) + '"><span class="provider-year-value">' + dinero(resumen.total) + '</span><span class="provider-year-track"><span class="provider-year-bar-fill" style="height:' + altura.toFixed(2) + '%"></span></span><span class="provider-year-label">' + Number(resumen.ano) + '</span><small class="text-muted">' + Number(resumen.cantidad) + ' mov.</small></button></div>';
                }).join('');
            }

            function pintarFacturas(facturas) {
                const cuerpo = document.getElementById('facturasProveedorReporte');
                document.getElementById('cantidadFacturasProveedor').textContent = facturas.length + (facturas.length === 1 ? ' transferencia' : ' transferencias');
                if (!facturas.length) {
                    cuerpo.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Este proveedor aún no tiene transferencias registradas.</td></tr>';
                    return;
                }
                cuerpo.innerHTML = facturas.map(function (factura) {
                    const detalles = factura.detalles || [];
                    const subtotalDetalle = detalles.reduce(function (suma, detalle) { return suma + (Number(detalle.subtotal) || 0); }, 0);
                    const igvDetalle = detalles.reduce(function (suma, detalle) { return suma + (Number(detalle.igv) || 0); }, 0);
                    const filasDetalle = detalles.length ? detalles.map(function (detalle) {
                        return '<tr><td><span class="fw-semibold">' + escaparHtml(detalle.producto) + '</span></td><td><span class="badge bg-success-subtle text-success">' + escaparHtml(detalle.categoria) + '</span></td><td class="text-end text-nowrap">' + new Intl.NumberFormat('es-PE', {maximumFractionDigits: 4}).format(Number(detalle.cantidad) || 0) + '</td><td class="text-end text-nowrap">' + dinero(detalle.precio_unitario) + '</td><td class="text-end text-nowrap">' + dinero(detalle.subtotal) + '</td><td class="text-end text-nowrap">' + dinero(detalle.igv) + '</td><td class="text-end text-nowrap fw-semibold">' + dinero(detalle.total) + '</td></tr>';
                    }).join('') : '<tr><td colspan="7" class="text-center text-muted py-3">Sin líneas de detalle registradas.</td></tr>';
                    return '<tr><td class="text-nowrap">' + fecha(factura.fecha) + '</td><td><span class="fw-semibold">' + escaparHtml(factura.tipo_comprobante) + '</span><br><small>' + escaparHtml(factura.numero_comprobante) + '</small></td><td>' + escaparHtml(factura.descripcion || 'Sin descripción') + '</td><td class="text-end text-nowrap fw-semibold">' + dinero(factura.total) + '</td><td class="text-end text-nowrap">' + dinero(factura.igv) + '</td><td><details><summary>Ver desglose · ' + detalles.length + ' líneas</summary><div class="provider-invoice-details"><div class="provider-invoice-detail-summary"><div class="provider-invoice-detail-kpi"><span>Subtotal productos</span><strong>' + dinero(subtotalDetalle) + '</strong></div><div class="provider-invoice-detail-kpi"><span>IGV de líneas</span><strong>' + dinero(igvDetalle) + '</strong></div><div class="provider-invoice-detail-kpi"><span>Total factura</span><strong>' + dinero(factura.total) + '</strong></div></div><div class="table-responsive"><table class="table table-sm table-bordered align-middle provider-invoice-lines mb-0"><thead><tr><th>Producto / concepto</th><th>Categoría</th><th class="text-end">Cantidad</th><th class="text-end">Precio unitario</th><th class="text-end">Subtotal</th><th class="text-end">IGV</th><th class="text-end">Total línea</th></tr></thead><tbody>' + filasDetalle + '</tbody></table></div></div></details></td></tr>';
                }).join('');
            }

            async function cargarReporte(proveedor, ano) {
                proveedorActual = proveedor;
                sugerencias.classList.add('d-none');
                resultado.classList.remove('d-none');
                document.getElementById('nombreProveedorReporte').textContent = proveedor.nombre;
                document.getElementById('rucProveedorReporte').textContent = proveedor.ruc ? 'RUC ' + proveedor.ruc : 'RUC no registrado';
                document.getElementById('facturasProveedorReporte').innerHTML = '<tr><td colspan="6"><div class="provider-report-loading"><span class="spinner-border spinner-border-sm text-success"></span>Cargando historial y detalle...</div></td></tr>';
                estado.textContent = 'Consultando todas las transferencias registradas...';
                const solicitud = ++solicitudActual;
                try {
                    const datos = await obtenerJson({ajax: 'reporte_proveedor', proveedor: proveedor.id, ano_reporte: ano || ''});
                    if (solicitud !== solicitudActual) return;
                    document.getElementById('nombreProveedorReporte').textContent = datos.proveedor.nombre;
                    document.getElementById('rucProveedorReporte').textContent = datos.proveedor.ruc ? 'RUC ' + datos.proveedor.ruc : 'RUC no registrado';
                    document.getElementById('totalProveedorReporte').textContent = dinero(datos.resumen.total);
                    document.getElementById('cantidadProveedorReporte').textContent = new Intl.NumberFormat('es-PE').format(datos.resumen.cantidad);
                    document.getElementById('igvProveedorReporte').textContent = dinero(datos.resumen.igv);
                    document.getElementById('promedioProveedorReporte').textContent = dinero(datos.resumen.promedio);
                    const periodo = datos.resumen.primera_fecha ? fecha(datos.resumen.primera_fecha) + ' - ' + fecha(datos.resumen.ultima_fecha) : 'Sin movimientos registrados';
                    document.getElementById('periodoProveedorReporte').textContent = periodo;
                    pintarResumenAnual(datos.resumenes_anuales || [], datos.ano_seleccionado || '');
                    pintarCategorias(datos.categorias || []);
                    pintarFacturas(datos.facturas || []);
                    estado.textContent = 'Reporte actualizado: ' + datos.resumen.cantidad + ' transferencias encontradas.';
                } catch (error) {
                    if (solicitud !== solicitudActual) return;
                    document.getElementById('facturasProveedorReporte').innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">' + escaparHtml(error.message) + '</td></tr>';
                    estado.textContent = error.message;
                }
            }

            busqueda.addEventListener('input', function () {
                clearTimeout(temporizador);
                solicitudActual++;
                resultado.classList.add('d-none');
                temporizador = setTimeout(buscarProveedores, 250);
            });
            sugerencias.addEventListener('click', function (evento) {
                const opcion = evento.target.closest('[data-proveedor-id]');
                if (!opcion) return;
                busqueda.value = opcion.dataset.proveedorNombre;
                cargarReporte({id: opcion.dataset.proveedorId, nombre: opcion.dataset.proveedorNombre, ruc: opcion.dataset.proveedorRuc});
            });
            selectorAno.addEventListener('change', function () {
                if (proveedorActual) cargarReporte(proveedorActual, selectorAno.value);
            });
            document.getElementById('comparacionAnualProveedor').addEventListener('click', function (evento) {
                const barra = evento.target.closest('[data-ano-proveedor]');
                if (!barra || !proveedorActual) return;
                selectorAno.value = barra.dataset.anoProveedor;
                cargarReporte(proveedorActual, selectorAno.value);
            });
            document.getElementById('limpiarAnalisisProveedor').addEventListener('click', function () {
                clearTimeout(temporizador);
                solicitudActual++;
                busqueda.value = '';
                proveedorActual = null;
                sugerencias.classList.add('d-none');
                resultado.classList.add('d-none');
                estado.textContent = 'Busca y selecciona un proveedor para consultar su historial total.';
                busqueda.focus();
            });
            document.addEventListener('click', function (evento) {
                if (!evento.target.closest('.provider-search-wrap')) sugerencias.classList.add('d-none');
            });
        }());
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html>
