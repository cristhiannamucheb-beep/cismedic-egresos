# Prueba de firma digital DNIe en CISMEDIC

## Alcance actual

La sección **Preparar firma digital DNIe** de `aprobacion.php` genera un PDF de prueba aleatorio. Este documento no contiene egresos reales, no aprueba grupos y no guarda el PIN ni la clave privada del DNIe.

El flujo actual es manual: CISMEDIC descarga el PDF, el aprobador lo firma localmente con el Firmador oficial de Firma Perú y valida el resultado en el Validador oficial. La aprobación de grupos todavía no está vinculada a una firma digital.

## Requisitos por cada PC aprobadora

- Windows 10/11 actualizado.
- Lector Zoweetek ZW-12026-3 conectado directamente por USB.
- DNI electrónico vigente con certificados digitales habilitados y PIN de firma conocido por su titular.
- Controlador/middleware oficial que permita a Windows y Firma Perú leer el DNIe.
- IDPlug Classic 4.6.1 (64 bits) para DNIe peruano, incluido en `storage/Firma_Digital/idplug-classic-4.6.1-Peru-es-64bits.msi`.
- FirmaPeru.exe instalado. La descarga debe iniciarse desde el servicio oficial: <https://www.gob.pe/22272>.
- Certificados DNIe instalados según las instrucciones oficiales de RENIEC: <https://identidad.reniec.gob.pe/documents/d/guest/guia_dnie_version3>.

Firma Perú indica que para Windows requiere los certificados DNIe instalados y .NET Framework 4.8 como mínimo (incluido con Windows 10/11). El modelo del lector no sustituye el middleware ni los certificados del DNIe.

## Pasos de instalación y prueba

1. En cada estación aprobadora, instala las actualizaciones pendientes de Windows y reinicia si el sistema lo solicita.
2. Conecta el Zoweetek a un puerto USB directo. En Administrador de dispositivos confirma que Windows detecta el lector y no muestra un icono de error.
3. Descarga **Instalar middleware DNIe** desde la sección de prueba de Aprobación o abre el MSI guardado en `storage/Firma_Digital`. Comprueba que Windows muestre a IDEMIA como firmante, acepta el control de cuentas de usuario y completa la instalación. Si el instalador lo pide, reinicia Windows.
4. Huella SHA-256 del MSI verificado en este entorno: `05DF8E88D17A424C0B7C435E30CD13F28EC1379A7DCFD25C2428E0F754EAEDCE`. El paquete está firmado por IDEMIA IDENTITY & SECURITY FRANCE SAS; la firma Authenticode del archivo comprobado resultó válida.
5. Instala/activa los certificados indicados por RENIEC para el DNIe utilizado. El MSI aporta minidriver y soporte de tarjeta; no reemplaza los certificados personales ni Firma Perú.
6. Descarga e instala Firma Perú desde <https://www.gob.pe/22272>. La página oficial describe `FirmaPeru.exe` como instalador ClickOnce; ejecútalo y selecciona **Instalar**.
7. En CISMEDIC, entra a **Aprobación** y pulsa **Descargar PDF de prueba**.
8. Abre Firma Perú, selecciona el PDF recién descargado y firma con el certificado de firma del DNIe. Introduce el PIN únicamente en la ventana oficial del firmador.
9. Conserva el archivo firmado (el firmador puede agregar `[FP]` al nombre).
10. Abre el Validador oficial: <https://www.gob.pe/14194> o <https://apps.firmaperu.gob.pe/web/validador.xhtml>. Carga el PDF firmado y verifica que el resultado sea válido y que el documento no haya sido alterado.
11. Repite la prueba en cada PC/aprobador, porque lector, drivers y certificados se configuran localmente en cada equipo.

## Si no detecta el DNIe

- Comprueba que el lector aparezca sin errores en Administrador de dispositivos.
- Cambia a otro puerto USB directo y vuelve a insertar la tarjeta.
- Comprueba que el servicio de Windows **Smart Card (SCardSvr)** no esté deshabilitado.
- Confirma que el DNIe y sus certificados estén habilitados y que el middleware sea el indicado por RENIEC.
- Prueba Firma Perú con el DNIe antes de intentar integrar el flujo en CISMEDIC.
- No compartas el PIN, no subas certificados privados y no copies claves privadas al servidor.

## Criterio para integrar aprobaciones reales

La integración solo debe avanzar después de que la prueba produzca un PDF PAdES aceptado por el Validador oficial en cada estación. Para el flujo de grupo, CISMEDIC deberá generar un PDF inmutable por grupo, pedir la firma local de cada aprobador con el DNIe, recibir el PDF firmado, validar la firma y el certificado, y conservar el original, los PDF firmados, las identidades verificadas, la fecha y las huellas SHA-256. Una aprobación de sesión o una imagen de firma no reemplaza estos controles.

El MSI IDPlug es middleware local de DNIe: no firma PDFs y no recibe órdenes HTTP desde la aplicación web. El Firmador ciudadano también se ejecuta en el equipo del aprobador y el portal oficial documenta firma manual de archivos. Para invocar la firma desde el propio botón **Aprobar grupo** se debe solicitar a PCM acceso al componente institucional **Firmador**, con credenciales para el sistema CISMEDIC y la URL de integración: <https://www.gob.pe/22273>. No se deben reutilizar credenciales de otra entidad. Hasta contar con ese acceso y su documentación técnica, el botón de aprobación seguirá siendo el flujo actual y la firma del PDF de prueba es una comprobación separada.

## Aprobación de grupos con firma DNIe (implementado)

Ahora toda aprobación (regular y final) exige un PDF firmado y validado en el servidor:

1. En **Aprobación**, el aprobador pulsa **Descargar expediente para firmar**. La primera descarga genera el expediente del grupo (acta y comprobantes anexos) y guarda su SHA-256 en `expedientes_firma_grupos`. Las descargas siguientes entregan exactamente el mismo archivo.
2. El aprobador firma ese PDF en su PC con Firma Perú y su DNIe, sin editarlo.
3. Sube el PDF firmado y pulsa **Validar firma y aprobar**. El servidor (`config/firma_digital.php`) comprueba que:
   - el PDF firmado contiene byte por byte el expediente original (se descarta cualquier otro PDF o un expediente editado);
   - la firma CMS/PAdES es criptográficamente correcta sobre todo el rango firmado;
   - el certificado está vigente, sirve para firmar y encadena a una CA de `storage/firma_digital/ca`;
   - el DNI del certificado coincide con el DNI del usuario (lo registra el superadmin en **Panel de seguridad**).
4. Solo si todo es válido se registra el voto en `aprobacion_grupos_egresos`. Además se guarda la evidencia en `firmas_grupos_egresos` (firmante, DNI, emisor, serie y vigencia del certificado, SHA-256 del original y del firmado), y el PDF firmado en `storage/firma_digital/firmados`.
5. El acta (`aprobacion_pdf.php`) muestra para cada voto «Firma DNIe verificada: nombre - DNI».

Si los egresos del grupo cambian después de que alguien firmó, el sistema bloquea la aprobación: un superadmin tiene que revertir el grupo para que se genere un expediente nuevo.

### Pendiente antes de producción

- Cargar en `storage/firma_digital/ca` los certificados públicos de la cadena del DNIe (ver `LEEME.md`) y probar con un DNIe real y Firma Perú.
- Registrar el DNI de cada aprobador.
- Consultar revocación del certificado (OCSP/CRL de RENIEC). Hoy se valida la vigencia por fechas, pero no si el certificado fue revocado.
- La fecha de firma que se registra es la de la verificación en el servidor; no se lee todavía el sello de tiempo del PDF.
