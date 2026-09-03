<?php

namespace App\Filament\Resources\Exercises;

use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Exercises\Schemas\ExerciseForm;
use App\Filament\Resources\Exercises\Tables\ExercisesTable;
use App\Models\Exercise;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Hito 9.3 — interfaz administrativa del catálogo INTERNO de ejercicios
 * (`Exercise`), nunca de un proveedor concreto. Deliberadamente sin un
 * Resource dedicado a un proveedor específico ni un enum de proveedores
 * en Filament: la lista de proveedores disponibles se resuelve en tiempo real desde
 * `App\ExerciseCatalog\ProviderRegistry::knownKeys()` (ver
 * Tables\ExercisesTable y Pages\ListExercises) — agregar un proveedor
 * nuevo (cualquiera, sin importar cuál) es una entrada en
 * config/exercise_providers.php, cero cambios aquí. Deliberadamente sin
 * un Resource dedicado a un proveedor concreto ni un enum de proveedores.
 *
 * Sin páginas de creación manual a propósito: un `Exercise` nace por
 * sincronización de un proveedor (`ExerciseImporter`) o ya existía como
 * demo manual — este Resource administra/cura lo que ya existe, no
 * fabrica ejercicios desde cero.
 */
class ExerciseResource extends Resource
{
    protected static ?string $model = Exercise::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'Exercises';

    public static function form(Schema $schema): Schema
    {
        return ExerciseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExercisesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExercises::route('/'),
            'edit' => EditExercise::route('/{record}/edit'),
        ];
    }
}
