<?php

use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Models\Exercise;
use App\Models\User;
use App\Training\Enums\LoadModality;
use App\Training\Enums\TrackingType;
use Livewire\Livewire;

/**
 * Hito D (diseño formal v2 aprobado, fase D5) — curación administrativa de
 * `Exercise::load_modality` vía Filament. `User::canAccessPanel()` es
 * incondicional (`return true`) — cualquier usuario autenticado accede al
 * panel, exactamente el mismo nivel de acceso que ya tiene el formulario
 * estándar de edición para tracking_type/movement_pattern (sin permiso
 * nuevo, ver ExerciseForm.php).
 */
function ldmExercise(?LoadModality $loadModality = null): Exercise
{
    return Exercise::factory()->create([
        'tracking_type' => TrackingType::RepsAndLoad,
        'load_modality' => $loadModality,
        'equipment_needed' => ['dumbbells'],
    ]);
}

it('1. Exercise sin clasificar (load_modality=null): el formulario lo muestra sin selección ("Sin clasificar")', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(null);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->assertFormSet(['load_modality' => null]);
});

it('2. Exercise None: el formulario refleja la clasificación correcta', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(LoadModality::None);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->assertFormSet(['load_modality' => 'none']);
});

it('3. Exercise Required: el formulario refleja la clasificación correcta', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(LoadModality::Required);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->assertFormSet(['load_modality' => 'required']);
});

it('4. el formulario puede guardar None', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(null);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['load_modality' => 'none'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()->load_modality)->toBe(LoadModality::None);
});

it('5. el formulario puede guardar Required', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(null);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['load_modality' => 'required'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()->load_modality)->toBe(LoadModality::Required);
});

it('6. el formulario puede devolver la clasificación a null / "Sin clasificar"', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(LoadModality::Required);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['load_modality' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()->load_modality)->toBeNull();
});

it('7. guardar load_modality nunca modifica tracking_type', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(null);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['load_modality' => 'required'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()->tracking_type)->toBe(TrackingType::RepsAndLoad);
});

it('8. guardar load_modality nunca modifica equipment_needed', function () {
    $this->actingAs(User::factory()->create());
    $exercise = ldmExercise(null);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['load_modality' => 'none'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->fresh()->equipment_needed)->toBe(['dumbbells']);
});

it('9. una edición normal del Exercise (ej. movement_pattern) conserva load_modality intacto', function () {
    $exercise = ldmExercise(LoadModality::Required);

    $exercise->update(['movement_pattern' => \App\Training\Enums\MovementPattern::Push]);

    expect($exercise->fresh()->load_modality)->toBe(LoadModality::Required);
});
