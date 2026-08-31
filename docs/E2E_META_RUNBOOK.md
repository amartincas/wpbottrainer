# Runbook — Prueba E2E real con Meta/WhatsApp (Hito 7)

> Este documento es el checklist exacto para ejecutar la prueba real que el Hito 7 pedía y que **no se pudo completar automáticamente** por falta de credenciales/teléfono real — ver `docs/DECISIONS.md` (D021) para el porqué. Todo lo que sí se pudo preparar/verificar sin depender de eso ya está hecho (túnel, instrumentación, tests offline). Esto es lo que falta para el resto.

## 0. Qué ya está listo (verificado en este hito)

- ✅ Túnel público disponible: Herd trae **Expose** ya autenticado (`expose token` devuelve un token válido) — no hay que instalar ni pagar nada.
- ✅ Ruta del webhook ya existe y funciona: `GET/POST /api/whatsapp/webhook/{tenant_token}` (`routes/api.php`, `App\Http\Controllers\WhatsAppController`).
- ✅ Pipeline completo probado offline con payloads idénticos a los reales de Meta (`tests/Feature/MetaWebhookTrainingE2ETest.php`) — la única pieza que falta es la conexión con la Meta real.
- ✅ Instrumentación de tiempos/errores ya integrada (ver `docs/DECISIONS.md`, D021) — en cuanto haya tráfico real, los logs ya van a registrar todo lo pedido.
- ✅ Video de prueba verificado y con licencia clara — ver sección 4.
- ❌ **`mysql_local` (BD `wpbottrainer`, la de desarrollo) tiene 0 filas en `tenants`** — hay que crear un Tenant con credenciales reales antes de poder probar nada.
- ❌ El sitio `wpbottrainer` no está actualmente servido por Herd (`herd links` no lo lista, `http://wpbottrainer.test` no resuelve) — hay que enlazarlo antes de compartirlo.

## 1. Qué necesita el administrador (tú) antes de continuar

Para poder ejecutar la prueba real necesito que me proporciones (o que tú mismo cargues en un `Tenant`, ver paso 3) lo siguiente — **nada de esto se puede inventar ni usar de prueba**:

| Dato | De dónde sale | Va en |
|---|---|---|
| `wa_access_token` | Meta App → WhatsApp → API Setup (token temporal de 24h para probar, o un token de System User de larga duración) | `Tenant.wa_access_token` |
| `wa_phone_number_id` | Meta App → WhatsApp → API Setup → "Phone number ID" del número de prueba | `Tenant.wa_phone_number_id` |
| `wa_business_account_id` | Meta App → WhatsApp → API Setup → "WhatsApp Business Account ID" | `Tenant.wa_business_account_id` |
| `wa_verify_token` | Lo eliges tú (cualquier string) — debe coincidir exactamente con lo que pongas en el paso 5 | `Tenant.wa_verify_token` |
| `ai_provider` + `ai_api_key` | Tu cuenta de OpenAI (u otro proveedor ya soportado) | `Tenant.ai_provider` / `Tenant.ai_api_key` |
| `openai_transcription_api_key` | Una API key real de OpenAI — **siempre**, sin importar qué `ai_provider` uses para chat (ver D022 en `docs/DECISIONS.md`: Whisper es exclusivamente OpenAI, es un campo independiente de `ai_api_key`) | `Tenant.openai_transcription_api_key` |
| Un número de teléfono de prueba **agregado como destinatario permitido** en Meta (los números de prueba de Meta exigen registrar explícitamente a quién le pueden llegar mensajes) | Meta App → WhatsApp → API Setup → "To" | — (es el teléfono desde el que enviarás los mensajes) |

> **¿Sirve el número de prueba gratuito que da Meta (no un número de WhatsApp Business verificado)?** Sí — es la forma recomendada para esta validación. La API se comporta exactamente igual (texto, audio y **video** incluidos) con el número de prueba que con uno de producción. Tres particularidades a tener en cuenta:
> 1. Solo puede enviar a los números que agregues explícitamente en la lista "To" de API Setup (verificados por código) — de ahí la fila de la tabla de arriba.
> 2. El `wa_access_token` que aparece por defecto en esa misma pantalla **expira en 24 horas** — suficiente para una sesión de prueba en un día; si se corta la prueba a otro día, hay que regenerarlo.
> 3. Meta impone un límite diario de mensajes salientes en números de prueba (pensado para desarrollo) — de sobra para los ~15-20 mensajes de los escenarios A-E, pero puede agotarse si se repiten muchas pruebas fallidas el mismo día.

## 2. Levantar el entorno local

```bash
# Terminal 1 — servidor (si Herd no lo sirve ya)
cd C:\Users\amart\Herd\wpbottrainer
herd link wpbottrainer   # una sola vez, registra wpbottrainer.test

# Terminal 2 — worker de colas (necesario: QUEUE_CONNECTION real en .env local no es "sync")
php artisan queue:work

# Terminal 3 — túnel público
herd share wpbottrainer
# o, equivalente: php C:\Users\amart\.config\herd\bin\expose.phar share wpbottrainer.test
```

`herd share` imprime una URL pública tipo `https://xxxxxxxx.sharedwithexpose.com` — esa es la que se usa en el paso 5. **La URL cambia cada vez que se reinicia el túnel** (a menos que se use `--subdomain` con una cuenta que lo permita) — si el túnel se cae a mitad de la prueba, hay que volver a actualizar la URL en Meta.

## 3. Crear el Tenant de prueba

Con las credenciales del paso 1, vía `php artisan tinker` (o Filament, `Tenant` resource) en la BD de desarrollo:

> **Nota sobre `personality_type`** (verificado en Hito 7A): la columna dejó de ser un `ENUM` a nivel de base de datos desde la migración `2026_05_04_000001_make_tenant_fields_nullable.php` (ahora es un `string` nullable), pero los únicos valores que la aplicación trata hoy como válidos (Filament `TenantForm`/`TenantWizardForm`, `TenantFactory`) siguen siendo `vendedor`, `soporte` y `asesor` — `"entrenador"` **no** es uno de ellos y no se agregó ninguno nuevo (no se tocó esquema ni se hizo migración). Se usa `asesor` ("Business Advisor") como el valor válido más cercano al rol de un entrenador/coach para este Tenant de prueba.

```php
\App\Models\Tenant::create([
    'name' => 'Prueba E2E Hito 7',
    'personality_type' => 'asesor',
    'system_prompt' => 'Eres un entrenador personal de WpbotTrainer.',
    'ai_provider' => 'openai',
    'ai_model' => 'gpt-4o-mini',
    'ai_api_key' => '<tu API key real>',
    // Siempre OpenAI, sin importar 'ai_provider' — Whisper no tiene alternativa
    // multi-proveedor (ver D022). Si el Escenario D (audio) falla con
    // "Incorrect API key provided", esto es lo primero a revisar.
    'openai_transcription_api_key' => '<tu API key real de OpenAI>',
    'wa_access_token' => '<token real de Meta>',
    'wa_phone_number_id' => '<phone_number_id real>',
    'wa_business_account_id' => '<business_account_id real>',
    'wa_verify_token' => 'hito7-verify-token',   // o el string que prefieras
]);
```

## 4. Ejercicio + video de prueba

Usar un `Exercise` con un video verificado (formato/tamaño/licencia ya comprobados en este hito):

- **URL**: `https://archive.org/download/big-buck-bunny-trailer/Big-buck-bunny_trailer.mp4`
- **Formato**: `video/mp4` (ISO Media, MP4 Base Media v1, H.264) — dentro de los formatos que WhatsApp Cloud API acepta para mensajes de video (MP4/3GPP, H.264+AAC recomendado).
- **Tamaño**: 2.93 MB — muy por debajo del límite de Meta para video por link (16 MB).
- **Origen/licencia**: tráiler oficial de *Big Buck Bunny* (Blender Foundation), alojado en Internet Archive, licencia **CC BY 3.0** — permite uso comercial y redistribución citando la fuente ("Big Buck Bunny, © Blender Foundation, CC BY 3.0"). Para esta prueba interna de validación no se distribuye a usuarios finales reales, así que no aplica la obligación de atribución visible — si en el futuro se usa contenido de terceros en producción, sí habría que atribuir según la licencia de cada pieza.
- **Latencia observada** (descarga completa desde este entorno): ~2.5s para 2.93MB (~1.19 MB/s) — la latencia real que Meta experimente al buscar la URL puede ser distinta; **esto queda pendiente de medir con Meta real** (ver sección 6).

```php
$exercise = \App\Models\Exercise::create([
    'name' => 'Plancha (demo E2E)',
    'slug' => 'plancha-demo-e2e',
    'instructions' => 'Mantén el cuerpo recto apoyado en antebrazos y puntas de los pies.',
    'video_url' => 'https://archive.org/download/big-buck-bunny-trailer/Big-buck-bunny_trailer.mp4',
    'muscle_group' => 'core',
    'equipment_needed' => [],
    'difficulty_level' => 'beginner',
    'contraindications' => [],
    'tracking_type' => 'time_based',
    'is_active' => true,
]);
```

## 5. Configurar el webhook en Meta

Meta App Dashboard → WhatsApp → Configuration → Webhook:

- **Callback URL**: `<url-del-tunel>/api/whatsapp/webhook/hito7-verify-token` (el segmento final debe ser exactamente el `wa_verify_token` del Tenant creado).
- **Verify token**: `hito7-verify-token` (el mismo valor).
- Click "Verify and Save" — Meta hace un GET inmediato; si responde 200 con el challenge, queda verificado (`WhatsAppController::verify()`, ya probado offline en `tests/Feature/WhatsAppWebhookTest.php`).
- Suscribirse al campo **`messages`**.

## 6. Escenarios a ejecutar (enviar desde el teléfono de prueba real)

Seguir exactamente los escenarios A-E del Hito 7. Para cada uno, además de observar la respuesta en WhatsApp, revisar en paralelo:

```bash
# logs estructurados en vivo
tail -f storage/logs/laravel.log | grep -E "JOB_START|JOB_END|ROUTER_CLASSIFIED|CONTEXT_BUILDER_RESULT|TRAINING_ENGINE_DECIDED|TRAINING_VIDEO_SENT|TRAINING_REPORT|TRANSCRIPTION|META_SEND_FAILED"
```

| Escenario | Qué enviar | Qué verificar |
|---|---|---|
| A — usuario nuevo | "Quiero empezar a entrenar" → responder las preguntas de onboarding → activar acceso manualmente (`TrainingAccess::create([...])` en tinker) → "Dame mi entrenamiento" | `TrainingProfile` completo, `WorkoutSession`/`WorkoutExercise` creados, video recibido **y reproducido dentro del chat** (confirmar visualmente: no debe abrir navegador/otra app) |
| B — usuario existente | Con el mismo contacto ya onboardeado, "Dame mi entrenamiento de mañana" | No vuelve a preguntar objetivo/nivel/etc. |
| C — reporte por texto | "3 series, 10/10/8, 40 kg" | `ExerciseLog` + 3 `ExerciseSet` en la BD |
| D — reporte por audio | Grabar y enviar un audio diciendo lo mismo que en C | Mismo resultado que C, pasando por transcripción real de Whisper |
| E — señal de seguridad | "Me duele mucho el pecho, no puedo seguir" | Respuesta de escalamiento, `TrainingProfile.safety_status = flagged_for_review` |

Para video específicamente, confirmar y anotar:
1. ¿Meta aceptó el payload (200 en la respuesta del `sendWhatsAppVideo`)? — ver log `TRAINING_VIDEO_SENT` vs `TRAINING_VIDEO_SEND_FAILED`.
2. ¿El mensaje llega a WhatsApp como tipo **video** (con miniatura/reproductor nativo), no como un link de texto?
3. ¿Se reproduce tocando el mensaje **dentro del chat**, sin salir a un navegador?
4. Tiempo aproximado entre el envío y la disponibilidad del video en el teléfono.

## 7. Después de la prueba

- Verificar en base de datos: `WhatsAppMessage` (ambos roles), `TrainingProfile`, `TrainingAccess`, `WorkoutSession`/`WorkoutExercise`, `ExerciseLog`/`ExerciseSet`.
- Revisar `storage/logs/laravel.log` por cualquier `ERROR`/`WARNING` no esperado.
- Traer los resultados (capturas de pantalla del video reproduciéndose, tiempos observados, cualquier comportamiento inesperado) para actualizar este runbook y cerrar el Hito 7 con evidencia real.
- **No dejar el túnel corriendo** ni el `wa_access_token` real en un Tenant de un entorno compartido más tiempo del necesario — es una credencial sensible.
