# 📚 Guía de Características del Sistema

## 🏠 Dashboard
El corazón del sistema. Aquí verás:

**Tarjetas de Estadísticas:**
- Total de egresos este mes
- Cantidad de facturas registradas
- Promedio diario de gastos
- Estado del sistema

**Tabla de Últimas Facturas:**
- Muestra los registros más recientes
- Información del proveedor
- Monto total

**Accesos Rápidos:**
- Crear nuevo egreso
- Registrar factura
- Agregar proveedor
- Generar reporte

---

## 💰 Egresos
Gestiona todos tus gastos y egresos

**¿Qué puedes hacer?**
- ✅ Registrar nuevos egresos
- ✅ Especificar tipo de comprobante
- ✅ Seleccionar proveedor
- ✅ Ingresar monto e IGV
- ✅ Agregar descripción
- ✅ Ver todos los egresos registrados
- ✅ Navegar por páginas
- ✅ Ver detalles de cada egreso

**Campos del formulario:**
```
Tipo Comprobante: FACTURA, RECIBO, NOTA CRÉDITO, BOLETA
Número Comprobante: Código único del comprobante
Fecha: Fecha del egreso
Proveedor: Selecciona de la lista
Total (S/.): Monto total
IGV (S/.): Impuesto (opcional)
Descripción: Detalles adicionales
```

---

## 📄 Facturas
Registro centralizado de todas tus facturas

**Información disponible:**
- Fecha de la factura
- Tipo de comprobante
- Número de comprobante
- Nombre del proveedor
- Total y IGV
- Usuario que registró

**Estadísticas:**
- Total facturado en la BD
- Total de IGV acumulado

---

## 🛍️ Proveedores
Base de datos de tus proveedores

**Información por proveedor:**
- Nombre
- RUC
- Dirección
- Contacto (teléfono)
- Email
- Responsable
- Estado (Activo/Inactivo)

**Acciones:**
- Ver detalles
- Eliminar registro
- Buscar proveedor

---

## 🏷️ Categorías
Todas las categorías de egresos disponibles

**Ejemplos de categorías en tu BD:**
- Laboratorios Clínicos Externos
- Insumos de Laboratorio
- Medicamentos
- Servicios de Internet
- Publicidad
- Mantenimiento de Equipos Médicos
- Y muchas más...

**Total en BD:** 135+ categorías

---

## 📊 Reportes
Análisis profundo de tus egresos

**Egresos por Mes:**
- Visualiza gastos mensuales del año actual
- Identifica meses con mayor gasto
- Compara tendencias

**Top 10 Proveedores:**
- Proveedores con más facturas
- Monto total por proveedor
- Ranking de gasto

**Usos:**
- Identificar patrones de gasto
- Negociar con proveedores mayores
- Presupuestar futuros gastos
- Auditoría financiera

---

## 👤 Perfil
Gestiona tu cuenta personal

**Información:**
- Avatar personalizado
- Nombre completo
- Email
- Rol en el sistema

**Seguridad:**
- Cambiar contraseña
- Validación de contraseña actual
- Confirmación de nueva contraseña

---

## ⚙️ Características Técnicas

### Seguridad
- Sesiones de usuario
- Logout/Cierre de sesión
- Validación en servidor
- Prepared statements (protección SQL)

### Interfaz
- Sidebar responsive
- Diseño mobile-friendly
- Temas consistentes
- Iconos intuitivos (Bootstrap Icons)

### Base de Datos
- Conexión PDO (PHP Data Objects)
- Charset UTF-8MB4
- Soporta MySQL 8.0+
- Relaciones entre tablas

### Performance
- Paginación de registros
- Índices optimizados
- Consultas eficientes
- Carga rápida

---

## 🎨 Interfaz de Usuario

### Colores
- **Primario:** Azul/Púrpura (#667eea - #764ba2)
- **Verde:** Éxito (#28a745)
- **Rojo:** Peligro/Error (#dc3545)
- **Gris:** Fondo (#f5f7fa)

### Componentes
- Cards (Tarjetas)
- Modals (Ventanas emergentes)
- Tables (Tablas)
- Buttons (Botones)
- Forms (Formularios)
- Badges (Etiquetas)
- Alerts (Alertas)

### Responsive
- Desktop: Sidebar expandido
- Tablet: Sidebar colapsable
- Móvil: Sidebar oculto

---

## 🔄 Flujo de Trabajo Típico

```
1. Accedo al sistema (index.php)
   ↓
2. Veo el Dashboard con estadísticas
   ↓
3. Registra un nuevo egreso (modal)
   ↓
4. Selecciono proveedor
   ↓
5. Ingreso datos de la factura
   ↓
6. Guardar ✅
   ↓
7. El registro aparece en la tabla
   ↓
8. Los reportes se actualizan automáticamente
```

---

## 📝 Tipos de Datos Soportados

| Tipo | Ejemplos |
|------|----------|
| Comprobantes | FACTURA, RECIBO, NOTA CRÉDITO, BOLETA |
| Moneda | Soles Peruanos (S/.) |
| Fechas | DD/MM/YYYY |
| Números | Enteros y decimales (hasta 2 dígitos) |
| Textos | UTF-8 (Español completo) |

---

## 🚀 Próximas Mejoras Planeadas

- PDF Export de reportes
- Gráficos interactivos (Chart.js)
- Búsqueda avanzada con filtros
- Flujo de aprobación de egresos
- Auditoría y historial
- Integración con contabilidad
- Backup automático
- Sistema de notificaciones

---

**Última actualización:** 18/08/2026
