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
    ];

    /**
     * Get the tenant that owns this message.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
