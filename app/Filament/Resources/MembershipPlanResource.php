<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MembershipPlan\Pages\CreateMembershipPlan;
use App\Filament\Resources\MembershipPlan\Pages\EditMembershipPlan;
use App\Filament\Resources\MembershipPlan\Pages\ListMembershipPlans;
use App\Filament\Resources\MembershipPlan\Schemas\MembershipPlanForm;
use App\Filament\Resources\MembershipPlan\Tables\MembershipPlansTable;
use App\Payments\Models\MembershipPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Hito 11 — catálogo de membresías (duración + precio) por Tenant. NO es
 * Subscription/Billing: sin ciclo de facturación, sin renovación
 * automática, sin invoices. Ver docs/DECISIONS.md.
 */
class MembershipPlanResource extends Resource
{
    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static ?string $navigationLabel = 'Membresías';

    protected static ?int $navigationSort = 1;

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
        return MembershipPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipPlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit' => EditMembershipPlan::route('/{record}/edit'),
        ];
    }
}
