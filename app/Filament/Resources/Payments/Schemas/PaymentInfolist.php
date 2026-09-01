<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Payments\Enums\PaymentStatus;
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
                        TextEntry::make('amount')->label('Monto')->money(fn ($record) => $record->currency),
                        TextEntry::make('reference')->label('Referencia')->placeholder('No legible'),
                        TextEntry::make('created_at')->label('Solicitado')->dateTime(),
                        TextEntry::make('receipt_submitted_at')->label('Comprobante recibido')->dateTime()->placeholder('Aún no'),
                    ]),

                Section::make('Extracción (IA — no autoritativa)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('extracted_data.amount')->label('Monto extraído')->placeholder('No legible'),
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
            ]);
    }
}
