<?php

namespace App\Filament\Resources\CustomerServiceRequest\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerServiceRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Solicitud de atención al cliente')
                ->columns(2)
                ->schema([
                    TextEntry::make('contact.customer_name')->label('Cliente')->placeholder('Sin nombre'),
                    TextEntry::make('contact.customer_phone')->label('WhatsApp'),
                    TextEntry::make('contact.tenant.name')->label('Tenant'),
                    TextEntry::make('created_at')->label('Fecha')->dateTime(),
                    TextEntry::make('message')->label('Mensaje')->columnSpanFull(),
                ]),
        ]);
    }
}
