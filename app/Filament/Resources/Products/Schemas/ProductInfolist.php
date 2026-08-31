<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('tenant.name')
                    ->label('Tenant'),
                TextEntry::make('name'),
                TextEntry::make('description')
                    ->columnSpanFull(),
                TextEntry::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'product' => 'blue',
                        'service' => 'green',
                        default => 'gray',
                    }),
                TextEntry::make('price')
                    ->money(),
                TextEntry::make('stock')
                    ->numeric()
                    ->label(function ($record) {
                        return $record?->type === 'service'
                            ? 'Availability'
                            : 'Stock Quantity';
                    }),
                TextEntry::make('ai_sales_strategy')
                    ->label('AI Sales Strategy')
                    ->columnSpanFull()
                    ->placeholder('Not set'),
                TextEntry::make('faq_context')
                    ->label('FAQ & Operational Context')
                    ->columnSpanFull()
                    ->placeholder('Not set'),
                TextEntry::make('required_customer_info')
                    ->label('Required Lead Data')
                    ->columnSpanFull()
                    ->placeholder('Not set'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
