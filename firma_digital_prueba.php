<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

requerir_roles(['superadmin', 'administrativo', 'caja']);
date_default_timezone_set('America/Lima');

function texto_pdf_prueba(string $texto): string
{
    $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
    $texto = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $texto) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

$referencia = strtoupper(bin2hex(random_bytes(4)));
$fechaEmision = date('d/m/Y H:i:s');
$usuario = trim((string) ($_SESSION['usuario_nombre'] ?? 'Usuario CISMEDIC'));
$contenido = "q\n0.05 0.36 0.25 rg\n0 760 595 82 re f\nQ\n";
$contenido .= "q\n0.91 0.96 0.93 rg\n40 655 515 92 re f\nQ\n";
$contenido .= "q\n0.91 0.96 0.93 rg\n40 603 515 30 re f\nQ\n";
$contenido .= "q\n0.91 0.96 0.93 rg\n40 547 515 30 re f\nQ\n";
$contenido .= "q\n1.00 0.96 0.86 rg\n40 377 515 30 re f\nQ\n";

$dibujarTexto = static function (string $texto, int $tamano, int $x, int $y, array $color) use (&$contenido): void {
    $contenido .= sprintf(
        "BT\n/F1 %d Tf\n%.3f %.3f %.3f rg\n%d %d Td (%s) Tj\nET\n",
        $tamano,
        $color[0],
        $color[1],
        $color[2],
        $x,
        $y,
        texto_pdf_prueba($texto)
    );
};

$verdeOscuro = [0.05, 0.24, 0.17];
$grisTexto = [0.20, 0.27, 0.23];
$grisSuave = [0.38, 0.44, 0.40];
$blanco = [1.00, 1.00, 1.00];
$ambarOscuro = [0.48, 0.31, 0.02];
$dibujarTexto('CISMEDIC - PRUEBA DE FIRMA DIGITAL DNIe', 22, 44, 803, $blanco);
$dibujarTexto('DOCUMENTO DE PRUEBA - NO APRUEBA NI MODIFICA EGRESOS', 10, 44, 779, [0.86, 0.95, 0.89]);
$dibujarTexto('Referencia: ' . $referencia, 11, 54, 720, $verdeOscuro);
$dibujarTexto('Preparado para: ' . $usuario, 11, 54, 694, $verdeOscuro);
$dibujarTexto('Emitido: ' . $fechaEmision . ' (America/Lima)', 11, 54, 668, $verdeOscuro);
$dibujarTexto('OBJETIVO', 12, 54, 613, $verdeOscuro);
$dibujarTexto('Verificar la firma digital del DNIe en esta computadora.', 11, 54, 589, $grisTexto);
$dibujarTexto('INSTRUCCIONES', 12, 54, 557, $verdeOscuro);
$dibujarTexto('1. Abre Firma Peru y selecciona este archivo PDF.', 11, 54, 526, $grisTexto);
$dibujarTexto('2. Elige el certificado de tu DNIe y firma con el PIN en el lector.', 11, 54, 502, $grisTexto);
$dibujarTexto('3. Conserva el PDF firmado; Firma Peru puede agregar [FP] al nombre.', 11, 54, 478, $grisTexto);
$dibujarTexto('4. Valida el archivo firmado en el servicio oficial Firma Peru.', 11, 54, 454, $grisTexto);
$dibujarTexto('IMPORTANTE', 12, 54, 387, $ambarOscuro);
$dibujarTexto('Este documento es solo una prueba tecnica.', 11, 54, 359, $grisTexto);
$dibujarTexto('No compartas el PIN ni guardes la clave privada en CISMEDIC.', 11, 54, 335, $grisTexto);
$dibujarTexto('Una firma de prueba no aprueba ningun grupo de egresos.', 11, 54, 311, $grisTexto);
$contenido .= "q\n0.82 0.88 0.84 RG\n0.7 w\n40 92 m 555 92 l S\nQ\n";
$dibujarTexto('Referencia de prueba: ' . $referencia, 9, 44, 70, $grisSuave);

$objetos = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "endstream",
];

$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [0];
foreach ($objetos as $indice => $objeto) {
    $numero = $indice + 1;
    $offsets[$numero] = strlen($pdf);
    $pdf .= $numero . " 0 obj\n" . $objeto . "\nendobj\n";
}
$inicioXref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
for ($numero = 1; $numero <= count($objetos); $numero++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$numero]);
}
$pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $inicioXref . "\n%%EOF";

$nombreArchivo = 'CISMEDIC-Prueba-DNIe-' . $referencia . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
exit;