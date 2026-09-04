<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

            // Hito 9.3 (fix post-E2E) — mismo comportamiento y mismas
            // restricciones que la acción homónima de ExercisesTable
            // (nunca activa, nunca sobreescribe el original); ver
            // App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator.
            Action::make('generateSpanishContent')
                ->label('Generar contenido en español')
                ->color('info')
                ->icon('heroicon-o-language')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin
                    && $this->record->reviewStatus() === 'pending_review')
                ->schema([
                    Select::make('tenant_id')
                        ->label('Credenciales de IA a usar (por tenant)')
                        ->helperText('El sistema todavía no tiene una configuración de IA independiente de un tenant — se reutiliza la de un tenant existente. Ver informe Hito 9.3.')
                        ->options(fn () => Tenant::whereNotNull('ai_api_key')->pluck('name', 'id'))
                        ->native(false)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Genera name/instructions/important_points en español mediante IA, a partir del contenido original. Nunca sobreescribe el original ni activa el ejercicio — queda editable en este formulario.')
                ->action(function (array $data): void {
                    /** @var Exercise $record */
                    $record = $this->record;
                    $tenant = Tenant::find($data['tenant_id']);

                    try {
                        $result = app(ExerciseSpanishContentGenerator::class)->generate($record, $tenant);

                        $record->update([
                            'name_es' => $result->name,
                            'instructions_es' => $result->instructions,
                            'important_points_es' => $result->importantPoints,
                            'content_translated_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Contenido en español generado')
                            ->body('Revísalo y edítalo si hace falta antes de activar el ejercicio.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo generar el contenido en español')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
