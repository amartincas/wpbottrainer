<?php

namespace App\Filament\Resources\Referral\Pages;

use App\Filament\Resources\ReferralResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Hito 13 — sin `CreateAction`: un `Referral` siempre se origina desde la
 * atribución automática (código en el primer mensaje relevante), nunca se
 * crea a mano desde el panel — mismo criterio que `PaymentResource`.
 */
class ListReferrals extends ListRecords
{
    protected static string $resource = ReferralResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
