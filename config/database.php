<?php
// Configuración de la base de datos
date_default_timezone_set('America/Lima');

$configuracion_local = __DIR__ . '/database.local.php';
$configuracion_db = is_file($configuracion_local) ? require $configuracion_local : [];
$configuracion_db = is_array($configuracion_db) ? $configuracion_db : [];

$db_host = getenv('CISMEDIC_DB_HOST') ?: ($configuracion_db['host'] ?? 'localhost');
$db_user = getenv('CISMEDIC_DB_USER') ?: ($configuracion_db['user'] ?? 'root');
$db_password = getenv('CISMEDIC_DB_PASSWORD');
if ($db_password === false) {
    $db_password = $configuracion_db['password'] ?? '';
}
$db_name = getenv('CISMEDIC_DB_NAME') ?: ($configuracion_db['name'] ?? 'clinica');

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
