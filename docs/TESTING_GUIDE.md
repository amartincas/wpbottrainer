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
| **mensajes `image` (D027)** | `tests/Feature/WhatsAppWebhookTest.php` (+3, Hito 8) — un mensaje `type: image` extrae `mediaId` y despacha el Job igual que `audio`/`voice` (defecto real encontrado en el E2E de Payments: antes caía en silencio, sin log ni Job); un caption presente se conserva como `messageBody`; sin `mediaId` ni caption, se ignora igual que un mensaje vacío. |

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
| **TrainingProfile** | `TrainingProfileTest.php` — casts de enums/arrays (incluidos `primary_focus`/`secondary_focus`, Hito 8.4), `flagForSafetyReview()`/`clearSafetyFlag()`, ausencia de `access_status`. |
| **Exercise** | `ExerciseTest.php` — casts, `is_active` como mecanismo de retiro (nunca borrado físico), `toSnapshot()` (incluye `primary_muscle`/`secondary_muscles` desde Hito 8.4). |
| **WorkoutSession** | `WorkoutSessionTest.php` — relación con `Contact`, orden de `workoutExercises`, estados `scheduled`/`completed`/`skipped`. |
| **WorkoutExercise + snapshot** | `WorkoutExerciseImmutabilityTest.php` — editar el `Exercise` en vivo **no** cambia el `exercise_snapshot` ya guardado; borrar el `Exercise` referenciado (`nullOnDelete`) no destruye el `WorkoutExercise` ni su snapshot; los campos `prescribed_*` no se sobrescriben. |
| **ExerciseLog / ExerciseSet** | `ExerciseLogAndSetTest.php` — reproduce el caso de la sentadilla con reps/carga distintas por serie (10×40kg, 10×45kg, 8×50kg); ejercicios por tiempo (`actual_duration_seconds`); una sesión puede reportarse parcialmente (no todo `WorkoutExercise` tiene `ExerciseLog`). |
| **prescrito vs. ejecutado** | `ExerciseLogAndSetTest.php` — registrar un `ExerciseLog`/`ExerciseSet` con valores muy distintos a lo prescrito nunca modifica las columnas `prescribed_*` del `WorkoutExercise`. |
| **TrainingAccess / acceso-expiración** | `TrainingAccessTest.php` — `isCurrentlyValid()` para activo/trial/expirado/revocado/con fecha pasada; verificación de que la tabla no contiene ningún campo de facturación. |
| **safety gate** | `TrainingAccessGateTest.php` + `SafetySignalDetectorTest.php` — el Gate bloquea por `no_access`/`access_invalid`/`safety_flagged` (seguridad tiene prioridad incluso con acceso vigente) y permite solo cuando ambas fuentes están en orden; el detector reconoce frases de alarma conocidas (case-insensitive) y no confunde una restricción normal ("me duele la rodilla") con una señal de alarma. |
| **Training Engine — decisión** | `TrainingEngineTest.php` — bloqueo por `TrainingAccessDeniedException` (sin acceso, perfil marcado); idempotencia (sesión pendiente se devuelve sin cambios); generación respetando restricciones/equipamiento; progresión de carga/duración según RPE reportado; rotación evita repetir el foco de la sesión completada inmediatamente anterior. Ampliado sustancialmente en Hito 8.4 (foco declarado, objetivo/nivel, anti-repetición, A/B/C) — ver sección dedicada más abajo. |

## Cobertura del primer flujo conversacional de Training (Hito 5)

25 tests nuevos: `RouterTest.php` +3 (mecanismo de clasificadores), y 22 en `tests/Feature/Training/`:

| Categoría pedida | Dónde |
|---|---|
| **detección de training** | `TrainingIntentClassifierTest.php` — palabras clave; contacto con onboarding incompleto se sigue clasificando como training aunque el mensaje no tenga palabras clave; un perfil ya completo no fuerza el intent. |
| **fallback** | Cubierto por la ausencia de cambios en `ProcessWhatsAppMessageJobTest.php` (sigue en verde, sin modificar) — un mensaje sin señal de training sigue resolviendo a `FallbackChatHandler` exactamente igual que antes. |
| **onboarding** | `TrainingConversationFlowTest.php` — primer contacto sin perfil recibe la primera pregunta; progresivo turno a turno sin repetir lo ya conocido; se completa en un solo mensaje si el usuario da todo de una vez; flujo completo de 5 turnos con conteo exacto de llamadas HTTP a IA (Hito 5.1 — ver abajo). |
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

## Cobertura de Hito 8.3 (onboarding ampliado, "hecho", skip_reason)

28 tests nuevos, repartidos en los archivos ya existentes de Training (sin archivos de test nuevos):

| Categoría | Dónde |
|---|---|
| **nombre + training_location + equipo amplio en un solo mensaje** | `OnboardingConversationServiceTest.php` — "Me llamo Ana, entreno en un gimnasio y tengo de todo" extrae `name`/`training_location`/`equipment_fully_equipped` los tres a la vez, sin inventar una lista de equipo. |
| **equipo específico vs. amplio** | `OnboardingConversationServiceTest.php` — "solo pesas" deja `available_equipment=['pesas']` y `equipment_fully_equipped=null` (nunca `true`) — la declaración de "todo" y la mención de equipo puntual se distinguen. |
| **validación de rango en datos físicos** | `OnboardingConversationServiceTest.php` — edad/peso/estatura fuera de un rango plausible se descartan, nunca se persisten tal cual. |
| **prompt explícito para equipo ambiguo** | `OnboardingConversationServiceTest.php` — aserción directa sobre el contenido del prompt enviado al proveedor de IA (mismo patrón que la independencia restrictions/safety_signal_text del Hito 5.1/D026). |
| **`isOnboardingComplete`/`firstMissingOnboardingField` con `Contact`** | `TrainingProfileTest.php` — `training_location` bloquea; edad/sexo/peso/estatura nunca bloquean; el nombre (`Contact.customer_name`) es el primer campo verificado, antes que cualquier columna de `TrainingProfile`. |
| **"preguntar una sola vez" los datos físicos** | `TrainingConversationFlowTest.php` — turno 3 del flujo progresivo: el usuario declina ("prefiero no decir esos datos") y el onboarding queda completo de todos modos; secuencia completa de 7 turnos (antes 5) con exactamente 1 llamada de IA por turno, sin excepción. |
| **fix real de "hecho"** | `ExecutionReportServiceTest.php` (aserción de prompt) + `ExecutionReportFlowTest.php` (flujo completo) — una confirmación sin detalle produce un reporte con `exercise_name: null`/`sets: []` en vez de `reports: []`; verificado de punta a punta que la sesión **nunca** se reenvía (`WorkoutSession.status` permanece `scheduled`, no se reenvía ningún video) y en cambio se pide la aclaración de series/repeticiones ya existente. |
| **`skip_reason`** | `ExecutionReportServiceTest.php` — solo se valida cuando `not_performed=true`; se descarta si el LLM lo envía junto a `not_performed=false`; un valor fuera del enum se descarta. `ExecutionReportFlowTest.php` — "no pude" (`cant_do`)/"no quiero" (`dont_want`) distinguibles de punta a punta; sin razón dada, `skip_reason` queda `null`, nunca inventado. |

## Cobertura de Hito 8.4 (objetivos específicos y personalización real por foco muscular)

35 tests nuevos netos (ver D034) — 10 en `TrainingEngineTest.php` (primer archivo con cobertura dedicada a `selectExercises()`/`progressionFor()` más allá de lo heredado de Hito 4), 9 en `OnboardingConversationServiceTest.php`, 4 en `TrainingProfileTest.php`, 1 en `ExerciseTest.php`; 2 tests existentes ajustados por consecuencia directa (`TrainingEngineTest`: `goal` fijado explícitamente donde antes era aleatorio; `TrainingConversationFlowTest`: turnos reordenados/ampliados para el nuevo campo obligatorio `primary_focus`).

| Categoría | Dónde |
|---|---|
| **foco simple** | `TrainingEngineTest.php` — con `primary_focus=['glutes']`, un ejercicio con `primary_muscle=glutes` es priorizado sobre el pool general. |
| **foco compuesto ("piernas")** | `TrainingEngineTest.php` — `primary_focus=[quads,hamstrings,glutes,calves]` selecciona los 3 ejercicios que matchean cualquiera de esos valores, no solo uno. |
| **foco + objetivo combinados** | `TrainingEngineTest.php` — un ejercicio de foco es seleccionado Y prescrito con los `GOAL_DEFAULTS` del objetivo vigente (verificado sobre `WorkoutExercise.prescribed_sets`/`rest_seconds` reales, no solo el ejercicio elegido). |
| **foco insuficiente (fallback)** | `TrainingEngineTest.php` — con `Log::spy()`, se verifica que `TRAINING_FOCUS_FALLBACK` se registra exactamente una vez cuando el catálogo elegible no alcanza la garantía mínima, y la sesión se genera igual (nunca se bloquea). |
| **garantía de mayoría (≥2 de 3)** | `TrainingEngineTest.php` — con exactamente 2 candidatos de foco disponibles, ambos terminan seleccionados. |
| **anti-repetición nunca gana sobre mejor foco/nivel** | `TrainingEngineTest.php` — un ejercicio usado en la sesión inmediatamente anterior es desempatado (nunca excluido por otra razón) frente a 3 alternativas igual de válidas en foco y nivel. |
| **prioridad de `difficulty_level`** | `TrainingEngineTest.php` — con más candidatos que cupos, los que coinciden exactamente con `experience_level` ganan sobre uno de nivel distinto. |
| **`equipment_fully_equipped` como elegibilidad (2 tests dedicados)** | `TrainingEngineTest.php` — con `training_location=gym` explícito en ambos: `equipment_fully_equipped=true` resuelve un equipo EFECTIVO amplio (3 ejercicios que exigen equipo distinto y no enumerado son los 3 elegibles, sin enumerar nada); `equipment_fully_equipped=false`, mismo `training_location=gym`, exige enumeración explícita (`available_equipment`) — un ejercicio no enumerado queda excluido pese al mismo lugar. Cubre un fix real encontrado en la revisión (el flag nunca se consultaba antes de este hito) y confirma que `training_location` no es lo que decide la elegibilidad de equipo. |
| **A/B/C — trazabilidad causal, no solo diferencia** | `TrainingEngineTest.php` — 3 perfiles distintos en goal/experience_level/primary_focus/split_type/training_location/sessions_per_week sobre el mismo catálogo, con aserciones que atribuyen CADA diferencia de salida a su variable causante: `primary_focus` → qué ejercicio de foco entra; `experience_level` → por qué el ejercicio de foco del otro perfil queda excluido del pool general pese a ser elegible; `split_type` → qué grupos musculares entran al pool general (dos ejercicios elegibles y de nivel correcto quedan fuera de la sesión de C solo por su `muscle_group` frente a la rotación `push_pull_legs`); `goal` → los 3 valores de prescripción (`prescribed_sets`/`prescribed_reps`/`rest_seconds`) vía `GOAL_DEFAULTS`. Documenta explícitamente, dentro del propio test, que `training_location`/`sessions_per_week` se verifican como correctamente persistidos pero **no son consumidos por `TrainingEngine` todavía** — evita sugerir una causalidad inexistente. |
| **traducción de lenguaje natural al vocabulario `MuscleFocus`** | `OnboardingConversationServiceTest.php` — "quiero aumentar glúteos" → `['glutes']`; "todo por igual" → `[]` (respuesta válida, no `null`); un valor fuera del vocabulario cerrado se descarta sin tumbar los demás válidos del mismo array; foco compuesto ("piernas") + secundario de menor énfasis en el mismo mensaje. |
| **`ask_primary_focus` en `resolveQuestion()`** | `OnboardingConversationServiceTest.php` — mismo mecanismo genérico ya probado para otros campos (usa la redacción de la IA solo si `next_action` coincide con el campo realmente pendiente; fallback canónico en caso contrario). |
| **el prompt nunca expone `primary_focus`/`secondary_focus` como términos de cara al usuario** | `OnboardingConversationServiceTest.php` — aserción directa sobre el contenido del prompt enviado al proveedor de IA. |
| **`primary_focus` en `firstMissingOnboardingField`** | `TrainingProfileTest.php` — `null` bloquea, `[]` no; se pregunta justo después de `experience_level` y antes de `training_location`; `secondary_focus` nunca bloquea, ni siquiera en `null`. |
| **`Exercise::toSnapshot()` ampliado** | `ExerciseTest.php` — incluye `primary_muscle`/`secondary_muscles` congelados junto al resto de campos ya existentes. |

**Qué queda fuera de esta cobertura, explícitamente** (ver D034): ninguno de estos tests demuestra personalización por foco contra el catálogo **real** de producción — el único `Exercise` activo hoy no tiene `primary_muscle`/`secondary_muscles` poblados. Toda la cobertura anterior usa `Exercise::factory()->withPrimaryMuscle()`/`withSecondaryMuscles()` explícitos; la validación en producción depende del catálogo real de Hito 9.

**No cubierto por este hito** (explícitamente fuera de alcance, ver D033): ningún test verifica que `TrainingEngine` use los nuevos campos — eso es Hito 8.4, todavía no implementado.

## Cobertura de Hito 9.0 (cierre de consumidores reales) + 9.1 (provider abstraction)

36 tests nuevos netos (ver D036). 9.0 en los archivos ya existentes de Training; 9.1 en un directorio nuevo, `tests/Feature/ExerciseCatalog/`.

| Categoría | Dónde |
|---|---|
| **`sessions_per_week` → `split_type`** | `TrainingProfileTest.php` — las 3 franjas de `deriveSplitTypeFromSessionsPerWeek()` (≤3/4/5+). |
| **`training_location=outdoor` restringe a sin equipo** | `TrainingEngineTest.php` — excluye un ejercicio con equipo aunque el perfil declare `equipment_fully_equipped=true`; un perfil de gimnasio con el mismo ejercicio no se ve afectado (aísla que la regla es específica de `outdoor`). |
| **`secondary_focus` aislado** | `TrainingEngineTest.php` — `primary_focus=[]`, solo `secondary_focus` activo, con más candidatos generales que cupos: prueba que gana por su nivel, no por casualidad. Cierra el gap de cobertura señalado en la revisión de Hito 9. |
| **Contrato sin fugas de proveedor** | `ProviderRegistryTest.php` — `NullExerciseProvider` (nunca registrado en producción) satisface `ExerciseProviderInterface` completo sin conocer YMove; `ProviderRegistry` resuelve `ymove` desde config y lanza para una clave desconocida. |
| **Normalización real de YMove** | `YMoveExerciseNormalizerTest.php` — shape real auditado (incluido `difficulty: null`, caso observado en producción, nunca inventado); mapeo de músculo/equipo; heurística de `tracking_type` por palabra clave; `movement_pattern` siempre `null` (sin heurística); metadata cruda preservada sin filtrar al contrato normalizado. |
| **Adapter de YMove** | `YMoveExerciseProviderTest.php` — `Http::fake()` sobre el shape HTTP real (nunca la API real): filtro de búsqueda por músculo, degradación a colección vacía en error, resolución de variante por defecto vs. `white-background`, disponibilidad, listado de variantes. |
| **`MediaResolver`** | `MediaResolverTest.php` — un ejercicio manual (`provider=null`) resuelve su propia `video_url` sin ninguna llamada de red (`Http::assertNothingSent()`); un ejercicio de proveedor resuelve fresco cada vez; fallo del proveedor o proveedor desconocido degradan a `null`, nunca lanzan. |
| **Importer + curación obligatoria** | `ExerciseImporterTest.php` — un ejercicio nuevo entra `is_active=false`/`contraindications=null`; un re-sync actualiza metadata sin tocar `is_active`/`contraindications` ya revisados; un `provider_exercise_id` que deja de aparecer se desactiva sin borrarse; el comando `exercises:sync --muscle=` filtra por foco. |
| **Invariante `Exercise` de proveedor** | `ExerciseTest.php` — guardar un `Exercise` con `provider` y `video_url` a la vez lanza `DomainException` (a nivel de modelo, no solo documentado); un ejercicio manual conserva su `video_url` propia; `activate()` lanza si `contraindications` sigue en `null`, y solo activa tras una revisión explícita (incluida `[]`, nunca asumida). |
| **Aislamiento arquitectónico multi-proveedor** | `MultiProviderIsolationArchTest.php` — `arch()` sobre `TrainingEngine`/`TrainingHandler`/`MediaResolver`: ninguno puede usar `App\ExerciseCatalog\Providers`; más una comprobación literal de que el texto "ymove" no aparece en ninguno de los tres archivos. |

**Qué NO demuestra esta cobertura, explícitamente** (ver D036): ningún test llama a la API real de YMove (todo vía `Http::fake()`/`Http::sequence()`); no se importó ni activó ningún ejercicio real; la matriz de cobertura mínima del catálogo (Hito 9, sección 12 del diseño) queda para el paso de import estratégico, todavía no ejecutado.

## Cobertura de Hito 9.2 (técnica de ejecución por ejercicio)

16 tests nuevos netos (ver D037).

| Categoría | Dónde |
|---|---|
| **Formato del mensaje de técnica** | `ExerciseMessageFormatterTest.php` (nuevo, 9 tests) — nombre+prescripción numerados; prescripción por duración vs. reps/carga; `instructions`+`important_points` combinados como viñetas, acotados a 4; `breathing_cue`/`common_mistakes` (máx. 2) solo si existen; ninguna sección vacía cuando el campo es `null`; el video siempre se menciona, con o sin técnica. |
| **Backfill real de `instructions` (text→json)** | `InstructionsMigrationBackfillTest.php` (nuevo, contra MySQL real, no simulado) — un valor de texto plano (como el Exercise real de producción) sobrevive como array de un elemento; un valor ya JSON válido no se envuelve dos veces. |
| **Normalización de la técnica de YMove** | `YMoveExerciseNormalizerTest.php` — `importantPoints[]` se mapea; `common_mistakes`/`breathing_cue` siempre `[]`/`null` para YMove, incluso con `instructions`/`importantPoints` ricos — nunca inventados. |
| **Importer: refresco vs. preservación** | `ExerciseImporterTest.php` — `important_points` se guarda al crear; un re-sync refresca `instructions`/`important_points` pero preserva `common_mistakes`/`breathing_cue` curados a mano, igual que `contraindications`. |
| **Asimetría de activación** | `ExerciseTest.php` — un solo test verifica los 3 casos juntos: `contraindications=null` bloquea, `instructions=[]` bloquea, `important_points`/`common_mistakes`/`breathing_cue` en `null` no bloquean. |
| **No interferencia con `TrainingEngine`** | `TrainingEngineTest.php` — con competencia real por cupos (más candidatos que espacios), un ejercicio con más técnica pero peor ajuste de dificultad nunca desplaza a uno mejor rankeado. |
| **Integración real por WhatsApp** | Extensión de `TrainingConversationFlowTest.php` — el mensaje saliente contiene el nombre, las instrucciones reales del snapshot y la respiración, y el video se sigue enviando. |

## Cobertura de Hito 9.3 (sincronización completa del catálogo)

22 tests nuevos netos (ver D038) — `tests/Feature/ExerciseCatalog/ExerciseFullSyncTest.php` (18, nuevo) + 4 en archivos existentes.

| Categoría | Dónde |
|---|---|
| **Paginación completa real** | `ExerciseFullSyncTest.php` — recorre todas las páginas usando `pagination.totalPages` reportado por el proveedor, nunca un número asumido; sync completo sobre el catálogo entero reporta `created`/`updated`/`unchanged` con exactitud. |
| **Idempotencia y no-duplicación** | La misma corrida dos veces no crea nada nuevo y reporta todo `unchanged`; el mismo `provider_exercise_id` nunca se duplica entre páginas ni entre corridas. |
| **Actualización real** | Un ejercicio se reporta `updated` solo cuando su metadata de proveedor cambió de verdad (nunca por ruido de `synced_at`/reordenamiento de JSON del `provider_metadata` — hallazgo real, ver D038). |
| **Ausente-en-una-página, presente-en-otra** | No se desactiva un ejercicio activo que simplemente aparece en una página posterior a la que ya se revisó. |
| **Fallo parcial nunca reconcilia** | Un error de la API a mitad de la sincronización (`ProviderSyncException`) detiene la corrida sin tocar ningún `is_active` — una respuesta incompleta nunca se trata como "el ejercicio desapareció". Probado tanto sin filtro de músculo como acotado a uno. |
| **Reconciliación acotada por músculo** | 4 variantes: pagina más de una página para un solo músculo; nunca desactiva un ejercicio activo de otro músculo; sigue pidiendo `includeVideos=false` y el filtro de músculo correcto; un error a mitad de camino estando acotado a un músculo tampoco desactiva nada. |
| **`provider_has_video`** | Se persiste desde la metadata del proveedor y se refresca en cada re-sync, sin ninguna relación con `is_active`. |
| **Cero video, siempre** | `includeVideos=false` en cada página consultada; ningún `video_url` de proveedor queda persistido tras un sync completo. |
| **Reconocimiento de un lote ya importado** | Un ejercicio importado antes por `importSelected()` se reconoce por `provider`+`provider_exercise_id` en el sync completo, sin duplicarse. |
| **`searchPaged()` del adapter** | `YMoveExerciseProviderTest.php` — expone la paginación real (`page`/`totalPages`/`total`) que YMove reporta; lanza `ProviderSyncException` en vez de devolver una página vacía cuando el proveedor falla (para que un error nunca se confunda con "fin del catálogo"). |
| **Comando reescrito** | `ExerciseImporterTest.php` — `exercises:sync` corre ahora sobre `fullSync()` (antes tenía un defecto real de una sola página por corrida, nunca ejecutado en producción, ver D038); sigue filtrando por `--muscle` correctamente. |
| **`hasVideo` en la normalización** | `YMoveExerciseNormalizerTest.php` — `true`/`false`/ausente se mapean sin inventar el valor cuando el proveedor no lo informa. |

**Sincronización real ejecutada contra la cuenta real de YMove** (no un doble de prueba, ver D038): 1068 ejercicios, 1067 con video, 1 sin video, 0 errores, 0 cuota de video consumida — cierra el gap que D036 dejó explícito ("ningún test llama a la API real de YMove").

## Cobertura del fix post-E2E: video fuera de página 1, contenido en español, onboarding (ver D039)

34 tests nuevos netos, **100% con `Http::fake()`/mocks — cero llamadas reales a YMove u OpenAI, cero cuota adicional consumida**.

| Categoría | Dónde |
|---|---|
| **Localización más allá de la página 1** | `YMoveExerciseProviderTest.php` (+5 en su momento) — **superseded**: esta cobertura asumía que había que paginar para resolver un id individual. La documentación oficial de YMove reveló un endpoint directo por id (`GET /exercises/{id}`) — ver D041 y la sección "Cobertura de la corrección: endpoint directo por id" más abajo, que reemplaza estos tests por completo. |
| **Generación de contenido en español — validación de fidelidad** | `ExerciseSpanishContentGeneratorTest.php` (nuevo, 8) — genera y valida `name`/`instructions`/`important_points`; nunca menciona un proveedor concreto en el prompt (agnosticismo real, no solo declarado); `important_points` vacío se preserva tal cual; JSON inválido se rechaza (`SpanishContentGenerationException`); una traducción que fusiona/inventa pasos o puntos importantes se rechaza por conteo exacto de elementos, no solo por confiar en el prompt; `name` vacío se rechaza; tolera fences de markdown igual que `OnboardingConversationService`. |
| **`Exercise::toSnapshot()` prefiere español cuando existe** | `ExerciseTest.php` (+3) — usa el original si `*_es` es `null`; prefiere `*_es` sin perder ni sobreescribir las columnas originales; distingue `important_points_es=[]` (traducido, confirmado vacío) de `null` (todavía sin traducir). |
| **Acción de Filament "Generar contenido en español"** | `ExerciseResourceTest.php` (+5) — genera y guarda desde la acción de tabla sin activar el ejercicio ni tocar `is_active`/`reviewStatus()`; una traducción que falla la validación de fidelidad no guarda nada y notifica el error; oculta para un ejercicio ya activo; oculta para un usuario no-super-admin; los campos `*_es` son editables directamente desde el formulario de edición, independientemente de la acción de generación. |
| **Contexto de "pregunta pendiente" en la extracción del onboarding** | `OnboardingConversationServiceTest.php` (+12, 5 casos + 7 variantes de un mismo dataset) — el prompt incluye la línea de contexto cuando se pasa `$pendingField`, y nunca la incluye si no se pasa (compatibilidad hacia atrás); el prompt instruye explícitamente enrutar una respuesta de foco a la pregunta de objetivo hacia `primary_focus`/`secondary_focus` sin forzar `goal`; extremo a extremo con `resolveQuestion()`/`usedAiResponse()` reales verificando que el sistema sigue pidiendo `goal` después; el prompt instruye aceptar cualquier negación natural a la pregunta de restricciones como `restrictions: []`; extremo a extremo con 7 variantes de negación ("no", "No", "ninguna", "ninguno", "no tengo", "nada", "no, ninguna") todas aceptadas como `[]`, nunca `null`. |
| **Confirmación explícita: contenido en español sobrevive a re-sync y a activación, `provider_has_video` sigue independiente** | `ExerciseImporterTest.php` (+1) — un solo test de extremo a extremo: genera contenido `*_es`, activa el ejercicio (`activate()` no lo toca), corre un segundo `importSearch()` que sí cambia metadata real y `hasVideo`, y confirma que `name_es`/`instructions_es`/`important_points_es`/`is_active` siguen intactos mientras `provider_has_video` refleja el nuevo valor del proveedor sin relación alguna con `is_active`; y que `toSnapshot()` ya devuelve el español mientras el ejercicio está activo. |

**Qué NO demuestra esta cobertura, explícitamente**: ningún test ejecuta un LLM real — las dos reglas nuevas del prompt de onboarding y el `ExerciseSpanishContentGenerator` son instrucciones a un LLM, verificadas aquí solo en que (a) el prompt realmente las contiene y (b) el código aplica/valida correctamente cualquier forma de respuesta ya validada que el LLM podría dar — nunca que un LLM real seguirá la instrucción en todos los casos reales. Confirmación de eso solo puede venir de una prueba E2E real posterior (fuera de alcance de este fix, por la restricción explícita de cero cuota adicional).

## Cobertura de la corrección: endpoint directo por id + observabilidad (ver D041, supersede D040)

24 tests en `YMoveExerciseProviderTest.php` (reemplaza la cobertura anterior de D040/paginación — ver nota en la sección de D039 arriba), **100% con mocks — cero llamadas reales a YMove**.

| Categoría | Dónde |
|---|---|
| **`find()` usa el endpoint directo** | `GET /exercises/{id}?includeVideos=false`, nunca `/exercises?page=N` — verificado por URL exacta y por `Http::assertSentCount(1)`. |
| **Resuelve un id "lejano" con una sola solicitud** | Un id que en el mecanismo anterior habría requerido recorrer varias páginas ahora se resuelve en 1 solicitud — el concepto de "página" ya no aplica a la resolución individual. |
| **`resolveMedia()` usa el endpoint directo con video** | `GET /exercises/{id}?includeVideos=true`; produce el `ResolvedMedia` esperado (URL por defecto y variante `white-background`); 1 sola solicitud, sin dependencia de ningún hint. |
| **`variants()` usa el mismo endpoint directo** | Mismo shape de respuesta (`videos[]`), 1 sola solicitud. |
| **`find()` nunca pide video; `resolveMedia()`/`variants()` sí** | Misma garantía de antes, verificada ahora sobre el endpoint directo. |
| **Logging: sin video** | Ejercicio encontrado, respuesta exitosa, sin `videoUrl`/`videos[]` → `reason=provider_has_no_video`, nivel `info`. |
| **Logging: cuota excedida — dos formas reales según la documentación de YMove** | (a) HTTP 429 directo; (b) HTTP 200 con `_warning.reason=monthly_exercise_cap` y campos de video ausentes — ambas producen `reason=provider_quota_exceeded`, **nunca** `provider_has_no_video` (test explícito de la distinción). |
| **Logging: no encontrado** | HTTP 404 → `reason=provider_exercise_not_found` con `http_status=404`, para `find()` y `resolveMedia()`. |
| **Logging: error HTTP genérico** | HTTP 503 → `reason=provider_http_error`, nunca confundido con cuota ni con no-encontrado. |
| **Nunca se loguean secretos** | El contexto del log nunca contiene la API key, el header `X-API-Key`, ni un fragmento de URL firmada (`token=`). |
| **Vocabulario sin hardcode de proveedor** | Ningún valor ni nombre de `MediaResolutionReason::cases()` contiene "ymove" — reutilizable por cualquier Adapter futuro. |
| **Regresión: `searchPaged()`/`fullSync()` intactos** | Los tests existentes de `searchPaged()` (paginación real, excepción en fallo) y de `ExerciseFullSyncTest`/`ExerciseImporterTest` (sincronización completa) no cambiaron — la corrección solo afecta la resolución de UN ejercicio individual. |

**Qué NO demuestra esta cobertura, explícitamente**: el shape de la respuesta 200-con-video (con `videoUrl`/`videos[]`) del endpoint `/exercises/{id}` no se verificó en vivo — solo el shape de la respuesta browse (`includeVideos=false`, confirmado en vivo, sin costo de cuota). Verificar el shape exacto con video requeriría una llamada `includeVideos=true` real, explícitamente prohibida mientras la cuenta esté sobre su cupo (114/100).

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

## Cobertura de AlertService (Hito 7.1)

| Categoría | Dónde |
|---|---|
| **fan-out** | `tests/Feature/Core/AlertServiceTest.php` — TODOS los canales que `supports()` una Alert la reciben (no solo el primero, a diferencia de Router/PreRoutingScreener); `deliver()` nunca se llama si `supports()` es falso. |
| **aislamiento de fallos** | `AlertServiceTest.php` — un canal que lanza excepción no detiene a los demás canales ni propaga la excepción al llamador. |
| **inmutabilidad y saneo de `context`** | `tests/Feature/Core/AlertTest.php` — propiedades `readonly`; cualquier clave de `context` que luzca como secreto (`key`/`token`/`password`/`secret`/`credential`, cualquier mayúscula/minúscula) se reemplaza por `'[REDACTED]'`. |
| **persistencia** | `tests/Feature/Core/PersistedAlertChannelTest.php` — soporta toda severidad/categoría; persiste en `alert_logs` con `delivery_status = 'recorded'`; nunca persiste un valor ya redactado por `Alert` como si fuera el secreto real. |
| **WhatsApp al superadmin** | `tests/Feature/Core/WhatsAppAdminAlertChannelTest.php` — solo `Warning`/`Critical` (no `Info`); requiere `tenant_id` resoluble en `context`; requiere al menos un `User.is_super_admin` con `phone`; envía usando las credenciales Meta del Tenant **originador** (nunca las de otro tenant); throttling básico (una alerta idéntica repetida dentro de la ventana no se reenvía); nunca lanza excepción aunque Meta responda error. |
| **integración con Safety** | `tests/Feature/Training/SafetyAlertIntegrationTest.php` — una señal de seguridad real genera un `AlertLog` y un envío de WhatsApp al superadmin; **el bloqueo de seguridad al usuario sigue funcionando aunque el envío de la alerta al admin falle** (verificado con `Http::sequence()` forzando un 500 en el primer envío). |
| **severidades** | Cubierto transversalmente en `AlertServiceTest`/`AlertTest`/`WhatsAppAdminAlertChannelTest` — las 3 (`Info`/`Warning`/`Critical`) se ejercitan explícitamente. |

## Cobertura de CustomerNotifier (ajuste de Hito 8)

| Categoría | Dónde |
|---|---|
| **ventana abierta → mensaje libre** | `tests/Feature/Core/CustomerNotifierTest.php` — `Conversation.last_session_at` dentro de las 23h30m envía vía `WhatsAppService::sendMessage()` con el texto libre exacto que el dominio proveyó. |
| **ventana cerrada → template** | `CustomerNotifierTest.php` — `last_session_at` a las 24h (>= 23h30m) resuelve la `WhatsAppTemplate` por `(tenant_id, event_key)`, cruza `parameters_map` contra las `variables` provistas, y envía vía `WhatsAppService::sendTemplateMessage()` con el `name`/`language` reales de esa plantilla. |
| **sin `Conversation` registrada** | `CustomerNotifierTest.php` — se trata como ventana cerrada (conservador), igual que si hubiera vencido. |
| **template inexistente** | `CustomerNotifierTest.php` — ventana cerrada sin ninguna `WhatsAppTemplate` para ese `(tenant_id, event_key)`: no se envía nada, no se lanza excepción, se loguea `CUSTOMER_NOTIFIER_TEMPLATE_NOT_CONFIGURED`. |
| **error de Meta / excepción inesperada** | `CustomerNotifierTest.php` — Meta respondiendo error en el envío de plantilla, y una llamada con datos mínimos que no encuentra nada que enviar, terminan sin lanzar ninguna excepción hacia el llamador. |
| **integración con Payments** | `tests/Feature/Payments/PaymentConfirmationServiceTest.php` — confirmar/rechazar con ventana abierta dispara el mensaje libre correcto (incluye el motivo real en el rechazo); un fallo de Meta durante la notificación nunca revierte el `Payment` ni el `TrainingAccess` ya persistidos. |
| **invitación a entrenar (D031)** | `PaymentConfirmationServiceTest.php` — confirmar envía DOS mensajes separados (pago confirmado + invitación); nunca crea una `WorkoutSession`; un reintento sobre un Payment ya confirmado no duplica ningún mensaje **ni registro en `WhatsAppMessage`**; ventana abierta → libre, cerrada con plantilla configurada → template; un fallo en la invitación no revierte Payment/TrainingAccess ni afecta al primer mensaje. |
| **persistencia en WhatsAppMessage (D032)** | `tests/Feature/Core/CustomerNotifierTest.php` — el mensaje libre se persiste con `role: assistant`; el texto libre equivalente se persiste también en la rama de plantilla; no se persiste nada si no había plantilla configurada (nada se envió); una sola invocación no duplica el registro. |
| **continuidad post-pago (D032)** | `tests/Feature/Training/TrainingIntentClassifierTest.php` — un contacto con `TrainingAccess` activo y cero `WorkoutSession` fuerza `Intent::Training` sin palabra clave ("Sí", "Dale", mensaje neutro); no aplica sin `TrainingAccess`, con acceso no-`Active` (ej. expirado), o si ya existe una `WorkoutSession`. `tests/Feature/PaymentToTrainingIntegrationTest.php` (nuevo) — integración completa: pago confirmado → `payment_confirmed` + `training_invite` enviados → "Sí" → `WorkoutSession` generada → video enviado, de punta a punta vía el Job real. |
| **aislamiento arquitectónico** | `tests/Feature/Core/CoreIsolationArchTest.php` (+1) — `App\Core` no depende de `App\Payments`; `CustomerNotifier` no puede acoplarse a ningún dominio concreto. |

## Cobertura de Payments (Hito 8)

| Categoría | Dónde |
|---|---|
| **clasificación de intent** | `tests/Feature/Payments/PaymentIntentClassifierTest.php` — palabras clave; un Payment abierto (`pending`/`under_review`) fuerza el intent aunque el mensaje no traiga texto (ej. una imagen sin caption); un contacto sin pagos abiertos y sin palabras clave nunca fuerza el intent. |
| **extracción del comprobante (Extract)** | `tests/Feature/Payments/ReceiptExtractionServiceTest.php` — texto e imagen; nunca inventa un valor no legible; JSON malformado o error del proveedor devuelven un resultado vacío/incierto en vez de lanzar excepción; texto vacío no llama a la IA. |
| **visión por proveedor** | `tests/Feature/Payments/AiVisionServicesTest.php` — confirma que cada implementación usa su modelo de visión dedicado (`gpt-4o-mini`, `grok-4.20-0309-non-reasoning`), distinto del modelo de chat configurado. Gemini solo prueba la forma de la petición — no fue verificado con una key real (ver docs/DECISIONS.md, D025). |
| **validación determinista (Decide)** | `tests/Feature/Payments/PaymentValidationServiceTest.php` — `amount_mismatch`/`amount_unreadable`, `reference_missing`/`reference_already_used` (control global contra pagos `confirmed`, no solo del mismo tenant/contacto), `stale_receipt`/`date_unreadable`, `uncertain_extraction`; confirma que el servicio nunca cambia el `status` del Payment por sí solo. Incluye una guarda explícita `extension_loaded('bcmath')` (D028) — detecta en local que la extensión sigue disponible, aunque solo un test dentro del contenedor real de producción puede detectar si vuelve a faltar ahí. |
| **confirmación → TrainingAccess** | `tests/Feature/Payments/PaymentConfirmationServiceTest.php` — acceso nuevo (1 mes); renovación antes de vencer extiende desde el vencimiento actual (no pierde días pagados); renovación después de vencer extiende desde `now()`; rechazar nunca toca `TrainingAccess`; reviewer `null` (pasarela futura) funciona sin cambiar la firma. |
| **idempotencia (ajuste Hito 8)** | `PaymentConfirmationServiceTest.php` — confirmar un Payment ya `confirmed` es no-op (no re-extiende `expires_at`, no crea un segundo `TrainingAccess`, no reenvía la notificación, no sobrescribe la nota original); rechazar un Payment ya `rejected` es no-op equivalente. |
| **notificación al cliente (ajuste Hito 8)** | `PaymentConfirmationServiceTest.php` — confirmar/rechazar con la ventana de conversación abierta dispara un mensaje libre con el contenido correcto; un fallo de Meta durante la notificación NUNCA revierte el `status` del Payment ni el `TrainingAccess` ya otorgado (ambos ya se persistieron antes de notificar). |
| **flujo conversacional real** | `tests/Feature/Payments/PaymentConversationFlowTest.php` — mismo patrón que `TrainingConversationFlowTest.php`: vía el Job real, no la clase aislada. Cubre `payment_options`, creación de `Payment(pending)` con instrucciones (nunca promete activación), comprobante por texto (`under_review` + `AlertLog` + WhatsApp al superadmin), comprobante por imagen (descarga, hash, persistencia permanente, extracción por visión), un mensaje sin señal real de pago no crea `PaymentReceipt` ni cambia el estado, `payment_status` reporta cada estado en lenguaje claro, y confirma que el flujo conversacional **nunca** toca `TrainingAccess`. |
| **aislamiento arquitectónico** | `tests/Feature/Payments/PaymentIsolationArchTest.php` — `App\Payments\Handlers`/`Support` (salvo `PaymentConfirmationService`) no dependen de `App\Models\TrainingAccess`. |
| **modelo** | `tests/Feature/Payments/PaymentModelTest.php` — relaciones, casts de enums/json, `isOpen()`/`isAwaitingReceipt()` por estado. |
| **formato de la alerta (D030)** | `tests/Feature/Payments/PaymentAlertFormatTest.php` — "Fecha extraída" siempre es el valor literal de `extracted_data['date']` (o "no disponible"), NUNCA `now()`; monto detectado y esperado se muestran por separado; `validation_flags` se traducen a frases legibles (nunca la clave técnica cruda en el texto); la sección de advertencias se omite por completo cuando no hay flags. |
| **Safety Review** | Extiende `tests/Feature/Training/TrainingProfileTest.php` — `clearSafetyFlag()` ahora exige reviewer+nota y los persiste; `flagForSafetyReview()` resetea cualquier revisión previa al marcar de nuevo. |

**No cubierto por tests automatizados** (ver D025, riesgo documentado): la capa Filament (`PaymentResource`, la acción "Revisar seguridad" de `ContactsTable`) — sin precedente de test para Resources/Actions en este proyecto. La lógica que esas acciones invocan (`PaymentConfirmationService`, `TrainingProfile::clearSafetyFlag()`) sí está cubierta; falta una verificación manual real (clic en el panel) antes de considerar esta pieza completamente probada.

## Cobertura de la fusión Extract+Narrate del onboarding (Hito 5.1)

| Categoría pedida | Dónde |
|---|---|
| **1. extracción válida** | `OnboardingConversationServiceTest.php` — `extracted.*` con las mismas reglas de siempre. |
| **2. extracción inválida** | Enum fuera del vocabulario, `restrictions` no-array, `sessions_per_week` fuera de 1-14 — todos se descartan (quedan `null`), igual que antes de la fusión. |
| **3. next_action correcto + response válido** | `resolveQuestion()` usa la redacción de la IA. |
| **4. next_action incorrecto** | La IA cree que falta un campo distinto del real → se descarta, se usa `FALLBACK_QUESTIONS` del campo real. |
| **5. next_action ausente** | Mismo resultado que "incorrecto" — `null` nunca coincide con ningún valor esperado. |
| **6. `complete_onboarding` antes de completar** | Verificado explícitamente: aunque la IA diga `complete_onboarding`, si el código determinó que falta un campo real, se pregunta por ese campo — `complete_onboarding` nunca puede completar el perfil por sí mismo (estructuralmente no aparece en `NEXT_ACTION_MAP`). |
| **7. response vacío** | Se descarta aunque `next_action` coincida. |
| **8. response excesivamente largo** | >300 caracteres se descarta aunque `next_action` coincida. |
| **9. JSON inválido** | Resultado vacío (`extracted` todo `null`, `next_action`/`response` `null`), sin excepción. |
| **10. error HTTP** | Mismo resultado vacío, sin excepción — el onboarding nunca se rompe. |
| **11. flujo completo de onboarding** | `TrainingConversationFlowTest.php` — 5 turnos consecutivos, perfil completo verificado en BD al final. |
| **12. exactamente UNA llamada HTTP a IA por turno** | Mismo test — se cuenta explícitamente cuántas peticiones a `api.openai.com` se hicieron en los 5 turnos (5, no 10 como habría sido con la arquitectura anterior). |
| **13. restrictions + safety_signal_text independientes** | `OnboardingConversationServiceTest.php` — "tengo dolor en las rodillas" → `restrictions` poblado, `safety_signal_text: null`; test adicional que verifica que el prompt enviado a la IA contiene explícitamente la instrucción de independencia entre ambos campos. |

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
