<?php

use App\ExerciseCatalog\ProviderRegistry;
use App\ExerciseCatalog\Providers\NullExerciseProvider;
use App\ExerciseCatalog\Providers\YMove\YMoveExerciseNormalizer;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\ExerciseVideoAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Training\Enums\MuscleFocus;
use Illuminate\Support\Facades\Http;
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
    // contraindications ya revisado ([]) para aislar la regla que se prueba
    // aquí (instructions vacío) de la nueva visibilidad de "activate" —
    // ver los tests dedicados de esa visibilidad más abajo.
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'instructions' => [],
        'contraindications' => [],
    ]);

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->callAction('activate');

    // instructions vacío sigue bloqueando activate() aunque el admin ya
    // haya "confirmado" desde Filament — la regla vive solo en el modelo.
    expect($exercise->fresh()->is_active)->toBeFalse();
});

it('activation succeeds once contraindications were captured through the separate "reviewSafety" step, using the real domain rule', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1']]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('reviewSafety', $exercise, data: [
            'contraindications' => ['hernia discal'],
            'reviewed_consciously' => true,
        ])
        ->assertHasNoTableActionErrors();

    // "reviewSafety" nunca activa por su cuenta.
    expect($exercise->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('activate', $exercise);

    $exercise->refresh();
    expect($exercise->is_active)->toBeTrue();
    expect($exercise->contraindications)->toBe(['hernia discal']);
    expect($exercise->contraindications_reviewed_by)->toBe($admin->id);
});

// ── Hito 9.3 (curación de seguridad, post-validación de UX) ─────────────
// "Revisar seguridad" y "Activar" separados: la revisión de seguridad
// nunca activa, y activar nunca vuelve a pedir/tocar contraindications.
// Ver docs/DECISIONS.md.

it('the safety review is rejected without an explicit confirmation checkbox — even to save an empty list — and nothing is persisted', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create();
    expect($exercise->contraindications)->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('reviewSafety', $exercise, data: [
            'contraindications' => [],
            'reviewed_consciously' => false,
        ])
        ->assertHasTableActionErrors(['reviewed_consciously']);

    // Nada se guardó: sigue exactamente en el mismo estado "pendiente".
    expect($exercise->fresh()->contraindications)->toBeNull();
});

it('the safety review saves an empty contraindications list once explicitly confirmed, and never activates', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('reviewSafety', $exercise, data: [
            'contraindications' => [],
            'reviewed_consciously' => true,
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $exercise->fresh();
    expect($fresh->contraindications)->toBe([]);
    expect($fresh->is_active)->toBeFalse();
    expect($fresh->reviewStatus())->toBe('inactive'); // revisado, todavía no activo
});

it('the safety review saves a real list of contraindications once explicitly confirmed, and never activates', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('reviewSafety', $exercise, data: [
            'contraindications' => ['hernia discal', 'lesión de hombro'],
            'reviewed_consciously' => true,
        ])
        ->assertHasNoTableActionErrors();

    $fresh = $exercise->fresh();
    expect($fresh->contraindications)->toBe(['hernia discal', 'lesión de hombro']);
    expect($fresh->is_active)->toBeFalse();
});

it('the "reviewSafety" action is hidden once the exercise is already active', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableActionHidden('reviewSafety', $exercise);
});

it('the "activate" table action is hidden while contraindications is still null, and appears once reviewed', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['instructions' => ['Paso 1']]);
    expect($exercise->contraindications)->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableActionHidden('activate', $exercise);

    $exercise->update(['contraindications' => []]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableActionVisible('activate', $exercise);
});

it('the "activate" header action on the edit page is hidden while contraindications is still null', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create();
    expect($exercise->contraindications)->toBeNull();

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->assertActionHidden('activate');

    $exercise->update(['contraindications' => []]);

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->assertActionVisible('activate');
});

it('saving the standard edit form without touching contraindications never changes it — the field is no longer editable there', function () {
    // Prueba empírica del riesgo señalado: Filament\Forms\Components\
    // TagsInput::setUp() fuerza cualquier estado no-array (es decir, null)
    // a [] al hidratarse (ver afterStateHydrated en el vendor). Antes de
    // este cambio, contraindications era un TagsInput editable en este
    // mismo formulario — abrir la página y guardar sin tocar nada
    // convertía silenciosamente "nunca revisado" en "revisado, sin
    // contraindicaciones". Ahora el campo no forma parte del schema del
    // formulario estándar en absoluto, así que "save" nunca lo dehidrata.
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create(['name_es' => 'Sin editar todavía']);
    expect($exercise->contraindications)->toBeNull();

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->fillForm(['name_es' => 'Editado'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $exercise->fresh();
    expect($fresh->contraindications)->toBeNull();
    expect($fresh->name_es)->toBe('Editado');
});

it('filters the table to an ad-hoc list of specific ids, without mixing in the rest of the pending catalog', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $included = Exercise::factory()->fromProvider('ymove')->create();
    $excluded = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->filterTable('specific_ids', ['ids' => (string) $included->id])
        ->assertCanSeeTableRecords([$included])
        ->assertCanNotSeeTableRecords([$excluded]);
});

it('never widens the "specific_ids" filter to the full catalog when the text is non-blank but has no valid numeric id', function () {
    // Fix: antes, un texto no vacío que no producía ningún id numérico
    // válido (ej. un rango "1-53" sin comas/espacios, o texto no numérico)
    // devolvía la query SIN acotar — mostrando el catálogo completo, justo
    // lo que este filtro existe para evitar. Ahora debe devolver cero
    // resultados, nunca ampliar el conjunto.
    $admin = User::factory()->create(['is_super_admin' => true]);
    $anyExercise = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->filterTable('specific_ids', ['ids' => '1-53'])
        ->assertCanNotSeeTableRecords([$anyExercise])
        ->assertCountTableRecords(0);
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

// ── Hito 9.3 (fix post-E2E): "Generar contenido en español" ─────────────

function fakeSpanishContentAiResponse(array $payload): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)]]],
        ], 200),
    ]);
}

it('generates and saves Spanish content from the table action for a pending-review exercise, without activating it', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $tenant = Tenant::factory()->create();
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Squat',
        'instructions' => ['Bend your knees.'],
        'important_points' => [],
    ]);

    fakeSpanishContentAiResponse(['name' => 'Sentadilla', 'instructions' => ['Dobla las rodillas.'], 'important_points' => []]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('generateSpanishContent', $exercise, data: ['tenant_id' => $tenant->id]);

    $fresh = $exercise->fresh();
    expect($fresh->name_es)->toBe('Sentadilla');
    expect($fresh->instructions_es)->toBe(['Dobla las rodillas.']);
    expect($fresh->content_translated_at)->not->toBeNull();
    // Nunca activa por su cuenta — sigue exactamente igual de pendiente.
    expect($fresh->is_active)->toBeFalse();
    expect($fresh->reviewStatus())->toBe('pending_review');
    // El original nunca se pierde.
    expect($fresh->name)->toBe('Squat');
    expect($fresh->instructions)->toBe(['Bend your knees.']);
});

it('shows a danger notification and saves nothing when the AI response fails content-fidelity validation', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $tenant = Tenant::factory()->create();
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'instructions' => ['Paso 1', 'Paso 2'],
        'important_points' => [],
    ]);

    // La IA fusionó dos pasos en uno — violación de fidelidad.
    fakeSpanishContentAiResponse(['name' => 'X', 'instructions' => ['Paso único'], 'important_points' => []]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->callTableAction('generateSpanishContent', $exercise, data: ['tenant_id' => $tenant->id])
        ->assertNotified();

    expect($exercise->fresh()->name_es)->toBeNull();
});

it('the generateSpanishContent action is not available once the exercise is already active', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableActionHidden('generateSpanishContent', $exercise);
});

it('the generateSpanishContent action is hidden from non-super-admin users', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($user)
        ->test(ListExercises::class)
        ->assertTableActionHidden('generateSpanishContent', $exercise);
});

it('running "generateSpanishContent" from the edit page header refreshes the open form, so a later Save cannot overwrite it with stale empty fields', function () {
    // Hallazgo real durante la curación manual (ID 55): la acción
    // actualizaba el modelo directamente pero el formulario ya abierto en
    // esa misma página seguía en blanco; un "Save" posterior enviaba ese
    // formulario vacío y borraba la traducción recién generada.
    $admin = User::factory()->create(['is_super_admin' => true]);
    $tenant = Tenant::factory()->create();
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name' => 'Squat',
        'instructions' => ['Bend your knees.'],
        'important_points' => [],
    ]);

    fakeSpanishContentAiResponse(['name' => 'Sentadilla', 'instructions' => ['Dobla las rodillas.'], 'important_points' => []]);

    $test = Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->callAction('generateSpanishContent', data: ['tenant_id' => $tenant->id]);

    // El formulario ya visible en pantalla debe reflejar la traducción
    // recién generada, no el estado vacío de antes de generar.
    $test->assertFormSet(['name_es' => 'Sentadilla']);

    // Con el formulario ya sincronizado, un "Save" posterior (aunque no
    // toque nada más) debe conservar la traducción, nunca borrarla.
    $test->call('save')->assertHasNoFormErrors();

    $fresh = $exercise->fresh();
    expect($fresh->name_es)->toBe('Sentadilla');
    expect($fresh->instructions_es)->toBe(['Dobla las rodillas.']);
});

it('Spanish content fields are editable directly from the edit form, independent of the generation action', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $exercise = Exercise::factory()->fromProvider('ymove')->create([
        'name_es' => 'Sentadilla',
        'instructions_es' => ['Dobla las rodillas.'],
        'important_points_es' => [],
    ]);

    Livewire::actingAs($admin)
        ->test(EditExercise::class, ['record' => $exercise->getRouteKey()])
        ->fillForm(['instructions_es' => ['Dobla las rodillas con cuidado.']])
        ->call('save');

    expect($exercise->fresh()->instructions_es)->toBe(['Dobla las rodillas con cuidado.']);
});

// ── Hito 9.3 (post-deploy): nuevos filtros — review_status, video_validated, equipment ──

it('the video_validated column reflects our own registry, not provider_has_video', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $validated = Exercise::factory()->fromProvider('ymove')->create(['provider_has_video' => true]);
    ExerciseVideoAccess::create([
        'exercise_id' => $validated->id,
        'provider' => 'ymove',
        'provider_exercise_id' => $validated->provider_exercise_id,
        'variant' => 'default',
        'resolved_at' => now(),
    ]);
    // Dice tener video pero NUNCA se resolvió con éxito desde este sistema.
    $unvalidated = Exercise::factory()->fromProvider('ymove')->create(['provider_has_video' => true]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableColumnStateSet('video_validated', true, $validated)
        ->assertTableColumnStateSet('video_validated', false, $unvalidated);
});

it('filters by review_status using the exact same rule as reviewStatus(), never a duplicated one', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $pending = Exercise::factory()->fromProvider('ymove')->create();
    $active = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive = Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create();
    $inactive->update(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->filterTable('review_status', 'pending_review')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$active, $inactive]);
});

it('filters by video_validated true/false using our own registry', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $validated = Exercise::factory()->fromProvider('ymove')->create();
    ExerciseVideoAccess::create([
        'exercise_id' => $validated->id,
        'provider' => 'ymove',
        'provider_exercise_id' => $validated->provider_exercise_id,
        'variant' => 'default',
        'resolved_at' => now(),
    ]);
    $unvalidated = Exercise::factory()->fromProvider('ymove')->create();

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->filterTable('video_validated', true)
        ->assertCanSeeTableRecords([$validated])
        ->assertCanNotSeeTableRecords([$unvalidated]);
});

it('the active_coverage column counts how many exercises are ALREADY active for the same primary_muscle, to prioritize curation candidates', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create(['primary_muscle' => MuscleFocus::Glutes]);
    Exercise::factory()->fromProvider('ymove')->reviewedAndActive()->create(['primary_muscle' => MuscleFocus::Glutes]);
    // Candidato pendiente del mismo foco — la cobertura ya activa es 2.
    $candidate = Exercise::factory()->fromProvider('ymove')->create(['primary_muscle' => MuscleFocus::Glutes]);
    // Otro candidato de un foco sin NINGÚN activo — cobertura 0, mayor prioridad.
    $underserved = Exercise::factory()->fromProvider('ymove')->create(['primary_muscle' => MuscleFocus::Back]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->assertTableColumnStateSet('active_coverage', 2, $candidate)
        ->assertTableColumnStateSet('active_coverage', 0, $underserved);
});

it('filters by equipment against the normalized closed vocabulary, using whereJsonContains', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $withMachine = Exercise::factory()->fromProvider('ymove')->create(['equipment_needed' => ['machine']]);
    $withBarbell = Exercise::factory()->fromProvider('ymove')->create(['equipment_needed' => ['barbell']]);

    Livewire::actingAs($admin)
        ->test(ListExercises::class)
        ->filterTable('equipment_needed', 'machine')
        ->assertCanSeeTableRecords([$withMachine])
        ->assertCanNotSeeTableRecords([$withBarbell]);
});
