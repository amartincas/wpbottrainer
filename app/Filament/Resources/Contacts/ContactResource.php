<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Models\Contact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document';

    protected static ?string $navigationLabel = 'Contacts';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Superusers see all contacts, regular users only see contacts from their tenant
        if (!Auth::user()?->is_super_admin) {
            $query->where('tenant_id', Auth::user()?->tenant_id);
        }

        return $query;
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return ContactForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
