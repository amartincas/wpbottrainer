<?php

namespace App\Filament\Resources\Exercises\Tables;

use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\User;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Hito 9.3 — el listado del catálogo interno. `provider` se filtra con
 * las claves REALES de `ProviderRegistry::knownKeys()` — nunca una lista
 * fija ni un enum — así que un proveedor nuevo aparece aquí solo con
 * registrarlo en config/exercise_providers.php, sin tocar este archivo.
 */
class ExercisesTable
{
    public static function configure(Table $table): Table
    {
        $providerOptions = array_combine(
            app(ProviderRegistry::class)->knownKeys(),
            app(ProviderRegistry::class)->knownKeys(),
        );

        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->placeholder('manual'),
                TextColumn::make('provider_exercise_id')
                    ->label('Provider ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
                TextColumn::make('difficulty_level')
                    ->label('Difficulty')
                    ->placeholder('—'),
                TextColumn::make('primary_muscle')
                    ->label('Primary focus')
                    ->formatStateUsing(fn (?MuscleFocus $state) => $state?->value)
                    ->placeholder('—'),
                TextColumn::make('secondary_muscles')
                    ->label('Secondary focus')
                    ->formatStateUsing(fn (?array $state) => $state !== null ? implode(', ', $state) : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('movement_pattern')
                    ->label('Movement')
                    ->formatStateUsing(fn (?MovementPattern $state) => $state?->value)
                    ->placeholder('—'),
                IconColumn::make('provider_has_video')
                    ->label('Has video')
                    // Tri-estado explícito, nunca ->boolean() a secas: null
                    // (proveedor no informa el concepto / ejercicio manual)
                    // es un valor real y distinto de false, no un vacío.
                    ->icon(fn (?bool $state) => match ($state) {
                        true => 'heroicon-o-video-camera',
                        false => 'heroicon-o-video-camera-slash',
                        null => 'heroicon-o-minus',
                    })
                    ->color(fn (?bool $state) => match ($state) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    }),
                TextColumn::make('review_status')
                    ->label('Status')
                    ->state(fn (Exercise $record) => $record->reviewStatus())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'pending_review' => 'warning',
                        'inactive' => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str($state)->headline()),
                TextColumn::make('synced_at')
                    ->label('Synced')
                    ->since()
                    ->sortable()
                    ->placeholder('never (manual)'),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options($providerOptions),
                TernaryFilter::make('is_active')
                    ->label('Active status')
                    ->placeholder('All'),
                TernaryFilter::make('provider_has_video')
                    ->label('Has video')
                    ->placeholder('All'),
                SelectFilter::make('difficulty_level')
                    ->label('Difficulty')
                    ->options(fn () => collect(ExperienceLevel::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                SelectFilter::make('primary_muscle')
                    ->label('Primary focus')
                    ->options(fn () => collect(MuscleFocus::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                SelectFilter::make('movement_pattern')
                    ->label('Movement pattern')
                    ->options(fn () => collect(MovementPattern::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
            ])
            ->defaultSort('synced_at', 'desc')
            ->recordActions([
                EditAction::make(),

                // Curación mínima en línea — la revisión completa (incluir
                // important_points/common_mistakes/breathing_cue/movement
                // pattern) vive en la página de edición; esta acción existe
                // para el caso común de "solo reviso contraindicaciones y
                // activo" sin salir de la lista. La regla de negocio (qué
                // bloquea activar) vive ÚNICAMENTE en Exercise::activate() —
                // esta acción nunca la reimplementa, solo captura la
                // revisión y delega.
                Action::make('activate')
                    ->label('Activar')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin
                        && $record->reviewStatus() !== 'active')
                    ->requiresConfirmation()
                    ->schema([
                        TagsInput::make('contraindications')
                            ->label('Contraindicaciones (Enter para cada una; deja vacío y confirma si revisaste y no hay ninguna)')
                            ->placeholder('Ej. hernia discal'),
                    ])
                    ->fillForm(fn (Exercise $record): array => [
                        'contraindications' => $record->contraindications ?? [],
                    ])
                    ->action(function (Exercise $record, array $data): void {
                        $record->update(['contraindications' => $data['contraindications']]);

                        try {
                            /** @var User $user */
                            $user = Auth::user();
                            $record->activate($user);

                            Notification::make()->title('Ejercicio activado')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->title('No se pudo activar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('deactivate')
                    ->label('Desactivar')
                    ->color('gray')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin && $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (Exercise $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()->title('Ejercicio desactivado')->success()->send();
                    }),
            ]);
    }
}
