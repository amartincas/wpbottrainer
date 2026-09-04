<?php

namespace App\Filament\Resources\Exercises\Schemas;

use App\Training\Enums\MovementPattern;
use App\Training\Enums\TrackingType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Hito 9.3 — curación de un `Exercise`. Deliberadamente NO expone
 * `is_active` aquí: la única vía de activación/desactivación son las
 * acciones de ExercisesTable, que delegan en Exercise::activate() — este
 * formulario nunca duplica esa regla.
 *
 * Los campos de metadata del proveedor (nombre, dificultad, foco,
 * instructions, important_points...) se muestran DESHABILITADOS: un
 * ExerciseImporter::fullSync() los refresca en cada re-sync, editarlos
 * aquí se perdería en la siguiente sincronización — ver
 * docs/DECISIONS.md D037.
 */
class ExerciseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Metadata del proveedor (solo lectura — se refresca en cada sincronización)')
                ->columns(2)
                ->schema([
                    Text::make(fn ($record) => 'Provider: '.($record?->provider ?? 'manual')),
                    Text::make(fn ($record) => 'Provider ID: '.($record?->provider_exercise_id ?? '—')),
                    Text::make(fn ($record) => 'Name: '.$record?->name),
                    Text::make(fn ($record) => 'Difficulty: '.($record?->difficulty_level ?? '—')),
                    Text::make(fn ($record) => 'Primary focus: '.($record?->primary_muscle?->value ?? '—')),
                    Text::make(fn ($record) => 'Has video: '.match ($record?->provider_has_video) {
                        true => 'Sí', false => 'No', null => 'No aplica / desconocido',
                    }),
                    Text::make(fn ($record) => 'Instructions: '.($record?->instructions ? implode(' · ', $record->instructions) : '—'))
                        ->columnSpanFull(),
                    Text::make(fn ($record) => 'Important points: '.($record?->important_points ? implode(' · ', $record->important_points) : '—'))
                        ->columnSpanFull(),
                ]),

            Section::make('Contenido en español (generado por IA en curación — editable)')
                ->description('Se genera con la acción "Generar contenido en español" (arriba). Nunca se regenera automáticamente ni se usa en el envío en tiempo real — TrainingHandler/ExerciseMessageFormatter siguen leyendo solo el snapshot ya congelado de cada sesión. Mientras estos campos estén vacíos, el usuario recibe el contenido original tal cual (ver Exercise::toSnapshot()).')
                ->schema([
                    TextInput::make('name_es')
                        ->label('Nombre (ES)')
                        ->placeholder('Sin generar todavía'),
                    TagsInput::make('instructions_es')
                        ->label('Instrucciones (ES)')
                        ->helperText('Debe tener el mismo número de pasos, en el mismo orden, que "Instructions" arriba.')
                        ->columnSpanFull(),
                    TagsInput::make('important_points_es')
                        ->label('Puntos importantes (ES)')
                        ->columnSpanFull(),
                    Text::make(fn ($record) => 'Última generación/edición: '.($record?->content_translated_at?->diffForHumans() ?? 'nunca')),
                ]),

            // Hito 9.3 (curación de seguridad, post-validación de UX) —
            // ANTES este campo era un TagsInput editable directamente desde
            // el guardado estándar del formulario. Riesgo verificado
            // empíricamente (ver tests): el propio TagsInput del vendor
            // (Filament\Forms\Components\TagsInput::setUp() →
            // afterStateHydrated) fuerza cualquier estado no-array — es
            // decir, `null` — a `[]` al hidratarse en el navegador. Eso
            // significa que simplemente abrir esta página y pulsar "Save"
            // sin tocar nada convertía silenciosamente "nunca revisado" en
            // "revisado, sin contraindicaciones", sin ninguna decisión
            // consciente de por medio. Se retira el campo editable de aquí
            // por completo — un campo fuera del schema nunca se dehidrata
            // ni se guarda, así que el "Save" estándar ya no puede tocar
            // contraindications de ninguna forma. La única vía de edición
            // ahora es la acción "Revisar seguridad" (en la lista o en el
            // encabezado de esta página), que exige una confirmación
            // explícita del administrador antes de guardar cualquier valor,
            // incluido `[]`.
            Section::make('Seguridad — requerido para activar')
                ->description('Solo lectura aquí — usa la acción "Revisar seguridad" (en la lista o en el encabezado de esta página) para cambiar esto.')
                ->schema([
                    Text::make(fn ($record) => match (true) {
                        $record === null => '—',
                        $record->contraindications === null => '⚠️ Pendiente de revisión de seguridad — nunca se ha decidido para este ejercicio.',
                        $record->contraindications === [] => '✅ Revisado — sin contraindicaciones registradas.',
                        default => '✅ Revisado — contraindicaciones registradas: '.implode(', ', $record->contraindications),
                    }),
                ]),

            Section::make('Técnica curada (opcional — nunca bloquea la activación)')
                ->schema([
                    TagsInput::make('common_mistakes')
                        ->label('Errores comunes')
                        ->helperText('Preservado en cada re-sincronización. Ningún proveedor auditado lo provee — solo existe si se cura aquí.'),
                    Textarea::make('breathing_cue')
                        ->label('Indicación de respiración')
                        ->helperText('Preservado en cada re-sincronización.'),
                ]),

            Section::make('Clasificación editable — ADVERTENCIA: se sobreescribe en el próximo sync')
                ->description('A diferencia de las secciones anteriores, estos dos campos SÍ los refresca ExerciseImporter en cada re-sincronización (si el proveedor de este ejercicio no informa el concepto, cualquier valor puesto aquí sobrevive hasta el próximo sync, pero no está protegido como contraindications/common_mistakes/breathing_cue).')
                ->schema([
                    Select::make('movement_pattern')
                        ->options(collect(MovementPattern::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)]))
                        ->native(false),
                    Select::make('tracking_type')
                        ->options(collect(TrackingType::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)]))
                        ->native(false),
                ]),
        ]);
    }
}
