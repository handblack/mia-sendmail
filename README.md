# MIA Sendmail — documentos electrónicos Facturanet

Servicio Laravel 12 que consulta comprobantes aceptados en una base Facturanet de solo lectura, descarga PDF/XML/CDR, los envía por SMTP mediante una cola y conserva localmente el estado de cada intento.

## Seguridad vigente

En esta etapa **todo correo se fuerza exclusivamente a `soporte@miasoftware.net`**. Hay dos controles independientes:

1. `RecipientResolver` ignora los correos remotos y utiliza el destinatario de seguridad.
2. `AppServiceProvider` reemplaza destinatarios, CC y BCC inmediatamente antes de enviar cualquier mensaje.

La activación de destinatarios reales requiere autorización explícita y un cambio deliberado en ambos controles. No basta con cambiar una variable de entorno.

La sincronización programada también está desactivada por defecto. Requiere el interruptor global, un servicio habilitado y un punto inicial por ID o fecha.

## Arquitectura

- `RemoteDocumentRepository`: ejecuta únicamente consultas `SELECT`, con orden estable por `documents.id`.
- `FacturanetDocumentClient`: descarga desde hosts HTTPS autorizados, limita tamaño/tiempo/redirecciones y valida el contenido real.
- `DocumentProcessingService`: adquiere el bloqueo local, descarga, envía y registra el resultado sin mantener transacciones durante HTTP o SMTP.
- `SendDocumentJob`: Job único, tres intentos con backoff y bloqueo adicional `WithoutOverlapping`.
- `Service`: empresa, URL, conexión remota, asunto y corte de sincronización.
- `Document`: control local idempotente por servicio y documento remoto.

Los criterios remotos aprobados son:

- Fecha de emisión mínima: `2026-11-18 00:00:00`, interpretada en `America/Lima`.
- Estado: `05` (Aceptado).
- Tipos: `01` (Factura), `03` (Boleta) y `07` (Nota de crédito).

La fecha mínima está impuesta en el repositorio para consultas manuales y automáticas. Un servicio puede definir un corte posterior mediante `sync_from_date` o `sync_from_id`, pero nunca ampliar el rango a fechas anteriores al 18 de noviembre de 2026.

Los campos remotos `has_pdf`, `has_xml` y `has_cdr` no se usan porque en la instalación inspeccionada no reflejan la disponibilidad real de los endpoints.

## Configuración

Copiar `.env.example` a `.env` y configurar, sin confirmar secretos en Git:

```dotenv
APP_TIMEZONE=America/Lima

FACTURANET_DB_HOST=154.38.185.147
FACTURANET_DB_PORT=3306
FACTURANET_DB_DATABASE=pro51_anticipate
FACTURANET_DB_USERNAME=sendmail
FACTURANET_DB_PASSWORD=secret
FACTURANET_ALLOWED_HOSTS=anticipate.facturanet.pe

QUEUE_CONNECTION=database
DOCUMENT_QUEUE=documents
DOCUMENT_SYNC_SCHEDULER_ENABLED=false

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=usuario
MAIL_PASSWORD=secret
MAIL_SCHEME=null
MAIL_FROM_ADDRESS=correo@example.com
DOCUMENT_MAIL_FROM_NAME="Comprobante electrónico"
```

La cuenta MySQL remota debe conservar exclusivamente privilegios `SELECT`. Nunca se ejecutan migraciones sobre la conexión `facturanet`.

## Migraciones locales

```bash
php artisan migrate
```

La migración local incorpora configuración de empresa y corte en `services`, control de estados e intentos en `documents` y restricciones únicas por servicio/documento. No se almacenan contraseñas remotas en texto plano: la conexión inicial usa `.env` y cualquier `dbpass` futuro usa el cast cifrado de Laravel.

## Alta del servicio

Crear el servicio inicialmente deshabilitado para automatización:

```php
App\Models\Service::create([
    'name' => 'Anticipate',
    'company_name' => 'Razón social del emisor',
    'phone' => 'Teléfono del emisor',
    'profile' => 'anticipate',
    'url' => 'https://anticipate.facturanet.pe',
    'remote_connection' => 'facturanet',
    'isactive' => 'Y',
    'automatic_enabled' => false,
]);
```

El corte global confirmado es `2026-11-18`. Opcionalmente puede establecerse un `sync_from_id` o una `sync_from_date` posterior; si ambos existen, ambos filtros deben cumplirse. Después se puede establecer `automatic_enabled=true` y, por último, `DOCUMENT_SYNC_SCHEDULER_ENABLED=true`.

## Comandos

```bash
# Consultar sin registrar ni encolar
php artisan documents:sync --service=1 --dry-run

# Sincronizar servicios habilitados
php artisan documents:sync

# Despachar un documento por su ID remoto
php artisan documents:send 1 14031

# Descargar y validar sin enviar correo
php artisan documents:send 1 14031 --dry-run
```

## Colas y scheduler

```bash
php artisan queue:work --queue=documents --tries=3
```

En producción, ejecutar `php artisan schedule:run` cada minuto. Laravel evalúa `documents:sync` cada cinco minutos en `America/Lima`, pero lo omite mientras `DOCUMENT_SYNC_SCHEDULER_ENABLED=false`.

## Estados y reintentos

- `pending`: registrado y disponible para encolar.
- `processing`: bloqueado temporalmente por un worker.
- `sent`: SMTP terminó sin excepción; nunca se reenvía automáticamente.
- `failed`: error previo a SMTP o agotamiento del worker; puede reintentarse manualmente.
- `uncertain`: se inició el envío pero no puede demostrarse si SMTP lo aceptó; nunca se reintenta automáticamente.

Los bloqueos persistentes vencen y pueden recuperarse después de una caída del worker. Las descargas HTTP transitorias usan el backoff del Job. Los errores permanentes de contenido no generan ciclos automáticos.

## Adjuntos

- PDF y XML son obligatorios; CDR es opcional.
- PDF se valida por `%PDF-`, XML se analiza con `LIBXML_NONET` y CDR se valida por la firma ZIP.
- Los archivos se guardan en un directorio privado aleatorio y siempre se eliminan al finalizar.

## Pruebas

```bash
php artisan test
```

Las pruebas usan SQLite en memoria, `Http::fake()`, `Mail::fake()` y `Queue::fake()`. No se conectan a Facturanet ni a SMTP real. Cubren archivos presentes/ausentes, contenido inválido, timeout, HTTP 500, error SMTP, duplicados, locks, recuperación del worker, reintentos, estado incierto, aislamiento por empresa y el destinatario obligatorio.

## Incorporar otra empresa

1. Crear otro `Service` con URL, perfil y conexión.
2. Agregar su host a `FACTURANET_ALLOWED_HOSTS`.
3. Usar una conexión Laravel nombrada o los campos remotos cifrados del servicio.
4. Confirmar privilegios remotos exclusivamente `SELECT`.
5. Probar manualmente con `--dry-run`.
6. Definir ID/fecha inicial y habilitar el servicio.

Los índices únicos incluyen `service_id`, por lo que UUID o IDs iguales de empresas distintas permanecen aislados.
