# VirtualMD Partner Booking API

Plugin WordPress independiente para integraciones externas. Permite consultar el catálogo, doctores y disponibilidad de VirtualMD, crear y reprogramar citas en Amelia sin cobrar y consultar el estado de las citas de cada proveedor.

No modifica el widget existente, no procesa Stripe o PayPal y no expone endpoints de pago. `list_price` es informativo; las citas creadas por esta API se registran en Amelia con importe cero y su liquidación, si existe, se gestiona fuera de esta API según el acuerdo con cada proveedor.

## Requisitos

- WordPress 6.2 o posterior.
- PHP 7.4 o posterior.
- Amelia activo y sus tablas instaladas.
- `AMELIA_API_KEY` definido en `wp-config.php`.
- `virtualmd-hero-booking-widget` activo. La API reutiliza directamente su motor de disponibilidad para aplicar exactamente horarios, descansos, días libres, citas ocupadas, buffers y anticipación mínima.
- HTTPS en producción.

## Instalación

1. Copia `virtualmd-partner-booking-api` a `wp-content/plugins/`.
2. Activa **VirtualMD Partner Booking API** desde WordPress.
3. Abre **Herramientas > VirtualMD Partner API**.
4. Crea una credencial distinta para cada proveedor, marca solamente los permisos necesarios y configura sus límites.
5. Copia la credencial al generarla. VirtualMD guarda únicamente un hash; el secreto no se puede recuperar.
6. Verifica `GET /wp-json/virtualmd-partners/v1/health` con esa credencial.

También se pueden administrar claves con WP-CLI:

```bash
wp virtualmd-partner-api key-create \
  --name="Proveedor de prueba" \
  --scopes="catalog:read,doctors:read,availability:read,appointments:write,appointments:read,appointments:reschedule" \
  --daily-limit=100 --monthly-limit=1000 --reschedule-limit=3

wp virtualmd-partner-api key-list
wp virtualmd-partner-api key-revoke 12
```

## Autenticación

Envía la credencial solamente en un encabezado. Nunca la pongas en la URL:

```http
Authorization: Bearer vmd_live_ID_PUBLICO.SECRETO
```

También se acepta `X-VirtualMD-API-Key`. Si se envían ambos encabezados deben contener exactamente la misma credencial.

Permisos disponibles:

- `catalog:read`
- `doctors:read`
- `availability:read`
- `appointments:write`
- `appointments:read`
- `appointments:reschedule`

Cada clave puede limitarse por IP o CIDR y tiene límites editables de solicitudes/minuto, citas/día, citas/mes y reprogramaciones por cita. `0` significa sin límite para citas y reprogramaciones. Además existe un límite global de intentos de autenticación por IP. Las claves revocadas dejan de funcionar inmediatamente.

Desde el panel también puedes editar permisos, IP/CIDR y límites de claves existentes. La pestaña **Consultas generadas** registra proveedor externo, referencia, servicio, doctor, horario, estado, número de reprogramaciones e IDs internos de Amelia. No muestra nombre, correo ni teléfono del paciente.

## Endpoints

Base URL:

```text
https://virtualmd.mx/wp-json/virtualmd-partners/v1
```

| Método | Ruta | Permiso | Uso |
|---|---|---|---|
| `GET` | `/health` | `catalog:read` | Verifica dependencias sin exponer secretos. |
| `GET` | `/catalog` | `catalog:read` | Categorías y servicios disponibles. |
| `GET` | `/doctors?service_id=123` | `doctors:read` | Doctores, opcionalmente filtrados por servicio. |
| `GET` | `/availability?service_id=123&provider_id=45&from=2026-08-20&to=2026-08-26` | `availability:read` | Horarios libres. `provider_id` es opcional. |
| `POST` | `/appointments` | `appointments:write` | Crea una cita sin pago. |
| `GET` | `/appointments/{external_reference}` | `appointments:read` | Consulta una cita propia del proveedor. |
| `PATCH` | `/appointments/{external_reference}/reschedule` | `appointments:reschedule` | Cambia horario y opcionalmente doctor. |

La especificación completa está en [`docs/openapi.yaml`](docs/openapi.yaml).

## Crear una cita

Cada intento requiere:

- `external_reference`: referencia única dentro del sistema del proveedor.
- `Idempotency-Key`: identificador único del intento, de 16 a 128 caracteres.
- `starts_at`: RFC 3339 con zona horaria. La API lo convierte a la zona configurada en WordPress.
- consentimiento declarado por la integración.
- teléfono E.164.

`provider_id` es opcional. Si no se envía, VirtualMD asigna de forma determinista un doctor libre para ese servicio y horario.

```bash
curl --request POST \
  'https://virtualmd.mx/wp-json/virtualmd-partners/v1/appointments' \
  --header 'Authorization: Bearer vmd_live_ID_PUBLICO.SECRETO' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: proveedor-orden-20260812-0001' \
  --data '{
    "external_reference": "ORD-20260812-0001",
    "patient_reference": "PAC-88391",
    "service_id": 123,
    "provider_id": 45,
    "starts_at": "2026-08-20T10:00:00-06:00",
    "patient": {
      "first_name": "Ana",
      "last_name": "Pérez",
      "email": "ana@example.com",
      "phone": "+525512345678",
      "country_phone_iso": "MX",
      "birth_date": "1990-05-21"
    },
    "consent": {
      "privacy_accepted": true,
      "booking_authorized": true,
      "accepted_at": "2026-08-12T12:30:00-06:00"
    },
    "reason": "Consulta inicial"
  }'
```

Respuesta exitosa:

```json
{
  "data": {
    "external_reference": "ORD-20260812-0001",
    "patient_reference": "PAC-88391",
    "status": "approved",
    "service_id": 123,
    "provider_id": 45,
    "starts_at": "2026-08-20T10:00:00-06:00",
    "created_at": "2026-08-12T18:30:02+00:00"
  },
  "meta": {
    "request_id": "6e596bd8-7e65-4ff0-a8ef-fb2324fb14be"
  }
}
```

Un reintento idéntico con el mismo `Idempotency-Key` devuelve la respuesta guardada y el encabezado `Idempotency-Replayed: true`. Reutilizar la clave con datos distintos produce `409`.

## Reprogramar una cita

El proveedor usa su propia `external_reference`; nunca necesita conocer el `booking_id` de Amelia ni buscar por correo o nombre. La API resuelve internamente el booking correcto y únicamente permite reprogramar citas creadas por esa misma credencial.

`provider_id` es opcional. Si se omite, se asigna un doctor disponible para el nuevo horario.

```bash
curl --request PATCH \
  'https://virtualmd.mx/wp-json/virtualmd-partners/v1/appointments/ORD-20260812-0001/reschedule' \
  --header 'Authorization: Bearer vmd_live_ID_PUBLICO.SECRETO' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: reprogramacion-ORD-20260812-0001-01' \
  --data '{
    "starts_at": "2026-08-21T12:00:00-06:00",
    "provider_id": 45
  }'
```

Cada reprogramación exitosa conserva `external_reference`, incrementa `reschedule_count`, queda registrada con horario/doctor anterior y nuevo, y solicita las notificaciones de correo/WhatsApp mediante el contexto protegido del widget. Citas pasadas, canceladas o que alcanzaron el límite de la clave no se pueden reprogramar.

## Mismo correo para pacientes distintos

La consulta se identifica por `external_reference`, no por correo. Igual que el widget, la API crea una identidad técnica única en Amelia para cada reserva y conserva los datos reales en el contexto protegido de notificaciones. Así un padre, tutor o coordinador puede usar el mismo correo para personas con nombres distintos sin mezclar reservas ni impedir la reprogramación.

## Seguridad y privacidad

- Las claves se guardan como HMAC-SHA-256 usando las sales de WordPress y se muestran una sola vez.
- HTTPS es obligatorio. Solo para desarrollo local puede definirse `VMD_PARTNER_API_ALLOW_INSECURE_LOCAL` como `true`.
- Por defecto se rechazan llamadas con encabezado `Origin`, porque la prioridad es servidor a servidor. Si en el futuro una aplicación web necesita acceso, define una lista exacta en `VMD_PARTNER_API_ALLOWED_ORIGINS`.
- La API no devuelve nombre, correo ni teléfono al consultar una cita.
- La bitácora guarda acción, clave, estado, referencia y un hash de IP; no guarda el cuerpo ni datos clínicos.
- Cada proveedor únicamente puede consultar las citas creadas con su propia clave.
- Cada proveedor únicamente puede reprogramar citas creadas con su propia clave.
- La reserva se vuelve a validar bajo un bloqueo antes de enviarla a Amelia para reducir carreras y dobles reservas.
- La reprogramación también usa idempotencia, doble validación y bloqueo de horario.
- Si Amelia devuelve una respuesta incierta, la API indica que no se debe reintentar con otra referencia. Conserva el `X-Request-Id` para soporte.
- Los proveedores deben contar con autorización del paciente y un acuerdo de tratamiento/transferencia de datos adecuado antes de enviar datos personales o de salud.

No uses datos reales en ambientes de prueba. No registres encabezados `Authorization`, cuerpos de solicitud ni respuestas en proxies o plataformas de observabilidad.

## Configuración opcional

En `wp-config.php`:

```php
// Solo desarrollo local; nunca en producción.
define( 'VMD_PARTNER_API_ALLOW_INSECURE_LOCAL', true );

// Lista exacta si alguna aplicación web autorizada necesita CORS.
define( 'VMD_PARTNER_API_ALLOWED_ORIGINS', [ 'https://app.ejemplo.com' ] );

// Ubicación Amelia usada al crear citas. El valor predeterminado es 1.
define( 'VMD_PARTNER_API_LOCATION_ID', 1 );

// Campo personalizado Amelia para fecha de nacimiento. Predeterminado: 17.
define( 'VMD_PARTNER_API_BIRTH_DATE_FIELD_ID', 17 );
```

## Lista antes de producción

1. Probar todo en un clon/staging con pacientes ficticios.
2. Confirmar que la zona horaria de WordPress sea la usada por operación.
3. Confirmar los IDs de ubicación y campo de fecha de nacimiento en Amelia.
4. Verificar correos, WhatsApp y videollamada generados por Amelia.
5. Crear una clave separada y con IP permitida para cada proveedor.
6. Configurar el proxy para ocultar `Authorization` y cuerpos JSON de sus logs.
7. Ejecutar pruebas de idempotencia, límites por clave, horario ocupado, reprogramación, credencial revocada y rate limit.
8. Formalizar autorización, privacidad, retención y soporte con cada proveedor.
