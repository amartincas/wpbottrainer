<?php

namespace App\Providers;

use App\Acquisition\Support\AcquisitionSourcePreRoutingScreen;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\Channels\PersistedAlertChannel;
use App\Core\Alerts\Channels\WhatsAppAdminAlertChannel;
use App\Core\Memory\ContextBuilder;
use App\Core\Messaging\Dispatcher;
use App\Core\Messaging\DomainFallbackResolver;
use App\Core\Messaging\Intent;
use App\Core\Messaging\PreRoutingScreener;
use App\Core\Messaging\Router;
use App\Core\Reminders\ReminderDispatcher;
use App\CustomerCare\Handlers\CustomerCareHandler;
use App\CustomerCare\Support\CustomerServiceEscalationIntentClassifier;
use App\CustomerCare\Support\FaqLikelyIntentClassifier;
use App\Handlers\FallbackChatHandler;
use App\Payments\Events\PaymentConfirmed;
use App\Payments\Handlers\PaymentHandler;
use App\Payments\Support\PaymentContextualIntentClassifier;
use App\Payments\Support\PaymentIntentClassifier;
use App\Referrals\Handlers\ReferralHandler;
use App\Referrals\Listeners\ApplyReferralRewardOnPaymentConfirmed;
use App\Referrals\Listeners\SendReferralIntroductionOnWorkoutCompleted;
use App\Referrals\Support\ReferralAttributionPreRoutingScreen;
use App\Referrals\Support\ReferralIntentClassifier;
use App\Training\Context\CoachContextProvider;
use App\Training\Events\WorkoutSessionCompleted;
use App\Training\Handlers\TrainingHandler;
use App\Training\Memory\ActiveWorkoutSessionContextProvider;
use App\Training\Memory\TrainingProfileContextProvider;
use App\Training\Onboarding\OnboardingRequirementRegistry;
use App\Training\Onboarding\Requirements\EquipmentRequirement;
use App\Training\Onboarding\Requirements\ExperienceLevelRequirement;
use App\Training\Onboarding\Requirements\GoalRequirement;
use App\Training\Onboarding\Requirements\HealthScreeningRequirement;
use App\Training\Onboarding\Requirements\NameRequirement;
use App\Training\Onboarding\Requirements\PhysicalStatsRequirement;
use App\Training\Onboarding\Requirements\PrimaryFocusRequirement;
use App\Training\Onboarding\Requirements\SessionsPerWeekRequirement;
use App\Training\Onboarding\Requirements\TrainingLocationRequirement;
use App\Training\Support\HealthScreeningPrecedencePreRoutingScreen;
use App\Training\Support\SafetySignalPreRoutingScreen;
use App\Training\Support\TrainingContextualIntentClassifier;
use App\Training\Support\TrainingDomainFallbackClaim;
use App\Training\Support\TrainingIntentClassifier;
use App\Training\Support\TrainingReminderExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use App\Services\AI\GeminiService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Core messaging Router: ordered TIERS of IntentClassifier classes
        // (not instances) — un tier se agota completo (todos sus
        // classifiers devuelven null) antes de intentar el siguiente;
        // dentro de un tier, el primero que reconoce el mensaje gana. Router
        // por defecto a Intent::FallbackChat si ningún tier produce nada.
        // Ver App\Core\Messaging\Router y docs/DECISIONS.md (D019, y la
        // decisión de precedencia de Intents en 3 tiers).
        //
        // Tier 0 — Escalamiento explícito: CustomerServiceEscalationIntentClassifier
        // se prueba PRIMERO: una petición explícita de ayuda humana debe
        // ganarle a cualquier colisión accidental de keyword con otro
        // dominio (ej. "Tengo un problema con el pago" contiene "pago",
        // keyword de PaymentIntentClassifier — ver docs/DECISIONS.md).
        //
        // Tier 1 — Señales EXPLÍCITAS de dominio: Training/Payment/Referral
        // (solo su mitad de keywords — ver TrainingIntentClassifier/
        // PaymentIntentClassifier).
        //
        // Tier 2 — Señales CONTEXTUALES (solo por estado del Contact, sin
        // ninguna señal textual): TrainingContextualIntentClassifier/
        // PaymentContextualIntentClassifier. Un Contact con cualquier estado
        // contextual de Training/Payment sigue pudiendo expresar
        // explícitamente OTRA intención (ej. "Quiero invitar a un amigo"
        // con una WorkoutSession pendiente termina en Referral, no en
        // Training) — el bug real que esta estructura corrige. Verificado
        // por IntentPrecedenceArchTest que ningún classifier marcado
        // ContextualIntentClassifierInterface aparece en el Tier 0/1.
        //
        // Tier 3 — FaqLikelyIntentClassifier, en su PROPIO tier, el ÚLTIMO
        // de todos (hallazgo real durante la validación de esta corrección,
        // no parte del diseño original): su heurística reconoce palabras
        // interrogativas sueltas ("que", "qué", "cómo", "cuánto"...), que
        // aparecen con frecuencia DENTRO de mensajes reales de Training
        // ("Creo QUE hice 10 repeticiones", "¿CUÁNTO me queda de
        // membresía?", "¿por QUÉ ese peso?"). Si compartiera el Tier 1 con
        // Training/Payment/Referral (como se planteó originalmente),
        // interceptaría esos mensajes ANTES de que el Tier 2 (contextual)
        // tuviera oportunidad de reconocerlos como Training — regresión
        // real, detectada por 10 tests preexistentes que empezaron a fallar
        // (ExecutionReportFlowTest, TrainingHandlerInterruptionTest,
        // ProductInteractionDuringTrialE2ETest, entre otros). Faq debe
        // seguir siendo el ÚLTIMO recurso de clasificación textual, después
        // de que TODA señal de dominio (explícita o contextual) ya haya
        // tenido su oportunidad — nunca al mismo nivel que ellas. Ver
        // docs/DECISIONS.md.
        $this->app->singleton(Router::class, fn ($app) => new Router($app, [
            [CustomerServiceEscalationIntentClassifier::class],
            [
                TrainingIntentClassifier::class,
                PaymentIntentClassifier::class,
                ReferralIntentClassifier::class,
            ],
            [
                TrainingContextualIntentClassifier::class,
                PaymentContextualIntentClassifier::class,
            ],
            [FaqLikelyIntentClassifier::class],
        ]));

        // Core messaging PreRoutingScreener (Hito 7, extendido Hito 13,
        // extendido P1-B): ordered list of PreRoutingScreen classes tried
        // BEFORE Router, regardless of what Intent the message would
        // otherwise classify as. SafetySignalPreRoutingScreen puede
        // reclamar el pipeline (retorna true); ni
        // ReferralAttributionPreRoutingScreen ni
        // AcquisitionSourcePreRoutingScreen lo reclaman NUNCA (siempre
        // retornan false) — son puramente efectos secundarios de
        // atribución que deben correr sin importar a qué Handler termine
        // yendo el mensaje (ver docs/DECISIONS.md).
        //
        // P1-B — AcquisitionSourcePreRoutingScreen va DESPUÉS de
        // ReferralAttributionPreRoutingScreen a propósito: necesita poder
        // ver, en la MISMA pasada, si ese screen (sin modificar) acaba de
        // crear un Referral para este Contact, para clasificar
        // correctamente source=referral en vez de caer a organic. Caso
        // límite documentado, no resuelto aquí: si SafetySignalPreRoutingScreen
        // reclama el pipeline en el primer mensaje de un Contact nuevo,
        // AcquisitionSourcePreRoutingScreen nunca llega a ejecutarse para
        // ese mensaje y la atribución de Meta Ads de ese mensaje se
        // pierde — no se reordena Safety para evitar esto.
        //
        // Hito A (Health Screening Precedence) — HealthScreeningPrecedencePreRoutingScreen
        // va DESPUÉS de SafetySignalPreRoutingScreen (una señal de
        // emergencia real — dolor de pecho, etc. — siempre gana) y ANTES
        // de Referral/Acquisition (nunca interfiere con su atribución: la
        // pregunta de salud solo puede estar pendiente después de que el
        // onboarding ya avanzó varios turnos, momento en el que la
        // atribución del primer mensaje ya se resolvió hace tiempo). Ver
        // App\Training\Support\HealthScreeningPrecedencePreRoutingScreen.
        // See App\Core\Messaging\PreRoutingScreener and docs/DECISIONS.md.
        $this->app->singleton(PreRoutingScreener::class, fn ($app) => new PreRoutingScreener($app, [
            SafetySignalPreRoutingScreen::class,
            HealthScreeningPrecedencePreRoutingScreen::class,
            ReferralAttributionPreRoutingScreen::class,
            AcquisitionSourcePreRoutingScreen::class,
        ]));

        // Core AlertService (Hito 7.1): infraestructura transversal, no
        // pertenece a Training ni a Payments — cualquier dominio puede
        // emitir una Alert sin saber por qué canal ni a quién llega. Mismo
        // patrón Container-resuelto, pero con fan-out (todos los canales que
        // "soporten" la Alert la reciben, no solo el primero). Ver
        // App\Core\Alerts\AlertService y docs/DECISIONS.md.
        $this->app->singleton(AlertService::class, fn ($app) => new AlertService($app, [
            PersistedAlertChannel::class,
            WhatsAppAdminAlertChannel::class,
        ]));

        // Core messaging Dispatcher: maps each Intent to the Handler class
        // (not an instance) that resolves it. The Container builds the actual
        // instance at dispatch time — see App\Core\Messaging\Dispatcher and
        // docs/DECISIONS.md (D016). Adding a new intent means adding a line
        // here, nothing else in Core needs to change.
        $this->app->singleton(Dispatcher::class, fn ($app) => new Dispatcher($app, [
            Intent::FallbackChat->value => FallbackChatHandler::class,
            Intent::Training->value => TrainingHandler::class,
            Intent::Payment->value => PaymentHandler::class,
            Intent::Referral->value => ReferralHandler::class,
            Intent::CustomerCare->value => CustomerCareHandler::class,
        ]));

        // Hito A (Entry/Domain Fallback) — mismo patrón Container-resuelto
        // que Router/Dispatcher/PreRoutingScreener: mapa de CLASES de
        // App\Core\Messaging\DomainFallbackClaimInterface, probadas en
        // orden. Se invoca ÚNICAMENTE cuando Router::route() ya retornó
        // Intent::FallbackChat (ver App\Jobs\ProcessWhatsAppMessage) —
        // nunca para todo mensaje, a diferencia de un PreRoutingScreen.
        // Contiene cero conocimiento de dominio — TrainingDomainFallbackClaim
        // es la única implementación real hoy, registrada aquí por nombre
        // exactamente igual que TrainingHandler/TrainingIntentClassifier ya
        // lo están arriba. Ver App\Core\Messaging\DomainFallbackResolver.
        $this->app->singleton(DomainFallbackResolver::class, fn ($app) => new DomainFallbackResolver($app, [
            TrainingDomainFallbackClaim::class,
        ]));

        // Core memory ContextBuilder: same Container-resolution pattern as
        // Dispatcher, but for structured memory providers instead of intent
        // Handlers. `training_profile` (Hito 5) and `active_workout_session`
        // (Hito 6) are memory/historial providers — see
        // App\Training\Memory\{TrainingProfileContextProvider,
        // ActiveWorkoutSessionContextProvider} and docs/DECISIONS.md (D019, D020).
        // `coach_context` (Bloque 9, D052) vive en App\Training\Context — NO
        // en App\Training\Memory — porque compone contexto de dominio
        // estructurado para una interacción puntual con el LLM, nunca
        // memoria persistida en sí misma. Mismo mecanismo genérico, sin
        // ningún cambio en ContextBuilder/ContextProviderInterface.
        $this->app->singleton(ContextBuilder::class, fn ($app) => new ContextBuilder($app, [
            'training_profile' => TrainingProfileContextProvider::class,
            'active_workout_session' => ActiveWorkoutSessionContextProvider::class,
            'coach_context' => CoachContextProvider::class,
        ]));

        // Bloque 4 — OnboardingRequirementRegistry: mismo patrón de
        // Router/Dispatcher (mapa de CLASES, no instancias, resuelto por el
        // Container en cada uso). El ORDEN de este array es la prioridad de
        // preguntas — agregar un requirement nuevo es una clase + una línea
        // aquí, sin tocar TrainingHandler/TrainingEngine (ver docs/DECISIONS.md D047).
        // Bloque 5 (D048): HealthScreeningRequirement REEMPLAZA a
        // RestrictionsRequirement en esta posición — una sola pregunta de
        // screening, nunca dos independientes sobre lo mismo.
        // RestrictionsRequirement.php se conserva sin borrar (documentación
        // histórica), pero deja de estar registrada — TrainingProfile.restrictions
        // ya no recibe escrituras nuevas desde este bloque.
        // Capa 1 (bloqueante): Name, Goal, ExperienceLevel, TrainingLocation,
        // Equipment, HealthScreening.
        // Capa 2 (oportunista, nunca bloquea): SessionsPerWeek, PrimaryFocus,
        // PhysicalStats.
        // Hito 10 — ReminderDispatcher: mismo patrón Container-resuelto que
        // Dispatcher/Router/PreRoutingScreener (mapa de CLASES, no
        // instancias). `training_weekly`/`training_one_off` comparten hoy
        // el mismo ejecutor de dominio — un segundo tipo de Reminder no
        // relacionado con Training solo necesitaría una línea más aquí.
        $this->app->singleton(ReminderDispatcher::class, fn ($app) => new ReminderDispatcher($app, [
            'training_weekly' => TrainingReminderExecutor::class,
            'training_one_off' => TrainingReminderExecutor::class,
        ]));

        $this->app->singleton(OnboardingRequirementRegistry::class, fn ($app) => new OnboardingRequirementRegistry($app, [
            NameRequirement::class,
            GoalRequirement::class,
            ExperienceLevelRequirement::class,
            TrainingLocationRequirement::class,
            EquipmentRequirement::class,
            HealthScreeningRequirement::class,
            SessionsPerWeekRequirement::class,
            PrimaryFocusRequirement::class,
            PhysicalStatsRequirement::class,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        
        Schema::defaultStringLength(191);

        if (app()->environment('production') || env('FORCE_HTTPS')) {
            URL::forceScheme('https');
        }

        $this->configureDefaults();

        // Controles P0 de lanzamiento — capa 1 de rate limiting del webhook
        // de WhatsApp: protección genérica por IP, aplicada vía
        // 'throttle:whatsapp-webhook-ip' únicamente sobre la ruta POST (ver
        // routes/api.php). Deliberadamente generosa (config
        // 'services.meta.webhook_ip_rate_limit') — solo frena un flood bruto
        // al endpoint; la protección real de costo de IA es la capa 2
        // (por tenant+contacto, ver WhatsAppController::handle()).
        RateLimiter::for('whatsapp-webhook-ip', function (Request $request) {
            return Limit::perMinute(config('services.meta.webhook_ip_rate_limit'))->by($request->ip());
        });

        // Hito 13 — primer listener real de PaymentConfirmed (el seam que
        // Hito 11 dejó preparado, sin consumidor hasta ahora). Registro
        // directo (no hay EventServiceProvider en este proyecto todavía) —
        // App\Payments no se toca ni se entera de que este listener existe.
        Event::listen(PaymentConfirmed::class, ApplyReferralRewardOnPaymentConfirmed::class);

        // Referral Introduction — mismo patrón exacto que el registro de
        // arriba: App\Training no se toca ni se entera de que este listener
        // existe (el evento es genérico, del dominio Training; el listener
        // vive en App\Referrals y decide qué hacer con él).
        Event::listen(WorkoutSessionCompleted::class, SendReferralIntroductionOnWorkoutCompleted::class);

        // Register Livewire components
        Livewire::component('whats-app-chat-center', \App\Livewire\WhatsAppChatCenter::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
