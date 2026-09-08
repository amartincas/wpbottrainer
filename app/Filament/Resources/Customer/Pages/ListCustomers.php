<?php

namespace App\Filament\Resources\Customer\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Hito 12 — sin `CreateAction`: un `Contact` siempre se origina desde el
 * flujo conversacional de WhatsApp, nunca se crea a mano desde el panel
 * (mismo criterio que `PaymentResource`).
 */
class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
