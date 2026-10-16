<?php
// SOLO PARA PRUEBAS: simula la firma de Firma Perú con un DNIe ficticio.
// Crea (una vez) una CA de prueba en storage/firma_digital/ca/PRUEBA_CA_CISMEDIC.pem
// y firma el expediente con un certificado de prueba que lleva el DNI indicado.
//
// Uso:   php database/firmar_prueba.php "C:\ruta\expediente_grupo_3.pdf" 12345678
// Borrar la CA de prueba cuando termines:  php database/firmar_prueba.php --quitar
if (PHP_SAPI !== 'cli') {
    exit('Solo desde la línea de comandos.');
}

$dirCa = __DIR__ . '/../storage/firma_digital/ca';
$dirPrueba = __DIR__ . '/../storage/firma_digital/prueba';
$caPublica = $dirCa . '/PRUEBA_CA_CISMEDIC.pem';
if (($argv[1] ?? '') === '--quitar') {
    @unlink($caPublica);
    @unlink($dirPrueba . '/ca_clave.pem');
    @unlink($dirPrueba . '/ca_cert.pem');
    @rmdir($dirPrueba);
    exit("CA de prueba eliminada. CISMEDIC ya no aceptará firmas simuladas.\n");
}

[$rutaPdf, $dni] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($rutaPdf) || !preg_match('/^\d{8}$/', $dni)) {
    exit("Uso: php database/firmar_prueba.php \"C:\\ruta\\expediente_grupo_X.pdf\" DNI_8_DIGITOS\n");
}

$opciones = ['config' => 'C:/xampp/apache/conf/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
if (!is_dir($dirPrueba)) {
    mkdir($dirPrueba, 0770, true);
}
if (!is_file($dirPrueba . '/ca_clave.pem') || !is_file($caPublica)) {
    $claveCa = openssl_pkey_new($opciones);
    $certCa = openssl_csr_sign(openssl_csr_new(['commonName' => 'CA DE PRUEBA CISMEDIC (NO VALIDA)'], $claveCa, $opciones), null, $claveCa, 365, $opciones + ['x509_extensions' => 'v3_ca'], 1);
    openssl_pkey_export_to_file($claveCa, $dirPrueba . '/ca_clave.pem', null, $opciones);
    openssl_x509_export_to_file($certCa, $dirPrueba . '/ca_cert.pem');
    copy($dirPrueba . '/ca_cert.pem', $caPublica);
    echo "Creada la CA de prueba en storage/firma_digital/ca/PRUEBA_CA_CISMEDIC.pem\n";
}

$claveCa = openssl_pkey_get_private('file://' . realpath($dirPrueba . '/ca_clave.pem'));
$certCa = (string) file_get_contents($dirPrueba . '/ca_cert.pem');
$claveUsuario = openssl_pkey_new($opciones);
$certUsuario = openssl_csr_sign(
    openssl_csr_new(['commonName' => "FIRMANTE DE PRUEBA DNI $dni", 'serialNumber' => "PNOPE-$dni"], $claveUsuario, $opciones),
    $certCa, $claveCa, 365, $opciones + ['x509_extensions' => 'usr_cert'], random_int(2, PHP_INT_MAX)
);
openssl_x509_export($certUsuario, $pemUsuario);
openssl_pkey_export($claveUsuario, $pemClave, null, $opciones);

// Firma incremental PAdES: se agrega la firma al final sin tocar los bytes originales (igual que Firma Perú).
$original = (string) file_get_contents($rutaPdf);
$hueco = 16384;
$documento = $original . "\n99 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached /ByteRange [0 AAAAAAAAAA BBBBBBBBBB CCCCCCCCCC] /Contents <"
    . str_repeat('0', $hueco) . "> >>\nendobj\ntrailer\n<< /Size 100 >>\n%%EOF\n";
$inicio = strpos($documento, '/Contents <', strlen($original)) + strlen('/Contents ');
$fin = $inicio + $hueco + 2;
$documento = substr_replace($documento, sprintf('%010d %010d %010d', $inicio, $fin, strlen($documento) - $fin), strpos($documento, 'AAAAAAAAAA', strlen($original)), 32);

$temporal = tempnam(sys_get_temp_dir(), 'cismedic_');
file_put_contents($temporal, substr($documento, 0, $inicio) . substr($documento, $fin));
openssl_cms_sign($temporal, $temporal . '.der', $pemUsuario, $pemClave, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
$firma = strtoupper(bin2hex((string) file_get_contents($temporal . '.der')));
@unlink($temporal);
@unlink($temporal . '.der');
$documento = substr_replace($documento, str_pad($firma, $hueco, '0'), $inicio + 1, $hueco);

$salida = preg_replace('/\.pdf$/i', '', $rutaPdf) . ' [PRUEBA FIRMADO].pdf';
file_put_contents($salida, $documento);
echo "PDF firmado (simulado) con DNI $dni:\n  $salida\nSúbelo en CISMEDIC con \"Validar firma y aprobar\".\n";
