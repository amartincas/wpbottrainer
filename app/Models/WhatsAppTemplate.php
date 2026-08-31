<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'body_preview',
        'parameters_map',
        'requires_phone_input',
        'language',
        'type',
        'is_reengagement',
    ];

    protected $casts = [
        'parameters_map'  => 'array',
        'is_reengagement' => 'boolean',
        'requires_phone_input' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
