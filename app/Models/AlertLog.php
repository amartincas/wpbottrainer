<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro durable de una Alert (App\Core\Alerts\Alert) escrita por
 * App\Core\Alerts\Channels\PersistedAlertChannel — ver docs/DECISIONS.md.
 * Este modelo vive en App\Models (igual que Tenant/Contact/WhatsAppMessage,
 * infraestructura Core-level que igual se ubica aquí por convención de este
 * proyecto), no dentro de App\Core\Alerts — Core no contiene modelos Eloquent.
 */
#[Fillable(['category', 'severity', 'message', 'context', 'delivery_status'])]
class AlertLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }
}
