<?php
// Descarga el expediente fijo de un grupo para firmarlo con DNIe en Firma Perú.
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

$idGrupo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idGrupo || $idGrupo < 1) {
    http_response_code(400);
    exit('Grupo no válido.');
}
$stmt = $pdo->prepare('SELECT estado_aprobacion FROM almacenamientos_egresos WHERE id = ?');
$stmt->execute([$idGrupo]);
if ($stmt->fetchColumn() === false) {
    http_response_code(404);
    exit('El grupo no existe o fue liberado para reagruparse.');
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM almacenamientos_egresos WHERE id = ? FOR UPDATE')->execute([$idGrupo]);
    $expediente = obtener_expediente_grupo($pdo, $idGrupo, (int) $_SESSION['usuario_id']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error al preparar el expediente del grupo ' . $idGrupo . ': ' . $e->getMessage());
    http_response_code(500);
    exit($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo preparar el expediente del grupo.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="expediente_grupo_' . $idGrupo . '.pdf"');
header('Content-Length: ' . (string) $expediente['tamano']);
header('X-Content-Type-Options: nosniff');
header('X-Expediente-SHA256: ' . $expediente['sha256']);
readfile($expediente['ruta']);
