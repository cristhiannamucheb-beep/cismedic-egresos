# Prueba de firma digital con DNIe en CISMEDIC — Paso a paso

Esta guía sirve para probar con un **DNIe real** la nueva aprobación con firma digital.
La prueba la hacen **dos personas**:

- **Persona A (el compañero con lector):** firma el documento con su DNIe. **No necesita tener CISMEDIC instalado**; solo firma un PDF y lo devuelve.
- **Persona B (quien tiene CISMEDIC en su PC):** le envía el PDF, recibe el PDF firmado y lo sube al sistema para que lo valide.

> 🔒 **Nunca compartas tu PIN del DNIe** con nadie, ni por chat ni por correo. Solo se escribe en la ventana de Firma Perú.

---

## Resumen del flujo

```
B descarga el expediente de CISMEDIC  ──►  se lo envía a A
A lo firma con su DNIe en Firma Perú   ──►  devuelve el PDF firmado [FP] + su número de DNI
B sube el PDF firmado en CISMEDIC      ──►  CISMEDIC valida la firma y registra la aprobación
```

---

# PARTE 1 — Persona A (compañero con lector)

## Lo que necesitas

- [ ] Tu **DNI electrónico** (el DNI azul con chip) y su **PIN de firma**.
- [ ] El **lector de DNIe** USB (por ejemplo, Zoweetek).
- [ ] Una PC con **Windows 10 u 11**.

## Paso A1 — Conectar el lector

1. Conecta el lector a un **puerto USB directo de la PC**, de preferencia en la parte de atrás del CPU. No uses un hub ni el USB del teclado.
2. Espera unos 30 segundos mientras Windows instala el lector.
3. Comprueba que Windows lo reconoce:
   - Pulsa **Windows + X** y elige **Administrador de dispositivos**.
   - Busca la sección **"Lectores de tarjetas inteligentes"**.
   - Debe aparecer el lector **sin triángulo amarillo**.
   - Si no aparece, cambia de puerto USB y vuelve a mirar.

## Paso A2 — Instalar el controlador del DNIe (IDPlug)

Este programa permite que Windows lea el chip del DNIe.

1. Consigue el instalador `idplug-classic-4.6.1-Peru-es-64bits.msi`. Lo da RENIEC, o te lo puede pasar la persona B.
2. Haz doble clic en el archivo. Si Windows pregunta, comprueba que el editor sea **IDEMIA** y pulsa **Sí**.
3. Pulsa **Siguiente** hasta que termine.
4. **Reinicia la PC** si te lo pide.

> Si ya firmaste antes con tu DNIe en esta PC, probablemente ya lo tienes instalado y puedes saltar este paso.

## Paso A3 — Instalar Firma Perú

1. Entra a la página oficial: **https://www.gob.pe/22272**
2. Descarga **Firma Perú** (`FirmaPeru.exe`).
3. Ábrelo y pulsa **Instalar**.

> Si ya lo tienes instalado, salta este paso.

## Paso A4 — Prueba rápida: ¿se lee el DNIe?

1. Mete el DNIe en el lector **con el chip dorado hacia arriba**, siguiendo la flecha del lector.
2. Abre **Firma Perú** desde el menú Inicio.
3. Elige **cualquier PDF** y pulsa firmar.
4. Al elegir el certificado debe aparecer **tu nombre**.
5. Escribe tu **PIN** en la ventana de Firma Perú.
6. Si se crea un PDF nuevo con **`[FP]`** en el nombre, ✅ **tu PC está lista**.

**Si algo falla aquí:**

| Problema | Qué hacer |
|---|---|
| No aparece tu nombre o el certificado | Revisa el paso A2. Si sigue igual, los certificados del DNIe podrían no estar activados: se gestiona en RENIEC. |
| PIN incorrecto | ¡Cuidado! Tras **3 intentos fallidos** el DNIe se bloquea. Si no recuerdas el PIN, se gestiona en RENIEC. |
| "No se detecta la tarjeta" | Saca y vuelve a meter el DNIe. Cambia de puerto USB. |

## Paso A5 — Firmar el expediente de CISMEDIC

1. La persona B te enviará un archivo llamado **`expediente_grupo_X.pdf`**.
2. Guárdalo en tu PC. ⚠️ **No lo abras ni lo guardes con otro programa** (Word, Adobe, etc.): si se modifica, CISMEDIC lo rechazará.
3. Abre **Firma Perú**, carga **ese archivo**, firma con tu DNIe y escribe tu PIN.
4. Se crea un archivo nuevo, por ejemplo **`expediente_grupo_X[FP].pdf`**.

## Paso A6 — Devolver el resultado a la persona B

Envía a la persona B:

1. El archivo **firmado** (el que tiene `[FP]`).
2. Tu **número de DNI** (los 8 dígitos). ⚠️ **Solo el número, nunca el PIN.**

✅ Con eso terminas tu parte.

---

# PARTE 2 — Persona B (quien tiene CISMEDIC)

## Paso B1 — Encender CISMEDIC

1. Abre **XAMPP Control Panel** y pulsa **Start** en **Apache** y en **MySQL**.
2. En el navegador, entra a: `http://localhost/cismedic-egresos/`
3. Las contraseñas de los usuarios de prueba están en `database/credenciales.local.txt`.

## Paso B2 — Descargar el expediente y enviarlo

1. Inicia sesión con `administrativo@cismedic.test`.
2. En el menú izquierdo, entra a **Aprobación**.
3. En un grupo en revisión, pulsa **"Descargar expediente para firmar"**.
4. Envía ese PDF **tal cual** a la persona A, por correo o en una USB. No lo abras con otro programa.
5. Anota de qué grupo es: el nombre del archivo trae el número, por ejemplo `expediente_grupo_3.pdf`.

## Paso B3 — Cuando recibas el PDF firmado: cargar los certificados de RENIEC (solo la primera vez)

CISMEDIC necesita saber que las firmas emitidas por RENIEC son de confianza.

1. Guarda el PDF firmado `[FP]` que te devolvió la persona A, por ejemplo en Descargas.
2. Abre una terminal en la carpeta del proyecto (`C:\xampp\htdocs\cismedic-egresos`) y ejecuta este comando, cambiando la ruta por la de tu archivo:

   ```bash
   php database/extraer_ca_de_pdf.php "C:\Users\TU_USUARIO\Downloads\expediente_grupo_3[FP].pdf"
   ```

3. Debe mostrar una o dos líneas **"Guardado: …"** y al final **"Listo"**.

> Si dice *"El PDF no incluye los certificados de la CA"*, hay que exportarlos a mano con Adobe Reader (ver `GUIA_FIRMA_DIGITAL_DNIE.md`).

## Paso B4 — Registrar el DNI del firmante

1. Cierra sesión y entra con `admin@cismedic.test`.
2. En el menú izquierdo, ve a **Panel de seguridad**.
3. Busca la tarjeta del usuario **"Jorge Administrativo"**.
4. En **"DNI para firma digital"** escribe el **DNI de la persona A** y pulsa **Guardar DNI**.
5. Cierra sesión.

## Paso B5 — Subir el PDF firmado y aprobar

1. Inicia sesión con `administrativo@cismedic.test` y entra a **Aprobación**.
2. Busca **el mismo grupo** del paso B2.
3. En **"PDF firmado con DNIe"**, pulsa **Seleccionar archivo** y elige el archivo **`[FP]`**.
4. Pulsa **"Validar firma y aprobar"** y acepta la confirmación.

### ✅ Resultado esperado

Un mensaje verde como este:

> *Aprobación regular registrada… **Firma DNIe verificada: NOMBRE DE LA PERSONA A (DNI 12345678).***

Si aparece, **la prueba fue un éxito**: la firma con DNIe real funciona en CISMEDIC.

---

## Si sale un mensaje rojo

| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| *No hay certificados raíz de confianza configurados* | Falta el paso B3 | Haz el paso B3 |
| *…no fue emitido por una entidad de confianza configurada* | Los certificados cargados no son los correctos | Repite el paso B3 con el PDF `[FP]` |
| *Tu usuario no tiene un DNI registrado* | Falta el paso B4 | Haz el paso B4 |
| *La firma pertenece al DNI X, no al tuyo* | El DNI registrado no coincide con el de la firma | Corrige el DNI en el paso B4 |
| *El PDF subido no corresponde al expediente…* | Se subió otro archivo, el expediente se modificó antes de firmar, o es de otro grupo | Verifica que subiste el `[FP]` del **mismo grupo**. Si todo está bien y sigue saliendo, avisa al desarrollador: puede que Firma Perú reescriba el archivo y haya que ajustar el validador |
| *Los egresos del grupo cambiaron después de generar el expediente* | El grupo cambió después de descargar el expediente | Vuelve al paso B2 y descarga un expediente nuevo |
| *El certificado del firmante no está vigente* | El certificado del DNIe venció | La persona A debe renovarlo en RENIEC |

---

## Reportar el resultado

Cuando termines, anota y comparte:

- [ ] ¿El lector apareció en el Administrador de dispositivos? (paso A1)
- [ ] ¿Firma Perú mostró el nombre y firmó? (paso A4)
- [ ] ¿Qué mostró el comando del paso B3?
- [ ] ¿Qué mensaje salió al subir el PDF? (paso B5) — copia el texto exacto o toma una captura.
