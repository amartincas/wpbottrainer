# Guía de testing — WpbotTrainer

## Estrategia

- Framework: **Pest** sobre PHPUnit, con `RefreshDatabase` habilitado (ver `tests/Pest.php`) — cada test corre contra una base de datos limpia y migrada.
- Base de datos de test: MySQL (`wpbottrainer_test`, configurada en `phpunit.xml`), no SQLite — ver `docs/DECISIONS.md` (D010).
- Ninguna prueba debe golpear la red real: Meta Graph API y los proveedores de IA (OpenAI/Grok/Gemini) se interceptan con `Http::fake()`.

## Tipos de prueba

- **Feature end-to-end del webhook**: request HTTP real contra `routes/api.php`, verificando resolución de tenant, idempotencia y despacho del Job.
- **Tests de modelo/relaciones**: factories (`Tenant`, `Contact`, `Product`, `User`) + assertions directas sobre Eloquent.
- **Tests de multi-tenancy/scoping**: llamando directamente a `Resource::getEloquentQuery()` con un usuario autenticado (`Auth::login()`), sin necesidad de renderizar la UI completa de Filament.
- **Tests de Jobs**: invocando `app()->call([$job, 'handle'])` para probar la lógica del Job de forma síncrona (desde el Hito 2, `handle()` type-hinta sus dependencias del pipeline de Core — `Ingest`, `Router`, `Dispatcher` — y Laravel las resuelve automáticamente cuando el worker real procesa un Job; en un test hay que pasar por el Container de la misma forma, `$job->handle()` a secas ya no funciona), o `Queue::fake()` cuando lo que se quiere verificar es que el Job se encoló correctamente (y con qué datos), no su contenido.
- **Tests del pipeline de Core** (`tests/Feature/Core/`): `Router`, `Dispatcher` e `Ingest` se prueban de forma aislada, instanciándolos directamente (sin pasar por el Job) — son clases simples sin dependencias ocultas. Desde el Hito 5, `RouterTest.php` prueba el mecanismo de clasificadores con clasificadores *de prueba* (test doubles), no con `TrainingIntentClassifier` real — ese vive en `tests/Feature/Training/`.
- **Tests del mecanismo de memoria** (`tests/Feature/Core/`, Hito 3): `ContextBuilder` se prueba igual que `Dispatcher` — instanciándolo directamente con un mapa de proveedores *de prueba* (test doubles con contador estático de invocaciones).
- **Tests de arquitectura** (`tests/Feature/Core/CoreIsolationArchTest.php`, Hito 3-5): usan el plugin `pestphp/pest-plugin-arch` (`arch(...)->expect(...)->not->toUse(...)`) para verificar en cada corrida que `App\Core` no importa `App\Handlers` ni `App\Training` ni el catálogo legacy de ecommerce, y que `ExecutionContext`/`IngestedMessage` no referencian `Product`/`Contact`. Esto convierte la regla "Core provee mecanismo, Domain provee contenido" en algo que rompe la suite si se viola, no solo un comentario en la documentación.
- **Tests del dominio Training** (`tests/Feature/Training/`, Hito 4/5/6): modelos/relaciones/casts probados con factories; `TrainingEngine`/`TrainingAccessGate`/`SafetySignalDetector`/`TrainingIntentClassifier`/`ExecutionReportRecorder` probados como servicios de dominio puros (100% deterministas, sin `Http::fake()` salvo cuando construyen su propio escenario vía el Job); `OnboardingConversationService`/`ExecutionReportService` y los flujos E2E de `TrainingHandler` sí requieren `Http::fake()` porque llaman al proveedor de IA.
- **Trampa conocida de `Http::fake()` con `Http::sequence()` en un mismo test** (aprendida en Hito 5): llamar a `Http::fake([...])` una segunda vez dentro del mismo test **no reemplaza** una `Http::sequence()` ya registrada para el mismo patrón de URL de una llamada anterior — la secuencia vieja (posiblemente ya agotada) puede seguir respondiendo, produciendo el error "A request was made, but the response sequence is empty." de forma silenciosa si el código bajo prueba atrapa la excepción. Para un test que simula varios turnos/llamadas al mismo endpoint, declarar **un único** `Http::fake()` con **una única** `Http::sequence()` que incluya todos los `push()` necesarios, en el orden exacto en que se consumirán — ver `tests/Feature/Training/TrainingConversationFlowTest.php`.

## Qué debe probarse como mínimo

- Autenticación/registro de tenant (`CustomRegister`) sigue funcionando.
- Resolución de `Tenant` por `wa_phone_number_id` (POST) y por `wa_verify_token` (GET, challenge de Meta).
- Idempotencia del webhook: un WAMID repetido no debe generar un segundo job ni una segunda respuesta de IA.
- Scoping multi-tenant: un usuario no-superadmin nunca debe ver datos de otro tenant en ningún Resource de Filament.
- El flujo feliz del Job de WhatsApp: guarda el mensaje del usuario, guarda la respuesta del asistente, y crea un `Contact` cuando corresponde.
- El flujo de bot desactivado: si `Contact.bot_active = false`, no debe hacerse ninguna llamada a IA ni a WhatsApp.

## Reglas para integraciones

- `Http::fake()` para cualquier llamada saliente a `api.openai.com`, `api.x.ai`, `generativelanguage.googleapis.com` o `graph.facebook.com`. Nunca depender de credenciales reales en tests.
- Si un test necesita simular una secuencia de respuestas de IA (ej. respuesta principal + extracción de datos), usar `Http::sequence()`.

## Idempotencia

- El mecanismo real (`Cache::add()` atómico sobre el WAMID, TTL 10 minutos) debe probarse enviando el mismo payload de webhook dos veces y verificando que el Job solo se encola una vez (`Queue::fake()` + `Queue::assertPushed(Job::class, 1)`).
- Limpiar la cache relevante al inicio del test (`Cache::flush()`) para evitar falsos positivos/negativos entre ejecuciones.

## Jobs / colas

- Para probar *que* un Job se encola: `Queue::fake()` + `Queue::assertPushed(...)`.
- Para probar *qué hace* un Job: instanciarlo directamente y llamar a `app()->call([$job, 'handle'])` (ver nota del Hito 2 arriba), con `Http::fake()` cubriendo sus dependencias externas (IA, WhatsApp).
- El middleware `WithoutOverlapping` del Job no se prueba con jobs reales en cola concurrente en este hito — queda como candidato para un test de integración más pesado si se detectan regresiones en producción.

## Cobertura del Router Core (Hito 2/5)

Las 8 categorías de test pedidas para el Router viven así:

| Categoría | Dónde |
|---|---|
| **routing** | `tests/Feature/Core/RouterTest.php` — mecanismo de clasificadores en cadena (Hito 5): sin clasificadores o con todos declinando cae a `Intent::FallbackChat`; el primero que reconoce el mensaje gana; resolución vía Container. La clasificación real de Training vive en `tests/Feature/Training/TrainingIntentClassifierTest.php`. |
| **dispatch** | `tests/Feature/Core/DispatcherTest.php` — resuelve el Handler correcto vía el Container, reenvía `$context` sin tocarlo, y lanza `RuntimeException` si el intent no tiene handler registrado. |
| **fallback** | `tests/Feature/ProcessWhatsAppMessageJobTest.php` (heredado del Hito 1, sin modificar sus aserciones) — prueba de no-regresión: el flujo completo a través de `Ingest → Router → Dispatcher → FallbackChatHandler` debe producir el mismo resultado observable que antes del refactor. |
| **tenant** | `tests/Feature/ProcessWhatsAppMessageJobTest.php` — dos tenants procesados en la misma corrida no mezclan `WhatsAppMessage`/`Contact`. |
| **conversación** | `tests/Feature/ProcessWhatsAppMessageJobTest.php` — el "sticky product" (`Conversation.current_product_id`) se mantiene entre dos turnos tras el refactor. |
| **WhatsApp** | `tests/Feature/ProcessWhatsAppMessageJobTest.php` — se verifica el payload exacto enviado a `graph.facebook.com` y que el tracking de estado (WAMID) se registra. |
| **errores** | `tests/Feature/Core/IngestTest.php` (fallo de transcripción → mensaje de fallback específico) y `tests/Feature/ProcessWhatsAppMessageJobTest.php` (fallo del proveedor de IA → mensaje de fallback genérico + re-throw). |
| **idempotencia** | `tests/Feature/WhatsAppWebhookTest.php` (heredado del Hito 1, sin modificar) — el Router/Dispatcher no introduce lógica de idempotencia nueva; la garantía sigue viviendo en el `Cache::add()` del Controller. |

## Cobertura de memoria y contexto (Hito 3)

| Categoría | Dónde |
|---|---|
| **forma de `ExecutionContext`** | `tests/Feature/Core/ExecutionContextTest.php` — bloquea por reflexión que solo existen las 4 propiedades públicas aprobadas (`tenant`, `conversation`, `message`, `legacy`), y que `conversation`/`legacy` tienen los valores por defecto correctos (`null`/`[]`). |
| **resolución de `ContextProvider`** | `tests/Feature/Core/ContextBuilderTest.php` — un proveedor registrado se resuelve vía el Container y devuelve su `ContextFragment`; varios proveedores se resuelven en el orden pedido. |
| **aislamiento de proveedores no solicitados** | `tests/Feature/Core/ContextBuilderTest.php` — un proveedor registrado pero no pedido en `build()` nunca se invoca (verificado con contador estático), y pedir cero claves devuelve `[]` sin tocar el Container. |
| **errores** | `tests/Feature/Core/ContextBuilderTest.php` — pedir una clave sin proveedor registrado lanza `RuntimeException` con un mensaje explícito. |
| **forma de `ContextFragment`** | `tests/Feature/Core/ContextBuilderTest.php` — value object simple (`label`, `data`, `source`, `confidence`), sin lógica. |
| **aislamiento Core/Domain** | `tests/Feature/Core/CoreIsolationArchTest.php` — reglas de arquitectura (ver arriba). |

No existe todavía ningún test de un proveedor de memoria *real* (Training) porque no existe el proveedor — es deliberado, ver `docs/DECISIONS.md` (D017).

## Cobertura del dominio Training (Hito 4)

43 tests nuevos en `tests/Feature/Training/`:

| Categoría | Dónde |
|---|---|
| **relaciones** | Cada archivo de entidad (`TrainingProfileTest`, `ExerciseTest`, `WorkoutSessionTest`, etc.) prueba sus propias relaciones (`belongsTo`/`hasMany`/`hasOne`) contra factories reales. |
| **multi-tenancy** | `TrainingMultiTenancyTest.php` — dos tenants no mezclan `WorkoutSession` de sus contactos; `Exercise` es verificado como catálogo global (sin columna `tenant_id`); `TrainingProfile`/`WorkoutSession`/`TrainingAccess` verificados sin `tenant_id` propio (se escalan vía `Contact`, mismo precedente que `product_images`). |
| **TrainingProfile** | `TrainingProfileTest.php` — casts de enums/arrays, `flagForSafetyReview()`/`clearSafetyFlag()`, ausencia de `access_status`. |
| **Exercise** | `ExerciseTest.php` — casts, `is_active` como mecanismo de retiro (nunca borrado físico), `toSnapshot()`. |
| **WorkoutSession** | `WorkoutSessionTest.php` — relación con `Contact`, orden de `workoutExercises`, estados `scheduled`/`completed`/`skipped`. |
| **WorkoutExercise + snapshot** | `WorkoutExerciseImmutabilityTest.php` — editar el `Exercise` en vivo **no** cambia el `exercise_snapshot` ya guardado; borrar el `Exercise` referenciado (`nullOnDelete`) no destruye el `WorkoutExercise` ni su snapshot; los campos `prescribed_*` no se sobrescriben. |
| **ExerciseLog / ExerciseSet** | `ExerciseLogAndSetTest.php` — reproduce el caso de la sentadilla con reps/carga distintas por serie (10×40kg, 10×45kg, 8×50kg); ejercicios por tiempo (`actual_duration_seconds`); una sesión puede reportarse parcialmente (no todo `WorkoutExercise` tiene `ExerciseLog`). |
| **prescrito vs. ejecutado** | `ExerciseLogAndSetTest.php` — registrar un `ExerciseLog`/`ExerciseSet` con valores muy distintos a lo prescrito nunca modifica las columnas `prescribed_*` del `WorkoutExercise`. |
| **TrainingAccess / acceso-expiración** | `TrainingAccessTest.php` — `isCurrentlyValid()` para activo/trial/expirado/revocado/con fecha pasada; verificación de que la tabla no contiene ningún campo de facturación. |
| **safety gate** | `TrainingAccessGateTest.php` + `SafetySignalDetectorTest.php` — el Gate bloquea por `no_access`/`access_invalid`/`safety_flagged` (seguridad tiene prioridad incluso con acceso vigente) y permite solo cuando ambas fuentes están en orden; el detector reconoce frases de alarma conocidas (case-insensitive) y no confunde una restricción normal ("me duele la rodilla") con una señal de alarma. |
| **Training Engine — decisión** | `TrainingEngineTest.php` — bloqueo por `TrainingAccessDeniedException` (sin acceso, perfil marcado); idempotencia (sesión pendiente se devuelve sin cambios); generación respetando restricciones/equipamiento; progresión de carga/duración según RPE reportado; rotación evita repetir el foco de la sesión completada inmediatamente anterior. |

## Cobertura del primer flujo conversacional de Training (Hito 5)

25 tests nuevos: `RouterTest.php` +3 (mecanismo de clasificadores), y 22 en `tests/Feature/Training/`:

| Categoría pedida | Dónde |
|---|---|
| **detección de training** | `TrainingIntentClassifierTest.php` — palabras clave; contacto con onboarding incompleto se sigue clasificando como training aunque el mensaje no tenga palabras clave; un perfil ya completo no fuerza el intent. |
| **fallback** | Cubierto por la ausencia de cambios en `ProcessWhatsAppMessageJobTest.php` (sigue en verde, sin modificar) — un mensaje sin señal de training sigue resolviendo a `FallbackChatHandler` exactamente igual que antes. |
| **onboarding** | `TrainingConversationFlowTest.php` — primer contacto sin perfil recibe la primera pregunta; progresivo turno a turno sin repetir lo ya conocido; se completa en un solo mensaje si el usuario da todo de una vez. |
| **persistencia de TrainingProfile** | `TrainingConversationFlowTest.php` — se verifica el estado de la fila en base de datos después de cada turno, no solo la respuesta enviada. |
| **acceso permitido / denegado** | `TrainingConversationFlowTest.php` — sin `TrainingAccess` se informa que debe activar el servicio (cero llamadas a IA, perfil ya completo); con acceso vigente se genera y entrega la sesión. |
| **generación de WorkoutSession / WorkoutExercise** | `TrainingConversationFlowTest.php` — se verifica la sesión creada, sus 3 `WorkoutExercise`, y que el texto enviado por WhatsApp contenga el entrenamiento. |
| **video** | `TrainingConversationFlowTest.php` — `Http::assertSent()` verifica un envío `type: video` con el `video.link` correcto por cada ejercicio con `exercise_snapshot.video_url`, usando `Exercise::factory()` (fixtures controladas, sin proveedor externo real). |
| **audio de entrada** | `TrainingConversationFlowTest.php` — mensaje `messageType=audio` con `mediaId`, fake de descarga de media + transcripción Whisper, verifica que el flujo de onboarding continúa igual que con texto. |
| **safety gate** | `TrainingConversationFlowTest.php` — una señal de riesgo en el mensaje bloquea inmediatamente (sin generar sesión, sin llamar a IA); un perfil ya bloqueado en un turno anterior se mantiene bloqueado en el siguiente, sin que el LLM pueda levantarlo. |
| **memoria `training_profile`** | `TrainingProfileContextProviderTest.php` — perfil inexistente → `confidence: unknown`, `data: null`; perfil existente → `confidence: confirmed` con los campos exactos; resoluble a través de `ContextBuilder` real. |
| **usuario con/sin perfil** | `TrainingConversationFlowTest.php` cubre ambos extremos: contacto totalmente nuevo (crea `Contact` + `TrainingProfile` incompleto) y contacto con perfil ya completo (cero llamadas de extracción/narración, va directo a acceso/generación). |
| **extracción validada (Extract)** | `OnboardingConversationServiceTest.php` — un valor de enum inválido, un tipo incorrecto (`restrictions` no-array), o un `sessions_per_week` fuera de rango se descartan (`null`) en vez de persistirse; sin llamada a IA para un mensaje vacío; degrada a valores seguros si el proveedor de IA falla. |
| **narración (Narrate)** | `OnboardingConversationServiceTest.php` — recorta espacios de la respuesta del LLM; usa una pregunta canónica de respaldo si el proveedor de IA falla. |

## Cobertura de reporte de ejecución + persistencia conversacional (Hito 6)

24 tests nuevos en `tests/Feature/Training/{ExecutionReportServiceTest,ExecutionReportFlowTest}.php`, mapeados a los 21 escenarios pedidos:

| Categoría pedida | Dónde |
|---|---|
| **1-4. reporte completo / parcial / varias series / reps-carga variables** | `ExecutionReportFlowTest.php` — sentadilla con 3 series de reps/carga distintas (10x40, 10x45, 8x50); sesión de 3 ejercicios con solo 2 reportados (uno completo, uno explícitamente "no realizado"), el tercero queda sin `ExerciseLog`. |
| **5. ejercicio por tiempo** | `ExecutionReportFlowTest.php` — `duration_seconds` en vez de reps/carga, usando `Exercise.tracking_type = time_based`. |
| **6. RPE** | `ExecutionReportFlowTest.php` + `ExecutionReportServiceTest.php` — mapeo determinista de categoría cualitativa ("hard"→8), número explícito con prioridad sobre la categoría, valores fuera de rango o categorías inválidas descartados. |
| **7. nota** | `ExecutionReportFlowTest.php` — observación textual persistida tal cual en `ExerciseLog.note`. |
| **8. datos faltantes** | `ExecutionReportFlowTest.php` — se menciona el ejercicio sin ningún número → no se crea `ExerciseLog`, se pregunta explícitamente. |
| **9. datos ambiguos** | `ExecutionReportFlowTest.php` — "Creo que hice unas 10 repeticiones" se registra (no se descarta), pero con una nota que marca la incertidumbre — nunca como un hecho confirmado sin distinción. |
| **10. audio de entrada** | `ExecutionReportFlowTest.php` — mismo patrón de fake que Hito 5 (descarga de media + Whisper), seguido de la misma extracción de reporte. |
| **11. duplicación del mensaje** | `ExecutionReportFlowTest.php` — el mismo ejercicio reportado dos veces produce un único `ExerciseLog` (verificado con `Http::sequence()` de una sola llamada `Http::fake()`, ver nota sobre esta trampa arriba). |
| **12. ejercicio que no pertenece a la sesión** | `ExecutionReportFlowTest.php` — un nombre que no coincide con ningún `WorkoutExercise` sin reportar de la sesión activa nunca crea un `ExerciseLog`; se pide aclaración. |
| **13-14. persistencia de WhatsAppMessage** | `ExecutionReportFlowTest.php` — se verifica una fila `role=user` con el contenido exacto del mensaje entrante, y una fila `role=assistant` con la confirmación enviada. |
| **15-17. integración con WorkoutSession/ExerciseLog/ExerciseSet** | `ExecutionReportFlowTest.php` — transición `scheduled → completed` (+`completed_at`) al completar todo o al indicar explícitamente que terminó; campos de `ExerciseLog`/`ExerciseSet` verificados directamente. |
| **18. no modificación de WorkoutExercise** | `ExecutionReportFlowTest.php` — comparación exacta (`toEqualCanonicalizing`) de `prescribed_*`/`exercise_snapshot` antes y después de procesar un reporte con valores muy distintos a lo prescrito. |
| **19. usuario sin sesión activa** | `ExecutionReportFlowTest.php` — sin `WorkoutSession` pendiente, un mensaje con palabra clave de training cae al flujo normal de generación (nueva sesión), nunca crea un `ExerciseLog` fantasma. |
| **20. bloqueado por acceso** | `ExecutionReportFlowTest.php` — con una sesión pendiente pero sin `TrainingAccess` vigente, el reporte se bloquea con el mensaje de activación, sin tocar `ExerciseLog`. |
| **21. bloqueado por safety** | `ExecutionReportFlowTest.php` — una señal de riesgo en el mismo mensaje del reporte bloquea antes de intentar extraer nada, sin llamar al proveedor de IA. |

## Cobertura de validación E2E con el payload real de Meta (Hito 7)

`tests/Feature/MetaWebhookTrainingE2ETest.php` (6 tests) — a diferencia de todos los tests anteriores (que construyen `ProcessWhatsAppMessage` directamente en PHP), estos hacen `postJson('/api/whatsapp/webhook/{token}')` con la estructura **completa** que Meta realmente envía (`object`, `entry[].id`, `changes[].field`, `contacts`, `messages[].timestamp`) — ejercitando el parseo real de `WhatsAppController` de punta a punta. Posible en tests porque `QUEUE_CONNECTION=sync` (`phpunit.xml`) ejecuta el Job dentro de la misma petición.

| Categoría pedida | Dónde |
|---|---|
| **webhook real / payload recibido** | Payload de texto y de audio con la forma exacta de Meta, verificando que `TrainingProfile`/`WhatsAppMessage` terminan correctos en base de datos tras el POST. |
| **audio** | Payload de audio real (`type: audio`, `audio.mime_type`, `audio.id`) con el mismo fake de descarga+transcripción ya usado en Hitos 2-6. |
| **idempotencia** | El mismo WAMID entregado dos veces (reintento real de Meta) solo genera un `ExerciseLog` y un `WhatsAppMessage` de usuario. |
| **errores (Meta)** | `graph.facebook.com` responde 401 al intentar enviar — el webhook igual responde 200 (para no generar reintentos infinitos de Meta) y el mensaje saliente queda igualmente persistido con `TRAINING_META_SEND_FAILED` en el log. |
| **errores (IA)** | `api.openai.com` responde 500 durante onboarding vía la ruta real — se envía la pregunta canónica de respaldo, el webhook no se rompe. |
| **aislamiento por Tenant** | Dos tenants con `wa_phone_number_id` distintos reciben webhooks independientes sin mezclar `Contact`/`TrainingProfile`. |

**No cubierto por tests** (requiere Meta real, ver `docs/E2E_META_RUNBOOK.md` y D021): que Meta efectivamente entregue el mensaje al teléfono, y que un video se reproduzca dentro del chat de WhatsApp sin salir a un navegador.

## WhatsApp

- Todo test que dispare `WhatsAppService::sendMessage`/`sendTemplateMessage`/`sendWhatsAppVideo` debe interceptar `graph.facebook.com/*` con `Http::fake()` y, si el test le interesa, verificar el payload enviado con `Http::assertSent(...)`.

## Criterios generales de aceptación

Antes de dar por cerrado un hito:

1. La suite completa corre sin **nuevas** fallas respecto a la línea base tomada al inicio del hito (los tests placeholder/heredados que ya fallaban por causas ajenas al hito — ej. Fortify no instalado — no bloquean el cierre, pero se documentan).
2. `php artisan migrate:fresh` corre limpio desde una base vacía.
3. Todo test nuevo que dependa de un factory debe usar un factory real (`database/factories/`), no datos hardcodeados por fuera del sistema de factories de Laravel.

## Deuda de testing conocida (no resuelta, verificada de nuevo en el Hito 7)

- `tests/Feature/Auth/*` y `tests/Feature/Settings/SecurityTest.php` dependen de `Laravel\Fortify\Features`, paquete que **no está instalado** en `composer.json` — fallan con `Class not found` independientemente de cualquier cambio de este hito.
- Varios tests de Auth dependen de rutas nombradas (`route('register')`, `route('login')`) que no existen tal como Filament las registra hoy.
- Ningún test (ni puede haberlo, en este entorno) ejercita Meta/WhatsApp real — toda la suite sigue con `Http::fake()`. La validación real queda en `docs/E2E_META_RUNBOOK.md`, pendiente de credenciales.
- Tests que renderizan vistas Blade con `@vite(...)` fallan si no se corrió `npm install && npm run build` (no se ejecutó en este entorno).
- Al cierre de cada hito desde el Hito 2 se ha comparado línea por línea contra la línea base del hito anterior: siguen siendo las mismas 22 fallas heredadas — cero regresiones introducidas por el dominio Training, incluyendo el Hito 6.
- No existe todavía ningún test de corrección de un `ExerciseLog` ya registrado por error — solo se prueba que no se duplique, no que se pueda editar.
