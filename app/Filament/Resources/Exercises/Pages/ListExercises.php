<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\ExerciseCatalog\Importer\ExerciseImporter;
use App\ExerciseCatalog\ProviderRegistry;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Models\Exercise;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 9.3 — "Sincronizar proveedor" es genérica a propósito: el Select
 * de proveedores se construye desde `ProviderRegistry::knownKeys()` en
 * tiempo real, nunca una lista fija ni un enum. La acción delega el 100%
 * de la lógica de sincronización a `ExerciseImporter::fullSync()` — este
 * archivo solo arma la UI y presenta el `FullSyncResult` ya calculado,
 * nunca reimplementa paginación, reconciliación, ni ninguna regla de
 * `ExerciseImporter`.
 *
 * `includeVideos=false` y "catálogo completo sin filtro hasVideo" no son
 * decisiones de esta acción — son el comportamiento por defecto de
 * `fullSync()` en sí (ver Hito 9.3, D038); esta UI simplemente no pasa
 * ningún override que los cambie.
 */
class ListExercises extends ListRecords
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncProvider')
                ->label('Sincronizar proveedor')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => (bool) Auth::user()?->is_super_admin)
                ->schema([
                    Select::make('provider')
                        ->label('Proveedor')
                        ->options(fn () => array_combine(
                            app(ProviderRegistry::class)->knownKeys(),
                            app(ProviderRegistry::class)->knownKeys(),
                        ))
                        ->native(false)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Trae metadata completa del catálogo del proveedor (con y sin video). Nunca solicita video, nunca activa ejercicios automáticamente.')
                ->action(function (array $data): void {
                    $provider = $data['provider'];
                    $startedAt = microtime(true);

                    $result = app(ExerciseImporter::class)->fullSync($provider);

                    $elapsed = round(microtime(true) - $startedAt, 1);

                    // Conteo de con/sin video: una simple lectura posterior,
                    // no forma parte de la lógica de fullSync() — el
                    // resultado abstracto (FullSyncResult) no la trae
                    // porque no todo proveedor tiene por qué distinguir
                    // video disponible/no disponible.
                    $withVideo = Exercise::where('provider', $provider)->where('provider_has_video', true)->count();
                    $withoutVideo = Exercise::where('provider', $provider)->where('provider_has_video', false)->count();

                    if (! $result->completedFully) {
                        Notification::make()
                            ->title("Sincronización de '{$provider}' incompleta")
                            ->body("Error: {$result->errorMessage}. No se reconciliaron bajas — una respuesta incompleta nunca se trata como ejercicio desaparecido.")
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Sincronización de '{$provider}' completada")
                        ->body(implode("\n", [
                            "Encontrados: {$result->totalReceived}",
                            "Creados: {$result->created}",
                            "Actualizados: {$result->updated}",
                            "Sin cambios: {$result->unchanged}",
                            'Desactivados: '.count($result->possiblyRemoved),
                            "Con video: {$withVideo}",
                            "Sin video: {$withoutVideo}",
                            "Páginas: {$result->pagesProcessed}",
                            "Duración: {$elapsed}s",
                        ]))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
