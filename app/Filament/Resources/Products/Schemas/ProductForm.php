<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('tenant_id')
                    ->relationship(
                        'tenant',
                        'name',
                        fn ($query) => $query
                            ->when(
                                !Auth::user()?->is_super_admin,
                                fn ($q) => $q->where('id', Auth::user()?->tenant_id)
                            )
                    )
                    ->required()
                    ->default(Auth::user()?->tenant_id),
                TextInput::make('id')
                    ->label('Product ID (for AI image references)')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($record) => $record?->id ? "Use [IMG:{$record->id}] to show this product" : '(ID will be assigned after creation)')
                    ->helperText('Reference this ID in system prompts or AI responses to display product images')
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                ToggleButtons::make('type')
                    ->options([
                        'product' => 'Product',
                        'service' => 'Service',
                    ])
                    ->default('product')
                    ->required()
                    ->inline(),
                TextInput::make('stock')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Availability (1 = Accepting Clients, 0 = Fully Booked)'
                            : 'Stock Quantity';
                    })
                    ->helperText(function (Get $get): string {
                        return $get('type') === 'service'
                            ? 'Enter 1 if accepting new clients, 0 if fully booked'
                            : 'Enter the number of units in stock';
                    }),
                Textarea::make('ai_sales_strategy')
                    ->label('AI Sales Strategy')
                    ->placeholder('How should the AI sell this product? E.g., "Emphasize quality over price" or "Focus on limited availability"...')
                    ->columnSpanFull(),
                Textarea::make('faq_context')
                    ->label('FAQ & Operational Context')
                    ->placeholder('Rules, cities served, employees, FAQs specific to this product...')
                    ->columnSpanFull(),
                TextInput::make('required_customer_info')
                    ->label('Required Contact Data')
                    ->placeholder('E.g., Full name, phone, delivery address, preferred date...')
                    ->columnSpanFull(),
                TagsInput::make('meta_ad_ids')
                    ->label('IDs de anuncios de Meta (Click-to-WhatsApp)')
                    ->placeholder('Pega el ID del anuncio y presiona Enter')
                    ->helperText('Copia el ID del anuncio desde Meta Ads Manager (no el texto del mensaje). Así, cuando un cliente escriba desde ese anuncio, el sistema identifica el producto exacto sin depender del texto del mensaje.')
                    ->columnSpanFull(),

                // === PRODUCT GALLERY ===
                FileUpload::make('images')
                    ->label('Product Images')
                    ->multiple()
                    ->reorderable()
                    ->directory('products')
                    ->disk('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(5120) // 5MB max per image
                    ->helperText('Upload multiple images (JPG, PNG, WebP). The first image will be used as primary.')
                    ->columnSpanFull()
                    // Load existing images from the relationship
                    ->formatStateUsing(fn ($record) => $record?->images()->pluck('image_path')->toArray() ?? [])
                    ->dehydrated(false),
            ]);
    }
}
