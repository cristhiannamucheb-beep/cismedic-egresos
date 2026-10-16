# Certificados de confianza para validar firmas DNIe

CISMEDIC solo acepta firmas cuyo certificado encadene a un certificado de esta carpeta.
Si la carpeta no tiene certificados, ninguna aprobación con firma podrá registrarse.

Coloca aquí (formato `.pem`, `.crt` o `.cer`, PEM o DER) los certificados **públicos**
de la cadena del DNIe peruano: la CA raíz del Estado peruano y la CA intermedia de RENIEC
que emite los certificados de firma del DNIe.

Cómo obtenerlos:

1. Descárgalos del repositorio oficial de la ECERNEP / RENIEC (Infraestructura Oficial de Firma Electrónica).
2. O bien, firma un PDF de prueba con Firma Perú, ábrelo en Adobe Reader → Panel de firmas →
   Detalles del certificado, y exporta la CA raíz y la intermedia.
3. Compara la huella SHA-256 de cada certificado con la publicada por la fuente oficial antes de usarlo.

Son certificados públicos: nunca pongas aquí claves privadas, archivos `.pfx`/`.p12` ni PINs.
