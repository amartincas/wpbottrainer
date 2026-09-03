<?php

namespace App\Filament\Resources\Exercises\Schemas;

use App\Training\Enums\MovementPattern;
use App\Training\Enums\TrackingType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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

            Section::make('Seguridad — requerido para activar')
                ->schema([
                    TagsInput::make('contraindications')
                        ->label('Contraindicaciones')
                        ->helperText('Preservado en cada re-sincronización — nunca lo sobreescribe el proveedor. Déjalo vacío (guardar sin ninguna) para confirmar "revisado, sin contraindicaciones" — distinto de no haberlo revisado todavía.')
                        ->placeholder('Ej. hernia discal'),
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
