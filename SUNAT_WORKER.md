# Worker independiente SUNAT

El envío inmediato desde el POS y el botón de reintento manual siguen activos. Este worker agrega una tercera vía independiente del navegador:

1. Laravel Scheduler ejecuta `sunat:dispatch-pending` cada minuto.
2. El comando reserva un lote con un lease y `FOR UPDATE SKIP LOCKED`.
3. Cada orden reservada se publica en la cola `sunat`.
4. `ProcessSunatOrder` reutiliza el flujo fiscal existente y cierra el lease, programa backoff o deja un rechazo como estado terminal.

Los estados `pendiente`, `error` y `procesando` obsoleto son recuperables. `enviado`, `aceptado_observaciones` y `rechazado` son terminales. Después del máximo configurado de intentos automáticos, la orden conserva su error para revisión y el botón manual continúa disponible.

## Laravel Cloud (producción, sin cluster adicional)

En el entorno que sirve `api-de-facturacion-sunat-main-qzacqf.laravel.cloud`:

1. En **App compute**, habilitar **Scheduler** y volver a desplegar. Laravel Cloud ejecutará `schedule:run` cada minuto.
2. En el mismo **App compute**, agregar un único background process de tipo queue worker con estos valores:
   - Connection: `database`.
   - Queue: `sunat`.
   - Processes: `1`.
   - Sleep: `3` segundos.
   - Tries: `1`.
   - Timeout: `75` segundos.
3. Mantener `QUEUE_CONNECTION=database`, `SUNAT_WORKER_ENABLED=true` y `SUNAT_WORKER_QUEUE=sunat`.

Esta configuración reutiliza el compute existente y evita crear un Worker Cluster o una Managed Queue facturable. Si el volumen futuro exige aislamiento, el mismo job se puede migrar a una cola administrada sin cambiar el flujo fiscal.

## Desarrollo o servidor propio

Ejecutar ambos procesos:

```bash
php artisan schedule:work
php artisan queue:work --queue=sunat --sleep=3 --tries=1 --timeout=75
```

Antes debe existir la tabla de jobs (`php artisan migrate`).

## Operación

```bash
# Ejecutar un barrido manual
php artisan sunat:dispatch-pending

# Ver la programación registrada
php artisan schedule:list

# Ver trabajos fallidos con el driver database
php artisan queue:failed
```

Los parámetros de lote, lease, máximo de intentos y backoff están documentados en `.env.example`.
