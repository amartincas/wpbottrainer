<?php

namespace App\Filament\Resources\MembershipPlan\Pages;

use App\Filament\Resources\MembershipPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipPlan extends CreateRecord
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Membresía creada correctamente';
    }
}
