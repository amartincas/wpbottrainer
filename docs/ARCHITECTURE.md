# Arquitectura — WpbotTrainer

> Este documento refleja el código real del repositorio. Reemplaza al `ARCHITECTURE.md` de raíz (conservado como artefacto histórico), que describía funcionalidad que nunca llegó a implementarse (guardrails de precio, multi-idioma, infraestructura Docker de producción). Cada sección está marcada como **ESTADO ACTUAL**, **ARQUITECTURA OBJETIVO APROBADA** o **PENDIENTE**.

## 1. Core Platform vs WpbotTrainer Domain

**ESTADO ACTUAL (Hito 7)**: `App\Core\Messaging\*` y `App\Core\Memory\*` (mecanismo — enrutamiento y memoria estructurada) siguen sin ninguna lógica de negocio ni conocimiento de `Product`/`Exercise`/dominio. `App\Handlers\FallbackChatHandler` sigue siendo el Handler conversacional del e-commerce heredado, fuera de Core a propósito. `App\Training\*` (Hito 4) tiene un único Handler real: `App\Training\Handlers\TrainingHandler`, resuelto por `Intent::Training` a través del mismo `Dispatcher` que ya resolvía `fallback_chat` — **sin un segundo intent**: el reporte de ejecución (Hito 6) se resuelve como un subflujo interno del mismo Handler, tal como se pidió explícitamente. Los modelos Eloquent del dominio siguen en `App\Models\*` plano, sin cambios respecto a Hito 4.

**Router deja de devolver siempre `Intent::FallbackChat`** (Hito 5): ahora prueba una lista ordenada de `IntentClassifierInterface` (Core, mismo patrón `Container → clase` de `Dispatcher`/`ContextBuilder`) y usa `Intent::FallbackChat` solo como default cuando ninguno reconoce el mensaje. `App\Training\Support\TrainingIntentClassifier` es el primer clasificador real — vive en Domain, Router no conoce su vocabulario. Desde el Hito 6, también fuerza `Intent::Training` cuando el `Contact` tiene una `WorkoutSession` pendiente (`scheduled`), no solo cuando el onboarding está incompleto — un reporte como "Sentadilla 10x40" casi nunca contiene una palabra clave de training. Desde el Hito 8.1, una tercera señal de estado: `TrainingAccess.status = Active` y cero `WorkoutSession` para el contacto → fuerza `Intent::Training` (hallazgo real: sin esto, "Sí" en respuesta a la invitación a entrenar de D031 caía en `fallback_chat`). Ver sección 8.1 y D032.

**ARQUITECTURA OBJETIVO APROBADA**: extender el mismo patrón — `App\Core\*` (mensajería WhatsApp, IA, identidad/tenancy, plantillas, facturación, el mecanismo Router/Dispatcher/ContextBuilder) y `App\Training\*` (el primer vertical: perfil, ejercicios, sesiones, progreso, y sus propios Handlers e Intents). Regla de dependencia de una sola vía: Domain puede importar de Core, Core nunca importa de Domain — verificado también hacia `App\Training` por un test de arquitectura (`tests/Feature/Core/CoreIsolationArchTest.php`). Un despliegue por vertical (no un runtime multi-vertical).

**PENDIENTE**: mover el resto de Core (WhatsApp, IA, Tenant/Contact) a `App\Core\*`; clasificación asistida por LLM para los mensajes que el clasificador determinista no reconoce (deferida explícitamente, ver `docs/DECISIONS.md`); subflujo conversacional de "consultar ejercicio" ad hoc (mencionado como posibilidad desde Hito 5, todavía no construido, sin consumidor real todavía).

## 2. Multi-tenancy

**ESTADO ACTUAL**: `Tenant` (antes `Store`) es la entidad de aislamiento multi-tenant. Cada `Tenant` tiene sus propias credenciales de WhatsApp (`wa_access_token`, `wa_phone_number_id`, `wa_business_account_id`, `wa_verify_token` — cifrados con `casts => 'encrypted'`) y su propia configuración de IA (`ai_provider`, `ai_model`, `ai_api_key`, también cifrado). La transcripción de audio (Whisper) es exclusivamente de OpenAI y usa un campo aparte, `openai_transcription_api_key` (también cifrado) — independiente de `ai_provider`/`ai_api_key`, que son solo del proveedor de chat (ver D022 en `docs/DECISIONS.md`). La resolución de tenant en el webhook entrante se hace por `wa_phone_number_id` (metadata del payload de Meta), no por el segmento de la URL. El segmento de la URL (`{tenant_token}`) solo se usa en el challenge GET de verificación, comparándolo contra `wa_verify_token`.

El aislamiento de datos por tenant se aplica manualmente en cada Filament Resource (`getEloquentQuery()` con `where('tenant_id', ...)` salvo `is_super_admin`), repetido en `ContactResource`, `ProductResource`, `TenantResource`, `WhatsAppTemplateResource`. No hay un trait/global scope compartido.

**PENDIENTE**: extraer el scoping repetido a un trait común (deuda técnica, no corregida en este hito).

## 3. WhatsApp

**ESTADO ACTUAL**: `WhatsAppController` expone `GET/POST /api/whatsapp/webhook/{tenant_token}`. El `POST` filtra eventos de status, aplica idempotencia atómica (`Cache::add` sobre el WAMID, TTL 10 min) antes de resolver el tenant, y despacha `ProcessWhatsAppMessage` de forma asíncrona. `WhatsAppService` encapsula el envío de mensajes de texto/imagen/plantilla y la descarga de medios vía Graph API v20.0. El Job usa `WithoutOverlapping` por `tenant_id`+teléfono para serializar mensajes casi simultáneos del mismo cliente.

## 4. Proveedores de IA

**ESTADO ACTUAL**: `App\Contracts\AiServiceInterface` + `App\Factories\AIServiceFactory` + `OpenAIService`/`GrokService`/`GeminiService` (namespace `App\Services\AI`). La factory valida el modelo configurado contra una lista soportada y usa un default si es inválido. `OpenAIService` también expone `transcribeAudio()` (Whisper) para mensajes de voz.

## 5. Flujo actual de `ProcessWhatsAppMessage` (Hito 1, superado por el Hito 2)

**HISTÓRICO**: hasta el Hito 2, un único método `handle()` de más de 1000 líneas hacía todo — bot activo/desactivado, transcripción, contexto de producto, historial, prompt, IA, extracción de lead, envío. Esa forma ya no existe; ver la sección 6 para el flujo real actual. El Job (`app/Jobs/ProcessWhatsAppMessage.php`) hoy tiene ~140 líneas y solo orquesta el pipeline de Core.

## 6. Router → Intent → Handler (Hito 2 — implementado)

**ESTADO ACTUAL**: el pipeline conversacional real es:

```
WhatsAppController (sin cambios) → ProcessWhatsAppMessage::dispatch(tenant, from, body, phoneId, type, mediaId, productContext)

ProcessWhatsAppMessage::handle(Ingest $ingest, Router $router, Dispatcher $dispatcher)
   → guard: Tenant válido
   → Ingest::process(tenant, from, messageBody, phoneId, messageType, mediaId): ?IngestedMessage
        ├─ Contact.bot_active = false → guarda el mensaje entrante, retorna null → Job termina
        ├─ audio/voice sin transcribir → descarga + Whisper; si falla, envía fallback específico y retorna null
        └─ éxito → IngestedMessage (from, messageBody, phoneId, messageType, mediaId)
   → Router::route(tenant, IngestedMessage): Intent
        — hoy siempre devuelve Intent::FallbackChat (placeholder documentado, sin clasificación real)
   → Dispatcher::dispatch(tenant, intent, IngestedMessage, $context)
        — resuelve la clase de Handler registrada para el intent vía el Container
          (Dispatcher → Container → Handler) y la invoca
        → FallbackChatHandler::handle(tenant, IngestedMessage, $context)
             — reproduce, sin cambios de comportamiento, el flujo completo de negocio:
               contexto de producto (sticky vía Conversation.current_product_id),
               historial, prompt, llamada a IA, extracción de lead/contacto,
               creación de Contact, tags [IMG:id], persistencia, envío, status tracking
   → catch(\Exception) en el Job → mensaje de fallback genérico + re-throw (igual que antes)
```

**Piezas y responsabilidades**:
- `App\Core\Messaging\Intent` — enum backed, único caso `FallbackChat = 'fallback_chat'`.
- `App\Core\Messaging\IngestedMessage` — DTO readonly (`from`, `messageBody`, `phoneId`, `messageType`, `mediaId`). Sin ningún campo de dominio (no `productContext`).
- `App\Core\Messaging\Ingest` — control de bot + transcripción de audio. Cero conocimiento de producto/lead/prompt.
- `App\Core\Messaging\Router` — clasifica; hoy trivial (placeholder), sin lógica de negocio.
- `App\Core\Messaging\HandlerInterface` — contrato `handle(Tenant, IngestedMessage, array $context = [])`.
- `App\Core\Messaging\Dispatcher` — resuelve la **clase** del Handler registrada para un `Intent` vía el Laravel Container (`$container->make($handlerClass)`) y le entrega el `ExecutionContext`. Lanza `RuntimeException` si el intent no tiene handler registrado.
- `App\Core\Messaging\ExecutionContext` *(Hito 3)* — el único objeto que fluye por `Router`→`Dispatcher`→`Handler`, reemplazando los parámetros sueltos del Hito 2. Contiene exactamente 4 propiedades: `tenant`, `conversation` (nullable), `message` (el `IngestedMessage`) y `legacy` (bolsa opaca transitoria — ver abajo). **No** contiene `Contact` (ningún proveedor lo necesita todavía; se resuelve por `tenant`+`from` cuando haga falta) ni `fragments` (ver `ContextBuilder`).
- `App\Handlers\FallbackChatHandler` — fuera de Core a propósito: contiene toda la lógica de dominio (catálogo, extracción de lead, prompt). Stateless — tenant/from/messageBody son variables locales de `handle()`, nunca propiedades de instancia. Sigue resolviendo su propio `Conversation` internamente (no usa `$context->conversation`) — deliberado, para no tocar su lógica interna en este hito.
- Wiring: `AppServiceProvider::register()` registra `Dispatcher` como singleton con el mapa `[Intent::FallbackChat->value => FallbackChatHandler::class]`.

**Mecanismo transitorio `legacy`** (ver `docs/DECISIONS.md`, D016): `ExecutionContext->legacy` es un `array` opaco que Core nunca interpreta. Hoy transporta únicamente `product_context` (el ID de producto resuelto por clic en anuncio, ver `WhatsAppController`) — es exclusivamente legado y específico de `FallbackChatHandler`. No deben agregarse nuevas claves de dominio ahí.

**Explícitamente NO implementado en el Hito 2**: clasificación real de intents (reglas o LLM), ningún intent de Training/Exercise, memoria estructurada, pagos, suscripciones, referidos, proactividad.

## 7. Memoria y Contexto (Hito 3 — mecanismo implementado, sin proveedores reales)

**ESTADO ACTUAL (Hito 5)**: el mecanismo (Hito 3) tiene ahora su **primer `ContextProvider` real**: `App\Training\Memory\TrainingProfileContextProvider`, registrado bajo la clave `training_profile`.

- `App\Core\Memory\ContextFragment` — value object inmutable: `label`, `data` (opaco para Core), `source` (`'db'|'computed'|'ai_summary'`, string libre por ahora — ver Riesgos en `docs/DECISIONS.md` D017), `confidence` (`'confirmed'|'system_recorded'|'inferred'|'unknown'|'computed'`). Esta etiqueta es la pieza concreta que permite diferenciar un hecho confirmado de uno inferido cuando el LLM lo reciba.
- `App\Core\Memory\ContextProviderInterface` — contrato: `provide(ExecutionContext $context): ContextFragment`. Un proveedor resuelve su propia identidad (ej. un `Contact`) a partir de `$context->tenant` + `$context->message->from` — nunca recibe uno preresuelto.
- `App\Core\Memory\ContextBuilder` — mismo patrón que `Dispatcher` (`ContextBuilder → Container → ContextProvider`, mapa de `clave => FQCN` resuelto vía `$container->make()`). `build(ExecutionContext $context, array $requestedKeys): ContextFragment[]` — invoca **solo** los proveedores pedidos; lanza `RuntimeException` si una clave pedida no tiene proveedor registrado.
- **`training_profile`** (Hito 5): `App\Training\Memory\TrainingProfileContextProvider` resuelve su propio `Contact` (tenant + `message->from`) y devuelve únicamente los campos ya respondidos del `TrainingProfile` (`goal`, `experience_level`, `restrictions`, `available_equipment`, `sessions_per_week`) — `confidence: 'confirmed'` si existe perfil, `'unknown'` si no. `App\Training\Handlers\TrainingHandler` es su único consumidor: lo usa para construir el prompt de extracción del onboarding (`OnboardingConversationService`), **nunca** envía el historial de WhatsApp al LLM. `FallbackChatHandler` sigue sin llamar a `ContextBuilder` — no lo necesita.

**Diseño aprobado, documentado, NO implementado en este hito** (ver `docs/DECISIONS.md`, D017): capas A–E de memoria (conversacional / perfil estructurado / memoria de dominio / historial de eventos `MemoryEvent` / contexto derivado), resúmenes automáticos de conversación, `Conversation.state` genérico, vector DB/embeddings/semantic search, compaction/archivado.

**`Conversation.current_product_id` — deuda técnica confirmada, no tocada**: se verificaron todas sus referencias (solo `FallbackChatHandler` y el modelo `Conversation`). Es la implementación e-commerce actual de lo que conceptualmente es "estado conversacional genérico" — queda documentado así, sin migrar, hasta que exista un primer consumidor real de Training que necesite ese estado en una forma no atada a `Product`.

## 8. Dominio Training (modelo de datos, Hito 4)

**ESTADO ACTUAL**: existe el dominio mínimo aprobado para vender y entregar el primer entrenamiento personalizado. `Product`/e-commerce no se tocó — coexisten como verticales independientes.

**Modelo de datos** (`App\Models\*`, todos con factory):

- `TrainingProfile` — 1:1 con `Contact`. Características/preferencias de entrenamiento: `goal`, `experience_level`, `available_equipment` (array), `restrictions` (array de tags), `sessions_per_week`, `split_type` (señal de rotación — `full_body`/`upper_lower`/`push_pull_legs`), `next_focus` (señal de continuidad, **no autoridad** — ver `TrainingEngine` abajo), `safety_status`/`safety_flag_reason`/`safety_flagged_at` (política de seguridad determinista). Métodos `flagForSafetyReview()`/`clearSafetyFlag()`. **No** tiene `access_status` — ver `TrainingAccess`. **No** tiene `tenant_id` — se escala vía `Contact` (mismo patrón que `ProductImage` respecto a `Product`).
- `Exercise` — catálogo maestro **global** (sin `tenant_id`, decisión aprobada: un ejercicio no varía por país/tenant). `name`, `slug`, `instructions`, `video_url`, `muscle_group`, `equipment_needed` (array), `difficulty_level`, `contraindications` (array de tags), `tracking_type` (`reps_and_load`/`time_based` — así se soportan ejercicios por tiempo sin una entidad aparte), `is_active` (nunca se borra físicamente). `toSnapshot()` congela los 4 campos que realmente se le muestran al usuario.
- `WorkoutSession` — la instancia concreta que un `Contact` debe realizar (o realizó, u omitió). `status` (`scheduled`/`completed`/`skipped`), `scheduled_at`, `completed_at`, `generated_by`. No existe "Workout" como plantilla separada — decisión aprobada de no crear `TrainingPlan` en este hito.
- `WorkoutExercise` — lo que el Training Engine **prescribió y mostró**, inmutable desde su creación. `order`, `prescribed_sets/reps/load/duration_seconds`, `rest_seconds`, y **`exercise_snapshot`** (JSON — el contenido exacto que se le mostró al usuario, congelado por `Exercise::toSnapshot()` en el momento de generar la sesión). `exercise_id` es **nullOnDelete** y sirve exclusivamente para trazabilidad/analítica — nunca se usa para reconstruir retroactivamente el contenido histórico (ver Inmutabilidad histórica abajo).
- `ExerciseLog` — cabecera de la ejecución real (`rpe` general, `note`, `logged_at`), 1:0..1 con `WorkoutExercise`. Nunca sobrescribe lo prescrito.
- `ExerciseSet` — cada serie realmente ejecutada (`set_number`, `actual_reps`, `actual_load`, `actual_duration_seconds`, todos nullable). Existe porque un `ExerciseLog` con campos escalares no puede representar series con reps/carga distintas (ej. 10×40kg, 10×45kg, 8×50kg) sin perder información.
- `TrainingAccess` — entitlement/acceso vigente, **separado** de `TrainingProfile` a propósito (características físicas ≠ estado comercial). `status` (`trial`/`active`/`expired`/`revoked`), `granted_at`, `expires_at`, `granted_by`, `notes`. **No** es `Subscription`/`Payment`/`Invoice`/`Enrollment` — solo responde "¿tiene acceso, desde cuándo, hasta cuándo, quién lo otorgó?". `isCurrentlyValid()` es su única regla de negocio.

**Inmutabilidad histórica** (requisito obligatorio de Hito 4): una `WorkoutSession` ya entregada conserva exactamente lo que el usuario recibió, sin importar qué le pase después a `Exercise` (edición de contenido) o al `TrainingEngine` (cambio de reglas). Se logra sin versionado: `WorkoutExercise.exercise_snapshot` congela el contenido en el momento de la creación; los campos `prescribed_*` se escriben una sola vez y nunca se recalculan retroactivamente. Cubierto por `tests/Feature/Training/WorkoutExerciseImmutabilityTest.php` (edición del `Exercise` en vivo no afecta el snapshot; borrar el `Exercise` referenciado no destruye el historial).

**`App\Training\Engine\TrainingEngine`** — paso "Decide" del patrón Extract → Decide → Narrate. Determinista, sin ninguna llamada al LLM. `decideNextSession(Contact $contact): WorkoutSession`:

1. Pasa por `TrainingAccessGate::authorize()` primero — lanza `TrainingAccessDeniedException` si no hay acceso vigente o el perfil está marcado por seguridad.
2. Si ya existe una `WorkoutSession` pendiente (`scheduled`), la devuelve sin cambios (idempotente — "qué toca hoy").
3. Si no, decide el **foco** de la nueva sesión evaluando, en orden de prioridad: (a) recuperación — un grupo muscular no trabajado hace más de `RECOVERY_NEGLECT_DAYS` tiene prioridad; (b) si la última sesión fue omitida, se reofrece el mismo foco; (c) evitar repetir el foco de la última sesión completada; (d) `TrainingProfile.next_focus` como señal de continuidad — **última prioridad, nunca autoridad absoluta**.
4. Selecciona hasta 3 ejercicios activos del foco elegido, excluyendo cualquiera cuyas `contraindications` choquen con `restrictions`, o cuyo `equipment_needed` no esté cubierto por `available_equipment`.
5. Prescribe cada ejercicio: sin historial previo, valores conservadores por defecto; con historial, progresión simple (sube carga/duración si el RPE reportado fue manejable, se mantiene si fue alto).
6. Avanza `TrainingProfile.next_focus` al siguiente foco de la rotación del `split_type` del perfil.

El foco histórico de una sesión pasada se deriva de los `muscle_group` realmente registrados en sus `exercise_snapshot` (nunca de `Exercise` actual) — no requirió agregar una columna `focus` a `WorkoutSession`.

**`App\Training\Support\TrainingAccessGate`** — frontera única entre Training y (a) el sistema comercial de acceso, (b) la política de seguridad determinista. `authorize(Contact): AccessGateResult` (`allowed: bool`, `reason: ?string` — `'no_access'`/`'access_invalid'`/`'safety_flagged'`). No conoce `Subscription`/`Payment`/`Invoice` — cuando el hito de Payments llegue, solo cambia lo que hay dentro de este método.

**`App\Training\Support\SafetySignalDetector`** — backstop determinista de patrones de texto (dolor de pecho, dificultad para respirar, pérdida de conciencia, cirugía reciente, síntomas neurológicos, lesión aguda no diagnosticada, complicación de embarazo). Desde el Hito 5, actúa en dos puntos: (a) siempre, sobre el texto crudo del mensaje entrante, antes de cualquier otra cosa; (b) sobre `safety_signal_text`, una frase que el LLM puede señalar durante la extracción de onboarding si detecta algo preocupante. Ninguna de las dos fuentes es autoritativa por sí sola; el bloqueo real lo ejecuta `TrainingProfile::flagForSafetyReview()`. El mensaje de escalamiento (`SafetySignalDetector::ESCALATION_MESSAGE`) y la lista de patrones siguen marcados en código con `// REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN`.

**Explícitamente NO implementado en Hitos 4-6**: `TrainingPlan`; `ExerciseCategory`/`Equipment`/restricción normalizados como tablas; `Progress` almacenado (se calcula bajo demanda desde `ExerciseLog`/`ExerciseSet`); `SafetyRule` como entidad; `Subscription`/`Payment`/`Invoice`/`Enrollment`; clasificación de intents asistida por LLM; pagos, proactividad, referidos, gamificación, nutrición, smartwatch, análisis de video, TTS, vector DB, `MemoryEvent`, summaries.

## 8.1 Primer flujo conversacional de Training (Hito 5, extendido en Hito 6)

**ESTADO ACTUAL**: `WhatsApp → Router → App\Training\Handlers\TrainingHandler → (TrainingEngine | ExecutionReportRecorder) → respuesta + video`, resuelto por `Intent::Training` vía el `Dispatcher` ya existente — **sigue sin existir un segundo intent**: el reporte de ejecución (Hito 6) es un subflujo interno del mismo `TrainingHandler`, tal como se pidió explícitamente. `TrainingHandler` es puro orquestador — cada decisión real vive en un colaborador de dominio:

```
TrainingHandler::handle(ExecutionContext)
  → Contact::firstOrCreate + TrainingProfile::firstOrCreate (split_type=full_body, safety_status=normal por defecto)
  → logInbound(): persiste el mensaje entrante en WhatsAppMessage (Hito 6 — ver 8.2)
  → SafetySignalDetector::detect(texto crudo)
       ├─ señal encontrada → TrainingProfile::flagForSafetyReview() + mensaje de escalamiento → FIN
       └─ perfil ya marcado de un turno anterior → mensaje de escalamiento → FIN (el LLM nunca lo desbloquea)
  → ¿TrainingProfile::isOnboardingComplete()?
       NO → ContextBuilder->build(['training_profile']) + OnboardingConversationService::extractAndRespond()
              (Extract + Narrate en UNA llamada de IA desde Hito 5.1 — validado antes de persistir, ver más abajo)
            → aplica solo los campos válidos y no nulos
            → ¿completo ahora? NO → OnboardingConversationService::resolveQuestion() (Decide: ¿la IA acertó
                                     qué faltaba? — Narrate real solo si sí) → FIN
                                SÍ → continúa en el mismo turno, sin esperar otro mensaje
  → TrainingAccessGate::authorize(Contact)
       NO permitido → mensaje "activa el servicio" o de escalamiento (según el motivo) → FIN
  → ContextBuilder->build(['active_workout_session'])
       ¿hay WorkoutSession pendiente con ejercicios sin reportar Y el mensaje trae señal de reporte?
       SÍ → ExecutionReportService::extractReport() + ExecutionReportRecorder::record() (Hito 6, ver 8.2) → FIN
       NO → continúa
  → TrainingEngine::decideNextSession(Contact) → WorkoutSession + WorkoutExercise[]
  → reply() (texto determinista, construido desde WorkoutExercise — nunca vía LLM; persiste en WhatsAppMessage)
  → WhatsAppService::sendWhatsAppVideo() por cada exercise_snapshot.video_url (sin persistir en WhatsAppMessage,
    mismo criterio que las imágenes de producto en FallbackChatHandler)
```

Todo mensaje saliente de `TrainingHandler` pasa por un método `reply()` interno que envía **y** persiste en `WhatsAppMessage` (ver 8.2) — no hay ninguna rama del flujo que hable con el usuario sin dejar rastro conversacional.

**Onboarding conversacional** (`App\Training\Support\OnboardingConversationService`) — Extract → Decide → Narrate real, fusionado en **una sola llamada de IA por turno** desde el Hito 5.1 (antes eran 2 secuenciales: extraer, y luego narrar — ver D026):
- **Extract + Narrate en una llamada** (`extractAndRespond()`): el LLM devuelve `{extracted: {...}, next_action, response}` — `extracted` con el mismo JSON estricto de siempre (`goal`, `experience_level`, `restrictions`, `available_equipment`, `sessions_per_week`, `safety_signal_text`), validado exactamente igual que antes (enums, arrays de strings, rango 1-14) antes de aceptarse. `next_action` (uno de 5 valores `ask_*` o `complete_onboarding`) y `response` (texto natural candidato) son señales adicionales, nunca autoritativas.
- **Decide, en dos puntos, ambos deterministas, sin cambios de autoridad**: qué campo falta sigue siendo `TrainingProfile::firstMissingOnboardingField()`; si se usa la redacción de la IA o el fallback canónico lo decide `resolveQuestion()`, comparando el `next_action` del modelo contra una tabla fija (`NEXT_ACTION_MAP`) — solo coincidencia exacta + `response` utilizable (no vacío, ≤300 caracteres) activa el texto de la IA. Un `next_action: "complete_onboarding"` del modelo nunca completa el onboarding por sí mismo — es estructuralmente imposible que "coincida" con un campo real pendiente.
- Si el proveedor de IA falla, o el JSON es inválido, se usa un resultado vacío + la pregunta canónica de `FALLBACK_QUESTIONS` — el onboarding nunca se rompe por una caída del proveedor de IA (sin cambios de comportamiento respecto a antes).
- `restrictions`/`available_equipment` distinguen `null` ("todavía no preguntado") de `[]` ("preguntado, sin ninguno") — sin cambios.
- `restrictions` y `safety_signal_text` son campos independientes del mismo JSON — una molestia física ordinaria ("dolor en la rodilla") siempre puede ir en `restrictions` sin que el prompt la fuerce a clasificarse (solo) como señal de seguridad; `safety_signal_text` queda acotado a las categorías reales que reconoce `SafetySignalDetector` (ver D026 — corrige un defecto real encontrado en el E2E del Hito 8).
- Si el onboarding se completa con toda la información dada en un solo mensaje, el mismo turno continúa directo a la verificación de acceso — no se espera un mensaje adicional solo para confirmar.

**Clasificación de intents** (`App\Core\Messaging\IntentClassifierInterface` + `App\Training\Support\TrainingIntentClassifier`): determinista, sin LLM. Coincidencia de palabras clave en español ("entrenar", "rutina", "gimnasio", etc.) **o** un `TrainingProfile` incompleto ya existente para ese `Contact` (para poder seguir el onboarding sin repetir palabras clave, ej. responder solo "3 veces por semana") **o**, desde el Hito 8.1, `TrainingAccess.status = Active` con cero `WorkoutSession` para el contacto (para que "Sí"/"Dale" en respuesta a la invitación a entrenar de D031 no se pierda en `fallback_chat` — hallazgo real del primer E2E comercial, ver D032; deliberadamente acotada a este caso puntual, no un mecanismo general de estado conversacional). Clasificación asistida por LLM para frases que el listado de palabras clave no reconozca queda **explícitamente diferida** — no hay evidencia todavía de que sea necesaria, y agregarla ahora encarecería/enlentecería cada mensaje entrante, incluidos los que van a `fallback_chat`. Ver `docs/DECISIONS.md` (D019).

**Video** (`WhatsAppService::sendWhatsAppVideo()`, Hito 5): mismo shape de payload que `sendWhatsAppImage()` (`type: 'video'`, `link` a una URL pública). No asume ningún proveedor de hosting de video definitivo — sigue pendiente la decisión de object storage/CDN (ver `docs/DECISIONS.md`, D018). Para pruebas se usan URLs de fixtures (`Exercise::factory()`), nunca una integración externa real.

**Audio de entrada**: sin cambios de comportamiento — `Ingest` (Core) sigue transcribiendo antes de que el Router/Handler vean el mensaje, así que Training soporta audio automáticamente sin código adicional. `TrainingHandler` solo recorta el prefijo decorativo `"🎤 [AUDIO]: "` antes de usar el texto en los prompts de extracción/clasificación. TTS de salida **no** se implementó — sigue evaluándose para un hito posterior.

## 8.2 Reporte de ejecución + persistencia conversacional (Hito 6)

**ESTADO ACTUAL**: cierra el ciclo `prescripción (WorkoutExercise) → ejecución real (ExerciseLog/ExerciseSet) → historial → próxima decisión del TrainingEngine` — el `TrainingEngine` ya leía estos datos desde Hito 4 (`progressionFor()`), pero hasta ahora nada los escribía desde una conversación real.

**`App\Training\Support\ExecutionReportService`** — mitad Extract. Un único LLM call por turno (`extractReport()`), solo cuando hay una `WorkoutSession` pendiente con ejercicios sin reportar. El prompt incluye la lista de nombres de ejercicios reportables (la única fuente de verdad de "a qué se puede referir el usuario" — el LLM debe devolver uno de esos nombres exactos, o `null`). Responde con un arreglo de reportes (uno por ejercicio mencionado), cada uno con `sets` (reps/carga/duración por serie), `rpe_number` o `rpe_category`, `note`, `not_performed` y `uncertain`. **Cada valor se valida** antes de aceptarse:
- Una serie sin ningún dato cuantificable (reps/carga/duración todos `null`) se descarta — nunca se guarda una serie vacía ni se completa con la prescripción.
- `rpe_number` fuera de 1-10, o `rpe_category` fuera del vocabulario cerrado (`App\Training\Enums\RpeCategory`), se descartan.
- El mapeo de categoría cualitativa ("estuvo pesado") a un RPE numérico es una **tabla determinista en código** (`RPE_CATEGORY_MAP`), nunca un número que el LLM inventa — si el usuario da un número explícito, ese número (validado) tiene prioridad sobre la categoría.
- Si el proveedor de IA falla, o el mensaje está vacío, o no hay ningún ejercicio reportable, se devuelve "sin reportes" sin lanzar excepción — el flujo normal de generación/reentrega asume el control.

**`App\Training\Support\ExecutionReportRecorder`** — mitad Decide, 100% determinista. Resuelve cada reporte ya validado contra los `WorkoutExercise` **sin reportar** de la sesión activa:
- Coincidencia por nombre exacto (case-insensitive) contra los ejercicios de esa sesión — nunca contra el catálogo `Exercise` completo, así que es estructuralmente imposible registrar un ejercicio que no pertenece a la sesión.
- Sin nombre explícito, solo se asume el ejercicio si hay exactamente **uno** pendiente — con más de uno, se pregunta cuál.
- Un ejercicio que ya tiene `ExerciseLog` nunca se vuelve a escribir (ni se duplica el `ExerciseLog` ni se le agregan más `ExerciseSet`) — evita duplicar un reporte reenviado.
- Un reporte sin ningún dato cuantificable, cualitativo (RPE) o nota, y que tampoco indica "no realizado", se trata como información faltante — el Handler pregunta en vez de asumir.
- `not_performed: true` sí genera un `ExerciseLog` (nota "No realizado. ...", cero `ExerciseSet`) — es una respuesta real del usuario, distinta de simplemente no mencionar el ejercicio (que no genera ninguna fila).
- `uncertain: true` (lenguaje de duda: "creo que", "unas", "tal vez") se persiste igual, pero con una nota que lo marca explícitamente — nunca se guarda como un hecho confirmado sin distinción.
- Cierra la `WorkoutSession` (`status = completed`, `completed_at = now()`) cuando todos los ejercicios ya tienen `ExerciseLog`, o cuando el usuario indica explícitamente que terminó (`session_finished`) aunque queden ejercicios sin reportar — soporta sesión parcialmente completada.

**`App\Training\Memory\ActiveWorkoutSessionContextProvider`** — segundo `ContextProvider` real (clave `active_workout_session`). Resuelve la `WorkoutSession` pendiente del `Contact`, si existe, con solo lo necesario para el prompt de extracción y la resolución determinista: `workout_session_id` y la lista de ejercicios **sin reportar** (`workout_exercise_id`, `name`, `tracking_type`) — nunca el `exercise_snapshot` completo. `confidence: 'unknown'` si no hay sesión pendiente.

**Clasificación (Router)** — ajuste necesario en `TrainingIntentClassifier`: un reporte como "Sentadilla 10x40" casi nunca contiene una palabra clave de training. Se agregó una tercera señal (junto a palabra clave y onboarding incompleto): **el `Contact` tiene una `WorkoutSession` con `status = scheduled`** → se clasifica como `training` sin necesitar palabra clave. Ver `docs/DECISIONS.md` (D020).

**Persistencia conversacional** (deuda de Hito 5, resuelta): `TrainingHandler::logInbound()` guarda el mensaje entrante (rol `user`) una sola vez por turno, sin importar qué subflujo lo procese después; `TrainingHandler::reply()` reemplaza toda llamada directa a `WhatsAppService::sendMessage()` — envía **y** guarda la respuesta (rol `assistant`), además de registrar el WAMID vía `WhatsAppStatusTracker::trackMessage()`, igual que ya hacía `FallbackChatHandler`. Los videos de ejercicios se envían pero **no** se persisten como `WhatsAppMessage` — mismo criterio que las imágenes de producto en `FallbackChatHandler` (no todo envío de media genera una fila de conversación).

**Separación memoria conversacional vs. historial de entrenamiento** (regla explícita del Hito 6, ya cumplida por diseño desde Hito 4): `WhatsAppMessage` representa la conversación; `ExerciseLog`/`ExerciseSet` representan hechos de entrenamiento. Ninguna escritura de `ExecutionReportRecorder` toca `WhatsAppMessage`, y `logInbound()`/`reply()` nunca escriben en `ExerciseLog`/`ExerciseSet` — son dos fuentes de verdad completamente independientes que solo comparten `tenant_id`/`customer_phone` como llave de correlación implícita (vía `Contact`).

**Inmutabilidad** (regla del Hito 4, reverificada en Hito 6): `ExecutionReportRecorder` únicamente hace `create()` sobre `ExerciseLog`/`ExerciseSet`, nunca `update()`; jamás escribe en `WorkoutExercise`. Cubierto explícitamente por `tests/Feature/Training/ExecutionReportFlowTest.php` (comparación byte-a-byte de los campos `prescribed_*`/`exercise_snapshot` antes y después de procesar un reporte).

**Observabilidad** (logs estructurados, sin dashboard): `TRAINING_REPORT_ATTEMPT`, `TRAINING_REPORT_SUCCESS`, `TRAINING_REPORT_INCOMPLETE`, `TRAINING_REPORT_MISSING_DATA`, `TRAINING_REPORT_DUPLICATE_SKIPPED`, `TRAINING_REPORT_EXTRACTION_ERROR`, `TRAINING_SESSION_COMPLETED`, `TRAINING_SESSION_PARTIALLY_COMPLETED` — todos vía `Log::info`/`Log::warning` con contexto estructurado (`workout_session_id`, `workout_exercise_id`, conteos, `elapsed_ms`). Sin tabla de eventos ni dashboard — mismo criterio que la deuda ya documentada de `MemoryEvent`.

**Explícitamente NO implementado en Hito 6**: subflujo de "consultar ejercicio" ad hoc; `TrainingPlan`; `MemoryEvent`; vector DB/embeddings/summaries; TTS; pagos/suscripciones/referidos/proactividad/smartwatch/análisis de video/nutrición/gamificación; cambios a las reglas de progresión del `TrainingEngine` (se verificó que ya consumían correctamente `ExerciseLog`/`ExerciseSet` reales sin necesitar cambios).

## 8.3 Validación E2E con Meta/WhatsApp real (Hito 7)

**ESTADO ACTUAL: preparado, NO ejecutado con Meta real.** El Hito 7 pedía demostrar el flujo completo contra Meta/WhatsApp real, no solo con tests internos. Auditoría previa (obligatoria antes de tocar código) encontró que el entorno de desarrollo actual **no tiene lo necesario**:

- `APP_URL=http://localhost` — sin URL pública, Meta no puede entregar ningún webhook.
- La base de datos de desarrollo (`wpbottrainer`) tiene **0 filas en `tenants`** — no hay ninguna credencial de Meta (real o de prueba) configurada.
- El sitio no está actualmente servido por Herd (`herd links` no lo lista).
- Confirmar visualmente "el video se reproduce dentro del chat" y enviar mensajes reales requiere un teléfono físico con WhatsApp — algo que ni el entorno ni quien ejecuta el código puede operar por sí mismo.

Ninguno de estos puntos es un defecto de arquitectura — son prerequisitos operativos de infraestructura/credenciales que solo el administrador del proyecto puede resolver. Ver `docs/DECISIONS.md` (D021) y el runbook completo en **`docs/E2E_META_RUNBOOK.md`**.

**Lo que sí se preparó y verificó en este hito (sin necesitar Meta real):**

- **Túnel público**: Herd trae **Expose** (`expose.phar`) ya autenticado — comando exacto documentado en el runbook (`herd share wpbottrainer`).
- **Instrumentación de observabilidad** (Hito 7, ver D021) integrada en el pipeline real: `JOB_START`/`JOB_END`/`ROUTER_CLASSIFIED` (`app/Jobs/ProcessWhatsAppMessage.php`), tiempo de transcripción (`app/Core/Messaging/Ingest.php`), `CONTEXT_BUILDER_RESULT`/`TRAINING_ENGINE_DECIDED`/`TRAINING_VIDEO_SENT`/`TRAINING_VIDEO_SEND_FAILED`/`TRAINING_META_SEND_FAILED` (`App\Training\Handlers\TrainingHandler`) — todos con `elapsed_ms` y contexto estructurado. Verificado que se emiten correctamente en `tests/Feature/MetaWebhookTrainingE2ETest.php`.
- **Tests con el payload EXACTO que Meta envía** (`tests/Feature/MetaWebhookTrainingE2ETest.php`) — a diferencia de los tests de Hitos 4-6 (que construían el Job directamente en PHP), estos hacen un `postJson()` real contra `/api/whatsapp/webhook/{token}` con la estructura completa de Meta (`object`, `entry[].id`, `changes[].field`, `contacts`, `messages[].timestamp`, etc.), ejercitando el parseo real de `WhatsAppController` de punta a punta hasta la base de datos (posible gracias a `QUEUE_CONNECTION=sync` en testing). Cubre: payload de texto, payload de audio, idempotencia de WAMID reintentado, fallo de Meta al enviar (sin romper la respuesta al webhook), fallo del proveedor de IA, y aislamiento entre dos tenants — todo por HTTP real, no por invocación directa del Job.
- **Video de prueba verificado** (formato/tamaño/licencia/latencia real de descarga) — ver `docs/E2E_META_RUNBOOK.md` sección 4. Resumen: tráiler oficial de *Big Buck Bunny* (Blender Foundation, CC BY 3.0) alojado en Internet Archive, `video/mp4` H.264, 2.93 MB (bajo el límite de 16 MB de Meta para video por link), ~2.5s de latencia de descarga completa desde este entorno. **No verificado**: cómo lo procesa Meta realmente, ni si se reproduce dentro del chat — eso requiere el envío real documentado en el runbook.

**Explícitamente NO se hizo en este hito**: ningún envío real a través de Meta; ninguna confirmación visual de reproducción de video; no se contrató ni pagó ningún proveedor de video definitivo; no se modificó la integración Meta existente (`WhatsAppController`/`WhatsAppService`) — se verificó que ya soporta correctamente el payload real sin necesitar cambios.

## 8.4 Pre-routing screening (Hito 7 — hallazgo de la ejecución E2E real)

Además del pipeline `Ingest → Router → Dispatcher → Handler` (sección 6), existe un paso adicional entre `Ingest` y `Router`: `App\Core\Messaging\PreRoutingScreener`. Corre sobre **todo** mensaje, antes de cualquier clasificación de Intent — su único caso de uso hoy es `App\Training\Support\SafetySignalPreRoutingScreen`, que garantiza que una señal de seguridad (ej. dolor de pecho) se atienda sin importar si el Router habría clasificado el mensaje como `training` o no. Ver D023 en `docs/DECISIONS.md` para el hallazgo real que motivó este mecanismo.

## 8.5 AlertService (Hito 7.1) — infraestructura transversal, no pertenece a ningún dominio

`App\Core\Alerts\{Alert,AlertSeverity,AlertChannelInterface,AlertService}` — mismo patrón Container-resuelto que Router/Dispatcher/ContextBuilder/PreRoutingScreener, pero con **fan-out**: todos los canales registrados que "soportan" una `Alert` la reciben (no solo el primero), porque un mismo evento puede tener sentido en varios canales a la vez.

Un dominio (Safety hoy; Payments, Meta/IA/Queue en el futuro) construye una `Alert` (`category` string libre, `severity` enum Info/Warning/Critical, `message`, `context`) y llama `AlertService::send()` — nunca decide el canal ni el destinatario. `Alert` sanea su propio `context` (redacta cualquier clave que luzca como secreto) antes de que un canal la vea.

Canales hoy: `PersistedAlertChannel` (siempre registra en la tabla `alert_logs`, durable) y `WhatsAppAdminAlertChannel` (solo severidad Warning/Critical, envía usando las credenciales Meta del `Tenant` en `context['tenant_id']` a todos los `User.is_super_admin` con `phone` configurado, con throttling básico). Un fallo de cualquier canal nunca se propaga al proceso que originó la alerta. Conectado hoy solo a Safety (`TrainingHandler::emitSafetyAlert()`). Ver D024 en `docs/DECISIONS.md`.

## 8.6 CustomerNotifier (Hito 8, ajuste) — infraestructura transversal para notificación transaccional al cliente

`App\Core\Notifications\CustomerNotifier` — **explícitamente separado de `AlertService`**: `AlertService` es para operadores/superadmins (admin-facing); `CustomerNotifier` es para el cliente final (transaccional). No se fusionan.

```php
notify(Tenant $tenant, string $to, string $eventKey, array $variables, string $freeFormText): void
```

Decide **únicamente el mecanismo de entrega** — mensaje libre si la ventana de 24h de WhatsApp sigue abierta, `WhatsAppTemplate` si no — nunca decide **cuándo ni por qué** contactar al cliente; esa autoridad sigue siendo exclusiva de quien llama (Payments hoy; un futuro motor de Proactividad, sección 10, reutilizará el mismo método sin que este componente contenga ninguna de sus reglas).

- **Señal de ventana**: `Conversation.last_session_at`, ya actualizada por el proyecto en cada mensaje **entrante** del cliente (nunca en salientes) — sin consultar ninguna API de Meta. Margen conservador de **23h30m** sobre las 24h reales.
- **Ventana abierta** (`< 23h30m`, o exactamente `last_session_at` disponible): `WhatsAppService::sendMessage()` con el texto libre que el dominio ya provee.
- **Ventana cerrada** (`>= 23h30m`, o sin `Conversation` registrada): busca `WhatsAppTemplate::where(tenant_id, event_key)` — el dominio nunca conoce el nombre técnico real aprobado por Meta, solo un `eventKey` libre (mismo criterio que `Alert::category`). Resuelve `parameters_map` (ya existente en `WhatsAppTemplate`) contra las `variables` que el dominio provee, y envía vía `WhatsAppService::sendTemplateMessage()`.
- **Sin plantilla configurada, plantilla rechazada por Meta, o cualquier excepción**: se loguea y **nunca se propaga** — mismo contrato de aislamiento de fallos que `AlertService::send()`.

Un dominio nunca conoce Graph API, nombre técnico de plantilla, versión de Meta, ni la regla de ventana — solo describe el evento (`eventKey` + `variables` + el texto libre que usaría si la ventana estuviera abierta). Conectado hoy a Payments (`PaymentConfirmationService::confirm()`/`reject()`, eventos `payment_confirmed`/`payment_rejected`/`training_invite`). Ver D029 en `docs/DECISIONS.md`.

**Persistencia en `WhatsAppMessage` (Hito 8.1)**: cada envío (mensaje libre o vía plantilla) se persiste como `role: assistant` **antes** de intentar el envío real, igual patrón que `PaymentHandler::reply()`/`TrainingHandler::reply()` — hallazgo real: sin esto, `FallbackChatHandler` no veía en su historial que ya se había enviado la invitación a entrenar, contribuyendo a que improvisara una respuesta al "Sí" del cliente. Para la rama de plantilla se persiste el texto libre equivalente, no la plantilla cruda con `{{N}}`. No se persiste nada si no había plantilla configurada (nada se envió realmente). Ver D032.

## 9. Exercise Library

**ESTADO ACTUAL**: catálogo mínimo (`Exercise`, ver sección 8) — sin contenido real cargado todavía, solo el esquema y las factories de test. Almacenamiento de video recomendado (no implementado): object storage + CDN (ver `docs/DECISIONS.md`), por compatibilidad con URLs públicas de WhatsApp Cloud API y para no pagar ancho de banda repetido sirviendo el mismo video desde el propio servidor de aplicación.

## 10. Proactividad

**PENDIENTE — no implementado.** El sistema es hoy 100% reactivo (solo responde a webhooks entrantes), con una única excepción puntual: tras confirmar un Payment, se envía una invitación a entrenar (D031) — no es un motor de proactividad, es un mensaje fijo disparado por un evento síncrono ya existente, sin programación ni recordatorios por inactividad. `WhatsAppTemplate` (con `is_reengagement`) y `App\Core\Notifications\CustomerNotifier` (sección 8.6) ya existen como la base de entrega que reutilizará el motor de proactividad real — este último decide únicamente el mecanismo de envío (libre vs. plantilla), nunca cuándo ni por qué contactar; esas reglas viven exclusivamente en Proactivity cuando se construya, no en `CustomerNotifier`.

## 11. Pagos (Hito 8 — implementado: flujo manual Nequi/Daviplata)

**ESTADO ACTUAL**: `App\Payments\*` es el segundo namespace de Domain real (después de Training, Hito 4). Flujo:

```
"Quiero pagar" → PaymentHandler(payment_options) → muestra métodos configurados en el Tenant
  → usuario elige → PaymentHandler(payment_instructions) → crea Payment(pending) + instrucciones
  → usuario envía comprobante (texto o imagen) → PaymentHandler(receipt_submission)
       → ReceiptExtractionService (Extract, IA — solo lee lo visible, nunca decide)
       → PaymentValidationService (Decide, determinista — produce validation_flags, nunca confirma/rechaza)
       → Payment(under_review) + PaymentReceipt creado
       → AlertService->send(category:'payments', severity:Warning) → WhatsApp al superadmin + AlertLog
  → superadmin revisa en Filament (PaymentResource) → Confirmar/Rechazar (is_super_admin, nota registrada)
       → App\Payments\Support\PaymentConfirmationService — ÚNICO camino a TrainingAccess
           → idempotente: repetir confirm()/reject() sobre un Payment ya resuelto es no-op completo
           → confirma: extiende TrainingAccess.expires_at (+1 mes, sin perder días ya pagados)
                       → CustomerNotifier->notify(..., 'payment_confirmed', ...) tras persistir
                       → CustomerNotifier->notify(..., 'training_invite', ...) — invita a entrenar,
                         NUNCA crea WorkoutSession; si el usuario responde, su mensaje entra por
                         el Router/Dispatcher normal, sin código nuevo que lo intercepte
           → rechaza: no toca TrainingAccess en absoluto
                       → CustomerNotifier->notify(..., 'payment_rejected', ...) tras persistir
```

`PaymentHandler` no importa `TrainingAccess` (verificado por arch test, no solo por convención) — `TrainingAccessGate`/`TrainingEngine` no se modificaron en absoluto para este hito; el propio `SafetySignalDetector` (Hito 4) ya anticipaba este momento en su propio comentario. `PaymentConfirmationService` tampoco importa Graph API/nombres de plantilla — delega toda la decisión de canal en `CustomerNotifier` (sección 8.6). Un fallo de notificación nunca revierte ni bloquea la confirmación/rechazo, que ya quedó persistida antes de intentar notificar.

Entidades: `Payment` (sin `tenant_id` propio, vía `Contact`, mismo precedente de Hito 4), `PaymentReceipt` (uno por intento de comprobante — separada de `Payment` por el mismo motivo de granularidad que separó `ExerciseLog`/`ExerciseSet`). `Subscription` y `PaymentMethod` como tabla quedan diferidas explícitamente — "1 mes de acceso + renovación" se resuelve reutilizando `TrainingAccess.expires_at`, sin entidades nuevas.

**Preparado para el futuro, no implementado**: `PaymentConfirmationService::confirm()` acepta un `?User $reviewer` nullable — el mismo método debe poder invocarse desde un webhook de pasarela real sin cambiar su firma, con la pasarela como autoridad automática (a diferencia de un comprobante manual, que siempre exige revisión humana). Ver D025 en `docs/DECISIONS.md`.

**Confirmación/rechazo por WhatsApp del superadmin — auditado, explícitamente NO implementado**: hoy la única interfaz administrativa es Filament (`PaymentResource`). Un comando determinista (`CONFIRMAR <id>` / `RECHAZAR <id> <motivo>`) desde el WhatsApp del superadmin se diseñaría como un `PreRoutingScreen` nuevo (mismo mecanismo de la sección 8.4), que verificaría teléfono autorizado + sintaxis exacta antes de delegar en `PaymentConfirmationService` — heredando gratis su idempotencia (ver D029). Sin este screen, ningún mensaje del superadmin puede confundirse con un comando administrativo porque el parser simplemente no existe todavía.

**Corregido en la validación E2E real de este ajuste** (ver D027/D028 en `docs/DECISIONS.md`): el webhook no reconocía mensajes `type: image` (caían en silencio antes de despachar el Job), y la imagen Docker de producción no tenía la extensión `bcmath` que `PaymentValidationService` necesita para comparar montos.

**Visión de IA**: verificado empíricamente en este hito que OpenAI (`gpt-4o-mini`) y Grok (`grok-4.20-0309-non-reasoning`, distinto del modelo de chat barato por defecto) leen comprobantes correctamente — sin necesidad de agregar ningún proveedor nuevo. Gemini implementado pero no verificado con una key real.

## 12. Dashboard

**ESTADO ACTUAL**: Filament 5. Recursos: `TenantResource`, `ContactResource`, `ProductResource`, `UserResource`, `WhatsAppTemplateResource`; página custom `ManageChats` con el Livewire `WhatsAppChatCenter` (bandeja de chat en vivo, toggle de bot, envío manual y de plantillas, filtro multi-tenant para superadmin).

## 13. Docker

**ESTADO ACTUAL**: solo `docker-compose-phpmyadmin.yml` (MySQL 8.0 + phpMyAdmin) para desarrollo local. Decisión de arquitectura confirmada: en desarrollo, Laravel/PHP y Node/Vite corren **nativos** en el host; **MySQL y Redis corren en Docker Desktop** (sin ejecutar la aplicación Laravel dentro de un contenedor). SQLite no es la base de datos de referencia del proyecto (ver `docs/DECISIONS.md`). La infraestructura Docker de producción (contenedores de app, worker, scheduler, etc.) queda **pendiente**, para un hito específico.

## 14. API-First

**DECISIÓN APROBADA: NO para el MVP.** Ver `docs/DECISIONS.md` para la justificación completa.
