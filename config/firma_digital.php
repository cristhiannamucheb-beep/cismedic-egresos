<?php
// Firma digital de grupos de egresos con DNIe (Firma Perú).
//
// Flujo: el sistema genera una sola vez el expediente PDF del grupo y guarda su SHA-256.
// Cada aprobador lo descarga, lo firma en su PC con Firma Perú y sube el PDF firmado.
// Antes de registrar la aprobación se valida en el servidor que:
//   1. el PDF firmado contiene, byte por byte, el expediente original (no fue alterado);
//   2. la firma PAdES/CMS es criptográficamente correcta sobre el rango firmado;
//   3. el certificado del firmante está vigente, sirve para firmar y encadena a una CA de confianza;
//   4. el DNI del certificado coincide con el DNI registrado del aprobador.
// El PIN y la clave privada nunca pasan por CISMEDIC.

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/acta_pdf.php';

const FIRMA_DIR = __DIR__ . '/../storage/firma_digital';
const FIRMA_DIR_CA = FIRMA_DIR . '/ca';
const FIRMA_TAMANO_MAXIMO = 40 * 1024 * 1024;

function inicializar_tablas_firma(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS expedientes_firma_grupos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_almacenamiento INT NOT NULL,
        archivo VARCHAR(100) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        huella_detalle CHAR(64) NOT NULL,
        tamano INT UNSIGNED NOT NULL,
        generado_por INT NULL,
        generado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_expediente_grupo (id_almacenamiento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sin claves foráneas a propósito: las firmas se conservan para auditoría aunque el grupo se rechace.
    $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_grupos_egresos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_almacenamiento INT NOT NULL,
        id_usuario INT NOT NULL,
        es_final TINYINT(1) NOT NULL DEFAULT 0,
        sha256_original CHAR(64) NOT NULL,
        archivo_firmado VARCHAR(100) NOT NULL,
        sha256_firmado CHAR(64) NOT NULL,
        firmante_nombre VARCHAR(255) NOT NULL,
        firmante_dni VARCHAR(20) NOT NULL,
        certificado_serie VARCHAR(100) NOT NULL,
        certificado_emisor VARCHAR(255) NOT NULL,
        certificado_desde DATETIME NOT NULL,
        certificado_hasta DATETIME NOT NULL,
        verificado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_firma_grupo (id_almacenamiento),
        KEY idx_firma_usuario (id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'dni'");
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE usuarios ADD COLUMN dni VARCHAR(8) NULL DEFAULT NULL AFTER correo');
    }
}

function asegurar_directorio_firma(string $subdirectorio): string
{
    $ruta = FIRMA_DIR . '/' . $subdirectorio;
    if (!is_dir($ruta) && !mkdir($ruta, 0770, true) && !is_dir($ruta)) {
        throw new RuntimeException('No se pudo crear el almacenamiento de firmas.');
    }
    if (!is_file(FIRMA_DIR . '/.htaccess')) {
        file_put_contents(FIRMA_DIR . '/.htaccess', "Require all denied\n");
    }
    return $ruta;
}

// Huella del contenido del grupo: si cambia, el expediente ya no representa lo que se aprueba.
function huella_detalle_grupo(PDO $pdo, int $idGrupo): string
{
    $stmt = $pdo->prepare('SELECT nombre, fecha, total, observacion FROM almacenamientos_egresos WHERE id = ?');
    $stmt->execute([$idGrupo]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT fuente_egreso, id_egreso, fecha, documento, proveedor_responsable, descripcion, total FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? ORDER BY id');
    $stmt->execute([$idGrupo]);
    return hash('sha256', json_encode([$grupo, $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function contar_firmas_grupo(PDO $pdo, int $idGrupo): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM firmas_grupos_egresos f JOIN expedientes_firma_grupos e ON e.id_almacenamiento = f.id_almacenamiento AND e.sha256 = f.sha256_original WHERE f.id_almacenamiento = ?');
    $stmt->execute([$idGrupo]);
    return (int) $stmt->fetchColumn();
}

/**
 * Devuelve el expediente vigente del grupo y lo genera si no existe.
 * Si el detalle cambió y nadie ha firmado aún, se regenera; si ya hay firmas, se bloquea.
 */
function obtener_expediente_grupo(PDO $pdo, int $idGrupo, int $idUsuario): array
{
    $huella = huella_detalle_grupo($pdo, $idGrupo);
    $stmt = $pdo->prepare('SELECT * FROM expedientes_firma_grupos WHERE id_almacenamiento = ?');
    $stmt->execute([$idGrupo]);
    $expediente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($expediente && hash_equals($expediente['huella_detalle'], $huella)) {
        $ruta = FIRMA_DIR . '/expedientes/' . $expediente['archivo'];
        if (!is_file($ruta) || !hash_equals($expediente['sha256'], hash_file('sha256', $ruta))) {
            throw new RuntimeException('El expediente guardado del grupo no coincide con su huella SHA-256. Revisa el almacenamiento antes de continuar.');
        }
        $expediente['ruta'] = $ruta;
        return $expediente;
    }
    if ($expediente && contar_firmas_grupo($pdo, $idGrupo) > 0) {
        throw new RuntimeException('Los egresos del grupo cambiaron después de que alguien firmó el expediente. Un superadministrador debe revertir el grupo para generar un expediente nuevo.');
    }

    $pdf = generar_acta_grupo_pdf($pdo, $idGrupo, true);
    $sha256 = hash('sha256', $pdf);
    $archivo = 'grupo_' . $idGrupo . '_' . substr($sha256, 0, 16) . '.pdf';
    $ruta = asegurar_directorio_firma('expedientes') . '/' . $archivo;
    if (file_put_contents($ruta, $pdf, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el expediente del grupo.');
    }

    $pdo->prepare('DELETE FROM expedientes_firma_grupos WHERE id_almacenamiento = ?')->execute([$idGrupo]);
    $pdo->prepare('INSERT INTO expedientes_firma_grupos (id_almacenamiento, archivo, sha256, huella_detalle, tamano, generado_por) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$idGrupo, $archivo, $sha256, $huella, strlen($pdf), $idUsuario]);

    return ['id_almacenamiento' => $idGrupo, 'archivo' => $archivo, 'sha256' => $sha256, 'huella_detalle' => $huella, 'tamano' => strlen($pdf), 'ruta' => $ruta];
}

// Longitud real de una estructura DER (el campo /Contents viene relleno con ceros).
function longitud_der(string $der): int
{
    if (strlen($der) < 2 || $der[0] !== "\x30") {
        throw new RuntimeException('La firma del PDF no tiene un formato CMS válido.');
    }
    $primer = ord($der[1]);
    if ($primer < 0x80) {
        return 2 + $primer;
    }
    $bytes = $primer & 0x7F;
    if ($bytes < 1 || $bytes > 4) {
        throw new RuntimeException('La firma del PDF no tiene un formato CMS válido.');
    }
    $longitud = 0;
    for ($i = 0; $i < $bytes; $i++) {
        $longitud = ($longitud << 8) | ord($der[2 + $i]);
    }
    return 2 + $bytes + $longitud;
}

function certificados_ca_confianza(): array
{
    $archivos = [];
    foreach (glob(FIRMA_DIR_CA . '/*.{pem,crt,cer}', GLOB_BRACE) ?: [] as $ruta) {
        $contenido = (string) file_get_contents($ruta);
        if (!str_contains($contenido, '-----BEGIN CERTIFICATE-----')) {
            // Certificado en DER (típico al exportar desde Windows): se convierte a PEM temporal.
            $contenido = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($contenido), 64, "\n") . "-----END CERTIFICATE-----\n";
            $temporal = tempnam(sys_get_temp_dir(), 'cismedic_ca_');
            file_put_contents($temporal, $contenido);
            $ruta = $temporal;
        }
        $archivos[] = $ruta;
    }
    return $archivos;
}

function extraer_dni_certificado(array $certificado): string
{
    $sujeto = $certificado['subject'] ?? [];
    foreach ([(string) ($sujeto['serialNumber'] ?? ''), (string) ($certificado['name'] ?? '')] as $texto) {
        if (preg_match('/(?<!\d)(\d{8})(?!\d)/', $texto, $coincidencia)) {
            return $coincidencia[1];
        }
    }
    return '';
}

/**
 * Valida un PDF firmado contra el expediente original.
 * Devuelve los datos del certificado del firmante cuyo DNI coincide con $dniEsperado.
 */
function validar_pdf_firmado(string $original, string $firmado, string $dniEsperado, ?array $certificadosCa = null): array
{
    $certificadosCa ??= certificados_ca_confianza();
    if (!$certificadosCa) {
        throw new RuntimeException('No hay certificados raíz de confianza configurados en storage/firma_digital/ca. Consulta GUIA_FIRMA_DIGITAL_DNIE.md.');
    }
    if (!preg_match('/^\d{8}$/', $dniEsperado)) {
        throw new RuntimeException('Tu usuario no tiene un DNI registrado. Pide al superadministrador que lo registre antes de firmar.');
    }

    $largoOriginal = strlen($original);
    if (strlen($firmado) <= $largoOriginal || strncmp($firmado, $original, $largoOriginal) !== 0) {
        throw new RuntimeException('El PDF subido no corresponde al expediente de este grupo o fue modificado. Descarga el expediente de nuevo, fírmalo sin editarlo y vuelve a subirlo.');
    }

    preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $firmado, $rangos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $errores = [];
    foreach ($rangos as $rango) {
        if ($rango[0][1] < $largoOriginal) {
            continue; // Pertenece al documento original, no a una firma nueva.
        }
        [$inicio1, $largo1, $inicio2, $largo2] = array_map(static fn($parte) => (int) $parte[0], array_slice($rango, 1));
        try {
            if ($inicio1 !== 0 || $inicio2 <= $largo1 || $inicio2 + $largo2 > strlen($firmado) || $inicio2 + $largo2 < $largoOriginal) {
                throw new RuntimeException('el rango firmado no cubre el documento completo');
            }
            $contenido = substr($firmado, $largo1, $inicio2 - $largo1);
            if (!preg_match('/^<([0-9A-Fa-f\s]+)>$/', $contenido, $hex)) {
                throw new RuntimeException('el contenido de la firma no es válido');
            }
            $der = (string) hex2bin(preg_replace('/\s+/', '', $hex[1]));
            $der = substr($der, 0, longitud_der($der));

            $datosFirmados = substr($firmado, 0, $largo1) . substr($firmado, $inicio2, $largo2);
            $certificado = verificar_cms_separado($datosFirmados, $der, $certificadosCa);
            $dni = extraer_dni_certificado($certificado['datos']);
            if ($dni !== $dniEsperado) {
                throw new RuntimeException('la firma pertenece al DNI ' . ($dni ?: 'desconocido') . ', no al tuyo');
            }
            return [
                'nombre' => (string) ($certificado['datos']['subject']['CN'] ?? ''),
                'dni' => $dni,
                'serie' => (string) ($certificado['datos']['serialNumberHex'] ?? $certificado['datos']['serialNumber'] ?? ''),
                'emisor' => (string) ($certificado['datos']['issuer']['CN'] ?? ''),
                'desde' => date('Y-m-d H:i:s', (int) $certificado['datos']['validFrom_time_t']),
                'hasta' => date('Y-m-d H:i:s', (int) $certificado['datos']['validTo_time_t']),
            ];
        } catch (RuntimeException $e) {
            $errores[] = $e->getMessage();
        }
    }

    if (!$errores) {
        throw new RuntimeException('El PDF subido no tiene una firma digital nueva. Fírmalo con Firma Perú y sube el archivo firmado.');
    }
    throw new RuntimeException('La firma digital no es válida: ' . implode('; ', array_unique($errores)) . '.');
}

// Verifica una firma CMS separada (PAdES/adbe.pkcs7.detached) y devuelve el certificado del firmante.
function verificar_cms_separado(string $datos, string $firmaDer, array $certificadosCa): array
{
    $temporales = [];
    $temporal = static function (string $contenido = '') use (&$temporales): string {
        $ruta = tempnam(sys_get_temp_dir(), 'cismedic_firma_');
        file_put_contents($ruta, $contenido);
        return $temporales[] = $ruta;
    };

    try {
        $rutaDatos = $temporal($datos);
        $rutaFirma = $temporal($firmaDer);
        $rutaFirmante = $temporal();
        // NOVERIFY: aquí solo se comprueba la integridad; la cadena se valida abajo con checkpurpose.
        $integra = openssl_cms_verify($rutaDatos, OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY, $rutaFirmante, [], null, null, null, $rutaFirma, OPENSSL_ENCODING_DER);
        if ($integra !== true) {
            throw new RuntimeException('el documento fue alterado después de firmar o la firma está dañada');
        }

        $pemFirmante = (string) file_get_contents($rutaFirmante);
        $datosCertificado = openssl_x509_parse($pemFirmante);
        if (!$datosCertificado) {
            throw new RuntimeException('no se pudo leer el certificado del firmante');
        }

        $ahora = time();
        if ($ahora < (int) $datosCertificado['validFrom_time_t'] || $ahora > (int) $datosCertificado['validTo_time_t']) {
            throw new RuntimeException('el certificado del firmante no está vigente');
        }
        $usoClave = (string) ($datosCertificado['extensions']['keyUsage'] ?? '');
        if ($usoClave !== '' && !str_contains($usoClave, 'Non Repudiation') && !str_contains($usoClave, 'Digital Signature')) {
            throw new RuntimeException('el certificado no está habilitado para firma digital');
        }

        // Los certificados intermedios que trae la propia firma se usan solo como no confiables.
        $intermedios = $temporal();
        openssl_pkcs7_read(
            "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode($firmaDer), 64, "\n") . "-----END PKCS7-----\n",
            $certificadosFirma
        );
        file_put_contents($intermedios, implode("\n", $certificadosFirma ?? []));
        if (openssl_x509_checkpurpose($pemFirmante, X509_PURPOSE_ANY, $certificadosCa, $intermedios) !== true) {
            throw new RuntimeException('el certificado no fue emitido por una entidad de confianza configurada');
        }

        return ['pem' => $pemFirmante, 'datos' => $datosCertificado];
    } finally {
        foreach ($temporales as $ruta) {
            @unlink($ruta);
        }
    }
}

/**
 * Valida el PDF firmado subido por el aprobador y guarda la evidencia.
 * Lanza una excepción si la firma no es válida; en ese caso no se registra nada.
 */
function registrar_firma_grupo(PDO $pdo, int $idGrupo, int $idUsuario, int $esFinal, array $archivoSubido): array
{
    if (($archivoSubido['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $archivoSubido['tmp_name'])) {
        throw new RuntimeException('Sube el PDF del expediente firmado con tu DNIe para aprobar.');
    }
    if ((int) $archivoSubido['size'] > FIRMA_TAMANO_MAXIMO) {
        throw new RuntimeException('El PDF firmado supera el tamaño máximo permitido.');
    }

    $stmt = $pdo->prepare('SELECT * FROM expedientes_firma_grupos WHERE id_almacenamiento = ?');
    $stmt->execute([$idGrupo]);
    $expediente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expediente) {
        throw new RuntimeException('Primero descarga el expediente del grupo para firmarlo.');
    }
    if (!hash_equals($expediente['huella_detalle'], huella_detalle_grupo($pdo, $idGrupo))) {
        throw new RuntimeException('Los egresos del grupo cambiaron después de generar el expediente. Descárgalo de nuevo antes de firmar.');
    }
    $rutaOriginal = FIRMA_DIR . '/expedientes/' . $expediente['archivo'];
    $original = is_file($rutaOriginal) ? (string) file_get_contents($rutaOriginal) : '';
    if ($original === '' || !hash_equals($expediente['sha256'], hash('sha256', $original))) {
        throw new RuntimeException('El expediente guardado del grupo no coincide con su huella SHA-256.');
    }

    $firmado = (string) file_get_contents((string) $archivoSubido['tmp_name']);
    $stmt = $pdo->prepare('SELECT dni FROM usuarios WHERE id = ?');
    $stmt->execute([$idUsuario]);
    $certificado = validar_pdf_firmado($original, $firmado, (string) $stmt->fetchColumn());

    $sha256Firmado = hash('sha256', $firmado);
    $archivo = 'grupo_' . $idGrupo . '_usuario_' . $idUsuario . '_' . substr($sha256Firmado, 0, 16) . '.pdf';
    if (file_put_contents(asegurar_directorio_firma('firmados') . '/' . $archivo, $firmado, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar el PDF firmado.');
    }

    $pdo->prepare('INSERT INTO firmas_grupos_egresos (id_almacenamiento, id_usuario, es_final, sha256_original, archivo_firmado, sha256_firmado, firmante_nombre, firmante_dni, certificado_serie, certificado_emisor, certificado_desde, certificado_hasta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$idGrupo, $idUsuario, $esFinal, $expediente['sha256'], $archivo, $sha256Firmado, $certificado['nombre'], $certificado['dni'], $certificado['serie'], $certificado['emisor'], $certificado['desde'], $certificado['hasta']]);

    return $certificado;
}
