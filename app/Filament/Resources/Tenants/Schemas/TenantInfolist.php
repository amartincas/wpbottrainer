<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('personality_type')
                    ->badge(),
                TextEntry::make('system_prompt')
                    ->columnSpanFull(),
                TextEntry::make('ai_provider')
                    ->badge(),
                TextEntry::make('ai_model'),
                TextEntry::make('wa_phone_number_id'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
