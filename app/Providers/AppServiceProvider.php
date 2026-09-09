<?php

namespace App\Providers;

use App\Core\Alerts\AlertService;
use App\Core\Alerts\Channels\PersistedAlertChannel;
use App\Core\Alerts\Channels\WhatsAppAdminAlertChannel;
use App\Core\Memory\ContextBuilder;
use App\Core\Messaging\Dispatcher;
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
use App\Payments\Support\PaymentIntentClassifier;
use App\Referrals\Handlers\ReferralHandler;
use App\Referrals\Listeners\ApplyReferralRewardOnPaymentConfirmed;
use App\Referrals\Support\ReferralAttributionPreRoutingScreen;
use App\Referrals\Support\ReferralIntentClassifier;
use App\Training\Context\CoachContextProvider;
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
use App\Training\Support\SafetySignalPreRoutingScreen;
use App\Training\Support\TrainingIntentClassifier;
use App\Training\Support\TrainingReminderExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Core messaging Router: ordered list of IntentClassifier classes
        // (not instances) tried in sequence — the first one that recognizes
        // the message wins, defaulting to Intent::FallbackChat if none do.
        // See App\Core\Messaging\Router and docs/DECISIONS.md (D019).
        // Hito 14 — CustomerServiceEscalationIntentClassifier se prueba
        // PRIMERO: una petición explícita de ayuda humana debe ganarle a
        // cualquier colisión accidental de keyword con otro dominio (ej.
        // "Tengo un problema con el pago" contiene "pago", keyword de
        // PaymentIntentClassifier — ver docs/DECISIONS.md).
        // FaqLikelyIntentClassifier se prueba ÚLTIMO, justo antes del
        // default a FallbackChat — su heurística es deliberadamente amplia
        // y nunca debe competir con Training/Payment/Referral.
        $this->app->singleton(Router::class, fn ($app) => new Router($app, [
            CustomerServiceEscalationIntentClassifier::class,
            TrainingIntentClassifier::class,
            PaymentIntentClassifier::class,
            ReferralIntentClassifier::class,
            FaqLikelyIntentClassifier::class,
        ]));

        // Core messaging PreRoutingScreener (Hito 7, extendido Hito 13):
        // ordered list of PreRoutingScreen classes tried BEFORE Router,
        // regardless of what Intent the message would otherwise classify
        // as. SafetySignalPreRoutingScreen puede reclamar el pipeline
        // (retorna true); ReferralAttributionPreRoutingScreen NUNCA lo
        // reclama (siempre retorna false) — es puramente un efecto
        // secundario de atribución que debe correr sin importar a qué
        // Handler termine yendo el mensaje (ver docs/DECISIONS.md).
        // See App\Core\Messaging\PreRoutingScreener and docs/DECISIONS.md.
        $this->app->singleton(PreRoutingScreener::class, fn ($app) => new PreRoutingScreener($app, [
            SafetySignalPreRoutingScreen::class,
            ReferralAttributionPreRoutingScreen::class,
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

        // Hito 13 — primer listener real de PaymentConfirmed (el seam que
        // Hito 11 dejó preparado, sin consumidor hasta ahora). Registro
        // directo (no hay EventServiceProvider en este proyecto todavía) —
        // App\Payments no se toca ni se entera de que este listener existe.
        Event::listen(PaymentConfirmed::class, ApplyReferralRewardOnPaymentConfirmed::class);

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
