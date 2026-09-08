<?php

namespace App\Filament\Resources\Referral\Tables;

use App\Referrals\Enums\ReferralRewardApplicationStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Hito 13 — auditoría de Referidos, deliberadamente de solo lectura: es un
 * registro de hechos ya ocurridos (atribución, recompensa), no algo que se
 * cree o edite a mano desde el panel (mismo criterio que `PaymentResource`).
 */
class ReferralsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('referrerContact.customer_name')->label('Referente')->searchable()->placeholder('Sin nombre'),
                TextColumn::make('referrerContact.customer_phone')->label('WhatsApp referente')->searchable(),
                TextColumn::make('referredContact.customer_name')->label('Referido')->searchable()->placeholder('Sin nombre'),
                TextColumn::make('referredContact.customer_phone')->label('WhatsApp referido')->searchable(),
                TextColumn::make('code')->label('Código'),
                TextColumn::make('created_at')->label('Atribuido')->dateTime()->sortable(),
                TextColumn::make('reward_status')
                    ->label('Estado')
                    ->state(fn ($record) => $record->reward?->application_status)
                    ->badge()
                    ->color(fn (?ReferralRewardApplicationStatus $state): string => match ($state) {
                        ReferralRewardApplicationStatus::Applied => 'success',
                        ReferralRewardApplicationStatus::NotApplicable => 'gray',
                        ReferralRewardApplicationStatus::Pending => 'danger',
                        null => 'warning',
                    })
                    ->formatStateUsing(fn (?ReferralRewardApplicationStatus $state): string => match ($state) {
                        ReferralRewardApplicationStatus::Applied => 'Recompensado',
                        ReferralRewardApplicationStatus::NotApplicable => 'Recompensado (sin cambio, ya ilimitado)',
                        ReferralRewardApplicationStatus::Pending => 'Recompensa pendiente de aplicar',
                        null => 'Atribuido, sin compra confirmada',
                    }),
                TextColumn::make('reward.reward_days')->label('Días')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('application_status')
                    ->label('Estado de recompensa')
                    ->options([
                        ReferralRewardApplicationStatus::Applied->value => 'Recompensado',
                        ReferralRewardApplicationStatus::NotApplicable->value => 'Recompensado (sin cambio)',
                        ReferralRewardApplicationStatus::Pending->value => 'Pendiente de aplicar',
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            $data['value'] ?? null,
                            fn ($q, $value) => $q->whereHas('reward', fn ($r) => $r->where('application_status', $value))
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
