<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}
requerir_roles(['superadmin', 'administrativo', 'caja']);

$idGrupo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idGrupo || $idGrupo < 1) {
    http_response_code(400);
    exit('Grupo no válido.');
}

require_once __DIR__ . '/config/database.php';
$stmt = $pdo->prepare('SELECT nombre FROM almacenamientos_egresos WHERE id = ? LIMIT 1');
$stmt->execute([$idGrupo]);
$nombreGrupo = $stmt->fetchColumn();
if ($nombreGrupo === false) {
    http_response_code(404);
    exit('El grupo no existe o fue liberado para reagruparse.');
}
$nombreGrupo = htmlspecialchars((string) $nombreGrupo, ENT_QUOTES, 'UTF-8');
$urlPdf = 'aprobacion_pdf.php?id=' . $idGrupo;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <meta name="theme-color" content="#145b42">
    <title>Acta de aprobación | CISMEDIC</title>
    <style>
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { background: #e9eeeb; color: #24372d; display: flex; flex-direction: column; font-family: "Segoe UI", Arial, sans-serif; }
        .toolbar { align-items: center; background: #fff; border-bottom: 1px solid #d8e2dc; display: flex; gap: 12px; min-height: 64px; padding: 9px 18px; }
        .brand-logo { height: 42px; object-fit: contain; width: 42px; }
        .brand-copy { flex: 1; min-width: 0; }
        .brand-copy strong { display: block; font-size: 14px; }
        .brand-copy span { color: #65766d; display: block; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .toolbar a { background: #145b42; border-radius: 5px; color: #fff; font-size: 12px; font-weight: 650; padding: 9px 12px; text-decoration: none; white-space: nowrap; }
        .document { border: 0; flex: 1; min-height: 0; width: 100%; }
        @media (max-width: 520px) { .toolbar { gap: 8px; padding: 8px 10px; } .brand-logo { height: 34px; width: 34px; } .toolbar a { font-size: 11px; padding: 8px; } }
    </style>
</head>
<body>
    <header class="toolbar">
        <img class="brand-logo" src="storage/iconos/LOGO.png" alt="CISMEDIC">
        <div class="brand-copy">
            <strong>CISMEDIC · Acta de aprobación</strong>
            <span><?= $nombreGrupo ?></span>
        </div>
        <a href="<?= htmlspecialchars($urlPdf, ENT_QUOTES, 'UTF-8') ?>" download="acta_aprobacion_grupo_<?= (int) $idGrupo ?>.pdf">Descargar PDF</a>
    </header>
    <iframe class="document" src="<?= htmlspecialchars($urlPdf, ENT_QUOTES, 'UTF-8') ?>" title="Acta de aprobación del grupo"></iframe>
</body>
</html>
