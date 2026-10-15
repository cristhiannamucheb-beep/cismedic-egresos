<?php
session_start();

if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $correo = trim($_POST['correo'] ?? '');
    $contrasena = $_POST['contraseña'] ?? '';

    if ($correo === '' || $contrasena === '') {
        $error = 'Ingresa tu correo y contraseña.';
    } elseif (strlen($correo) > 150) {
        $error = 'El usuario de acceso no puede superar 150 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE correo = ? LIMIT 1');
            $stmt->execute([$correo]);
            $usuario = $stmt->fetch();
            $bloqueado = $usuario && !empty($usuario['bloqueado_hasta']) && strtotime($usuario['bloqueado_hasta']) > time();

            if (!$usuario || (array_key_exists('activo', $usuario) && !(int) $usuario['activo'])) {
                $error = 'Correo o contraseña incorrectos.';
            } elseif ($bloqueado) {
                $error = 'La cuenta está temporalmente bloqueada. Intenta nuevamente más tarde.';
            } else {
                $contrasena_valida = password_verify($contrasena, (string) $usuario['contraseña']);
                $hash_legacy = !str_starts_with((string) $usuario['contraseña'], '$2y$') && hash_equals((string) $usuario['contraseña'], $contrasena);

                if (!$contrasena_valida && !$hash_legacy) {
                    $intentos = (int) ($usuario['intentos_fallidos'] ?? 0) + 1;
                    $bloqueo = $intentos >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                    $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?');
                    $stmt->execute([$intentos, $bloqueo, $usuario['id']]);
                    $error = $intentos >= 5 ? 'Cuenta bloqueada durante 15 minutos por demasiados intentos.' : 'Correo o contraseña incorrectos.';
                } else {
                    if ($hash_legacy) {
                        $stmt = $pdo->prepare('UPDATE usuarios SET contraseña = ? WHERE id = ?');
                        $stmt->execute([password_hash($contrasena, PASSWORD_DEFAULT), $usuario['id']]);
                    }
                    $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?');
                    $stmt->execute([$usuario['id']]);
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['usuario_nombre'] = $usuario['nombre'];
                    $_SESSION['usuario_rol'] = $usuario['rol'];
                    $_SESSION['debe_cambiar_contrasena'] = !empty($usuario['debe_cambiar_contrasena']);
                    $destino = $usuario['rol'] === 'caja' ? 'egresos.php' : 'dashboard.php';
                    header('Location: ' . $destino);
                    exit();
                }
            }
        } catch (Exception $e) {
            $error = 'No se pudo completar el inicio de sesión.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>CISMEDIC - Iniciar sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root { --verde: #087f5b; --verde-oscuro: #07543f; --verde-claro: #e8f5ef; --texto: #173b2d; }
        * { box-sizing: border-box; }
        body {
            background: radial-gradient(circle at top right, #b8e3ce 0, transparent 38%), linear-gradient(135deg, #edf8f2 0%, #cfe9dc 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
        }
        .login-container {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border: 1px solid rgba(8,127,91,.14);
            border-radius: 18px;
            box-shadow: 0 24px 60px rgba(23,59,45,.16);
            padding: 42px;
        }
        .brand-mark { align-items: center; background: var(--verde); border-radius: 12px; color: #fff; display: flex; font-size: 1.25rem; font-weight: 800; height: 48px; justify-content: center; letter-spacing: .12em; margin: 0 auto 18px; width: 48px; }
        .login-container h1 { color: var(--texto); font-size: 2rem; letter-spacing: .08em; margin-bottom: 8px;
            text-align: center;
            font-weight: 700;
        }
        .login-container .subtitle {
            text-align: center;
            color: #6a7b73;
            margin-bottom: 32px;
            font-size: 14px;
        }
        .form-label { color: var(--texto); font-size: .86rem; font-weight: 600; }
        .form-control { border: 1px solid #ceded5; border-radius: 8px; padding: 12px 14px; }
        .form-control:focus { border-color: var(--verde); box-shadow: 0 0 0 .2rem rgba(8,127,91,.14); }
        .password-group .form-control { border-radius: 8px 0 0 8px; }
        .password-group .btn { border-color: #ceded5; border-radius: 0 8px 8px 0; color: var(--verde); width: 48px; }
        .btn-login {
            width: 100%;
            padding: 13px;
            background: var(--verde);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            transition: background .2s, transform .2s;
        }
        .btn-login:hover { background: var(--verde-oscuro); transform: translateY(-1px); }
        .login-footer { border-top: 1px solid #e4eee8; color: #819089; font-size: 12px; margin-top: 28px; padding-top: 18px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand-mark">C</div>
        <h1>CISMEDIC</h1>
        <p class="subtitle">Sistema de Control de Egresos</p>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="mb-3">
                <label for="correo" class="form-label">Correo Electrónico</label>
                <input type="text" class="form-control" id="correo" name="correo" required>
            </div>
            
            <div class="mb-3">
                <label for="contraseña" class="form-label">Contraseña</label>
                <div class="input-group password-group">
                    <input type="password" class="form-control" id="contraseña" name="contraseña" required>
                    <button type="button" class="btn btn-outline-secondary" id="mostrarContraseña" title="Mostrar contraseña" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            
            <button type="submit" class="btn btn-login">Iniciar Sesión</button>
        </form>
        
        <div class="login-footer">Sistema de Control de Egresos · CISMEDIC</div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mostrarContraseña').addEventListener('click', function () {
            const campo = document.getElementById('contraseña');
            const visible = campo.type === 'text';
            campo.type = visible ? 'password' : 'text';
            this.title = visible ? 'Mostrar contraseña' : 'Ocultar contraseña';
            this.setAttribute('aria-label', this.title);
            this.innerHTML = visible ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
    </script>
</body>
</html>
