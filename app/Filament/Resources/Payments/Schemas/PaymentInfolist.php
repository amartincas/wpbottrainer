<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Payment;
use App\Payments\Enums\PaymentStatus;
use App\Training\Enums\TrainingAccessStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pago')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')->label('Pago #'),
                        TextEntry::make('status')->badge()->color(fn (PaymentStatus $state): string => match ($state) {
                            PaymentStatus::Pending => 'gray',
                            PaymentStatus::UnderReview => 'warning',
                            PaymentStatus::Confirmed => 'success',
                            PaymentStatus::Rejected => 'danger',
                            PaymentStatus::Expired => 'gray',
                        }),
                        TextEntry::make('contact.customer_phone')->label('Usuario'),
                        TextEntry::make('method_label')->label('Método'),
                        TextEntry::make('created_at')->label('Solicitado')->dateTime(),
                        TextEntry::make('receipt_submitted_at')->label('Comprobante recibido')->dateTime()->placeholder('Aún no'),
                    ]),

                // Hito 11 — membresía comprada. Todo lo que se muestra aquí
                // es el SNAPSHOT congelado en el propio Payment
                // (membership_months/amount/currency), nunca una relectura
                // del MembershipPlan actual — si el plan cambió de precio o
                // se desactivó después, esta sección sigue mostrando
                // exactamente lo que se compró en su momento.
                Section::make('Membresía')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('membershipPlan.label')->label('Plan')->placeholder('— (Payment anterior al catálogo de membresías)'),
                        TextEntry::make('membership_months')
                            ->label('Duración comprada')
                            ->formatStateUsing(fn (?int $state): string => $state === null ? '1 mes (valor histórico por defecto)' : ($state === 1 ? '1 mes' : "{$state} meses"))
                            ->placeholder('—'),
                        TextEntry::make('amount')->label('Precio esperado')->money(fn (Payment $record) => $record->currency ?? 'COP')->placeholder('—'),
                        TextEntry::make('extracted_data.amount')->label('Monto detectado')->placeholder('No legible'),
                        TextEntry::make('amount_difference')
                            ->label('Diferencia')
                            ->state(function (Payment $record): ?string {
                                $extracted = $record->extracted_data['amount'] ?? null;

                                if ($extracted === null || $record->amount === null) {
                                    return null;
                                }

                                $diff = (float) $extracted - (float) $record->amount;
                                $sign = $diff > 0 ? '+' : '';

                                return $sign.number_format($diff, 0, ',', '.').' '.$record->currency;
                            })
                            ->color(function (Payment $record): string {
                                $extracted = $record->extracted_data['amount'] ?? null;

                                if ($extracted === null || $record->amount === null) {
                                    return 'gray';
                                }

                                return (float) $extracted === (float) $record->amount ? 'success' : 'danger';
                            })
                            ->placeholder('—'),
                        TextEntry::make('reference')->label('Referencia')->placeholder('No legible'),
                    ]),

                Section::make('Extracción (IA — no autoritativa)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('extracted_data.date')->label('Fecha extraída')->placeholder('No legible'),
                        TextEntry::make('extracted_data.entity')->label('Entidad')->placeholder('No legible'),
                        TextEntry::make('extracted_data.payer_name')->label('Nombre del pagador')->placeholder('No disponible'),
                        TextEntry::make('validation_flags')
                            ->label('Alertas de validación (código, determinista)')
                            ->badge()
                            ->separator(',')
                            ->color('warning')
                            ->placeholder('Sin observaciones')
                            ->columnSpanFull(),
                    ]),

                Section::make('Comprobantes enviados')
                    ->schema([
                        RepeatableEntry::make('receipts')
                            ->label('')
                            ->schema([
                                TextEntry::make('source_type')->label('Tipo'),
                                TextEntry::make('file_path')->label('Archivo')->placeholder('Sin archivo (descrito por texto)'),
                                TextEntry::make('created_at')->label('Enviado')->dateTime(),
                            ])
                            ->columns(3),
                    ]),

                Section::make('Revisión humana')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reviewedBy.name')->label('Revisado por')->placeholder('Sin revisar'),
                        TextEntry::make('reviewed_at')->label('Fecha de revisión')->dateTime()->placeholder('—'),
                        TextEntry::make('review_note')->label('Observación')->columnSpanFull()->placeholder('—'),
                    ]),

                // Hito 11 (D6) — efecto real sobre TrainingAccess, para que
                // el admin no tenga que ir a otro Resource a reconstruirlo
                // manualmente. Es solo lectura de una relación ya
                // existente — ninguna lógica nueva.
                Section::make('Acceso técnico resultante (TrainingAccess)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('contact.trainingAccess.status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn (?TrainingAccessStatus $state): string => match ($state) {
                                TrainingAccessStatus::Trial, TrainingAccessStatus::Active => 'success',
                                TrainingAccessStatus::Expired => 'warning',
                                TrainingAccessStatus::Revoked => 'danger',
                                null => 'gray',
                            })
                            ->placeholder('Sin acceso otorgado'),
                        TextEntry::make('contact.trainingAccess.expires_at')->label('Vigente hasta')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }
}
