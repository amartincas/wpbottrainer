# Decisiones arquitectónicas — WpbotTrainer

Formato: `ID / DECISIÓN / CONTEXTO / ALTERNATIVAS / DECISIÓN TOMADA / JUSTIFICACIÓN / IMPACTO`.

---

### D001 — Separación Core Platform / WpbotTrainer Domain

**CONTEXTO**: el proyecto pivota de un bot de negociación e-commerce a WpbotTrainer (entrenamiento por WhatsApp), con intención de soportar verticales futuros.
**ALTERNATIVAS**: (a) un solo runtime multi-vertical con selección dinámica de módulo por tenant; (b) un despliegue por vertical compartiendo un Core interno separado por namespace; (c) extraer el Core a un paquete Composer publicado desde ya.
**DECISIÓN TOMADA**: (b).
**JUSTIFICACIÓN**: (a) es sobreingeniería sin un segundo vertical real que valide la necesidad. (c) es prematuro sin un segundo consumidor del paquete.
**IMPACTO**: bajo costo ahora; extraer a paquete el día que exista un vertical #2 es un ejercicio mecánico si la regla de dependencia de una sola vía (Domain→Core) se respeta desde ya.

### D002 — Eliminación del legado ecommerce (Hito 1)

**CONTEXTO**: auditoría encontró código muerto verificado y componentes huérfanos.
**DECISIÓN TOMADA**: eliminados `AIOrchestrator`, el `AIServiceFactory`/`AIServiceInterface` duplicado en `App\Services\AI`, `ProductSearchService`, el método `extractLeadData()` deprecado, `tmp_queue_check.php`, `./count()`, los tests placeholder de Pest, y dos componentes huérfanos descubiertos durante la verificación: `App\Livewire\RegisterUser` (+ vista) y `DebugInfoDisplayWidget` (+ vista) — ambos confirmados sin referencias activas antes de borrarlos.
**IMPACTO**: ninguno funcional (cero referencias activas verificadas por grep antes de cada borrado).

### D003 — `Store` → `Tenant` (nomenclatura del Core)

**CONTEXTO**: `Store` es vocabulario de retail; el Core debe usar nombres genéricos válidos para cualquier vertical y para múltiples instancias regionales (ej. "WpBotTrainer Colombia", "WpBotTrainer México").
**ALTERNATIVAS evaluadas**: `Bot` (reduce la entidad a solo el chatbot, cuando contiene facturación/credenciales/config), `Agent` (colisión semántica con "agente de IA"), `Instance` (válido pero frío), `Tenant` (término estándar de multi-tenancy, vertical-agnóstico).
**DECISIÓN TOMADA**: `Tenant` (provisional — puede revisarse si emergen requisitos que lo justifiquen).
**IMPACTO**: rename completo de modelo, tabla, migraciones, Filament, Livewire, Job, Controller, Middleware (Hito 1, ya ejecutado).

### D004 — `Lead` → `Contact`

**CONTEXTO**: `Lead` conflicto con el doble propósito ya existente (registro de control de bot + lead de marketing).
**DECISIÓN TOMADA**: renombrar a `Contact`, manteniendo el mismo doble propósito y el mismo valor sentinela `customer_name = 'Unknown'` para registros de control (sin introducir un campo booleano nuevo — eso habría sido "inventar un campo de negocio" fuera del alcance del Hito 1).
**Campos ecommerce conservados como deuda técnica explícita**: `delivery_address_or_location`, `product_service_name`, `preferred_date_time` se mantienen sin cambios en `Contact` porque `ProcessWhatsAppMessage` y los flujos de plantillas dependen de ellos activamente, y ese archivo no se refactoriza en este hito. **No se presentan como parte del modelo conceptual definitivo de `Contact`** — se eliminarán/rediseñarán cuando se refactorice `ProcessWhatsAppMessage`/Training.
**IMPACTO**: rename completo (Hito 1, ya ejecutado); sin cambio de comportamiento.

### D005 — Router especializado (Ingest → Router → Dispatch → Handler → Domain)

**CONTEXTO**: hoy una sola llamada libre al LLM decide y ejecuta todo.
**DECISIÓN TOMADA**: el Router es un mecanismo Core que solo clasifica intent (LLM en modo de salida restringida a un enum cerrado + atajos deterministas para botones/comandos) y despacha a un Handler; el LLM nunca ejecuta acciones directamente. Los intents concretos son contenido de cada Domain.
**IMPACTO**: **PENDIENTE de implementar** — diseño aprobado, no construido en este hito.

### D006 — Memoria estructurada

**DECISIÓN TOMADA**: capas (ventana corta, estado de sesión, perfil estructurado, historial episódico, resumen IA) + `ContextBuilder` con etiquetado de confianza por bloque, en vez de RAG/vector store desde el día uno.
**IMPACTO**: **PENDIENTE de implementar.**

### D007 — Training Engine separado del LLM

**DECISIÓN TOMADA**: patrón Extract → Decide → Narrate (el LLM extrae parámetros de lenguaje natural, un servicio determinista decide la regla aplicable, el LLM solo narra el resultado). Sin motor de reglas genérico para el MVP.
**IMPACTO**: paso **Decide implementado en el Hito 4** (`App\Training\Engine\TrainingEngine::decideNextSession()`), 100% determinista, sin ninguna llamada a un proveedor de IA. Extract (interpretación de lenguaje natural del usuario) y Narrate (redacción de la respuesta) quedan **pendientes** — llegan con el primer Handler conversacional de Training. Ver D018.

### D008 — Pagos híbridos

**DECISIÓN TOMADA**: `Payment` → `PaymentMethod` → `PaymentProviderInterface` (pasarelas con webhook) / `VerificationProviderInterface` (transferencia manual con evidencia). Auto-aprobación por timeout **no** se activa en v1 (escala a revisión prioritaria en su lugar) hasta tener una línea base de confiabilidad del extractor de evidencia.
**IMPACTO**: **PENDIENTE de implementar.**

### D009 — API-First = NO

**CONTEXTO**: no hay consumidor externo identificado hoy; el único cliente del backend es el propio Dashboard Filament/Livewire.
**DECISIÓN TOMADA**: no adoptar API-First para el MVP. Mantener los Domain Services desacoplados de Livewire/Filament para que una API futura sea un adaptador delgado, no una reescritura.
**IMPACTO**: ahorra semanas de trabajo no vendible hoy; los webhooks entrantes (Meta, pasarela de pago) se implementan como controladores delgados normales, sin que eso implique adoptar una filosofía API-First para todo el backend.

### D010 — Docker: local nativo, producción en contenedores

**CONTEXTO**: se evaluó Docker para desarrollo local y se corrigió la dirección dos veces durante la ejecución del Hito 1.
**DECISIÓN TOMADA (final)**: en desarrollo local, Laravel/PHP y Node/Vite corren **nativos** en el host Windows; **MySQL y Redis corren en Docker Desktop** (sin ejecutar la aplicación Laravel dentro de un contenedor). SQLite **no** es la base de datos de referencia del proyecto ni en desarrollo ni en producción. La infraestructura Docker de producción (contenedores de app, worker, scheduler, nginx, etc.) se define en un **hito posterior**, no en este.
**IMPACTO**: para este hito se usó el contenedor MySQL ya existente en el repo (`docker-compose-phpmyadmin.yml`, servicio `mysql_local`), creando dos bases dedicadas (`wpbottrainer` para desarrollo, `wpbottrainer_test` para tests). `.env.example` se actualizó para reflejar MySQL como default en vez de SQLite.

### D011 — Edición de migraciones históricas (en vez de migraciones aditivas de rename)

**CONTEXTO**: `Store→Tenant` y `Lead→Contact` requerían decidir entre reescribir el historial de migraciones o apilar migraciones nuevas de `Schema::rename()`.
**DECISIÓN TOMADA**: *"Se permitió modificar migraciones históricas porque el proyecto aún no contiene datos persistidos de producción y se busca establecer una línea base limpia antes del lanzamiento."*
**IMPACTO**: el historial de migraciones nace directamente creando `tenants`/`contacts`/`tenant_id`, sin pasos intermedios de rename. Requiere `migrate:fresh` en cualquier entorno con una base de datos local ya migrada bajo el esquema viejo — verificado y documentado como paso operativo, no como efecto secundario silencioso.

### D012 — Eliminación de la migración no-op `add_bot_active_to_leads_table` (2026_04_29_175658)

**CONTEXTO**: se detectaron dos migraciones con el mismo nombre descriptivo; una (`2026_04_29_175658`) tenía `up()`/`down()` completamente vacíos.
**DECISIÓN TOMADA**: eliminarla, documentada como limpieza de una migración histórica vacía/redundante. La columna real la crea `2026_04_30_172600` (renombrada a `add_bot_active_to_contacts_table`), que se conserva.
**IMPACTO**: ninguno en el esquema final (la migración no hacía nada); evita además un riesgo de ruptura de `migrate:fresh` al referenciar una tabla `leads` que en el historial editado ya no existiría bajo ese nombre en ese punto.

### D013 — Video demostrativo de ejercicios: dentro del MVP

**DECISIÓN TOMADA**: el contenido demostrativo (imagen + video) de cada ejercicio es parte del MVP de WpbotTrainer.
**IMPACTO**: `Exercise.video_url` existe desde el Hito 4 (columna obligatoria, no nullable — un ejercicio sin video no debería entrar al catálogo). Carga real de contenido y almacenamiento en object storage/CDN siguen **pendientes** (ver D018).

### D014 — Análisis de video del usuario: fuera del MVP

**DECISIÓN TOMADA**: Computer Vision, análisis biomecánico y corrección automática de técnica mediante video enviado por el usuario quedan explícitamente fuera del MVP.
**IMPACTO**: ninguno ahora; candidato post-MVP.

### D015 — Smartwatch / interoperabilidad: fuera del MVP

**DECISIÓN TOMADA**: integración con smartwatches u otros wearables queda fuera del MVP.
**IMPACTO**: ninguno ahora; candidato post-MVP.

### D016 — Router Core (Hito 2): Ingest → Router → Intent → Dispatcher → Handler

**CONTEXTO**: `ProcessWhatsAppMessage` concentraba toda la lógica conversacional en un único método. Se necesitaba extraer el mecanismo de enrutamiento como pieza de Core reutilizable para futuros verticales, sin tocar el comportamiento observable ni introducir lógica de dominio en Core.

**ALTERNATIVAS evaluadas**:
- Registrar instancias concretas de Handler en el Dispatcher (`[intent => new FallbackChatHandler()]`) vs. registrar **clases** y resolverlas vía el Laravel Container en cada `dispatch()`.
- Incluir `productContext` (un concepto de e-commerce/catálogo) directamente en `IngestedMessage`/`Ingest` vs. mantener Core completamente agnóstico y transportar ese dato por un canal aparte.

**DECISIÓN TOMADA**:
1. **Dispatcher → Container → Handler**: `Dispatcher` recibe un mapa `[intent-value => FQCN del Handler]` (clases, no instancias) y resuelve la instancia con `$container->make($handlerClass)` en cada `dispatch()`. Esto permite que un Handler futuro declare sus propias dependencias de constructor sin que nadie tenga que tocar el wiring de `AppServiceProvider`.
2. **`productContext` queda fuera de `IngestedMessage`/`Ingest` por completo.** Core (`Ingest`, `Router`, `Dispatcher`, `HandlerInterface`) no conoce `Product` ni ningún concepto de e-commerce. El dato se transporta como una entrada (`product_context`) dentro de un **`array $context` opaco**, parámetro adicional de `Dispatcher::dispatch()` y `HandlerInterface::handle()`, que Core nunca lee ni escribe — solo lo reenvía. `FallbackChatHandler` es la única clase que sabe qué significa esa clave.
3. **`$context` es un mecanismo transitorio, no un contrato de dominio**: Core no interpreta ninguna clave de `$context`; `product_context` es exclusivamente legado y específico de `FallbackChatHandler`; no deben agregarse nuevas claves de dominio al `context` desde Core; antes de incorporar los primeros intents reales de WpbotTrainer se evaluará si este mecanismo debe sustituirse por un contexto tipado/específico por Handler.
4. **`FallbackChatHandler` es stateless**: tenant/from/messageBody son variables locales de `handle()` y de los métodos privados que invoca, nunca propiedades de instancia — seguro de resolver como singleton o reutilizar entre mensajes/tenants distintos en el mismo worker.
5. **Router sin clasificación real**: con un único intent (`fallback_chat`), `Router::route()` siempre devuelve ese valor. Es un placeholder documentado explícitamente, no una cobertura de clasificador real — eso llega con los primeros intents de dominio.

**JUSTIFICACIÓN**: mantiene Core (`App\Core\Messaging\*`) completamente libre de conocimiento de dominio (ni Product, ni lead, ni prompt), cumpliendo el requisito explícito de que la lógica específica de WpbotTrainer no debe filtrarse a Core. La resolución vía Container es el patrón estándar de Laravel para este tipo de wiring y evita construir un mecanismo de auto-discovery a medida (sobreingeniería) mientras sigue siendo trivial añadir un intent nuevo (una línea en `AppServiceProvider`).

**IMPACTO**: `ProcessWhatsAppMessage` pasó de ~1000 líneas a un orquestador de ~140 líneas. Toda la lógica de negocio que antes vivía ahí se movió, prácticamente sin cambios, a `App\Handlers\FallbackChatHandler`. Los 12 tests del Hito 1 se mantienen en verde (uno de ellos requirió cambiar `$job->handle()` por `app()->call([$job, 'handle'])`, ya que `handle()` ahora tiene dependencias tipadas que Laravel resuelve automáticamente al procesar un Job real desde la cola — el cambio es solo de invocación en el test, ninguna aserción se modificó). Se añadieron 14 tests nuevos (routing, dispatch, ingest/errores, tenant, conversación, WhatsApp, errores) — ver `docs/TESTING_GUIDE.md`.

### D017 — Memoria y Contexto (Hito 3): ExecutionContext + ContextBuilder, sin proveedores reales ni tablas de Training

**CONTEXTO**: antes del primer intent real de Training se necesitaba la infraestructura de memoria estructurada (cómo el Handler pide "solo lo que necesita" y cómo se etiqueta confirmado/inferido/desconocido), sin construir todavía ningún dato de dominio (Training aún no existe).

**ALTERNATIVAS evaluadas**:
- Mantener `array $context` (Hito 2) vs. reemplazarlo por un objeto tipado.
- Incluir `Contact` y/o `fragments` (resultado del `ContextBuilder`) dentro de `ExecutionContext` vs. mantenerlo mínimo y resolver ambos bajo demanda.
- Construir ya `MemoryEvent` (log de eventos transversal) y `Conversation.summary`/`summary_updated_at` vs. diferirlos hasta tener un consumidor real.
- Generalizar `Conversation.current_product_id` a un `state` JSON genérico ahora vs. dejarlo intacto.

**DECISIÓN TOMADA**:
1. **`array $context` → `ExecutionContext`** (`App\Core\Messaging\ExecutionContext`), un único objeto inmutable que fluye por `Router::route()`, `Dispatcher::dispatch()` y `HandlerInterface::handle()`. Contiene **exactamente** `tenant`, `conversation` (nullable), `message` (`IngestedMessage`) y `legacy` (la misma bolsa opaca transitoria de D016, hoy solo con `product_context`). Ninguna propiedad se agregó "por si hace falta después".
2. **Sin `Contact` en `ExecutionContext`**: ningún consumidor actual necesita uno resuelto de antemano. Un `ContextProvider` que lo necesite lo resuelve él mismo con `tenant` + `message->from` — mismo patrón ya usado en todo el proyecto.
3. **Sin `fragments` en `ExecutionContext`**: `ContextBuilder::build(ExecutionContext $context, array $requestedKeys): ContextFragment[]` es una llamada directa que el Handler hace y usa localmente, no un enriquecimiento de un objeto compartido — evita estado mutable y el problema de orden (los fragments solo se conocen dentro del Handler, después de que Router/Dispatcher ya corrieron).
4. **`ContextBuilder → Container → ContextProvider`**: mismo patrón de resolución que `Dispatcher` (D016) — mapa de `clave => FQCN`, `$container->make()`, lanza `RuntimeException` si la clave pedida no está registrada.
5. **`App\Core\Memory\ContextFragment`**: value object con `label`, `data` (opaco), `source`, `confidence` — la etiqueta de confianza (`confirmed`/`system_recorded`/`inferred`/`unknown`/`computed`) es el mecanismo concreto para que el LLM nunca trate un dato inferido como un hecho confirmado.
6. **Cero proveedores reales, cero tablas de Training, cero `MemoryEvent`, cero `Conversation.summary`**: `AppServiceProvider` registra `ContextBuilder` como singleton con mapa vacío `[]`. `FallbackChatHandler` no se modificó para usar `ContextBuilder` — no lo necesita, y forzarlo habría sido usar el mecanismo artificialmente. Estas piezas se diseñaron y quedaron documentadas (ver `docs/ARCHITECTURE.md` §7) pero deliberadamente no implementadas, por falta de un consumidor real — mismo criterio ya aplicado a `MemoryEvent` en la propuesta original.
7. **`Conversation.current_product_id` no se toca**: se verificaron exhaustivamente sus referencias (solo `FallbackChatHandler` y el modelo `Conversation`, cero en Filament/Livewire/vistas). Es la implementación e-commerce actual de lo que conceptualmente es estado conversacional genérico — generalizarla ahora habría exigido modificar la lógica interna de `FallbackChatHandler` (fuera de lo acordado: "mover, no reescribir") sin que exista todavía un segundo consumidor real que justifique la forma que debería tener. Queda como deuda técnica explícita, a revisar cuando el primer intent de Training necesite genuinamente un estado conversacional compartido.

**JUSTIFICACIÓN**: aplica exactamente el mismo criterio ya usado en el Hito 2 (Core = mecanismo, Domain = contenido) a una tercera pieza (memoria), y el mismo criterio de "sin consumidor real, no se construye" que motivó diferir `MemoryEvent`/`summary` — extendido aquí, con evidencia concreta, a `Conversation.state`.

**IMPACTO**: `Router`, `Dispatcher`, `HandlerInterface` y `FallbackChatHandler` cambiaron de firma (parámetros sueltos → `ExecutionContext`), sin cambio de comportamiento observable — los 16 tests de Hitos 1-2 siguen en verde sin modificar sus aserciones (ninguno necesitó cambios; solo los tests de `RouterTest`/`DispatcherTest` se adaptaron a la nueva firma, ya que construyen los objetos directamente). Se añadieron 12 tests nuevos (`ExecutionContext`, `ContextBuilder`, resolución de providers, ausencia de providers no solicitados, y 3 tests de arquitectura que verifican en CI que Core nunca importa `Product`/`Handlers`).

**RIESGOS**: `ContextFragment.source`/`confidence` son strings libres (no un enum) — se decidió así explícitamente para no introducir una abstracción nueva no aprobada a mitad de la implementación; si el volumen de valores crece, tipar con un enum (como `Intent`) es una mejora futura de bajo costo. `ContextBuilder` no tiene todavía ningún punto de llamada en producción (ningún Handler lo usa) — es infraestructura construida antes de su primer consumidor real, igual que le pasó al `Router` en el Hito 2 antes de tener un segundo intent.

### D018 — Dominio Training mínimo (Hito 4): TrainingProfile, Exercise, WorkoutSession/Exercise/Log/Set, TrainingAccess, TrainingEngine, TrainingAccessGate

**CONTEXTO**: se necesitaba el dominio mínimo para vender y entregar el primer entrenamiento personalizado, sin construir nada especulativo (`TrainingPlan`, `Enrollment`, pagos, proactividad). Cuatro decisiones se profundizaron explícitamente antes de implementar: granularidad del historial, rotación sin `TrainingPlan`, separación de entitlement, y política de seguridad — más un requisito transversal obligatorio de inmutabilidad histórica.

**ALTERNATIVAS evaluadas** (resumen — ver el análisis completo en la conversación de aprobación del hito):
- Historial: `ExerciseLog` con campos escalares (rechazado — no representa series con reps/carga distintas) vs. sets como JSON vs. `ExerciseLog` + `ExerciseSet` como entidad separada.
- Rotación: `TrainingPlan` con calendario fijo (rechazado) vs. 100% reactivo sin estado vs. estado mínimo de rotación en `TrainingProfile`.
- Entitlement: dejarlo en `TrainingProfile.access_status` vs. entidad `TrainingAccess` independiente vs. resolverlo solo por configuración global.
- Seguridad: LLM decidiendo directamente si una alarma es segura (rechazado) vs. lista cerrada + regla determinista + Gate.
- Inmutabilidad: versionado completo de `Exercise` (rechazado — complejidad innecesaria) vs. snapshot congelado en `WorkoutExercise` en el momento de generar la sesión.

**DECISIÓN TOMADA**:

1. **`ExerciseLog` (cabecera) + `ExerciseSet` (detalle por serie)**. `ExerciseLog` guarda `rpe` general y `note`; `ExerciseSet` guarda `set_number`, `actual_reps`, `actual_load`, `actual_duration_seconds` (todos nullable — un ejercicio es por reps/carga o por tiempo, nunca ambos, vía `Exercise.tracking_type`). Sin RPE por serie en el MVP.
2. **Sin `TrainingPlan`.** `TrainingProfile.next_focus` es una **señal de continuidad, nunca una autoridad**: `TrainingEngine::decideNextSession()` evalúa, en orden, sesión pendiente → grupo muscular descuidado (recuperación) → sesión omitida (reofrecer) → evitar repetir el foco de la última sesión completada → `next_focus` como última prioridad. El foco histórico de una sesión se deriva de los `muscle_group` de su `exercise_snapshot`, nunca de una columna nueva en `WorkoutSession` ni del catálogo `Exercise` actual.
3. **`TrainingAccess` como entidad independiente**, sin `access_status` en `TrainingProfile`. Campos exclusivamente de entitlement: `status`, `granted_at`, `expires_at`, `granted_by`, `notes`. Sin `Subscription`/`Payment`/`Invoice`/`Enrollment`. `TrainingAccessGate::authorize(Contact): AccessGateResult` es la única frontera hacia el sistema comercial — cuando Payments llegue, solo cambia la implementación interna de este método.
4. **Seguridad determinista, nunca delegada al LLM**: `App\Training\Support\SafetySignalDetector` (patrones de texto fijos, versionados en código, marcados `// REQUIERE REVISIÓN DE NEGOCIO/PROFESIONAL ANTES DE PRODUCCIÓN`) detecta una señal candidata; `TrainingProfile::flagForSafetyReview()` es la única forma determinista de bloquear un perfil; `TrainingAccessGate` es quien realmente impide que `TrainingEngine` genere contenido para un perfil marcado. `TrainingProfile::clearSafetyFlag()` existe pero ningún código de este hito lo invoca automáticamente — solo una acción humana explícita debe hacerlo. Sin `SafetyRule` como entidad.
5. **Inmutabilidad histórica**: `WorkoutExercise.exercise_snapshot` (JSON) congela `name`/`instructions`/`video_url`/`muscle_group` en el momento en que `TrainingEngine` genera la sesión, vía `Exercise::toSnapshot()`. `exercise_id` es `nullOnDelete` (nunca cascade) y sirve **exclusivamente** para trazabilidad/analítica — ningún código debe usarlo para reconstruir retroactivamente lo que un usuario recibió. Los campos `prescribed_*` se escriben una única vez y ningún código de este hito los actualiza después de creados. Cubierto explícitamente por `tests/Feature/Training/WorkoutExerciseImmutabilityTest.php`.
6. **`Exercise` es catálogo global** (sin `tenant_id`) — un ejercicio no varía por país/tenant; personalización por tenant queda como extensión POST-MVP si un tenant real la pide.
7. **Sin `tenant_id` denormalizado en `TrainingProfile`/`WorkoutSession`/`TrainingAccess`** — mismo precedente que `product_images` respecto a `products`: se escalan vía `Contact`, que ya es tenant-scoped.
8. **Vocabulario cerrado como backed enums** (`App\Training\Enums\*`: `TrainingGoal`, `ExperienceLevel`, `SplitType`, `SafetyStatus`, `WorkoutSessionStatus`, `TrackingType`, `TrainingAccessStatus`), mismo patrón que `Intent` (Hito 2) — validación por tipo en vez de una tabla de catálogo o un `FormRequest` sin consumidor todavía.
9. **Modelos Eloquent en `App\Models\*` (plano)**, no en un subnamespace `App\Training\Models\*` — se respeta la convención 100% plana ya usada por `Tenant`/`Contact`/`Product`/`Conversation`. Servicios de dominio (`TrainingEngine`, `TrainingAccessGate`, `SafetySignalDetector`) sí viven en `App\Training\*`, extendiendo el mismo criterio Core/Domain de los Hitos 2-3.

**JUSTIFICACIÓN**: cada pieza tiene un consumidor real e inmediato dentro de este mismo hito (`ExerciseSet` lo necesita la progresión del Engine; `TrainingAccess` lo necesita `TrainingAccessGate`, que el Engine ya invoca; los campos de seguridad los necesita el mismo Gate) — ninguna es una abstracción especulativa. Se aplicó consistentemente el criterio "no construir por si acaso" ya usado en Hitos 1-3: `TrainingPlan`, `ExerciseCategory`, `Equipment`, restricción normalizada, `Progress` almacenado, `SafetyRule` y cualquier concepto de facturación quedaron fuera por falta de consumidor real hoy.

**IMPACTO**: 7 migraciones nuevas (`training_profiles`, `exercises`, `workout_sessions`, `workout_exercises`, `exercise_logs`, `exercise_sets`, `training_accesses`), 7 modelos Eloquent nuevos + 3 relaciones nuevas en `Contact` (aditivas, sin tocar su comportamiento existente), 7 factories, 7 enums, `App\Training\Engine\TrainingEngine`, `App\Training\Support\{TrainingAccessGate,SafetySignalDetector,AccessGateResult,TrainingAccessDeniedException}`. Cero cambios a `Product`/e-commerce. 43 tests nuevos (`tests/Feature/Training/*`). Se extendió `tests/Feature/Core/CoreIsolationArchTest.php` con una regla que verifica que `App\Core` tampoco depende de `App\Training`. Cero regresiones: los 22 fallos heredados (Fortify/Vite/rutas) siguen siendo exactamente los mismos, confirmado línea por línea contra la línea base pre-Hito 4.

**RIESGOS**:
- El algoritmo de rotación/recuperación de `TrainingEngine` es una heurística simple de MVP (constantes fijas: 3 ejercicios/sesión, 5 días de descuido, umbral de RPE 7, +2.5 de carga o +10s de progresión) — no configurable todavía por tenant/usuario; candidato a ajuste cuando haya datos reales de uso.
- La selección de ejercicios dentro de un foco toma los primeros N que cumplen filtros (sin ponderar variedad/balance entre grupos musculares dentro de una sesión "full body") — simplificación aceptada, no bloquea la venta/entrega del primer entrenamiento.
- `SafetySignalDetector` es un backstop determinista por palabras clave — no reemplaza la clasificación real del LLM (Extract), que todavía no existe porque no hay Handler conversacional; ambas piezas deberán combinarse cuando llegue ese Handler.
- Ningún código de este hito llama a `TrainingEngine`/`TrainingAccessGate` desde un Handler real — son piezas de dominio completamente probadas pero sin integración conversacional todavía (a propósito, ver alcance del Hito 4).
- El mensaje de escalamiento y la lista de patrones de `SafetySignalDetector` están marcados como pendientes de revisión profesional/de negocio — no deben usarse con usuarios reales sin esa validación.

### D019 — Primer flujo conversacional de Training (Hito 5): Router con clasificadores, onboarding Extract→Decide→Narrate, primer ContextProvider real

**CONTEXTO**: hasta el Hito 4, el dominio Training existía (datos + `TrainingEngine`/`TrainingAccessGate` probados) pero sin ningún Handler ni Intent real — nada en una conversación de WhatsApp podía llegar a generar una `WorkoutSession`. El Hito 5 pedía el primer flujo conversacional real: `WhatsApp → Router → TrainingHandler → TrainingEngine → WorkoutSession → respuesta + video`, con onboarding conversacional progresivo y soporte de audio.

**ALTERNATIVAS evaluadas**:
- Router: seguir devolviendo siempre `Intent::FallbackChat` con un `if` hardcodeado a `TrainingIntentClassifier` (viola la regla de dependencia de una sola vía verificada por `CoreIsolationArchTest`) vs. un contrato `IntentClassifierInterface` + lista ordenada resuelta vía Container (mismo patrón que `Dispatcher`/`ContextBuilder`).
- Clasificación de intents: LLM en cada mensaje entrante (más preciso, pero costo/latencia en el 100% del tráfico, incluido lo que hoy va a `fallback_chat`) vs. palabras clave deterministas + señal de "perfil incompleto" (barato, siempre disponible, suficiente para 2 intents).
- `TrainingProfile.goal`/`experience_level` `NOT NULL` (esquema de Hito 4) vs. nullable, para soportar onboarding progresivo.
- Onboarding: un único LLM call combinando extracción + decisión de la siguiente pregunta (mezclaría Decide dentro del LLM) vs. mantener Extract/Decide/Narrate como tres pasos separados, con Decide 100% en código.
- Señal de seguridad durante onboarding: una segunda llamada a IA dedicada exclusivamente a detectarla vs. incluir un campo `safety_signal_text` opcional en la misma llamada de extracción, evaluado luego por `SafetySignalDetector` (determinista).
- Video: mecanismo propio de envío vs. extender `WhatsAppService::sendWhatsAppImage()` a un `sendWhatsAppVideo()` análogo.

**DECISIÓN TOMADA**:
1. **`Router` con clasificadores registrados** (`App\Core\Messaging\IntentClassifierInterface`): Router prueba una lista ordenada de FQCN resueltos vía Container, devuelve el primer `Intent` no nulo, o `Intent::FallbackChat` si ninguno reconoce el mensaje. `App\Training\Support\TrainingIntentClassifier` es el primero registrado — vive en Domain, Router no conoce su vocabulario. Extiende, no reemplaza, el patrón ya usado por `Dispatcher`/`ContextBuilder`.
2. **Clasificación 100% determinista este hito, sin LLM**: palabras clave en español + "¿el `Contact` tiene un `TrainingProfile` incompleto?" (para continuar el onboarding sin repetir palabras clave — ej. responder solo "3 veces por semana"). Clasificación asistida por LLM para frases que las palabras clave no cubran queda diferida explícitamente — sin evidencia de que sea necesaria todavía, y encarecería cada mensaje entrante, incluidos los que van a `fallback_chat`.
3. **`TrainingProfile.goal`/`experience_level` pasan a nullable**, editando la migración histórica del Hito 4 (mismo criterio que D011: sin datos de producción todavía). Es la única forma de que "conservar el progreso si el usuario responde parcialmente" sea posible — un perfil de onboarding en curso debe poder existir incompleto.
4. **Onboarding = Extract → Decide → Narrate real, en dos llamadas de IA por turno** (`App\Training\Support\OnboardingConversationService`): `extractFields()` (Extract, JSON validado — cualquier valor inválido o alucinado se descarta antes de persistirse) y `nextQuestion()` (Narrate, solo redacta una pregunta sobre el campo que el código ya decidió que falta vía `TrainingProfile::firstMissingOnboardingField()`, determinista). Si el proveedor de IA falla en cualquiera de las dos llamadas, se degrada a un valor seguro (extracción vacía / pregunta canónica) — el onboarding nunca se rompe por una caída de IA.
5. **`safety_signal_text` viaja dentro de la misma llamada de extracción**, no como una segunda llamada de IA dedicada — el LLM puede señalar una frase preocupante como parte de su salida JSON normal; `SafetySignalDetector::detect()` evalúa esa frase con las mismas reglas deterministas que ya evalúa el texto crudo del mensaje. Ninguna fuente decide sola: el LLM solo señala, el detector decide.
6. **`TrainingHandler` verifica `TrainingAccessGate::authorize()` explícitamente antes de invocar `TrainingEngine`** (aunque el Engine también lo verifica internamente) — permite responder con el mensaje correcto según el motivo exacto de bloqueo (`no_access`/`access_invalid`/`safety_flagged`) en un solo lugar, siguiendo el flujo pedido literalmente.
7. **El mensaje de entrega del entrenamiento y los videos se construyen de forma determinista** desde `WorkoutExercise` (nunca vía LLM) — evita que el LLM pueda alterar series/repeticiones/cargas reales al momento de comunicarlas.
8. **`WhatsAppService::sendWhatsAppVideo()`** — nuevo método, calcado de `sendWhatsAppImage()` ya existente. No asume un proveedor de hosting de video definitivo.
9. **Audio de entrada no requirió cambios en `Ingest`**: la transcripción ya ocurre antes de que el Router exista como concepto (Hito 2) — Training la hereda gratis. `TrainingHandler` solo recorta el prefijo decorativo `"🎤 [AUDIO]: "` antes de usar el texto en prompts.

**JUSTIFICACIÓN**: cada extensión reutiliza un patrón ya aprobado (Container-resolved-map para Router, Extract→Decide→Narrate para onboarding, snapshot ya existente de Hito 4 para el mensaje determinista) en vez de introducir uno nuevo. La clasificación determinista y el bundling del safety-signal en una sola llamada evitan sobreingeniería (dos llamadas de IA innecesarias) sin sacrificar los requisitos pedidos.

**IMPACTO**: `Router` cambia de firma (ahora requiere `Container` + lista de clasificadores — registrado como singleton en `AppServiceProvider`); `Intent::Training` nuevo caso; `Dispatcher` gana `training => TrainingHandler`; `ContextBuilder` gana `training_profile => TrainingProfileContextProvider`. Archivos nuevos: `App\Core\Messaging\IntentClassifierInterface`, `App\Training\Support\{TrainingIntentClassifier,OnboardingConversationService}`, `App\Training\Handlers\TrainingHandler`, `App\Training\Memory\TrainingProfileContextProvider`. 25 tests nuevos (`RouterTest` +3, `tests/Feature/Training/*` +22). Cero regresiones: los mismos 22 fallos heredados, confirmado línea por línea contra la línea base pre-Hito 5. `tests/Feature/Core/RouterTest.php` se reescribió por completo (la firma de `Router` cambió) sin que eso representara pérdida de cobertura — cubre ahora el mecanismo genérico con clasificadores de prueba, dejando la cobertura del clasificador real de Training en `tests/Feature/Training/`.

**RIESGOS**:
- Clasificación por palabras clave puede tener falsos negativos (frases que expresan intención de entrenar sin usar ninguna palabra de la lista) — aceptado como limitación de MVP, sin datos de uso real todavía que justifiquen un clasificador más sofisticado.
- `OnboardingConversationService` hace 1-2 llamadas de IA por turno mientras el onboarding está incompleto — costo aceptado por ser conversacional y de duración acotada (5 campos como máximo).
- El mensaje de acceso requerido y el de escalamiento de seguridad son deterministas/canned — no personalizados por IA; se prioriza previsibilidad y testabilidad sobre naturalidad en estos dos casos específicos.
- No existe todavía ningún flujo para que el usuario reporte la ejecución real (RPE/sets) de un entrenamiento entregado — el Hito 5 llega hasta la entrega, no hasta el registro de vuelta.

### D020 — Reporte de ejecución + persistencia conversacional (Hito 6): ExecutionReportService/Recorder, segundo ContextProvider, clasificador extendido, WhatsAppMessage resuelto

**CONTEXTO**: el Hito 5 dejó explícitamente pendiente cerrar el ciclo prescripción→ejecución (RPE/sets reales nunca se escribían) y una deuda confirmada: los mensajes de Training no se persistían en `WhatsAppMessage`. El Hito 6 pedía ambas cosas, sin un intent nuevo, sin rediseñar `TrainingEngine`, y con validación estricta de que el LLM nunca invente datos faltantes.

**ALTERNATIVAS evaluadas**:
- Resolver a qué ejercicio se refiere un reporte: dejar que el LLM elija libremente un nombre de ejercicio (riesgo de alucinar uno inexistente) vs. restringir la elección del LLM a la lista exacta de ejercicios sin reportar de la sesión activa, validada después contra esa misma lista.
- RPE cualitativo ("estuvo pesado"): que el LLM devuelva directamente un número vs. que solo clasifique en una categoría cerrada (`App\Training\Enums\RpeCategory`) y una tabla determinista en código haga la conversión a número.
- Identificar la sesión activa: consulta ad hoc dentro de `TrainingHandler` vs. un segundo `ContextProvider` (`active_workout_session`) reutilizable y con la misma disciplina de confianza que `training_profile`.
- Clasificación del Router: mantenerla igual (solo palabras clave + onboarding incompleto) — insuficiente, un reporte real casi nunca trae una palabra clave — vs. agregar una tercera señal determinista ("¿hay una `WorkoutSession` pendiente para este `Contact`?").
- Mensajes duplicados/repetidos: un mecanismo de idempotencia nuevo y dedicado vs. apoyarse en la idempotencia de WAMID ya existente (Hito 1, a nivel de Controller) más una verificación determinista de "¿este `WorkoutExercise` ya tiene `ExerciseLog`?" antes de escribir.
- Persistencia de conversación: registrar solo lo que se envía por HTTP exitosamente vs. persistir siempre (entrante y saliente) independientemente del resultado del envío, igual que `FallbackChatHandler`.

**DECISIÓN TOMADA**:
1. **`App\Training\Support\ExecutionReportService`** (Extract): un LLM call por turno, solo cuando hay una `WorkoutSession` pendiente con ejercicios sin reportar. El prompt restringe `exercise_name` a la lista exacta de ejercicios reportables; toda salida (sets, RPE, nota, booleanos) se valida antes de devolverse — una serie sin ningún dato cuantificable se descarta, nunca se guarda vacía ni se completa con la prescripción.
2. **RPE: LLM clasifica, código convierte.** `App\Training\Enums\RpeCategory` (vocabulario cerrado: very_easy/easy/moderate/hard/very_hard) + una tabla determinista (`RPE_CATEGORY_MAP`) en `ExecutionReportService` son lo único que produce un número a partir de lenguaje cualitativo. Un número explícito del usuario, si es válido (1-10), tiene prioridad sobre la categoría.
3. **`App\Training\Support\ExecutionReportRecorder`** (Decide, 100% determinista): resuelve cada reporte contra los `WorkoutExercise` sin reportar de la sesión activa — nunca contra el catálogo `Exercise` completo, haciendo estructuralmente imposible registrar un ejercicio ajeno a la sesión. Sin nombre explícito, solo asume si hay exactamente un ejercicio pendiente. Un ejercicio ya reportado nunca se reescribe ni duplica. Cierra la `WorkoutSession` (`completed`) cuando todo quedó reportado, o antes si el usuario indica explícitamente que terminó (permite sesión parcialmente completada).
4. **`App\Training\Memory\ActiveWorkoutSessionContextProvider`** (segundo `ContextProvider` real, clave `active_workout_session`): expone únicamente `workout_session_id` y los ejercicios sin reportar (id, nombre, tipo de seguimiento) — nunca el `exercise_snapshot` completo. `confidence: 'unknown'` sin sesión pendiente.
5. **`TrainingIntentClassifier` gana una tercera señal**: además de palabra clave y onboarding incompleto, un `Contact` con una `WorkoutSession` en estado `scheduled` también clasifica como `training` — un reporte de ejecución casi nunca contiene una palabra clave de la lista original.
6. **Idempotencia**: no se construyó un mecanismo nuevo. Se apoya en (a) la deduplicación de WAMID ya existente a nivel de Controller (evita que el mismo mensaje de WhatsApp dispare el Job dos veces) y (b) una verificación determinista en `ExecutionReportRecorder` (`$resolved->exerciseLog !== null` → se omite, se registra `TRAINING_REPORT_DUPLICATE_SKIPPED`) como segunda capa de protección a nivel de datos.
7. **Persistencia conversacional**: `TrainingHandler::logInbound()` (una vez por turno, sin importar el subflujo) y `TrainingHandler::reply()` (reemplaza toda llamada directa a `WhatsAppService::sendMessage()`, agregando además `WhatsAppStatusTracker::trackMessage()`) — mismo patrón exacto que `FallbackChatHandler` ya usaba. Los videos de ejercicios se envían pero no generan fila en `WhatsAppMessage` — mismo criterio que las imágenes de producto.
8. **Separación estricta memoria conversacional / historial de entrenamiento**: `ExecutionReportRecorder` nunca toca `WhatsAppMessage`; `logInbound()`/`reply()` nunca tocan `ExerciseLog`/`ExerciseSet`. Se correlacionan implícitamente solo vía `Contact` (tenant_id + customer_phone), nunca por FK directa entre ambos modelos.
9. **`TrainingEngine` no se modificó**: ya leía `ExerciseLog`/`ExerciseSet` correctamente (`progressionFor()`, desde Hito 4) para decidir progresión; con datos reales ahora existentes, funciona sin cambios. Se verificó explícitamente que no había ningún defecto que corregir.

**JUSTIFICACIÓN**: cada pieza nueva sigue un patrón ya validado en hitos previos (Extract→Decide→Narrate, `ContextProvider` con confianza tipada, Container-resolved-map para el Router) — nada de esto es un patrón nuevo, es la tercera vez que se aplica el mismo criterio arquitectónico. Restringir la resolución de ejercicios a la lista de la sesión activa (nunca al catálogo completo) es lo que hace **imposible por construcción**, no solo por convención, registrar un ejercicio ajeno a la sesión.

**IMPACTO**: `TrainingIntentClassifier` y `TrainingHandler` modificados; `ContextBuilder` gana `active_workout_session`. Archivos nuevos: `App\Training\Enums\RpeCategory`, `App\Training\Memory\ActiveWorkoutSessionContextProvider`, `App\Training\Support\{ExecutionReportService,ExecutionReportRecorder,ExecutionReportOutcome}`. 24 tests nuevos (`tests/Feature/Training/{ExecutionReportServiceTest,ExecutionReportFlowTest}.php`). Cero regresiones: los mismos 22 fallos heredados, confirmado línea por línea contra la línea base pre-Hito 6. Se resuelve la deuda de Hito 5 sobre persistencia de `WhatsAppMessage`.

**RIESGOS**:
- La resolución "un solo ejercicio pendiente → se asume sin nombre" puede fallar si el usuario reporta fuera de orden en una sesión con 2+ ejercicios pendientes sin nombrar ninguno — pide aclaración en ese caso, no adivina; aceptado como límite conocido del MVP.
- El umbral de "sin dato cuantificable ni cualitativo → preguntar" puede sentirse repetitivo si el usuario reporta de forma muy vaga repetidamente — sin datos de uso real todavía que justifiquen un manejo más sofisticado.
- La idempotencia a nivel de datos (verificación de `ExerciseLog` existente) protege contra reportos duplicados del mismo ejercicio, pero no contra un usuario que genuinamente quiere corregir un valor ya registrado — no hay mecanismo de corrección explícita todavía (fuera de alcance de este hito).
- Persistir siempre el mensaje entrante en `WhatsAppMessage`, incluso en las ramas de bloqueo por seguridad/acceso, significa que un mensaje con una señal de seguridad grave queda en texto plano en la tabla de conversación — mismo nivel de exposición que ya existe para `FallbackChatHandler`, no una regresión, pero tampoco una mejora de privacidad.

### D021 — Validación E2E con Meta/WhatsApp real (Hito 7): auditoría de prerequisitos, instrumentación, tests con payload real, video verificado — sin ejecución real de Meta

**CONTEXTO**: el Hito 7 pedía demostrar el flujo completo funcionando contra Meta/WhatsApp real (no solo tests internos), incluyendo confirmar visualmente que un video se reproduce dentro del chat de WhatsApp. Antes de tocar código, se auditó explícitamente si el entorno actual tenía lo necesario — instrucción explícita del propio hito ("verifica que Meta y las credenciales actuales estén disponibles").

**HALLAZGO (bloqueante, reportado antes de proceder)**: el entorno de desarrollo no tiene lo necesario para una prueba real end-to-end:
- `APP_URL=http://localhost` — sin URL pública, Meta no puede entregar webhooks.
- La base de datos `wpbottrainer` (desarrollo) tiene **0 filas en `tenants`** — ninguna credencial de Meta configurada, ni siquiera de prueba.
- El sitio `wpbottrainer` no estaba servido por Herd (`herd links` no lo listaba).
- Confirmar visualmente la reproducción de un video dentro de WhatsApp y enviar mensajes reales desde un teléfono son acciones que requieren intervención humana directa — no son automatizables ni simulables sin faltar a la honestidad de lo reportado.

Se consultó al usuario antes de proceder (no se decidió unilateralmente cómo resolver un hallazgo estructural, tal como pide el propio hito). La instrucción recibida: preparar todo lo automatizable, no inventar credenciales, no marcar ningún escenario como exitoso sin haberlo ejecutado realmente, no modificar la integración Meta existente salvo defecto concreto encontrado, identificar exactamente qué necesita el administrador, y entregar un runbook — sin ejecutar la prueba en vivo todavía.

**ALTERNATIVAS evaluadas** para lo que sí se podía hacer sin Meta real:
- Simular "éxito" de los escenarios A-E con fixtures y reportarlos como si fueran la prueba real pedida — **rechazado explícitamente**: sería reportar un resultado no verificado como verificado.
- Dejar el hito completamente bloqueado sin avanzar nada — rechazado: hay trabajo real y valioso ejecutable sin credenciales (instrumentación, tests con el payload exacto de Meta, verificación del recurso de video).
- Construir la instrumentación de observabilidad ahora vs. esperar a tener tráfico real para diseñarla — se decidió construirla ahora: es exactamente lo que el hito pidió medir, y no depende de que el tráfico sea real para ser correcta.

**DECISIÓN TOMADA**:
1. **Instrumentación de observabilidad real** (sin dashboard, logs estructurados): `JOB_START`/`JOB_END` con `elapsed_ms` total y `outcome` (`app/Jobs/ProcessWhatsAppMessage.php`), `ROUTER_CLASSIFIED` con el intent resuelto y su tiempo, tiempo de transcripción agregado al log ya existente de `Ingest` (éxito y fallo), `CONTEXT_BUILDER_RESULT` (clave, confidence, tamaño aproximado en bytes) envolviendo las dos llamadas a `ContextBuilder` en `TrainingHandler`, `TRAINING_ENGINE_DECIDED` con tiempo de `TrainingEngine::decideNextSession()`, `TRAINING_VIDEO_SENT`/`TRAINING_VIDEO_SEND_FAILED` por cada video con su tiempo, y `TRAINING_META_SEND_FAILED` cuando `WhatsAppService::sendMessage()` devuelve null. Todo aditivo — ninguna lógica de negocio cambió.
2. **Tests con el payload EXACTO de Meta, vía la ruta HTTP real** (`tests/Feature/MetaWebhookTrainingE2ETest.php`): a diferencia de todos los tests de Hitos 4-6 (que construían `ProcessWhatsAppMessage` directamente en PHP), estos hacen `postJson('/api/whatsapp/webhook/...')` con la estructura completa que Meta envía (`object`, `entry[].id`, `changes[].field`, `contacts`, `timestamp`), aprovechando que `QUEUE_CONNECTION=sync` en testing ejecuta el Job en la misma petición — permite afirmar sobre el estado final en base de datos después de un POST real. Cubre payload de texto, de audio, idempotencia de WAMID reintentado, fallo de Meta al enviar (verificando que el webhook sigue respondiendo 200 para no generar reintentos infinitos de Meta), fallo del proveedor de IA, y aislamiento entre dos tenants.
3. **Video de prueba real, verificado, con licencia clara**: se descargaron y verificaron (headers HTTP + descarga completa) tres candidatos; se eligió el tráiler oficial de *Big Buck Bunny* (Blender Foundation) alojado en Internet Archive — `video/mp4`, H.264, 2.93 MB (bajo el límite de 16 MB de Meta), servido con `Accept-Ranges: bytes` sobre HTTPS válido, ~2.5s de latencia de descarga completa desde este entorno. Licencia **CC BY 3.0**: permite uso comercial y redistribución con atribución — para esta validación interna (sin distribución a usuarios finales reales) no aplica la obligación de atribución visible, pero queda documentada para cuando se decida un proveedor de video definitivo.
4. **Cero cambios a la integración Meta existente** (`WhatsAppController`, `WhatsAppService`) más allá de la instrumentación aditiva — se verificó que el parseo del payload real ya funcionaba correctamente (los nuevos tests con payload completo pasan sin haber tenido que corregir nada), así que no había ningún defecto concreto que justificara tocarla.
5. **Runbook explícito** (`docs/E2E_META_RUNBOOK.md`): checklist exacto de qué credenciales necesita el administrador (`wa_access_token`, `wa_phone_number_id`, `wa_business_account_id`, `wa_verify_token`, `ai_api_key`, número de teléfono de prueba autorizado en Meta), cómo levantar el túnel (`herd share`, ya autenticado), cómo crear el `Tenant`/`Exercise` de prueba, cómo configurar el webhook en Meta, y los 5 escenarios A-E a ejecutar con lo que observar en cada uno — para que la prueba real se pueda ejecutar en cuanto las credenciales estén disponibles.

**JUSTIFICACIÓN**: reportar honestamente un bloqueador de infraestructura/credenciales, en vez de fabricar resultados, es coherente con el estándar de reporte de todo el proyecto hasta ahora ("informar outcomes de forma fiel"). Todo lo construido en este hito (instrumentación, tests con payload real, verificación del video) tiene valor real inmediato independientemente de cuándo se ejecute la prueba en vivo — no es trabajo especulativo, es exactamente lo que el hito pidió medir/preparar.

**IMPACTO**: `app/Jobs/ProcessWhatsAppMessage.php`, `app/Core/Messaging/Ingest.php`, `App\Training\Handlers\TrainingHandler` modificados (solo logs, sin cambio de comportamiento). Archivo nuevo: `tests/Feature/MetaWebhookTrainingE2ETest.php` (6 tests) y `docs/E2E_META_RUNBOOK.md`. Cero regresiones: los mismos 22 fallos heredados, confirmado línea por línea contra la línea base pre-Hito 7. **La prueba E2E real con Meta queda pendiente de ejecución** hasta que el administrador provea las credenciales del runbook.

**RIESGOS**:
- Sin ejecución real, no se puede garantizar que Meta procese/entregue el video exactamente como se espera (ancho de banda, caché de Meta, políticas de contenido) — solo se verificó el recurso en sí, no su paso por Meta.
- La latencia de descarga medida (~2.5s) es desde este entorno de desarrollo, no representativa de la latencia que experimenten los servidores de Meta al buscar la URL.
- El runbook asume un flujo manual (crear Tenant vía tinker, activar acceso manualmente) — coherente con que Payments todavía no existe (Hito 8).

---

## Deuda técnica y hallazgos documentados (Hitos 1-7, no corregidos, fuera de alcance)

- Con el Router ya extraído, `FallbackChatHandler` sigue conteniendo toda la lógica de negocio previa (catálogo de productos, extracción de lead) sin descomponer más — es la única forma de intent hoy, y descomponerla más no era el objetivo del Hito 2 ("extraer, no reescribir").
- Ampliar el mapa de intents del `Dispatcher`, clasificadores del `Router`, o proveedores del `ContextBuilder` requiere editar `AppServiceProvider` manualmente — aceptado como limitación conocida, evita sobreingeniería de auto-discovery.
- `Conversation.current_product_id` sigue siendo la implementación e-commerce del estado conversacional — ver D017, punto 7.
- Heurísticas de `TrainingEngine` (constantes de rotación/recuperación/progresión) son fijas en código, no configurables por tenant/perfil todavía.
- Clasificación de intents es 100% por palabras clave + señales de estado (onboarding incompleto, sesión pendiente) — sin fallback a LLM para frases no reconocidas por ninguna de las tres (ver D019, D020).
- `TrainingHandler` crea un `Contact` de control (`summary: 'Registro de Training'`) igual que `FallbackChatHandler` crea uno de lead — si algún día el mismo tenant mezclara ambos verticales (no ocurre en el modelo de un-despliegue-por-vertical actual), el guard anti-duplicados de `FallbackChatHandler` podría verse afectado por un Contact creado por Training en la última hora. No corregido: requeriría un discriminador de origen en `Contact`, sin justificación real bajo el modelo de despliegue actual.
- El panel `WhatsAppChatCenter` (Filament) todavía no distingue visualmente los hilos de Training de los de e-commerce — ambos ahora se persisten en `WhatsAppMessage` (resuelto en Hito 6), pero no hay ninguna etiqueta/filtro de "vertical" en el dashboard. No era parte del alcance explícito de este hito.
- No existe todavía un mecanismo de corrección explícita para un `ExerciseLog` ya registrado por error — la única protección es no duplicar; corregir un valor mal reportado requeriría una acción humana/administrativa no construida en este hito.
- Catálogo `Exercise` sin contenido real cargado — solo esquema y factories de test.
- **La prueba E2E real con Meta/WhatsApp (Hito 7) no se ha ejecutado** — falta que el administrador provea credenciales reales de un número de prueba de Meta. Runbook completo listo en `docs/E2E_META_RUNBOOK.md`. Hasta que se ejecute, no hay confirmación real de que un video se reproduzca dentro del chat de WhatsApp (solo se verificó el recurso de video en sí, no su paso por Meta).
- No hay ningún proveedor de video definitivo contratado — el video usado para la verificación de formato/tamaño (Big Buck Bunny, CC BY 3.0, Internet Archive) es solo para pruebas internas, no una decisión de producto.
- Los 3 campos ecommerce de `Contact` (ver D004).
- Bug preexistente **no corregido** (fuera del alcance explícito de este hito): `$contact->status` se lee/escribe en `WhatsAppController` y `WhatsAppChatCenter`, pero `status` no existe como columna ni está en `$fillable` de `Contact` — esas escrituras son no-ops silenciosos y esas lecturas siempre devuelven `null`.
- Posible bug preexistente en `dashboard.blade.php`: el enlace "Go to Tenant Settings" apunta a la misma ruta del propio dashboard.
- `resources/views/livewire/auth/register.blade.php` postea a `route('register.store')`, y existen `app/Actions/Fortify/CreateNewUser.php`/`ResetUserPassword.php`, pero **Laravel Fortify no está instalado** en `composer.json`. Es casi con certeza código muerto de un starter-kit no instalado, pero no se verificó al 100% ni se tocó — código de autenticación, fuera del pedido explícito.
- Ambigüedad de prefijo de URL de los recursos Filament: el panel tiene `path('')`, por lo que las rutas reales son `/contacts`, `/tenants`, etc. (sin prefijo `/admin`), pero el widget `UnprocessedContactsStats` (heredado de `UnprocessedLeadsStats`) usa una URL hardcodeada `/admin/contacts?...` — posible bug preexistente, no verificado en runtime, no corregido en este hito.
