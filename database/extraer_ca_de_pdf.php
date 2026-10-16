<?php
// Extrae los certificados de CA (raíz e intermedios) de un PDF firmado con DNIe
// y los guarda en storage/firma_digital/ca para que CISMEDIC confíe en ellos.
// Uso: php database/extraer_ca_de_pdf.php "C:\ruta\al\archivo[FP].pdf"
if (PHP_SAPI !== 'cli') {
    exit('Solo desde la línea de comandos.');
}
$ruta = $argv[1] ?? '';
if ($ruta === '' || !is_file($ruta)) {
    exit("Indica la ruta de un PDF firmado con Firma Perú.\n");
}

$pdf = (string) file_get_contents($ruta);
if (!preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $rangos, PREG_SET_ORDER)) {
    exit("Ese PDF no tiene firma digital. Fírmalo primero con Firma Perú.\n");
}

$destino = __DIR__ . '/../storage/firma_digital/ca';
$guardados = 0;
foreach ($rangos as $rango) {
    [$largo1, $inicio2] = [(int) $rango[2], (int) $rango[3]];
    if (!preg_match('/^<([0-9A-Fa-f\s]+)>$/', substr($pdf, $largo1, $inicio2 - $largo1), $hex)) {
        continue;
    }
    $der = (string) hex2bin(preg_replace('/\s+/', '', $hex[1]));
    $pkcs7 = "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode(rtrim($der, "\0")), 64, "\n") . "-----END PKCS7-----\n";
    if (!openssl_pkcs7_read($pkcs7, $certificados)) {
        continue;
    }
    foreach ($certificados as $pem) {
        $datos = openssl_x509_parse($pem);
        $esCa = str_contains((string) ($datos['extensions']['basicConstraints'] ?? ''), 'CA:TRUE');
        $nombre = (string) ($datos['subject']['CN'] ?? 'sin nombre');
        if (!$esCa) {
            echo "Omitido (certificado personal, no es CA): $nombre\n";
            continue;
        }
        $huella = openssl_x509_fingerprint($pem, 'sha256');
        $archivo = $destino . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $nombre) . '.pem';
        file_put_contents($archivo, $pem);
        $guardados++;
        echo "Guardado: $nombre\n  Emitido por: " . ($datos['issuer']['CN'] ?? '') . "\n  SHA-256: " . strtoupper($huella) . "\n";
    }
}

echo $guardados
    ? "\nListo: $guardados certificado(s) de CA guardados en storage/firma_digital/ca.\n"
    : "\nEl PDF no incluye los certificados de la CA. Expórtalos desde Adobe Reader (ver GUIA_FIRMA_DIGITAL_DNIE.md).\n";
