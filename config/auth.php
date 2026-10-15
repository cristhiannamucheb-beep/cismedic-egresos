<?php
date_default_timezone_set('America/Lima');

function iniciar_sesion_segura(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function usuario_autenticado(): bool
{
    return isset($_SESSION['usuario_id'], $_SESSION['usuario_rol']);
}

function usuario_es_superadmin(): bool
{
    return usuario_autenticado() && $_SESSION['usuario_rol'] === 'superadmin';
}

function requerir_superadmin(): void
{
    if (!usuario_es_superadmin()) {
        http_response_code(403);
        exit('Acceso denegado. Esta area es exclusiva del superadministrador.');
    }
}

function requerir_roles(array $roles): void
{
    if (!usuario_autenticado() || !in_array($_SESSION['usuario_rol'], $roles, true)) {
        http_response_code(403);
        exit('Acceso denegado para este perfil de usuario.');
    }
}

function usuario_puede(string $modulo): bool
{
    $rol = $_SESSION['usuario_rol'] ?? '';
    if ($rol === 'superadmin') {
        return true;
    }
    if ($rol === 'caja') {
        return in_array($modulo, ['egresos', 'personal', 'proveedores', 'preaprobacion', 'categorias'], true);
    }
    return true;
}

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}
