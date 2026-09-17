# Emisor de boletas electrónicas (Chile) con LibreDTE

Sistema en PHP para emitir **boletas electrónicas afectas (tipo 39)** directamente ante el
**Servicio de Impuestos Internos (SII)** de Chile, usando la biblioteca libre
[LibreDTE Core](https://core.libredte.cl) y sin servicios pagados. Pensado para correr en un
**hosting compartido** (probado en Hostinger con PHP 8.5 y MySQL).

Está en uso en producción y fue certificado en el ambiente de pruebas del SII.

## Funcionalidades

- **Panel web** con usuarios para emitir boletas, ver su estado en el SII, reenviar y abrir el PDF.
- **API JSON** con token para que otra plataforma emita boletas.
- **Control de folios** en MySQL sin duplicados, aun con emisiones simultáneas.
- Timbre (CAF), firma, armado del sobre `EnvioBOLETA`, **envío por la API REST de boletas del SII** y
  consulta de estado.
- **PDF** de cada boleta con enlace firmado (HMAC) para compartir con el cliente.
- **Notas de crédito (61)** que anulan totalmente una boleta aceptada (una por boleta), enviadas por el
  canal DTE del SII (`DTEUpload`), con reenvío, estado y PDF.

## Requisitos

- PHP **8.5** con `curl`, `json`, `mbstring`, `openssl`, `soap`, `dom`, `pdo_mysql`.
- MySQL / MariaDB.
- Composer.
- Empresa autorizada por el SII para emitir boleta electrónica, **certificado digital** (.pfx) de un
  usuario autorizado y **CAF** de boletas (tipo 39) del ambiente correspondiente.

## Estructura

```
├── public_html/              → raíz web
│   ├── index.php             (incluye ../app/web.php)
│   └── .htaccess             (HTTPS, header Authorization, todo a index.php)
└── app/                      → fuera de la raíz web
    ├── bootstrap.php         (autoload, zona horaria, env(), db(), emisor(), libredte())
    ├── web.php               (rutas del panel y de la API)
    ├── config/
    │   ├── empresa.example.php (copiar a empresa.php con los datos del emisor)
    │   ├── services.yaml     (configuración de LibreDTE para esta app)
    │   └── schema.sql        (tablas usuarios, cafs, boletas, notas_credito)
    ├── src/
    │   ├── Emisor.php        (boletas: emitir, reenviar, actualizarEstado, listar, pdf)
    │   ├── NotasCredito.php  (anular boleta, reenviar, actualizarEstado, pdf)
    │   ├── Folios.php        (registrar CAF, reservar folio con bloqueo)
    │   ├── Sobre.php         (sobre EnvioBOLETA / EnvioDTE firmado)
    │   ├── SiiSemilla.php    (firma de la semilla para el token del SII)
    │   ├── SiiBoletaClient.php (API REST de boletas del SII: token, envío, estado)
    │   ├── SiiDteClient.php  (canal DTE del SII: token SOAP, DTEUpload, QueryEstUp)
    │   └── LibreDte/         (proveedores de emisor/receptor que no inventan datos)
    ├── views/                (login y panel)
    ├── scripts/              (utilidades de línea de comandos)
    └── var/                  (no versionado: secrets/, cache/, …)
```

## Instalación

1. Subir `app/` fuera de la raíz web y `public_html/` como raíz web.
2. En `app/`: `composer install --no-dev -o` (crea también un enlace simbólico que LibreDTE necesita).
3. Copiar `config/empresa.example.php` a `config/empresa.php` y completar los datos del emisor y las
   resoluciones del SII (certificación y producción).
4. Crear `app/.env` (permisos 600):

   ```
   CERT_PASSWORD=contraseña del certificado
   DB_HOST=localhost
   DB_NAME=...
   DB_USER=...
   DB_PASSWORD=...
   APP_KEY=64 caracteres hexadecimales aleatorios (firma de enlaces PDF)
   API_TOKEN=64 caracteres hexadecimales aleatorios
   AMBIENTE=cert        # o prod
   ```

5. Copiar el certificado `.pfx` y los CAF a `app/var/secrets/` (permisos 700 para la carpeta, 600 para los archivos).
6. `php scripts/migrar.php` para crear las tablas.
7. `php scripts/registrar_caf.php <archivo CAF> [siguiente_folio]` por cada CAF.
8. `php scripts/crear_usuario.php correo@dominio.cl "Nombre"` por cada usuario (pide la contraseña oculta).

## Flujo de emisión

1. Se validan los ítems (antes de gastar folio).
2. `Folios::reservar()` toma el siguiente folio con `SELECT … FOR UPDATE` (estado `reservada`).
3. Se arma, timbra y firma la boleta y se guarda el XML (estado `emitida`).
4. Se envía el sobre `EnvioBOLETA` al SII (estado `enviada`, con `track_id`). Si el SII falla,
   queda `emitida` para reenviarla.
5. `actualizarEstado()` consulta el SII → `aceptada`, `reparos` o `rechazada`.

## API

Autenticación: `Authorization: Bearer <API_TOKEN>`.

**Emitir** — `POST /api/boletas`

```json
{"items": [{"nombre": "Resma papel carta", "cantidad": 1, "precio": 4990}]}
```

`precio` es entero **con IVA**; `nombre` hasta 80 caracteres. Respuesta `201`:

```json
{"id": 1, "folio": 1001, "estado": "enviada", "fecha_emision": "2026-09-17",
 "monto_total": 4990, "track_id": 123456789, "pdf_url": "https://…/pdf/1/…"}
```

**Consultar** — `GET /api/boletas/{id}`: misma respuesta; si está `enviada`, consulta al SII.

**Anular (nota de crédito)** — `POST /api/boletas/{id}/anular`, sin cuerpo. Solo boletas `aceptada` o
`reparos`, una vez. Respuesta `201`:

```json
{"id": 1, "folio": 101, "boleta_id": 1, "estado": "enviada", "fecha_emision": "2026-09-17",
 "monto_total": 4990, "track_id": 123456789, "pdf_url": "https://…/pdf/nc/1/…"}
```

**Consultar nota de crédito** — `GET /api/notas-credito/{id}`: si está `enviada`, consulta al SII.

Errores: `401` token inválido · `422` datos inválidos · `404` no existe ·
`409` boleta no aceptada o ya anulada · `400` otros (p. ej. sin folios).

## Scripts

| Script | Uso |
|---|---|
| `migrar.php` | Crea las tablas |
| `registrar_caf.php` | Registra un CAF de `var/secrets` |
| `crear_usuario.php` | Crea o cambia la contraseña de un usuario del panel |
| `prueba_boleta_fake.php` | Boleta con CAF y certificado falsos, sin SII |
| `prueba_token_sii.php` | Obtiene un token del SII (certificación) |
| `prueba_folios_concurrencia.php` | 4 procesos reservando folios en paralelo, verifica que no haya duplicados |
| `prueba_emisor_cert.php` | Emite una boleta en certificación (gasta folio) |
| `prueba_reenviar_cert.php` | Simula SII caído, reenvía y verifica (gasta folio) |
| `prueba_nc_concurrencia.php` | Sin SII: 4 procesos anulan la misma boleta; verifica que solo se emita 1 nota de crédito |
| `prueba_anular_cert.php` | Anula una boleta de certificación (gasta folio 61) |

## Notas técnicas (no obvias)

1. **LibreDTE Core no envía boletas al SII**: `BoletaSenderStrategy` no está implementada. `SiiBoletaClient`
   implementa la [API REST de boletas del SII](https://www4c.sii.cl/bolcoreinternetui/api/)
   (`apicert`/`pangal` en certificación, `api`/`rahue` en producción).
2. **La firma de `derafu/signature` es rechazada al pedir el token** (ESTADO 11, "elemento Certificate no
   existe"), tanto en REST como en SOAP. La semilla se firma a mano en `SiiSemilla::firmar()`.
   La firma del sobre `EnvioBOLETA` de la librería sí es aceptada.
3. **Los proveedores "Fake" de LibreDTE sobrescriben los datos** del emisor y receptor con datos de ejemplo.
   Se reemplazan en `config/services.yaml`.
4. **LibreDTE asume ser el proyecto raíz**: `bootstrap.php` le indica sus directorios y Composer crea el enlace
   `vendor/libredte/libredte-lib-core/vendor → ../..`.
5. **El XML se guarda como `MEDIUMBLOB`**: en una columna de texto `utf8mb4` las tildes del XML ISO-8859-1 se
   corrompen y la firma queda inválida.
6. **Zona horaria**: se fija `America/Santiago` en PHP; si MySQL no tiene zonas con nombre, la conexión usa el
   desfase actual.
7. **Contraseñas con caracteres especiales**: `.env` se lee línea a línea, no con `parse_ini_file`.
8. **Carátula de boletas**: `RutReceptor` es el SII (60803000-K). Receptor genérico: 66666666-6 "Cliente Internet".
9. **El Resumen de Ventas Diarias (RVD) ya no es obligatorio** desde el 01-08-2022
   ([Res. Ex. SII N° 53 de 2022](https://www.sii.cl/normativa_legislacion/resoluciones/2022/reso53.pdf)).
10. **Notas de crédito sobre boletas**: se envían por el canal DTE (`DTEUpload`, sobre `EnvioDTE`), no por la
    API de boletas. Los jobs de LibreDTE para ese canal usan la misma firma rechazada y un cliente SOAP que no
    carga el WSDL, por eso `SiiDteClient` hace SOAP directo. Se usa `MntBruto=1` para que los totales coincidan
    con la boleta. El SII acepta el receptor genérico 66666666-6. En certificación maullin puede autorizar
    solo 1 folio 61 por solicitud.
11. Para saber qué folios ya recibió el SII se puede usar
    `GET /boleta.electronica/{rut}-{dv}-39-{folio}/estado` con datos genéricos: responde `DNK` si fue recibido
    y `FAU` si no.

## Licencia

Este proyecto se distribuye bajo la **GNU Affero General Public License v3.0** (ver [LICENSE](LICENSE)),
conforme a los [términos de uso de LibreDTE](https://core.libredte.cl/legal).

Usa **LibreDTE Core** — © LibreDTE, https://www.libredte.cl — Facturación Electrónica Libre para Chile.
