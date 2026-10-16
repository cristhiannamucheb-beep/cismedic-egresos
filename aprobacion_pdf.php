<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión para descargar este documento.');
}
requerir_roles(['superadmin', 'administrativo', 'caja']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/aprobaciones.php';
require_once __DIR__ . '/config/firma_digital.php';
inicializar_tablas_aprobacion($pdo);
inicializar_tablas_firma($pdo);
date_default_timezone_set('America/Lima');

$idGrupo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idGrupo || $idGrupo < 1) {
    http_response_code(400);
    exit('Grupo no válido.');
}

try {
    $pdfFinal = generar_acta_grupo_pdf($pdo, $idGrupo);
} catch (Throwable $e) {
    error_log('Error al generar el acta del grupo ' . $idGrupo . ': ' . $e->getMessage());
    http_response_code(500);
    exit($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo generar el acta del grupo.');
}

$nombreArchivo = 'acta_aprobacion_grupo_' . $idGrupo . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdfFinal));
header('X-Content-Type-Options: nosniff');
echo $pdfFinal;
