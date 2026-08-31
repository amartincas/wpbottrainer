<?php

namespace App\Filament\Resources\Contacts\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('customer_name')
                    ->label('Full Name')
                    ->required()
                    ->columnSpan(2),
                TextInput::make('customer_phone')
                    ->label('WhatsApp Number')
                    ->tel()
                    ->required()
                    ->columnSpan(2),
                Textarea::make('delivery_address_or_location')
                    ->label('Delivery Address / Service Location')
                    ->rows(3)
                    ->columnSpanFull(),
                TextInput::make('product_service_name')
                    ->label('Product / Service Name')
                    ->columnSpan(2),
                TextInput::make('preferred_date_time')
                    ->label('Preferred Date & Time')
                    ->hint('For services: preferred booking date/time')
                    ->columnSpan(2),
                Textarea::make('summary')
                    ->label('Order Summary')
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('is_processed')
                    ->label('Mark as Processed')
                    ->hint('Toggle when the contact has been successfully handled')
                    ->columnSpanFull(),
                Toggle::make('bot_active')
                    ->label('Bot Active for this Client')
                    ->helperText('Disable this to handle the conversation manually.')
                    ->default(true)
                    ->columnSpanFull(),
                Placeholder::make('recent_conversation')
                    ->label('Recent Conversation')
                    ->content(function ($record) {
                        if (!$record) {
                            return view('filament.components.chat-history', [
                                'record' => null,
                                'messages' => collect([]),
                            ]);
                        }

                        $messages = \App\Models\WhatsAppMessage::where('tenant_id', $record->tenant_id)
                            ->where('customer_phone', $record->customer_phone)
                            ->orderBy('created_at', 'desc')
                            ->limit(15)
                            ->get()
                            ->reverse();

                        return view('filament.components.chat-history', [
                            'record' => $record,
                            'messages' => $messages,
                        ]);
                    })
                    ->columnSpanFull(),
            ]);
    }
}
