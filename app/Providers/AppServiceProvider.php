<?php

namespace App\Providers;

use App\Core\Memory\ContextBuilder;
use App\Core\Messaging\Dispatcher;
use App\Core\Messaging\Intent;
use App\Core\Messaging\Router;
use App\Handlers\FallbackChatHandler;
use App\Training\Handlers\TrainingHandler;
use App\Training\Memory\ActiveWorkoutSessionContextProvider;
use App\Training\Memory\TrainingProfileContextProvider;
use App\Training\Support\TrainingIntentClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->app->singleton(Router::class, fn ($app) => new Router($app, [
            TrainingIntentClassifier::class,
        ]));

        // Core messaging Dispatcher: maps each Intent to the Handler class
        // (not an instance) that resolves it. The Container builds the actual
        // instance at dispatch time — see App\Core\Messaging\Dispatcher and
        // docs/DECISIONS.md (D016). Adding a new intent means adding a line
        // here, nothing else in Core needs to change.
        $this->app->singleton(Dispatcher::class, fn ($app) => new Dispatcher($app, [
            Intent::FallbackChat->value => FallbackChatHandler::class,
            Intent::Training->value => TrainingHandler::class,
        ]));

        // Core memory ContextBuilder: same Container-resolution pattern as
        // Dispatcher, but for structured memory providers instead of intent
        // Handlers. `training_profile` (Hito 5) and `active_workout_session`
        // (Hito 6) are the only real providers — see
        // App\Training\Memory\{TrainingProfileContextProvider,
        // ActiveWorkoutSessionContextProvider} and docs/DECISIONS.md (D019, D020).
        $this->app->singleton(ContextBuilder::class, fn ($app) => new ContextBuilder($app, [
            'training_profile' => TrainingProfileContextProvider::class,
            'active_workout_session' => ActiveWorkoutSessionContextProvider::class,
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
