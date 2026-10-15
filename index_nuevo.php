<?php
header('Location: index.php');
exit();
session_start();

// Si está autenticado, redirige al dashboard
if(isset($_SESSION['usuario_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Si envía el formulario de login
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once 'config/database.php';
    
    $correo = trim($_POST['correo'] ?? '');
    $contraseña = trim($_POST['contraseña'] ?? '');
    
    if(!empty($correo) && !empty($contraseña)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ?");
            $stmt->execute([$correo]);
            $usuario = $stmt->fetch();
            
            if($usuario && $usuario['contraseña'] == $contraseña) {
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'];
                $_SESSION['usuario_rol'] = $usuario['rol'];
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Correo o contraseña incorrectos";
            }
        } catch(Exception $e) {
            $error = "Error en la conexión: " . $e->getMessage();
        }
    } else {
        $error = "Por favor completa todos los campos";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Login - Sistema de Control de Egresos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            padding: 40px;
        }
        .login-container h1 {
            color: #333;
            margin-bottom: 10px;
            text-align: center;
            font-weight: 700;
        }
        .login-container .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-control {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 12px 15px;
            margin-bottom: 15px;
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 5px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
        }
        .alert {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🏥 Clínica CISMEDIC</h1>
        <p class="subtitle">Sistema de Control de Egresos</p>
        
        <?php if(isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="index.php">
            <div class="mb-3">
                <label for="correo" class="form-label">Correo Electrónico</label>
                <input type="email" class="form-control" id="correo" name="correo" required>
            </div>
            
            <div class="mb-3">
                <label for="contraseña" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="contraseña" name="contraseña" required>
            </div>
            
            <button type="submit" class="btn-login">Iniciar Sesión</button>
        </form>
        
        <hr class="my-3">
        <p class="text-center text-muted" style="font-size: 12px;">
            CISMEDIC © 2025 - Sistema de Control de Egresos
        </p>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
