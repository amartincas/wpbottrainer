<?php

use App\ExerciseCatalog\ProviderRegistry;
use App\ExerciseCatalog\Providers\NullExerciseProvider;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\User;
use Livewire\Livewire;

/**
 * Hito 9.3 — ExerciseResource administra el catálogo INTERNO
 * multi-proveedor. Estos tests verifican explícitamente que no hay
 * ningún acoplamiento a YMove: registran un segundo proveedor de
 * mentira EN TIEMPO DE EJECUCIÓN (nunca tocando ExerciseResource ni
 * ningún archivo de Filament) y comprueban que todo sigue funcionando.
 */
function registerFakeSecondProvider(string $key = 'fakeprovider2'): void
{
    config([
        "exercise_providers.providers.{$key}" => NullExerciseProvider::class,
        "exercise_providers.normalizers.{$key}" => YMoveExerciseNormalizer::class,
    ]);
}

it('renders the exercise list independently of which provider each row belongs to', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    Exercise::factory()->fromProvider('ymove')->create(['name' => 'From YMove']);
    registerFakeSecondProvider();
    Exercise::factory()->fromProvider('fakeprovider2')->create(['name' => 'From Fake Provider']);
    Exercise::factory()->create(['name' => 'Manual demo', 'provider' => null]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Exercise::all());
});

it('lists providers dynamically from ProviderRegistry, including one registered only at runtime', function () {
    registerFakeSecondProvider();

    $keys = app(ProviderRegistry::class)->knownKeys();

    expect($keys)->toContain('ymove');
    expect($keys)->toContain('fakeprovider2');
});

it('the sync action processes whichever provider is selected, not just ymove', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    registerFakeSecondProvider();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertActionExists('syncProvider')
        ->callAction('syncProvider', data: ['provider' => 'fakeprovider2'])
        ->assertHasNoActionErrors();

    // NullExerciseProvider no devuelve nada — la corrida es válida y
    // completa (0 encontrados), no un error. Ningún Exercise se crea,
    // pero tampoco se tocó nada de 'ymove'.
    expect(Exercise::where('provider', 'fakeprovider2')->count())->toBe(0);
});

it('adding another registered provider requires no change to any ExerciseResource file (grep proof)', function () {
    // Ya se probó funcionalmente arriba (dos tests anteriores) sin tocar
    // ningún archivo de Filament — esto lo documenta explícitamente:
    // ningún archivo del Resource fue modificado para soportar
    // 'fakeprovider2', solo se registró en config en tiempo de ejecución.
    $files = glob(app_path('Filament/Resources/Exercises/**/*.php'), GLOB_BRACE)
        ?: array_merge(
            glob(app_path('Filament/Resources/Exercises/*.php')),
            glob(app_path('Filament/Resources/Exercises/*/*.php')),
        );

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('fakeprovider2');
    }
});

it('contains no YMove-specific logic anywhere in the Exercise Resource files', function () {
    $files = array_merge(
        glob(app_path('Filament/Resources/Exercises/*.php')),
        glob(app_path('Filament/Resources/Exercises/*/*.php')),
    );

    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect(mb_stripos($contents, 'ymove'))->toBe(false, "Referencia literal a 'ymove' encontrada en {$file}");
    }
});

it('the syncProvider action is hidden from non-super-admin users', function () {
    $user = User::factory()->create(['is_super_admin' => false]);

    Livewire::actingAs($user)
        ->test(ListExercises::class)
        ->assertActionHidden('syncProvider');
});

it('activation still defers entirely to Exercise::activate() — Filament never invents a weaker rule', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => []]);

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->callAction('activate');

    // instructions vacío sigue bloqueando activate() aunque el admin ya
    // haya "confirmado" desde Filament — la regla vive solo en el modelo.
    expect($exercise->fresh()->is_active)->toBeFalse();
});

it('activation succeeds through the table action once contraindications are captured, using the real domain rule', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1']]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('activate', $exercise, data: ['contraindications' => ['hernia discal']]);

    $exercise->refresh();
    expect($exercise->is_active)->toBeTrue();
    expect($exercise->contraindications)->toBe(['hernia discal']);
    expect($exercise->contraindications_reviewed_by)->toBe($admin->id);
});

it('deactivation is available from the table for an active exercise', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('deactivate', $exercise);

    expect($exercise->fresh()->is_active)->toBeFalse();
});

it('reviewStatus() reflects pending_review/active/inactive exactly as designed, computed by the domain', function () {
    $pending = Exercise::factory()->fromProvider('ymove')->create();
    $active = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive->update(['is_active' => false]);

    expect($pending->reviewStatus())->toBe('pending_review');
    expect($active->reviewStatus())->toBe('active');
    expect($inactive->fresh()->reviewStatus())->toBe('inactive');
});
