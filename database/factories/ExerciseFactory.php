<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use App\Training\Enums\TrackingType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1000000),
            // Hito 9.2: array de pasos (antes era un párrafo de texto libre).
            'instructions' => [fake()->sentence(), fake()->sentence()],
            'important_points' => null,
            'common_mistakes' => null,
            'breathing_cue' => null,
            'video_url' => 'https://videos.example.test/'.Str::slug($name).'.mp4',
            'muscle_group' => fake()->randomElement(['chest', 'back', 'legs', 'shoulders', 'arms', 'core']),
            // Hito 8.4: null por defecto (refleja el estado real del catálogo
            // de producción — ningún ejercicio real tiene esta metadata
            // todavía). Las pruebas de foco usan withPrimaryMuscle()/
            // withSecondaryMuscles() explícitamente, nunca un valor
            // aleatorio aquí (evitaría falsos positivos/negativos en tests
            // de scoring).
            'primary_muscle' => null,
            'secondary_muscles' => null,
            'movement_pattern' => null,
            'equipment_needed' => [],
            'difficulty_level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'contraindications' => [],
            'tracking_type' => TrackingType::RepsAndLoad,
            'is_active' => true,
        ];
    }

    public function timeBased(): static
    {
        return $this->state(fn () => ['tracking_type' => TrackingType::TimeBased]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Hito 8.4: fija la zona muscular principal para pruebas de scoring por
     * foco. Deliberadamente explícito (nunca aleatorio) — el resultado de
     * un test de personalización debe depender de un valor conocido, no de
     * fake()->randomElement().
     */
    public function withPrimaryMuscle(MuscleFocus $focus): static
    {
        return $this->state(fn () => ['primary_muscle' => $focus]);
    }

    public function withSecondaryMuscles(array $focuses): static
    {
        return $this->state(fn () => [
            'secondary_muscles' => array_map(fn (MuscleFocus $f) => $f->value, $focuses),
        ]);
    }

    public function withMovementPattern(MovementPattern $pattern): static
    {
        return $this->state(fn () => ['movement_pattern' => $pattern]);
    }

    /**
     * Hito 9.1: simula un ejercicio recién importado de un proveedor —
     * SIN video_url directo (se resuelve vía MediaResolver, nunca se
     * persiste) y SIN contraindications revisadas (`null`, bloquea
     * `is_active` hasta que un humano llame a Exercise::activate()).
     * Refleja exactamente lo que hace App\ExerciseCatalog\Importer\
     * ExerciseImporter — nunca un ejercicio "listo para producción" por
     * defecto.
     */
    public function fromProvider(string $provider, ?string $providerExerciseId = null): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'provider_exercise_id' => $providerExerciseId ?? (string) Str::uuid(),
            'video_url' => null,
            'contraindications' => null,
            'is_active' => false,
        ]);
    }

    /**
     * Simula la revisión humana ya hecha (Exercise::activate()) — para
     * tests que necesitan un Exercise de proveedor ya elegible, sin pasar
     * por el flujo de revisión completo.
     */
    public function reviewedAndActive(array $contraindications = []): static
    {
        return $this->state(fn () => [
            'contraindications' => $contraindications,
            'is_active' => true,
        ]);
    }

    /**
     * Hito 9.2: fija técnica de ejecución explícita para tests de
     * ExerciseMessageFormatter. Deliberadamente explícito, nunca aleatorio
     * — mismo criterio que withPrimaryMuscle().
     */
    public function withTechnique(
        ?array $instructions = null,
        ?array $importantPoints = null,
        ?array $commonMistakes = null,
        ?string $breathingCue = null,
    ): static {
        return $this->state(fn () => array_filter([
            'instructions' => $instructions,
            'important_points' => $importantPoints,
            'common_mistakes' => $commonMistakes,
            'breathing_cue' => $breathingCue,
        ], fn ($value) => $value !== null));
    }
}
