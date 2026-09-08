<?php

namespace App\Filament\Resources\MembershipPlan\Pages;

use App\Filament\Resources\MembershipPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembershipPlans extends ListRecords
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva Membresía'),
        ];
    }
}
