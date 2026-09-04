# Guía de desarrollo — WpbotTrainer

## Estructura del proyecto (estado actual)

```
app/
  Core/Messaging/   Intent, IngestedMessage, Ingest, Router, IntentClassifierInterface,
                     HandlerInterface, Dispatcher, ExecutionContext (mecanismo de
                     enrutamiento — CERO lógica de negocio, ver Hito 2, 3 y 5)
  Core/Memory/      ContextFragment, ContextProviderInterface, ContextBuilder
                     (mecanismo de memoria estructurada — primer proveedor real desde Hito 5)
  Core/Alerts/      Alert, AlertSeverity, AlertChannelInterface, AlertService,
                     Channels/{PersistedAlertChannel,WhatsAppAdminAlertChannel}
                     (infraestructura transversal de notificación — fan-out a todos los
                     canales que soporten la Alert, no solo el primero; ver Hito 7.1)
  Handlers/         FallbackChatHandler (Handler heredado del e-commerce — TODA la lógica
                     conversacional de ese dominio vive aquí, fuera de Core a propósito)
  Training/         Engine/TrainingEngine (paso "Decide", determinista), Handlers/
                     TrainingHandler (único Handler real de Training — onboarding, generación
                     y reporte de ejecución son subflujos internos, no intents separados),
                     Memory/{TrainingProfileContextProvider,ActiveWorkoutSessionContextProvider}
                     (los dos únicos ContextProvider reales), Support/{TrainingAccessGate,
                     SafetySignalDetector,TrainingIntentClassifier,OnboardingConversationService,
                     ExecutionReportService,ExecutionReportRecorder,ExecutionReportOutcome,
                     AccessGateResult,TrainingAccessDeniedException}, Enums/* (vocabulario
                     cerrado del dominio, incluye RpeCategory) — ver Hito 4, 5 y 6.
  Payments/         Enums/{PaymentStatus,PaymentMethodType}, Support/{PaymentIntentClassifier,
                     ReceiptExtractionService,PaymentValidationService,PaymentConfirmationService},
                     Handlers/PaymentHandler (único Handler real — payment_options/
                     payment_instructions/receipt_submission/payment_status son subflujos
                     internos, no intents separados) — segundo namespace de Domain, mismo
                     criterio que App\Training\* (Hito 8)
  Models/           Tenant, User, Contact, Conversation, Product, ProductImage,
                     WhatsAppMessage, WhatsAppTemplate, TrainingProfile, Exercise,
                     WorkoutSession, WorkoutExercise, ExerciseLog, ExerciseSet,
                     TrainingAccess, AlertLog, Payment, PaymentReceipt (todos planos,
                     mismo namespace, no App\Training\Models / App\Payments\Models)
  Http/Controllers/ WhatsAppController (webhook Meta)
  Http/Middleware/  CheckTenantSetup
  Jobs/             ProcessWhatsAppMessage (orquestador delgado: Ingest → Router → Dispatcher,
                     construye el ExecutionContext)
  Services/         WhatsAppService, WhatsAppStatusTracker, ProductFinderService,
                     Services/AI/{OpenAIService,GrokService,GeminiService}
  Factories/        AIServiceFactory
  Contracts/        AiServiceInterface
  Livewire/         WhatsAppChatCenter (bandeja de chat en vivo)
  Filament/         Resources (Tenants, Contacts, Products, Users, WhatsAppTemplate),
                     Pages (Dashboard, ManageChats, CustomRegister), Widgets
```

Desde el Hito 2, `App\Core\Messaging\*` y `App\Handlers\*` son la primera frontera real de namespace del proyecto. El Hito 3 añadió `App\Core\Memory\*` como segunda pieza de Core, con el mismo criterio. El Hito 4 añadió `App\Training\*` como el primer namespace de **Domain** real (servicios, no modelos — los modelos Eloquent del dominio se quedaron en `App\Models\*` plano, para no romper la convención ya establecida por `Tenant`/`Contact`/`Product`/`Conversation`). El resto (`Services`, `Filament`, etc.) sigue sin separar — se irá moviendo a `App\Core\*` a medida que haga falta, no de una sola vez.

## Frontera Core / Domain

- **Core** (`App\Core\Messaging\*`, `App\Core\Memory\*`; se irá ampliando con WhatsApp, IA, `Tenant`, `Contact`, `Conversation`, plantillas, facturación): el mecanismo del Router/Dispatcher/Ingest/ContextBuilder — nunca lógica de negocio, nunca un concepto de un vertical específico (ni Product, ni Exercise). Verificado por `tests/Feature/Core/CoreIsolationArchTest.php`, que desde el Hito 4 también prohíbe que `App\Core` dependa de `App\Training`.
- **Domain** (`App\Training\*`, desde el Hito 4): `Engine/TrainingEngine` (paso "Decide" — determinista, sin LLM), `Handlers/TrainingHandler` (único Handler real), `Memory/{TrainingProfileContextProvider,ActiveWorkoutSessionContextProvider}`, `Support/{TrainingAccessGate,SafetySignalDetector,TrainingIntentClassifier,OnboardingConversationService,ExecutionReportService,ExecutionReportRecorder}` (frontera de acceso/seguridad/clasificación/onboarding/reporte de ejecución), `Enums/*` (vocabulario cerrado del dominio, backed enums). Los modelos Eloquent del dominio (`TrainingProfile`, `Exercise`, `WorkoutSession`, etc.) viven en `App\Models\*`, no aquí — ver la sección de estructura arriba.
- **Handlers** (`App\Handlers\*` para e-commerce heredado, `App\Training\Handlers\*` para Training): contienen la lógica de negocio conversacional de cada intent. `FallbackChatHandler` (la conversación libre heredada) y `TrainingHandler` (onboarding + generación + reporte de ejecución + finalización de sesión — todos subflujos internos del mismo Handler, sin un intent por cada uno, per Hito 6). Cada Handler es independiente — no se conocen entre sí, y el Router nunca sabe qué hace un Handler.
- **Regla de dependencia de una sola vía**: un Handler puede importar de Core; Core **nunca** importa de un Handler concreto ni de ningún clasificador de dominio (`AppServiceProvider` es la única excepción consciente: es el "composition root" que conecta ambos mundos vía los mapas de `Router`/`Dispatcher`/`ContextBuilder`).
- **Cómo añadir un intent nuevo**:
  1. Añadir el caso al enum `App\Core\Messaging\Intent`.
  2. Implementar `App\Core\Messaging\HandlerInterface` en una clase nueva (fuera de Core).
  3. Registrar `Intent::NuevoCaso->value => NuevaClaseHandler::class` en el mapa de `Dispatcher` dentro de `AppServiceProvider::register()`.
  4. Implementar `App\Core\Messaging\IntentClassifierInterface::classify(ExecutionContext $context): ?Intent` en una clase de Domain que sepa reconocer ese intent (retorna `null` si el mensaje no es suyo, para que Router pruebe el siguiente clasificador o caiga a `fallback_chat`).
  5. Registrar la clase del clasificador en la lista que recibe `Router` en `AppServiceProvider::register()` (el orden importa: el primero que reconozca el mensaje gana).
  - Ver `docs/DECISIONS.md` (D016, D019) para el porqué de este diseño y sus límites conocidos. `App\Training\Support\TrainingIntentClassifier` es el ejemplo real a seguir — determinista, sin LLM, vive en Domain.
- **Cómo registrar un `ContextProvider` nuevo**:
  1. Implementar `App\Core\Memory\ContextProviderInterface::provide(ExecutionContext $context): ContextFragment` en una clase nueva (fuera de Core — vive junto al dominio al que pertenece el dato, ej. `App\Training\Memory\...`).
  2. Registrar `'clave' => NuevoProviderClass::class` en el mapa que recibe `ContextBuilder` al construirse en `AppServiceProvider::register()`.
  3. Desde el Handler que lo necesite: `$this->contextBuilder->build($context, ['clave', ...])` y usar los `ContextFragment` devueltos — nunca todos los registrados, solo las claves explícitamente pedidas.
  4. No añadir una clave "por si acaso": cada proveedor nuevo debe tener un consumidor real en el mismo cambio que lo introduce. `App\Training\Memory\TrainingProfileContextProvider` es el ejemplo real a seguir (Hito 5) — resuelve su propio `Contact`, nunca envía historial de WhatsApp, `data` es un array plano pensado para interpolarse en un prompt, no el modelo Eloquent vivo.
  - `ExecutionContext` es deliberadamente mínimo (`tenant`, `conversation`, `message`, `legacy`) y está bloqueado por test de forma (`tests/Feature/Core/ExecutionContextTest.php`) — no se le agregan propiedades sin una necesidad real y aprobada; los fragmentos de memoria se calculan bajo demanda vía `ContextBuilder::build()`, nunca se guardan dentro del propio `ExecutionContext`.
  - Ver `docs/DECISIONS.md` (D017) para el porqué de este diseño (incluyendo por qué `Conversation.current_product_id` sigue sin tocarse) y sus límites conocidos.

## Dominio Training (Hito 4/5/6/7)

- **`next_focus` es una señal, nunca una autoridad.** Cualquier lógica que decida qué entrenar a continuación debe evaluar historial reciente, restricciones, frecuencia real y recuperación **antes** de aceptar `TrainingProfile.next_focus` — nunca `next_focus === X → generar(X)` directamente. Ver `App\Training\Engine\TrainingEngine::decideFocus()` y `docs/DECISIONS.md` (D018).
- **Inmutabilidad histórica es obligatoria.** Ningún código debe modificar los campos `prescribed_*` ni `exercise_snapshot` de un `WorkoutExercise` después de creado, ni usar `WorkoutExercise->exercise` (la relación al catálogo vigente) para reconstruir lo que un usuario recibió — para eso existe `exercise_snapshot`, congelado por `Exercise::toSnapshot()` en el momento de generar la sesión. Cubierto por `tests/Feature/Training/WorkoutExerciseImmutabilityTest.php`.
- **Prescrito vs. ejecutado nunca se mezclan.** `WorkoutExercise` = lo que el Training Engine decidió y mostró. `ExerciseLog`/`ExerciseSet` = lo que el usuario reportó. Ninguna escritura de ejecución debe tocar una columna `prescribed_*`.
- **Acceso y seguridad pasan siempre por `TrainingAccessGate`.** Ningún Handler ni servicio debe generar contenido de entrenamiento sin invocar `TrainingAccessGate::authorize()` primero. `TrainingHandler` lo verifica explícitamente antes de llamar a `TrainingEngine` (que también lo verifica internamente, como defensa en profundidad) — no introducir un tercer mecanismo de verificación en paralelo.
- **El LLM nunca decide seguridad por sí solo.** `SafetySignalDetector::detect()` es un backstop determinista de patrones, evaluado siempre sobre el texto crudo del mensaje **y**, durante onboarding, sobre `safety_signal_text` (una frase que el LLM puede señalar como parte de su JSON de extracción). Ninguna de las dos fuentes decide por sí sola — el bloqueo real siempre pasa por `TrainingProfile::flagForSafetyReview()` (determinista). Solo una acción humana explícita puede llamar a `clearSafetyFlag()` — nunca código automático ni una nueva respuesta del usuario.
- **El LLM nunca decide onboarding, solo extrae y redacta.** Desde el Hito 5.1, `App\Training\Support\OnboardingConversationService::extractAndRespond()` hace Extract+Narrate en una sola llamada — `extracted.*` se valida contra el vocabulario permitido exactamente igual que antes (un valor inválido se descarta, nunca se persiste). El `next_action`/`response` que devuelve son solo una **señal**: qué campo preguntar a continuación lo sigue decidiendo `TrainingProfile::firstMissingOnboardingField()` (determinista, sin cambios); `OnboardingConversationService::resolveQuestion()` es quien compara el `next_action` del modelo contra el campo real que el código determinó — solo si coinciden se usa la redacción de la IA, si no, se usa `FALLBACK_QUESTIONS`. No agregar nunca una ruta donde el valor `next_action`/`response` del modelo se use sin pasar por esa verificación. Ver D026.
- **`restrictions` y `safety_signal_text` son independientes en el prompt de onboarding.** No volver a redactar el prompt de forma que "cualquier mención de dolor" apunte solo a `safety_signal_text` — un hallazgo real del Hito 8 mostró que eso impedía completar `restrictions` con limitaciones físicas ordinarias. `safety_signal_text` debe seguir acotado a las mismas categorías que `SafetySignalDetector` ya reconoce como urgencia real.
- **El mensaje de entrega del entrenamiento se construye sin LLM.** Ver `TrainingHandler::buildWorkoutMessage()` — texto determinista desde `WorkoutExercise`, para que el LLM nunca pueda alterar series/repeticiones/cargas al comunicarlas.
- **`Exercise` es catálogo global.** No agregar `tenant_id` a `Exercise` sin una decisión explícita — ver D018.
- **Clasificación de intents de Training es determinista, sin LLM** (`TrainingIntentClassifier`) — no agregar una llamada de IA a la clasificación sin una decisión explícita, ya que corre en el camino de cada mensaje entrante. Tres señales, todas deterministas: palabra clave, `TrainingProfile` incompleto, o `WorkoutSession` pendiente (`scheduled`) para el `Contact`. Ver D019, D020.
- **Un reporte de ejecución nunca se resuelve contra el catálogo `Exercise` completo.** `ExecutionReportRecorder` solo compara contra los `WorkoutExercise` sin reportar de la `WorkoutSession` activa — es la regla que hace imposible registrar un ejercicio que no pertenece a la sesión. No cambiar esto a una búsqueda global "por conveniencia".
- **El LLM nunca decide RPE por sí solo.** Solo puede clasificar lenguaje cualitativo ("estuvo pesado") en una de las categorías cerradas de `App\Training\Enums\RpeCategory`; la conversión a un número (1-10) es una tabla determinista en `ExecutionReportService`. Un RPE nunca se asume si el usuario no lo expresó de ninguna forma.
- **No completar automáticamente series/reps/carga/RPE faltantes.** Si un reporte no trae ningún dato cuantificable ni cualitativo, `ExecutionReportRecorder` pregunta en vez de inferir — nunca usa la prescripción (`WorkoutExercise`) como sustituto de lo que el usuario no dijo.
- **Toda conversación de Training pasa por `TrainingHandler::logInbound()`/`reply()`.** No llamar a `WhatsAppService::sendMessage()` directamente desde un subflujo nuevo — eso rompería la persistencia en `WhatsAppMessage` que Hito 6 resolvió como deuda de Hito 5.
- **Memoria conversacional (`WhatsAppMessage`) y historial de entrenamiento (`ExerciseLog`/`ExerciseSet`) nunca se mezclan.** Ningún código debe escribir en ambas desde el mismo punto pensando que son la misma fuente de verdad — se correlacionan solo a través de `Contact`.
- **`isOnboardingComplete()`/`firstMissingOnboardingField()` reciben `Contact` (Hito 8.3).** El nombre vive en `Contact.customer_name`, no en `TrainingProfile` — cualquier llamada nueva a estos métodos debe pasar el `Contact` correcto, nunca asumir que basta con el perfil.
- **Los datos físicos (`age`/`sex`/`weight_kg`/`height_cm`) nunca bloquean el onboarding, y no tienen ningún efecto de prescripción todavía — no inventar uno.** Se capturan una sola vez (`TrainingProfile.physical_stats_asked`) y se aceptan aunque el usuario no responda. Antes de que cualquiera de estos 4 campos module algo en `TrainingEngine`, debe existir una justificación técnica documentada (ver D033/Hito 8.2, Parte 12) — nunca una regla como "sexo → ejercicios X".
- **`equipment_fully_equipped` no significa "tiene literalmente todo".** Representa que el usuario declaró disponibilidad amplia sin enumerar, condicionado a `training_location` — nunca asumir que un gimnasio cualquiera tiene cualquier aparato específico sin que el propio dominio (Training Engine, cuando lo consuma) resuelva un conjunto razonable por ubicación.
- **`ExecutionReportService::buildPrompt()` debe seguir tratando una confirmación sin detalle ("hecho", "listo") como un reporte real** (`exercise_name: null`, `sets: []`), nunca como "sin reportes" — es exactamente el defecto real corregido en Hito 8.3. No revertir esa regla del prompt sin un caso de prueba que la respalde.
- **El orden de prioridad de `TrainingEngine::selectExercises()` es fijo: elegibilidad → foco → objetivo/nivel → anti-repetición → determinismo (Hito 8.4, ver D034).** No convertirlo en un sistema de pesos numéricos calibrados a mano sin preservar la garantía estructural de que la anti-repetición nunca puede hacer ganar a un ejercicio peor en foco/nivel por ser distinto — esa garantía depende de que los niveles se evalúen en ese orden estricto, no de un umbral ajustable.
- **`primary_focus`/`secondary_focus` usan el mismo criterio `null`/`[]` que `restrictions`/`available_equipment`.** `primary_focus` tiene pregunta dedicada obligatoria en el onboarding — no lo conviertas en puramente oportunista. `secondary_focus` es representación interna: nunca exponer esos dos nombres técnicos en un mensaje al usuario.
- **`Exercise.primary_muscle`/`secondary_muscles` son independientes de `muscle_group`.** No los uses como reemplazo del mecanismo de rotación por continuidad (`TrainingEngine::ROTATIONS`/`decideFocus()`) — son ejes ortogonales (rotación gruesa para continuidad vs. foco fino para prioridad del usuario).
- **`GOAL_DEFAULTS` (`TrainingEngine`) son heurísticas de producto, no una prescripción científica.** Documentarlas siempre como revisables en el código si se ajustan; nunca presentarlas a un cliente/usuario como una tabla validada clínicamente.
- **No sembrar ejercicios ficticios en producción** para "demostrar" personalización por foco — el catálogo real de producción no tiene todavía `primary_muscle`/`secondary_muscles` poblados (ver D034); esa demostración depende del catálogo real de Hito 9. En tests, usar siempre `Exercise::factory()->withPrimaryMuscle()`/`withSecondaryMuscles()`.
- **`TrainingEngine`/`TrainingHandler`/`MediaResolver` nunca conocen un proveedor concreto (Hito 9, D036).** Solo `App\ExerciseCatalog\Contracts\ExerciseProviderInterface` y `ProviderRegistry`. Verificado por `tests/Feature/ExerciseCatalog/MultiProviderIsolationArchTest.php` — si necesitas referenciar YMove (o cualquier proveedor) fuera de `App\ExerciseCatalog\Providers\{ese proveedor}`, es una señal de que el acoplamiento está en el lugar equivocado.
- **`Exercise.provider` es un `string` simple, nunca un enum de dominio ni una tabla `providers`.** Un proveedor nuevo es una clase Adapter + Normalizer + una entrada en `config/exercise_providers.php` — nunca una migración ni un cambio en `TrainingEngine`.
- **La URL de video de un proveedor NUNCA se persiste, ni siquiera de paso.** `Exercise` guarda solo `provider`+`provider_exercise_id`; la URL se resuelve fresca en el momento del envío vía `MediaResolver`. Esto está reforzado a nivel de modelo (`Exercise::booted()` lanza si `provider !== null && video_url !== null`), no solo documentado.
- **Ningún `Exercise` de proveedor se activa automáticamente.** `ExerciseImporter` siempre crea con `is_active=false`, `contraindications=null`. La única vía a `is_active=true` es `Exercise::activate(User $reviewer)`, y lanza si `contraindications` sigue en `null` — nunca asumas `[]` solo porque el proveedor no informó nada.
- **Un re-sync de un ejercicio ya existente nunca toca `is_active` ni `contraindications`.** Preserva siempre la revisión humana ya hecha — solo actualiza metadata.
- **`common_mistakes`/`breathing_cue` siguen el mismo criterio de preservación que `contraindications` (Hito 9.2, D037).** Ningún proveedor auditado los provee — cualquier valor real vino de una curación humana; `ExerciseImporter` nunca los toca, ni siquiera al crear. `instructions`/`important_points` sí vienen del proveedor y sí se refrescan en cada re-sync, igual que `name`.
- **`instructions` vacío bloquea `Exercise::activate()`, igual que `contraindications=null` — pero `important_points`/`common_mistakes`/`breathing_cue` en `null` nunca bloquean.** Asimetría deliberada: sin pasos no hay ejercicio que mostrar; sin un tip de respiración, sí lo hay. No inviertas esta regla sin revisar D037.
- **La información técnica de un ejercicio se presenta con `App\Training\Support\ExerciseMessageFormatter`, nunca con texto armado a mano dentro de `TrainingHandler`.** Lee exclusivamente de `WorkoutExercise.exercise_snapshot` — nunca del `Exercise` en vivo (rompería la inmutabilidad histórica) ni de un proveedor directamente.
- **Cualquier adapter de proveedor que necesite "localizar un id concreto" debe paginar de verdad, nunca asumir que vive en la primera página (fix post-E2E, D039).** El bug real de `YMoveExerciseProvider::fetchAndLocate()` era exactamente esto — un catálogo de 1068 ítems con solo ~20 por página. Si agregas un proveedor nuevo con el mismo patrón de "listar y buscar por id" (sin endpoint `/exercises/{id}`), replica las dos fases de `fetchAndLocate()`/`fetchPage()`: localizar la página en modo gratuito recorriendo `totalPages` real, y solo entonces (si hace falta) repetir esa única página en el modo que cueste cuota.
- **`Exercise.name_es`/`instructions_es`/`important_points_es` nunca se generan ni se leen en tiempo de envío — solo en curación manual (Filament) y en `Exercise::toSnapshot()` (D039).** Si necesitas contenido en otro idioma, no le pidas a `TrainingEngine`/`TrainingHandler`/`ExerciseMessageFormatter` que sepan de esto — siguen leyendo únicamente el snapshot ya congelado; el único punto que decide español-vs-original es `toSnapshot()`.
- **`App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator` nunca debe leer `provider_metadata` ni mencionar un proveedor concreto** — opera solo sobre los campos ya normalizados de `Exercise`. Cualquier resultado de la IA se valida por código (mismo número de pasos/puntos que el original) antes de aceptarse — nunca confíes la fidelidad estructural solo al prompt.
- **No existe todavía una configuración de IA independiente de un `Tenant`.** `AIServiceFactory::make()` siempre requiere un `Tenant` real con `ai_api_key`. Cualquier acción GLOBAL (no ligada a un tenant, como la curación de `Exercise`) que necesite IA debe pedir explícitamente de qué `Tenant` tomar la credencial (ver el selector en `ExerciseResource`) — no inventes una segunda vía de construir `AiServiceInterface` ni un config paralelo hasta que exista la pieza real de "Provider Management"/config de IA a nivel de sistema.
- **`OnboardingConversationService::extractAndRespond()` recibe `$pendingField` (el campo real pendiente, calculado por `TrainingProfile::firstMissingOnboardingField()`), solo como contexto para el prompt — nunca cambia quién decide (D039).** Si agregas un campo nuevo al onboarding, añádelo también a `OnboardingConversationService::PENDING_FIELD_LABELS` para que la IA sepa a qué pregunta responde un mensaje corto y ambiguo; no hardcodees frases o nombres de zona muscular específicos en el código, esas reglas viven como instrucciones generalizadas en el prompt.

## Dominio Payments (Hito 8)

- **`TrainingAccess` solo se modifica desde `PaymentConfirmationService`.** `PaymentHandler` no debe importar `App\Models\TrainingAccess` bajo ninguna circunstancia — verificado por `tests/Feature/Payments/PaymentIsolationArchTest.php`, no solo por convención. Confirmar/rechazar un pago SIEMPRE pasa por ese servicio, nunca por una escritura directa desde un Handler o una acción de Filament.
- **La IA nunca confirma un pago.** `ReceiptExtractionService` (Extract) solo puede devolver `null` para un campo no legible — nunca decide si el monto es correcto ni si el pago es válido. `PaymentValidationService` (Decide, determinista, sin IA) solo produce `validation_flags`; la decisión de `confirmed`/`rejected` es siempre una acción humana explícita (`is_super_admin`) o, en el futuro, un webhook de pasarela verificado — nunca el LLM.
- **Un `Payment` nunca se crea ni se edita a mano desde Filament.** `PaymentResource` no tiene páginas de crear/editar a propósito — solo se revisa (Confirmar/Rechazar). Si algún día hace falta editar un campo, es una señal de que falta un flujo conversacional o administrativo real, no un formulario genérico.
- **Nunca hardcodear Colombia/COP en `App\Payments\*`.** Toda configuración de país/moneda/métodos vive en `Tenant` (`currency`, `country`, `monthly_price`, `nequi_number`, `daviplata_number`, `gateway_provider`). Un método sin configurar (`null`) simplemente no se ofrece — no usar un valor por defecto de negocio dentro del código.
- **Visión de IA usa un modelo dedicado, nunca `Tenant.ai_model`.** `AiServiceInterface::analyzeImage()` en cada servicio (`OpenAIService`/`GrokService`/`GeminiService`) usa una constante de modelo propia (`VISION_MODEL`), verificada empíricamente por proveedor — el modelo de chat del Tenant se elige por costo (ver `AIServiceFactory::DEFAULT_MODELS`) y no tiene por qué soportar visión.
- **Un comprobante es evidencia de auditoría — nunca se borra.** A diferencia del audio de `Ingest` (transitorio, se borra tras transcribir), `PaymentHandler::persistReceiptFile()` copia el archivo descargado a una ubicación permanente (`receipts/{tenant_id}/...`) antes de que cualquier limpieza posterior pudiera afectarlo.
- **Safety Review comparte la misma convención que Payment.** `TrainingProfile::clearSafetyFlag(User $reviewer, string $note)` tiene exactamente el mismo shape que `PaymentConfirmationService::confirm()`/`reject()` (reviewer + nota + timestamp) — si se agrega un tercer flujo de "revisión humana" en el futuro, debe seguir el mismo patrón, no inventar uno nuevo.
- **`PaymentConfirmationService::confirm()`/`reject()` son idempotentes (ajuste de Hito 8).** Verifican `$payment->status` antes de escribir nada — una segunda llamada sobre un Payment ya resuelto es un no-op completo (no re-extiende `TrainingAccess`, no re-notifica). Cualquier canal nuevo que llegue a estos métodos (Filament hoy; un futuro comando de WhatsApp del superadmin, no implementado) hereda la protección gratis — **nunca dupliques esta verificación en el canal**, la guarda vive únicamente aquí.
- **La notificación al cliente nunca vive en `PaymentHandler` ni en Filament.** `PaymentConfirmationService` llama a `CustomerNotifier::notify()` después de persistir el cambio de estado — ver "Cómo notificar a un cliente desde un dominio nuevo" más abajo.
- **La invitación a entrenar tras confirmar (`training_invite`) nunca crea una `WorkoutSession`.** Es solo un mensaje — si el usuario responde, su mensaje entra por el Router/Dispatcher normal, como cualquier otro. No agregues código que interprete la respuesta como una confirmación implícita de inicio de entrenamiento; eso es responsabilidad de `TrainingIntentClassifier`/`TrainingHandler`, sin cambios especiales por venir de esta invitación.
- **Una respuesta genérica ("Sí", "Dale") tras activar el acceso SÍ debe llegar a Training** (Hito 8.1, hallazgo real: sin esto caía en `fallback_chat`) — vía `TrainingIntentClassifier::hasActiveAccessAwaitingFirstWorkout()` (`TrainingAccess.status = Active` + cero `WorkoutSession`). Es una señal puntual para este caso concreto, no un mecanismo general — no la reutilices como base para otros estados conversacionales futuros sin evaluarlo aparte.

## Observabilidad del pipeline real (Hito 7)

Logs estructurados (sin dashboard, ver D021) ya integrados en el camino real de un mensaje:

| Log | Dónde | Qué mide |
|---|---|---|
| `JOB_START` / `JOB_END` | `app/Jobs/ProcessWhatsAppMessage.php` | Tiempo total del pipeline (`elapsed_ms`) y su resultado (`outcome`). |
| `ROUTER_CLASSIFIED` | `app/Jobs/ProcessWhatsAppMessage.php` | Intent resuelto y tiempo de `Router::route()`. |
| Log existente de transcripción (éxito/fallo) | `app/Core/Messaging/Ingest.php` | Ahora incluye `elapsed_ms` de la llamada a Whisper. |
| `CONTEXT_BUILDER_RESULT` | `App\Training\Handlers\TrainingHandler::buildContext()` | Clave pedida, `confidence` del fragment, si trajo datos, tamaño aproximado en bytes. |
| `TRAINING_ENGINE_DECIDED` | `App\Training\Handlers\TrainingHandler` | Tiempo de `TrainingEngine::decideNextSession()` y cuántos ejercicios decidió. |
| `TRAINING_VIDEO_SENT` / `TRAINING_VIDEO_SEND_FAILED` | `App\Training\Handlers\TrainingHandler` | Tiempo de `WhatsAppService::sendWhatsAppVideo()` por cada video. |
| `TRAINING_META_SEND_FAILED` | `App\Training\Handlers\TrainingHandler::reply()` | Cuando Meta rechaza un envío saliente de Training. |
| `TRAINING_REPORT_*` | `App\Training\Handlers\TrainingHandler` (Hito 6) | Intentos/éxitos/incompletos/duplicados de reporte de ejecución. |

Nueva instrumentación siempre debe ser **aditiva** (un `Log::info`/`Log::warning` más) — nunca cambiar el valor de retorno ni el comportamiento de un método solo para poder medirlo.

## Prueba E2E real con Meta/WhatsApp

Ver **`docs/E2E_META_RUNBOOK.md`** para el checklist completo (credenciales necesarias, cómo levantar el túnel con `herd share`, cómo crear un `Tenant`/`Exercise` de prueba, y los escenarios A-E a ejecutar). El entorno de desarrollo actual no tiene credenciales de Meta configuradas — ver D021.

## Cómo emitir una alerta desde un dominio nuevo (Hito 7.1)

```php
app(\App\Core\Alerts\AlertService::class)->send(new \App\Core\Alerts\Alert(
    category: 'payments',                                  // string libre — el nombre del dominio/subsistema
    severity: \App\Core\Alerts\AlertSeverity::Warning,      // Info | Warning | Critical (solo Warning/Critical llegan a WhatsApp)
    message: 'Pago #23 pendiente de verificación',
    context: ['tenant_id' => $tenant->id, 'payment_id' => 23],
));
```

- Nunca construyas un mensaje de WhatsApp ni resuelvas un destinatario en tu propio dominio — `AlertService` y sus canales (`App\Core\Alerts\Channels\*`) son los únicos que conocen ese detalle.
- Incluye `tenant_id` en `context` si tu alerta debe entregarse por WhatsApp (`WhatsAppAdminAlertChannel` no puede resolver un emisor Meta sin él).
- No incluyas nunca un valor sensible en `context` bajo una clave que contenga `key`/`token`/`password`/`secret`/`credential` — `Alert` lo redacta automáticamente, pero es mejor no depender de eso: pasa solo identificadores (IDs, nombres, razones).

## Cómo notificar a un cliente desde un dominio nuevo (Hito 8, ajuste)

`AlertService` es para operadores/superadmins. Si en cambio necesitas avisarle algo **al cliente final** (transaccional, no una alerta administrativa), usa `App\Core\Notifications\CustomerNotifier`:

```php
app(\App\Core\Notifications\CustomerNotifier::class)->notify(
    tenant: $tenant,
    to: $contact->customer_phone,
    eventKey: 'payment_confirmed',                 // string libre — ver más abajo cómo registrar uno nuevo
    variables: ['amount' => '50.000 COP', 'method' => 'Nequi'],
    freeFormText: '✅ Tu pago fue confirmado. Tu acceso ya está activo. 💪',
);
```

- **Nunca** construyas tú mismo la llamada a Meta ni decidas mensaje-libre-vs-plantilla en tu dominio — `CustomerNotifier` es el único que conoce `Conversation.last_session_at`/la regla de ventana (23h30m) y `WhatsAppService`.
- `freeFormText` es el mensaje que se envía tal cual si la ventana de 24h sigue abierta — la misma redacción humana que ya usarías en un `PaymentHandler::reply()`.
- `variables` son valores YA resueltos por tu dominio (nunca resuelvas tú un `parameters_map` ni sepas de posiciones `{{1}}`, `{{2}}`) — si la ventana está cerrada, `CustomerNotifier` los cruza contra el `parameters_map` de la `WhatsAppTemplate` que un operador registró para ese `eventKey`.
- **Para que la plantilla exista**: un operador (o superadmin) crea/edita una fila en `WhatsAppTemplate` (Filament → WhatsApp Templates) con el `name`/`language` reales aprobados por Meta, y selecciona tu `eventKey` en el campo "Evento del sistema". Si no existe ninguna plantilla para ese `(tenant_id, eventKey)`, `CustomerNotifier` solo loguea (`CUSTOMER_NOTIFIER_TEMPLATE_NOT_CONFIGURED`) y no revienta — pero tampoco entrega nada fuera de ventana hasta que se configure.
- Un fallo de entrega (plantilla no configurada, Meta la rechaza, error de red) **nunca** se propaga — mismo contrato que `AlertService::send()`. No envuelvas la llamada en tu propio try/catch, ya es innecesario.
- `eventKey` es un string libre a propósito (mismo criterio que `Alert::category`) — no hace falta tocar `CustomerNotifier` para agregar un evento nuevo, solo definir el nombre en tu dominio y registrar la plantilla correspondiente en Filament.
- Un canal nuevo (email, push, Slack) se agrega implementando `AlertChannelInterface` y registrándolo en el array de `AppServiceProvider` — igual que agregar un `IntentClassifier` o un `ContextProvider`.

## Convenciones de nombres

- `Tenant` (no `Store`): la unidad de aislamiento multi-tenant.
- `Contact` (no `Lead`): la persona que escribe por WhatsApp. Un único modelo con `status`/flags, no una tabla por cada etapa del embudo.
- Todo FK a tenant se llama `tenant_id` (no `store_id`).

## Reglas para nuevos módulos

- Un módulo de Domain nuevo no debe requerir cambios en clases de Core — si los requiere, es señal de que falta una interfaz/mecanismo genérico en Core.
- No introducir vocabulario de un vertical específico (fitness, retail, etc.) en `App\Models`, `App\Services`, o cualquier clase que hoy vive fuera de un futuro namespace de Domain.

## Reglas para IA/LLM

- El LLM se usa para comprensión del lenguaje, extracción de datos estructurados, conversación libre y narración de resultados — **no** para decidir ni ejecutar acciones críticas de negocio directamente.
- Todo intent debe resolverse a través de un Handler determinista antes de que ocurra cualquier efecto secundario (crear/actualizar un registro, enviar un mensaje, cobrar un pago).
- Cuando el LLM extraiga un parámetro de una instrucción en lenguaje natural (ej. "hoy solo tengo 30 minutos"), la decisión de qué hacer con ese parámetro la toma un servicio determinista, no el propio LLM. Ver `docs/DECISIONS.md` (D007).

## Qué NO debe hacerse

- No reintroducir conceptos de retail/e-commerce (precio, stock por unidades, catálogo de venta) en el Core.
- No acoplar el Core a fitness ni a ningún otro vertical específico.
- No dejar lógica de negocio en el Router — el Router solo prueba clasificadores registrados y despacha, nunca contiene vocabulario de un dominio específico (ver `App\Core\Messaging\Router`, Hito 2/5).
- No agregar nuevas claves de dominio al `$context` opaco de `Dispatcher`/`HandlerInterface` desde Core — es un mecanismo transitorio (ver `docs/DECISIONS.md`, D016), no un contrato a extender libremente.
- No agregar propiedades a `ExecutionContext` "por si después hacen falta", ni cargar `Contact` u otros modelos de dominio dentro de él — solo lo que el pipeline actual usa de verdad (ver `docs/DECISIONS.md`, D017).
- No registrar un `ContextProvider` sin un consumidor real en el mismo cambio, ni convertir `ContextBuilder` en un mecanismo que resuelve "todo lo disponible" — solo las claves que el Handler pide explícitamente.
- No usar el LLM para decisiones críticas de negocio sin una regla determinista detrás.
- No crear `TrainingPlan`, `ExerciseCategory`, `Equipment`, una entidad de restricción normalizada, `Progress` almacenado, `SafetyRule`, ni `Subscription`/`Payment`/`Invoice`/`Enrollment` sin detenerse y justificarlo — ninguna tiene consumidor real hoy (ver D018).
- No dejar que `next_focus` actúe como autoridad absoluta del Training Engine, ni mezclar `prescribed_*` (Training Engine) con lo reportado por el usuario (`ExerciseLog`/`ExerciseSet`).
- No crear un Intent nuevo por cada subflujo de Training (reportar, finalizar sesión, consultar ejercicio, etc.) — se resuelven dentro de `TrainingHandler`, el Router se mantiene con `training`/`fallback_chat` únicamente (ver D020).
- No dejar que un reporte de ejecución se resuelva contra un ejercicio fuera de la sesión activa, ni sobrescribir/duplicar un `ExerciseLog` ya existente para el mismo `WorkoutExercise`.
- No conservar código simplemente porque ya existe — si un componente no aporta valor verificado, se documenta y se elimina (ver `docs/DECISIONS.md`).

## Proceso de desarrollo por hitos

Cada hito tiene un alcance escrito y acotado antes de tocar código (ver los planes de hito). Dentro de un hito:

1. Se audita/verifica el estado real antes de asumir nada (referencias, dependencias, uso dinámico).
2. Se ejecuta una línea base de tests antes de cualquier cambio.
3. Los cambios se limitan al alcance escrito; cualquier hallazgo que implique ampliar el alcance o revisar una decisión previa se reporta y se pausa para aprobación, no se decide unilateralmente.
4. Se cierra con: suite de tests, migraciones desde cero, y un informe de cambios/decisiones/riesgos pendientes.

## Entorno local (decisión D010)

- Laravel/PHP y Node/Vite corren **nativos** en el host (no en Docker).
- MySQL y Redis corren en **Docker Desktop**. Para este proyecto, el contenedor MySQL de `docker-compose-phpmyadmin.yml` (`mysql_local`, puerto 3306, sin password) ya cubre esa necesidad — se crearon las bases `wpbottrainer` (desarrollo) y `wpbottrainer_test` (tests).
- SQLite **no** es la base de datos de referencia del proyecto — algunas migraciones (ej. `products`, índice FULLTEXT) dependen de características específicas de MySQL.
- La infraestructura Docker de producción se define en un hito posterior.
