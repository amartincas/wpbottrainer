<?php

namespace App\Filament\Resources\Customer\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\Contact;
use App\Models\User;
use App\Training\Enums\TrainingAccessStatus;
use App\Training\Support\TrainingAccessAdministrationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 12 — las 5 acciones administrativas de membresía/acceso viven ÚNICA
 * y EXCLUSIVAMENTE aquí (nunca en `CustomersTable`, a diferencia de
 * Confirmar/Rechazar en Payments) porque cada una requiere datos que el
 * administrador debe decidir conscientemente en el momento (duración,
 * fecha, motivo) — no son acciones de un clic. Todas delegan en
 * `App\Training\Support\TrainingAccessAdministrationService`, la única
 * puerta para estas transiciones; ninguna crea, edita, ni toca un
 * `Payment`. Todas están restringidas a `is_super_admin` — misma y única
 * dimensión de autorización que el resto del panel (no hay roles).
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            Action::make('grant_trial')
                ->label('Otorgar Trial')
                ->color('info')
                ->icon('heroicon-o-clock')
                ->visible(fn (): bool => Auth::user()?->is_super_admin ?? false)
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('duration_days')
                        ->label('Duración (días)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(7),
                    Textarea::make('reason')->label('Motivo (opcional)'),
                ])
                ->action(function (Contact $record, array $data): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(TrainingAccessAdministrationService::class)
                        ->grantTrial($record, $admin, (int) $data['duration_days'], $data['reason'] ?: null);

                    Notification::make()->title('Trial otorgado')->success()->send();
                }),

            Action::make('grant_free')
                ->label('Otorgar Free')
                ->color('success')
                ->icon('heroicon-o-gift')
                ->visible(fn (): bool => Auth::user()?->is_super_admin ?? false)
                ->requiresConfirmation()
                ->schema([
                    DatePicker::make('until')->label('Vence el (vacío = indefinido)'),
                    Textarea::make('reason')->label('Motivo (opcional)'),
                ])
                ->action(function (Contact $record, array $data): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(TrainingAccessAdministrationService::class)
                        ->grantFree($record, $admin, $data['until'] ? Carbon::parse($data['until']) : null, $data['reason'] ?: null);

                    Notification::make()->title('Free otorgado')->success()->send();
                }),

            Action::make('extend')
                ->label('Extender')
                ->color('info')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (Contact $record): bool => (Auth::user()?->is_super_admin ?? false)
                    && $record->trainingAccess !== null
                    && $record->trainingAccess->status !== TrainingAccessStatus::Revoked)
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('months')
                        ->label('Meses a extender')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1),
                    Textarea::make('reason')->label('Motivo (opcional)'),
                ])
                ->action(function (Contact $record, array $data): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(TrainingAccessAdministrationService::class)
                        ->extend($record, $admin, (int) $data['months'], $data['reason'] ?: null);

                    Notification::make()->title('Acceso extendido')->success()->send();
                }),

            Action::make('revoke')
                ->label('Revocar')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible(fn (Contact $record): bool => (Auth::user()?->is_super_admin ?? false)
                    && $record->trainingAccess !== null
                    && $record->trainingAccess->status !== TrainingAccessStatus::Revoked)
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('reason')->label('Motivo (obligatorio)')->required(),
                ])
                ->action(function (Contact $record, array $data): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(TrainingAccessAdministrationService::class)
                        ->revoke($record, $admin, $data['reason']);

                    Notification::make()->title('Acceso revocado')->warning()->send();
                }),

            // Solo visible cuando el acceso está efectivamente Revoked —
            // mismo criterio que el propio servicio documenta (no-op
            // seguro fuera de ese estado, pero Filament ni siquiera ofrece
            // la acción en ese caso).
            Action::make('reactivate')
                ->label('Reactivar')
                ->color('success')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (Contact $record): bool => (Auth::user()?->is_super_admin ?? false)
                    && $record->trainingAccess?->status === TrainingAccessStatus::Revoked)
                ->requiresConfirmation()
                ->schema([
                    Select::make('target_status')
                        ->label('Reactivar como')
                        ->options([
                            TrainingAccessStatus::Trial->value => 'Trial',
                            TrainingAccessStatus::Free->value => 'Free',
                        ])
                        ->required()
                        ->live(),
                    DatePicker::make('until')
                        ->label('Vence el')
                        ->required(fn (Get $get): bool => $get('target_status') === TrainingAccessStatus::Trial->value)
                        ->helperText('Obligatorio para Trial. Para Free, vacío = indefinido.'),
                    Textarea::make('reason')->label('Motivo (opcional)'),
                ])
                ->action(function (Contact $record, array $data): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(TrainingAccessAdministrationService::class)
                        ->reactivate(
                            $record,
                            $admin,
                            TrainingAccessStatus::from($data['target_status']),
                            $data['until'] ? Carbon::parse($data['until']) : null,
                            $data['reason'] ?: null,
                        );

                    Notification::make()->title('Acceso reactivado')->success()->send();
                }),
        ];
    }
}
