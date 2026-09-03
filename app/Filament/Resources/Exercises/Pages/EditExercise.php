<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 9.3 — el guardado estándar de este formulario ya cubre la
 * curación (contraindications/common_mistakes/breathing_cue/
 * movement_pattern/tracking_type, ver Schemas\ExerciseForm). La
 * activación es una acción aparte a propósito: `is_active` nunca se
 * expone como campo del formulario, para que la única vía posible siga
 * siendo Exercise::activate() — nunca un simple guardado de formulario.
 */
class EditExercise extends EditRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label('Activar')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin
                    && $this->record->reviewStatus() !== 'active')
                ->requiresConfirmation()
                ->modalDescription('Requiere haber guardado ya las contraindicaciones (aunque sea una lista vacía, confirmando que se revisó) y que instructions no esté vacío.')
                ->action(function (): void {
                    /** @var Exercise $record */
                    $record = $this->record;

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
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin && $this->record->is_active)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['is_active' => false]);

                    Notification::make()->title('Ejercicio desactivado')->success()->send();
                }),
        ];
    }
}
