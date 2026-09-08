<?php

namespace App\Filament\Resources\Customer\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Hito 12 — usa `CustomerForm`, deliberadamente acotado a identidad
 * (`customer_name`/`customer_phone`). Nunca edita `TrainingAccess` — eso
 * vive exclusivamente en las 5 acciones administrativas de `ViewCustomer`.
 */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Cliente actualizado correctamente';
    }
}
