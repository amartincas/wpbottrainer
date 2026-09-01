<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Support\PaymentConfirmationService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 8: Confirmar/Rechazar son las ÚNICAS acciones que existen sobre un
 * Payment — ambas restringidas a `is_super_admin` (punto 3 aprobado), y
 * ambas delegan en App\Payments\Support\PaymentConfirmationService, nunca
 * tocan TrainingAccess directamente desde aquí. Rechazar exige nota;
 * confirmar registra reviewer + fecha siempre (el servicio lo hace, no
 * esta clase).
 */
class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Pago #')->sortable(),
                TextColumn::make('contact.customer_phone')->label('Usuario')->searchable(),
                TextColumn::make('method_label')->label('Método'),
                TextColumn::make('amount')->label('Monto')->money(fn (Payment $record) => $record->currency)->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::Pending => 'gray',
                        PaymentStatus::UnderReview => 'warning',
                        PaymentStatus::Confirmed => 'success',
                        PaymentStatus::Rejected => 'danger',
                        PaymentStatus::Expired => 'gray',
                    }),
                TextColumn::make('validation_flags')
                    ->label('Alertas')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('created_at')->label('Solicitado')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn ($case) => [$case->value => ucfirst($case->value)])),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),

                Action::make('confirm')
                    ->label('Confirmar')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Payment $record): bool => Auth::user()?->is_super_admin && $record->isOpen())
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('note')->label('Observación (opcional)'),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        app(PaymentConfirmationService::class)->confirm($record, Auth::user(), $data['note'] ?? null);

                        Notification::make()->title('Pago confirmado')->success()->send();
                    }),

                Action::make('reject')
                    ->label('Rechazar')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Payment $record): bool => Auth::user()?->is_super_admin && $record->isOpen())
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')->label('Motivo del rechazo')->required(),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        /** @var User $user */
                        $user = Auth::user();
                        app(PaymentConfirmationService::class)->reject($record, $user, $data['reason']);

                        Notification::make()->title('Pago rechazado')->warning()->send();
                    }),
            ]);
    }
}
