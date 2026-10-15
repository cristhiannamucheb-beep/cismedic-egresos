<?php
session_start();
date_default_timezone_set('America/Lima');

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'config/database.php';

$id_almacenamiento = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$cheque = trim($_GET['cheque'] ?? '');
if (!$id_almacenamiento || !preg_match('/^[A-Za-z0-9-]{1,30}$/', $cheque)) {
    http_response_code(400);
    exit('El grupo o el numero de cheque no son validos.');
}

$stmt = $pdo->prepare('SELECT a.*, u.nombre AS usuario FROM almacenamientos_egresos a LEFT JOIN usuarios u ON u.id = a.id_usuario_registra WHERE a.id = ?');
$stmt->execute([$id_almacenamiento]);
$grupo = $stmt->fetch();
if (!$grupo) {
    http_response_code(404);
    exit('El grupo de egresos no existe.');
}

$stmt = $pdo->prepare('SELECT fecha, documento, proveedor_responsable, descripcion, total FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? ORDER BY fecha, id');
$stmt->execute([$id_almacenamiento]);
$detalles = $stmt->fetchAll();

function escapar_pdf($texto, $limite = 112) {
    $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', (string) $texto);
    $texto = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $texto);
    $texto = substr($texto, 0, $limite);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

$total = array_sum(array_map(static fn($detalle) => (float) $detalle['total'], $detalles));
$lineas = [
    'CISMEDIC - EGRESOS AGRUPADOS',
    'GRUPO: ' . $grupo['nombre'],
    'Fecha de almacenamiento: ' . date('d/m/Y', strtotime($grupo['fecha'])),
    '',
    'FECHA        DOCUMENTO                 PROVEEDOR/RESPONSABLE       DESCRIPCION                         TOTAL'
];
foreach ($detalles as $detalle) {
    $lineas[] = date('d/m/Y', strtotime($detalle['fecha'])) . '   ' . $detalle['documento'] . '   ' . $detalle['proveedor_responsable'] . '   ' . $detalle['descripcion'] . '   S/. ' . number_format((float) $detalle['total'], 2);
}
$lineas[] = '';
$lineas[] = 'TOTAL DEL GRUPO: S/. ' . number_format($total, 2);
$lineas[] = '';
$lineas[] = 'CH ' . $cheque;
$lineas[] = 'DIA ' . date('d/m/y', strtotime($grupo['fecha']));
$lineas[] = 'IMPRESO';

$contenido = "q\n0.72 0.72 0.72 RG\n0.6 w\n";
$contenido .= "0.82 0.82 0.82 rg\n40 455 762 22 re f\n0.72 0.72 0.72 RG\n40 455 762 22 re S\n";
$cantidad_filas = max(count($detalles), 1);
$alto_tabla = 22 * ($cantidad_filas + 1);
$base_tabla = 455 - (22 * $cantidad_filas);
$contenido .= "0.82 0.82 0.82 rg\n40 " . $base_tabla . " 762 " . $alto_tabla . " re S\n";
for ($fila = 1; $fila <= $cantidad_filas; $fila++) {
    $contenido .= "40 " . (455 - (22 * $fila)) . " 762 22 re S\n";
}
$contenido .= "124 477 m 124 " . $base_tabla . " l S\n235 477 m 235 " . $base_tabla . " l S\n455 477 m 455 " . $base_tabla . " l S\n721 477 m 721 " . $base_tabla . " l S\nQ\n";
$contenido .= "BT\n/F1 12 Tf\n1 0 0 rg\n260 535 Td (" . escapar_pdf($grupo['nombre'], 80) . ") Tj\nET\n";
$contenido .= "BT\n/F1 8 Tf\n0 0 0 rg\n40 520 Td (Fecha de almacenamiento: " . escapar_pdf(date('d/m/Y', strtotime($grupo['fecha'])), 20) . ") Tj\nET\n";
$encabezados = [['Fecha', 69], ['Documento', 145], ['Proveedor/Responsable', 247], ['Descripcion', 482], ['TOTAL', 730]];
foreach ($encabezados as $encabezado) {
    $contenido .= "BT\n/F1 8 Tf\n0 0 0 rg\n" . $encabezado[1] . " 463 Td (" . escapar_pdf($encabezado[0], 28) . ") Tj\nET\n";
}
$y = 441;
foreach ($detalles as $detalle) {
    if ($y < $base_tabla + 8) { break; }
    $contenido .= "BT\n/F1 7 Tf\n0 0 0 rg\n48 " . $y . " Td (" . escapar_pdf(date('d/m/Y', strtotime($detalle['fecha'])), 14) . ") Tj\n78 0 Td (" . escapar_pdf($detalle['documento'], 25) . ") Tj\n117 0 Td (" . escapar_pdf($detalle['proveedor_responsable'], 27) . ") Tj\n225 0 Td (" . escapar_pdf($detalle['descripcion'], 38) . ") Tj\n285 0 Td (" . escapar_pdf(number_format((float) $detalle['total'], 2), 15) . ") Tj\nET\n";
    $y -= 22;
}
$posicion_total = max(75, $base_tabla - 35);
$contenido .= "BT\n/F1 9 Tf\n0 0 0 rg\n690 " . $posicion_total . " Td (TOTAL: S/. " . escapar_pdf(number_format($total, 2), 18) . ") Tj\nET\n";
$contenido .= "BT\n/F1 8 Tf\n0 0 0 rg\n745 " . ($posicion_total - 32) . " Td (CH " . escapar_pdf($cheque, 30) . ") Tj\n0 -14 Td (DIA " . escapar_pdf(date('d/m/y', strtotime($grupo['fecha'])), 20) . ") Tj\n0 -14 Td (IMPRESO) Tj\nET\n";

$objetos = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . 'endstream'
];
$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objetos as $indice => $objeto) {
    $numero = $indice + 1;
    $offsets[$numero] = strlen($pdf);
    $pdf .= $numero . " 0 obj\n" . $objeto . "\nendobj\n";
}
$inicio_xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
for ($numero = 1; $numero <= count($objetos); $numero++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$numero]);
}
$pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $inicio_xref . "\n%%EOF";

$directorio = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdfs';
if (!is_dir($directorio) && !mkdir($directorio, 0775, true)) {
    http_response_code(500);
    exit('No se pudo crear la carpeta de documentos.');
}
$nombre_archivo = 'egresos_' . $id_almacenamiento . '_' . date('Ymd_His') . '.pdf';
if (file_put_contents($directorio . DIRECTORY_SEPARATOR . $nombre_archivo, $pdf, LOCK_EX) === false) {
    http_response_code(500);
    exit('No se pudo guardar el documento PDF.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
exit();
