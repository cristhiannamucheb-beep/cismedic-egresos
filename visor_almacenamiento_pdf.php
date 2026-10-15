<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$cheque = trim((string) ($_GET['cheque'] ?? ''));
if (!$id || $id < 1 || !preg_match('/^[A-Za-z0-9-]{1,30}$/', $cheque)) {
    http_response_code(400);
    exit('El grupo o el número de cheque no son válidos.');
}

$url_pdf = 'almacenamiento_pdf.php?id=' . $id . '&cheque=' . rawurlencode($cheque);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <meta name="theme-color" content="#087f5b">
    <title>PDF de almacenamiento <?= htmlspecialchars($cheque) ?> | CISMEDIC</title>
    <style>
        * { box-sizing: border-box; }
        body { background: #edf2ef; color: #23352d; font-family: "Segoe UI", Tahoma, sans-serif; margin: 0; min-height: 100vh; }
        header { align-items: center; background: #fff; border-bottom: 1px solid #dce5e0; display: flex; gap: 13px; min-height: 66px; padding: 10px 20px; }
        header img { height: 44px; object-fit: contain; width: 44px; }
        header strong { font-size: 15px; }
        header small { color: #697970; display: block; margin-top: 3px; }
        main { height: calc(100vh - 67px); padding: 10px; }
        iframe { background: #fff; border: 1px solid #d5dfda; border-radius: 5px; height: 100%; width: 100%; }
        @media (max-width: 576px) { header { padding: 8px 12px; } header img { height: 38px; width: 38px; } main { height: calc(100vh - 59px); padding: 5px; } }
    </style>
</head>
<body>
    <header><img src="storage/iconos/LOGO.png" alt="CISMEDIC"><div><strong>CISMEDIC · Almacenamiento de egresos</strong><small>Documento PDF · Cheque <?= htmlspecialchars($cheque) ?></small></div></header>
    <main><iframe src="<?= htmlspecialchars($url_pdf) ?>" title="PDF del almacenamiento"></iframe></main>
</body>
</html>
