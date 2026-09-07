<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'tenant_id',
        'customer_phone',
        'role',
        'content',
        // Hito 10 — idempotencia de envíos proactivos de Reminder.
        // Ausentes/null para toda conversación normal (Bloque 9 y antes).
        'idempotency_key',
        'dispatch_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns this message.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
