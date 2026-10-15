<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

requerir_roles(['superadmin', 'administrativo']);

require_once 'config/database.php';

$id_factura = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$factura = null;
$detalles = [];
$error = null;

if (!$id_factura) {
    $error = 'Factura no válida.';
} else {
    try {
        $stmt = $pdo->prepare('SELECT f.*, p.nombre as proveedor, p.ruc, p.direccion, u.nombre as usuario FROM facturas f LEFT JOIN proveedores p ON f.id_proveedor = p.id LEFT JOIN usuarios u ON f.id_usuario = u.id WHERE f.id = ?');
        $stmt->execute([$id_factura]);
        $factura = $stmt->fetch();

        if (!$factura) {
            $error = 'La factura no existe.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM detalle_factura WHERE id_factura = ? ORDER BY id');
            $stmt->execute([$id_factura]);
            $detalles = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $error = 'No se pudo cargar la factura.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title><?= $factura ? 'Factura ' . htmlspecialchars($factura['numero_comprobante']) : 'Factura' ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f6; color: #1f2937; font: 14px/1.45 Arial, sans-serif; }
        .toolbar { display: flex; justify-content: flex-end; gap: 10px; padding: 18px max(18px, calc((100% - 900px) / 2)); }
        button, a { border: 0; border-radius: 5px; color: #fff; cursor: pointer; font-size: 14px; padding: 10px 16px; text-decoration: none; }
        .btn-print { background: #198754; }
        .btn-close { background: #6b7280; }
        .document { background: #fff; margin: 0 auto 30px; max-width: 900px; padding: 42px 48px; }
        .header { border-bottom: 3px solid #198754; display: flex; justify-content: space-between; gap: 30px; padding-bottom: 24px; }
        .brand { color: #198754; font-size: 24px; font-weight: 700; margin: 0 0 6px; }
        .muted { color: #6b7280; }
        .invoice-box { border: 1px solid #d9e1ea; border-radius: 6px; min-width: 220px; padding: 14px 18px; text-align: right; }
        .invoice-number { color: #198754; font-size: 18px; font-weight: 700; }
        .info { display: grid; gap: 22px; grid-template-columns: 1fr 1fr; margin: 28px 0; }
        .label { color: #6b7280; font-size: 11px; letter-spacing: .05em; text-transform: uppercase; }
        .value { font-weight: 600; margin-top: 3px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #f1f5f9; color: #374151; font-size: 12px; text-align: left; text-transform: uppercase; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 11px 9px; }
        .number { text-align: right; }
        .totals { display: flex; justify-content: flex-end; margin-top: 22px; }
        .totals table { max-width: 310px; }
        .grand-total td { border-top: 2px solid #198754; font-size: 17px; font-weight: 700; }
        .footer { border-top: 1px solid #e5e7eb; color: #6b7280; margin-top: 38px; padding-top: 16px; }
        .error { background: #fff; margin: 50px auto; max-width: 600px; padding: 30px; text-align: center; }
        @media (max-width: 640px) { .document { padding: 24px 18px; } .header { display: block; } .invoice-box { margin-top: 18px; text-align: left; } .info { grid-template-columns: 1fr; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .document { margin: 0; max-width: none; padding: 0; } }
    </style>
</head>
<body>
    <?php if ($error): ?>
        <div class="error"><h2><?= htmlspecialchars($error) ?></h2><a class="btn-close" href="facturas.php">Volver a facturas</a></div>
    <?php else: ?>
        <div class="toolbar">
            <button class="btn-print" type="button" onclick="window.print()">&#128438; Descargar / Guardar PDF</button>
            <a class="btn-close" href="facturas.php">Cerrar</a>
        </div>
        <main class="document">
            <header class="header">
                <div>
                    <h1 class="brand">CISMEDIC</h1>
                    <div class="muted">Sistema de Control de Egresos</div>
                    <div class="muted">Comprobante de compra</div>
                </div>
                <div class="invoice-box">
                    <div class="label">Comprobante</div>
                    <div class="invoice-number"><?= htmlspecialchars($factura['tipo_comprobante'] . ' ' . $factura['numero_comprobante']) ?></div>
                    <div class="muted">Fecha: <?= $factura['fecha'] ? date('d/m/Y', strtotime($factura['fecha'])) : 'N/A' ?></div>
                </div>
            </header>

            <section class="info">
                <div><div class="label">Proveedor</div><div class="value"><?= htmlspecialchars($factura['proveedor'] ?? 'N/A') ?></div><?php if (!empty($factura['ruc'])): ?><div class="muted">RUC: <?= htmlspecialchars($factura['ruc']) ?></div><?php endif; ?></div>
                <div><div class="label">Registrado por</div><div class="value"><?= htmlspecialchars($factura['usuario'] ?? 'N/A') ?></div><?php if (!empty($factura['descripcion'])): ?><div class="muted">Nota: <?= htmlspecialchars($factura['descripcion']) ?></div><?php endif; ?></div>
            </section>

            <table>
                <thead><tr><th>Producto / Concepto</th><th class="number">Cantidad</th><th class="number">P. unitario</th><th class="number">Subtotal</th><th class="number">IGV</th><th class="number">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($detalles as $detalle): ?>
                    <tr>
                        <td><?= htmlspecialchars($detalle['producto']) ?></td>
                        <td class="number"><?= number_format($detalle['cantidad'], 2) ?></td>
                        <td class="number">S/. <?= number_format($detalle['precio_unitario'], 2) ?></td>
                        <td class="number">S/. <?= number_format($detalle['subtotal'], 2) ?></td>
                        <td class="number">S/. <?= number_format($detalle['igv'], 2) ?></td>
                        <td class="number">S/. <?= number_format($detalle['subtotal_con_igv'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($detalles)): ?><tr><td colspan="6" class="muted">Sin líneas de detalle registradas.</td></tr><?php endif; ?>
                </tbody>
            </table>

            <div class="totals"><table><tr><td>IGV</td><td class="number">S/. <?= number_format($factura['igv'], 2) ?></td></tr><tr class="grand-total"><td>Total</td><td class="number">S/. <?= number_format($factura['total'], 2) ?></td></tr></table></div>
            <div class="footer">Documento generado por CISMEDIC · <?= date('d/m/Y H:i') ?></div>
        </main>
    <?php endif; ?>
</body>
</html>

