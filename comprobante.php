<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
$fuente = $_GET['fuente'] ?? '';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!in_array($fuente, ['personal', 'proveedores'], true) || !$id || $id < 1) {
    http_response_code(400);
    exit('Comprobante no válido.');
}

$stmt = $pdo->prepare('SELECT archivo_guardado, nombre_original, mime FROM comprobantes_egresos WHERE fuente_egreso = ? AND id_egreso = ? LIMIT 1');
$stmt->execute([$fuente, $id]);
$comprobante = $stmt->fetch();
if (!$comprobante || !preg_match('/^[a-f0-9]{48}\\.(jpg|png|webp|pdf)$/', $comprobante['archivo_guardado'])) {
    http_response_code(404);
    exit('No se encontró el comprobante solicitado.');
}

$tabla = $fuente === 'personal' ? 'entregas_dinero' : 'entregas_proveedores';
$columna = $fuente === 'personal' ? 'id_entrega' : 'id_entrega_proveedor';
$stmt = $pdo->prepare("SELECT $columna FROM $tabla WHERE $columna = ? LIMIT 1");
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    exit('No se encontró el egreso relacionado.');
}

$nombre = basename($comprobante['nombre_original']);
$url_archivo = 'egresos.php?ver_comprobante=1&fuente=' . rawurlencode($fuente) . '&id=' . $id;
$es_imagen = str_starts_with($comprobante['mime'], 'image/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <meta name="theme-color" content="#087f5b">
    <title><?= htmlspecialchars($nombre) ?> | CISMEDIC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #edf2ef; color: #23352d; margin: 0; min-height: 100vh; }
        .viewer-header { align-items: center; background: #fff; border-bottom: 1px solid #dce5e0; display: flex; gap: 13px; padding: 12px 20px; }
        .viewer-logo { height: 42px; object-fit: contain; width: 42px; }
        .viewer-title { font-size: .95rem; font-weight: 750; margin: 0; }
        .viewer-subtitle { color: #6a7971; font-size: .78rem; overflow-wrap: anywhere; }
        .viewer-canvas { align-items: center; display: flex; justify-content: center; min-height: calc(100vh - 68px); padding: 18px; }
        .viewer-image { background: #fff; border: 1px solid #d9e2dd; border-radius: 6px; box-shadow: 0 8px 28px rgba(25, 54, 41, .12); max-height: calc(100vh - 110px); max-width: min(100%, 1400px); object-fit: contain; }
        .viewer-pdf { background: #fff; border: 1px solid #d9e2dd; border-radius: 6px; height: calc(100vh - 105px); width: min(100%, 1400px); }
        @media (max-width: 576px) { .viewer-header { padding: 10px 12px; } .viewer-logo { height: 36px; width: 36px; } .viewer-canvas { padding: 8px; } .viewer-pdf { height: calc(100vh - 78px); } }
    </style>
</head>
<body>
    <header class="viewer-header">
        <img class="viewer-logo" src="storage/iconos/LOGO.png" alt="CISMEDIC">
        <div class="flex-grow-1"><h1 class="viewer-title">CISMEDIC · Comprobante de egreso</h1><div class="viewer-subtitle"><?= htmlspecialchars($nombre) ?></div></div>
        <a class="btn btn-outline-success btn-sm" href="<?= htmlspecialchars($url_archivo) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir archivo</a>
    </header>
    <main class="viewer-canvas">
        <?php if ($es_imagen): ?>
            <img class="viewer-image" src="<?= htmlspecialchars($url_archivo) ?>" alt="Comprobante <?= htmlspecialchars($nombre) ?>">
        <?php else: ?>
            <iframe class="viewer-pdf" src="<?= htmlspecialchars($url_archivo) ?>" title="Comprobante PDF <?= htmlspecialchars($nombre) ?>"></iframe>
        <?php endif; ?>
    </main>
</body>
</html>
