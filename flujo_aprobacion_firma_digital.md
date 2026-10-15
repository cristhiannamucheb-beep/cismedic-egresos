# Flujo objetivo: almacenamiento, aprobación y firma DNIe

El diagrama visual está en [flujo_aprobacion_firma_digital.svg](flujo_aprobacion_firma_digital.svg).

## Secuencia propuesta

1. El usuario selecciona egresos pendientes y los reúne en un grupo de almacenamiento.
2. CISMEDIC prepara el expediente del grupo con detalle, importes y comprobantes adjuntos, y calcula una huella SHA-256 de la versión que será firmada.
3. Cada preaprobador contable revisa ese mismo expediente y firma el PDF con su DNI electrónico. Solo después de validar la firma se registra su aprobación.
4. El sistema mantiene bloqueada la etapa gerencial hasta que todos los preaprobadores contables activos hayan aprobado y firmado.
5. El gerente/aprobador final revisa el expediente y firma con su DNI electrónico.
6. CISMEDIC valida la firma y el certificado, comprueba que el PDF no cambió, y guarda el PDF firmado, los datos verificables de cada firma y las huellas digitales. Entonces marca el grupo como aprobado.
7. Si cualquier aprobador rechaza el grupo, debe ingresar un motivo. El sistema archiva el expediente y las decisiones para auditoría, libera los egresos para reagruparlos y notifica a los aprobadores.

## Importante: estado de implementación

Este es el flujo objetivo solicitado; la firma DNIe todavía no está conectada al botón real de aprobación. La prueba existente en el panel es un procedimiento manual y no aprueba grupos. Antes de activar la firma en producción se necesita:

- Integración institucional autorizada con el Firmador compatible con el flujo de CISMEDIC.
- Intercambio del PDF firmado (PAdES) entre el equipo del firmante y el sistema.
- Validación criptográfica de firma, certificado, vigencia y correspondencia con el PDF que se aprobó.
- Conservación inmutable del documento, firmas, identidad validada, fecha y huellas SHA-256.

El PIN del DNIe y la llave privada no deben enviarse ni almacenarse en el servidor. Una imagen de firma o un registro de aprobación no sustituye una firma digital con certificado.
