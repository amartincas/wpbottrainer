<?php

namespace App\Filament\Resources;

use App\CustomerCare\Models\Faq;
use App\Filament\Resources\Faq\Pages\CreateFaq;
use App\Filament\Resources\Faq\Pages\EditFaq;
use App\Filament\Resources\Faq\Pages\ListFaqs;
use App\Filament\Resources\Faq\Schemas\FaqForm;
use App\Filament\Resources\Faq\Tables\FaqsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 14 — catálogo de FAQ por Tenant. CRUD completo (crear/editar/
 * activar-desactivar/ordenar), mismo criterio de autorización que
 * `MembershipPlanResource`: cualquier admin autenticado, tenant-scoped —
 * no exclusivo de superadmin.
 */
class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QuestionMarkCircle;

    protected static ?string $navigationLabel = 'FAQ';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! Auth::user()?->is_super_admin) {
            $query->where('tenant_id', Auth::user()?->tenant_id);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return FaqForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FaqsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqs::route('/'),
            'create' => CreateFaq::route('/create'),
            'edit' => EditFaq::route('/{record}/edit'),
        ];
    }
}
