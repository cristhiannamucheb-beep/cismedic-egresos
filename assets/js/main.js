// Toggle Sidebar
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.main-content')) {
        const bloqueoClave = 'cismedic_pestana_activa';
        const identificadorPestana = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
        let paginaBloqueada = false;
        let temporizadorInactividad;

        function bloquearPagina() {
            if (paginaBloqueada) return;
            paginaBloqueada = true;
            document.body.innerHTML = '<main style="align-items:center;background:#eef2f5;display:flex;font-family:Arial,sans-serif;justify-content:center;min-height:100vh;padding:24px;text-align:center"><section style="background:#fff;border-left:5px solid #dc3545;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.12);max-width:480px;padding:32px"><h2 style="color:#842029;margin-top:0">Sesión abierta en otra pestaña</h2><p style="color:#56636f">Esta página se bloqueó para evitar duplicidad. Continúa trabajando en la pestaña más reciente.</p><a href="logout.php" style="background:#087f5b;border-radius:5px;color:#fff;display:inline-block;padding:10px 18px;text-decoration:none">Ir al inicio de sesión</a></section></main>';
        }

        localStorage.setItem(bloqueoClave, identificadorPestana);
        window.addEventListener('storage', function(event) {
            if (event.key === bloqueoClave && event.newValue && event.newValue !== identificadorPestana) bloquearPagina();
        });
        window.addEventListener('beforeunload', function() {
            if (localStorage.getItem(bloqueoClave) === identificadorPestana) localStorage.removeItem(bloqueoClave);
        });

        function reiniciarTemporizador() {
            clearTimeout(temporizadorInactividad);
            temporizadorInactividad = setTimeout(function() {
                window.location.href = 'logout.php?motivo=inactividad';
            }, 600000);
        }
        ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function(eventName) {
            document.addEventListener(eventName, reiniciarTemporizador, { passive: true });
        });
        reiniciarTemporizador();
    }

    document.querySelectorAll('[data-submenu-trigger]').forEach(function(trigger) {
        const submenu = trigger.parentElement?.querySelector('.nav-submenu');
        if (!submenu) return;

        const keepOpen = function(event) {
            if (event) event.preventDefault();
            submenu.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            trigger.classList.add('active');
        };

        trigger.addEventListener('click', keepOpen);

        if (trigger.classList.contains('active') || trigger.getAttribute('aria-expanded') === 'true') {
            submenu.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            trigger.classList.add('active');
        }
    });

    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if(sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Guardar estado en localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });
        
        // Restaurar estado del sidebar
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if(sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
    }
    
    // Cerrar alertas automáticamente (excepto la de duplicidad)
    const alerts = document.querySelectorAll('.alert:not(.alerta-duplicidad)');
    alerts.forEach(alert => {
        // No cerrar si es alerta de duplicidad
        if (alert.classList.contains('alerta-duplicidad')) return;
        
        setTimeout(() => {
            if (alert.isConnected) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });

    if (document.body.dataset.rol === 'caja') {
        document.querySelectorAll('a[href="dashboard.php"], a[href="facturas.php"], a[href="factura_pdf.php"], a[href="reportes.php"]').forEach(function(link) {
            link.remove();
        });
    }

    document.querySelectorAll('input[type="password"]').forEach(function(input) {
        if (input.closest('.input-group')) return;
        const group = document.createElement('div');
        group.className = 'input-group';
        input.parentNode.insertBefore(group, input);
        group.appendChild(input);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-secondary';
        button.title = 'Mostrar contraseña';
        button.setAttribute('aria-label', 'Mostrar contraseña');
        button.innerHTML = '<i class="bi bi-eye"></i>';
        button.addEventListener('click', function() {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            button.title = visible ? 'Mostrar contraseña' : 'Ocultar contraseña';
            button.setAttribute('aria-label', button.title);
            button.innerHTML = visible ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
        group.appendChild(button);
    });

    document.querySelectorAll('.user-card').forEach(function(card) {
        const id = card.querySelector('input[name="id"]')?.value;
        const nombre = card.querySelector('h6')?.textContent.trim();
        const correo = card.querySelector('.small.text-muted')?.textContent.trim();
        const rol = card.querySelector('.role-badge')?.textContent.trim().toLowerCase();
        const activo = card.querySelector('input[name="activo"]')?.checked;
        if (!id || !nombre || !correo) return;

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-outline-primary btn-sm mt-3';
        boton.innerHTML = '<i class="bi bi-pencil-square me-1"></i>Editar usuario';
        card.appendChild(boton);

        boton.addEventListener('click', function() {
            let modal = document.getElementById('editorUsuarioModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'editorUsuarioModal';
                modal.className = 'modal fade';
                modal.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Editar usuario</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="accion" value="actualizar_usuario"><input type="hidden" name="id" id="editorUsuarioId"><label class="form-label">Nombre de acceso</label><input class="form-control mb-3" name="nombre" id="editorUsuarioNombre" required><label class="form-label">Correo de acceso</label><input class="form-control mb-3" type="email" name="correo" id="editorUsuarioCorreo" required><label class="form-label">Rol</label><select class="form-select mb-3" name="rol" id="editorUsuarioRol"><option value="superadmin">Superadmin</option><option value="administrativo">Administrativo</option><option value="caja">Caja</option></select><label class="form-label">Nueva contraseña</label><div class="input-group mb-3"><input class="form-control" type="password" name="contrasena" id="editorUsuarioContrasena" minlength="10" placeholder="Opcional"><button type="button" class="btn btn-outline-secondary" id="editorVerContrasena"><i class="bi bi-eye"></i></button></div><div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="editorUsuarioActivo"><label class="form-check-label">Usuario activo</label></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar cambios</button></div></form></div></div>';
                document.body.appendChild(modal);
                document.getElementById('editorVerContrasena').addEventListener('click', function() { const input = document.getElementById('editorUsuarioContrasena'); input.type = input.type === 'password' ? 'text' : 'password'; });
            }
            document.getElementById('editorUsuarioId').value = id;
            document.getElementById('editorUsuarioNombre').value = nombre;
            document.getElementById('editorUsuarioCorreo').value = correo;
            document.getElementById('editorUsuarioRol').value = rol || 'caja';
            document.getElementById('editorUsuarioActivo').checked = activo;
            document.getElementById('editorUsuarioContrasena').value = '';
            bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    });
});

// Funciones útiles
function formatCurrency(value) {
    return new Intl.NumberFormat('es-PE', {
        style: 'currency',
        currency: 'PEN'
    }).format(value);
}

function formatDate(date) {
    return new Intl.DateTimeFormat('es-PE').format(new Date(date));
}

// Validación de formularios
function validateForm(formId) {
    const form = document.getElementById(formId);
    if(!form) return false;
    
    const inputs = form.querySelectorAll('[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if(!input.value.trim()) {
            input.classList.add('is-invalid');
            isValid = false;
        } else {
            input.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}

// Confirmar acción
function confirmAction(message = "¿Está seguro?") {
    return confirm(message);
}
