<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
            // Hito 9.3 (curación de seguridad, post-validación de UX) —
            // separada de "Activar" a propósito: guarda contraindications
            // y NUNCA activa. Ver el comentario homólogo en ExercisesTable
            // (misma acción, duplicada aquí a propósito — mismo patrón ya
            // usado por generateSpanishContent en este archivo) sobre por
            // qué "?? []" en fillForm y un checkbox de confirmación
            // explícita (regla `accepted`) son necesarios: el propio
            // TagsInput del vendor fuerza cualquier estado no-array a `[]`
            // al hidratarse, así que la única salvaguarda real contra un
            // guardado sin decisión consciente es bloquear la acción
            // completa hasta que el administrador confirme explícitamente.
            Action::make('reviewSafety')
                ->label('Revisar seguridad')
                ->color('warning')
                ->icon('heroicon-o-shield-exclamation')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin && ! $this->record->is_active)
                ->schema([
                    Placeholder::make('current_status')
                        ->label('Estado actual')
                        ->content(function (): string {
                            /** @var Exercise $record */
                            $record = $this->record;

                            return match (true) {
                                $record->contraindications === null => '⚠️ Pendiente de revisión de seguridad — nunca se ha decidido para este ejercicio.',
                                $record->contraindications === [] => '✅ Revisado — sin contraindicaciones registradas.',
                                default => '✅ Revisado — contraindicaciones registradas: '.implode(', ', $record->contraindications),
                            };
                        }),
                    TagsInput::make('contraindications')
                        ->label('Contraindicaciones')
                        ->helperText('Añade una por cada contraindicación real. Si tras revisar no encuentras ninguna, déjalo vacío — deberás confirmarlo abajo igualmente.')
                        ->placeholder('Ej. hernia discal'),
                    Checkbox::make('reviewed_consciously')
                        ->label('Confirmo que revisé este ejercicio y que las contraindicaciones de arriba (o la ausencia de ellas) reflejan mi decisión, no una casilla vacía sin revisar.')
                        ->default(false)
                        ->required()
                        ->rule('accepted'),
                ])
                ->fillForm(fn (): array => [
                    'contraindications' => $this->record->contraindications,
                    'reviewed_consciously' => false,
                ])
                ->requiresConfirmation()
                ->modalDescription('Esto SOLO guarda la revisión de seguridad — el ejercicio sigue pendiente de activación. Usa "Activar" por separado después.')
                ->action(function (array $data): void {
                    /** @var Exercise $record */
                    $record = $this->record;
                    $record->update(['contraindications' => $data['contraindications']]);

                    Notification::make()
                        ->title('Revisión de seguridad guardada')
                        ->body('El ejercicio sigue pendiente de activación.')
                        ->success()
                        ->send();
                }),

            // Ya no expone ni edita contraindications: solo visible cuando
            // "Revisar seguridad" ya decidió algo (contraindications !==
            // null). La validación definitiva sigue siendo, únicamente,
            // Exercise::activate().
            Action::make('activate')
                ->label('Activar')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin
                    && $this->record->reviewStatus() !== 'active'
                    && $this->record->contraindications !== null)
                ->requiresConfirmation()
                ->modalDescription('Activa el ejercicio con las contraindicaciones ya guardadas. Para cambiarlas, usa "Revisar seguridad" primero.')
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
            //
            // Hito 9.3 (curación de seguridad) — mismo fix que en
            // ExercisesTable: ANTES exigía reviewStatus()==='pending_review'
            // (is_active===false Y contraindications===null), lo que
            // bloqueaba la traducción para siempre en cuanto se guardaba una
            // revisión de seguridad (aunque fuera []) — hallazgo real con el
            // ID 55. La única condición correcta es no estar activo.
            Action::make('generateSpanishContent')
                ->label('Generar contenido en español')
                ->color('info')
                ->icon('heroicon-o-language')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin
                    && ! $this->record->is_active)
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

                        // Hito 9.3 (curación de seguridad) — hallazgo real
                        // durante la curación manual: esta acción actualiza
                        // $record directamente, pero el formulario principal
                        // de esta misma página (name_es/instructions_es/
                        // important_points_es) seguía mostrando el estado de
                        // ANTES de generar. Si el administrador guardaba esa
                        // misma página después (ej. por otro campo), el
                        // "Save" estándar enviaba el formulario tal como
                        // estaba en pantalla — vacío — sobrescribiendo la
                        // traducción recién guardada.
                        //
                        // Se probó primero refreshFormData() (pensado para
                        // refrescar solo estos 3 campos) pero falla en la
                        // práctica para instructions_es/important_points_es:
                        // Schema::fillPartially() aplana el estado con
                        // collect($state)->dot() antes de filtrar por
                        // statePaths, así que un campo array como
                        // 'instructions_es' termina como claves
                        // 'instructions_es.0', 'instructions_es.1'... y la
                        // ruta plana 'instructions_es' ya no coincide con
                        // nada — confirmado con un test que reproducía
                        // exactamente esto (name_es sí se refrescaba,
                        // instructions_es no). fillForm() no tiene ese
                        // problema (usa el estado completo sin aplanar) — es
                        // el mismo método que ya se usa al montar la página,
                        // así que su corrección no depende de ninguna
                        // suposición nueva. Efecto secundario aceptado: si el
                        // administrador tenía cambios sin guardar en OTRO
                        // campo de este formulario en ese momento, también se
                        // descartan — se prefiere sobre el riesgo real de
                        // perder la traducción en silencio.
                        $this->fillForm();

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
