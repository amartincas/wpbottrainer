<?php

namespace App\Filament\Resources\Referral\Schemas;

use App\Referrals\Enums\ReferralRewardApplicationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Hito 13 — responde exactamente las preguntas de auditoría del diseño:
 * quién refirió a quién, cuándo, con qué código, qué Payment generó la
 * recompensa, cuántos días, y si se aplicó materialmente o quedó
 * pendiente. Solo lectura.
 */
class ReferralInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Atribución')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('referrerContact.customer_name')->label('Referente')->placeholder('Sin nombre'),
                        TextEntry::make('referrerContact.customer_phone')->label('WhatsApp referente'),
                        TextEntry::make('referredContact.customer_name')->label('Referido')->placeholder('Sin nombre'),
                        TextEntry::make('referredContact.customer_phone')->label('WhatsApp referido'),
                        TextEntry::make('code')->label('Código usado'),
                        TextEntry::make('created_at')->label('Fecha de atribución')->dateTime(),
                    ]),

                Section::make('Recompensa')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reward.payment_id')->label('Payment que generó la recompensa')->placeholder('— (sin compra confirmada todavía)'),
                        TextEntry::make('reward.reward_days')->label('Días otorgados')->placeholder('—'),
                        TextEntry::make('reward.application_status')
                            ->label('Estado de aplicación')
                            ->badge()
                            ->color(fn (?ReferralRewardApplicationStatus $state): string => match ($state) {
                                ReferralRewardApplicationStatus::Applied => 'success',
                                ReferralRewardApplicationStatus::NotApplicable => 'gray',
                                ReferralRewardApplicationStatus::Pending => 'danger',
                                null => 'warning',
                            })
                            ->formatStateUsing(fn (?ReferralRewardApplicationStatus $state): string => match ($state) {
                                ReferralRewardApplicationStatus::Applied => 'Aplicada — TrainingAccess extendido',
                                ReferralRewardApplicationStatus::NotApplicable => 'Generada — sin cambio (referente ya tenía acceso ilimitado)',
                                ReferralRewardApplicationStatus::Pending => 'Pendiente — referente Revoked o sin TrainingAccess, requiere revisión manual',
                                null => 'Sin recompensa todavía',
                            }),
                        TextEntry::make('reward.created_at')->label('Fecha de recompensa')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }
}
