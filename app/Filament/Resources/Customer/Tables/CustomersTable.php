<?php

namespace App\Filament\Resources\Customer\Tables;

use App\Models\Contact;
use App\Training\Enums\TrainingAccessStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hito 12 — tabla principal de "Clientes", deliberadamente acotada (sin
 * saturar): identidad + estado de acceso EFECTIVO (nunca la columna cruda
 * — ver `TrainingAccess::effectiveStatus()`) + tipo de membresía +
 * vencimiento + última actividad. Las acciones administrativas sensibles
 * (Trial/Free/extend/revoke/reactivate) viven en el detalle
 * (`ViewCustomer`), no aquí — esta tabla es para ENCONTRAR rápido, no para
 * administrar in-line.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin nombre'),

                TextColumn::make('customer_phone')
                    ->label('WhatsApp')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('access_status')
                    ->label('Estado de acceso')
                    ->state(fn (Contact $record) => $record->trainingAccess?->effectiveStatus())
                    ->badge()
                    ->color(fn (?TrainingAccessStatus $state): string => match ($state) {
                        TrainingAccessStatus::Trial => 'info',
                        TrainingAccessStatus::Active, TrainingAccessStatus::Free => 'success',
                        TrainingAccessStatus::Expired => 'warning',
                        TrainingAccessStatus::Revoked => 'danger',
                        null => 'gray',
                    })
                    ->formatStateUsing(fn (?TrainingAccessStatus $state): string => match ($state) {
                        TrainingAccessStatus::Trial => 'Trial',
                        TrainingAccessStatus::Active => 'Active',
                        TrainingAccessStatus::Free => 'Free',
                        TrainingAccessStatus::Expired => 'Expirado',
                        TrainingAccessStatus::Revoked => 'Revocado',
                        null => 'Sin acceso',
                    }),

                TextColumn::make('membership_type')
                    ->label('Membresía')
                    ->state(function (Contact $record): string {
                        $access = $record->trainingAccess;

                        if ($access === null) {
                            return '—';
                        }

                        if ($access->status === TrainingAccessStatus::Active) {
                            $months = $access->payment?->membership_months;

                            return $months !== null ? ($months === 1 ? '1 mes' : "{$months} meses") : 'Active';
                        }

                        return match ($access->status) {
                            TrainingAccessStatus::Trial => 'Trial',
                            TrainingAccessStatus::Free => 'Free',
                            default => '—',
                        };
                    }),

                TextColumn::make('trainingAccess.expires_at')
                    ->label('Vence')
                    ->dateTime('d/m/Y')
                    ->placeholder('Sin vencimiento')
                    ->sortable(),

                TextColumn::make('last_activity')
                    ->label('Última actividad')
                    ->state(function (Contact $record): ?string {
                        $last = $record->workoutSessions()
                            ->whereNotNull('completed_at')
                            ->latest('completed_at')
                            ->first();

                        return $last?->completed_at?->diffForHumans();
                    })
                    ->placeholder('Sin actividad'),
            ])
            ->filters([
                SelectFilter::make('trainingAccess.status')
                    ->label('Estado (crudo)')
                    ->options([
                        TrainingAccessStatus::Trial->value => 'Trial',
                        TrainingAccessStatus::Active->value => 'Active',
                        TrainingAccessStatus::Free->value => 'Free',
                        TrainingAccessStatus::Revoked->value => 'Revocado',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $q, string $value): Builder => $q->whereHas('trainingAccess', fn (Builder $ta) => $ta->where('status', $value))
                        );
                    }),

                Filter::make('expired')
                    ->label('Expirado (efectivo)')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'trainingAccess',
                        fn (Builder $ta) => $ta->whereIn('status', [
                            TrainingAccessStatus::Trial->value, TrainingAccessStatus::Active->value, TrainingAccessStatus::Free->value,
                        ])->whereNotNull('expires_at')->where('expires_at', '<', now())
                    )),

                Filter::make('currently_valid')
                    ->label('Con acceso vigente')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'trainingAccess',
                        fn (Builder $ta) => $ta->whereIn('status', [
                            TrainingAccessStatus::Trial->value, TrainingAccessStatus::Active->value, TrainingAccessStatus::Free->value,
                        ])->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
