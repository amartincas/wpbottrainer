<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'customer_phone',
    'customer_name',
    'delivery_address_or_location',
    'product_service_name',
    'preferred_date_time',
    'summary',
    'is_processed',
    'bot_active',
])]
class Contact extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_processed' => 'boolean',
            'bot_active' => 'boolean',
        ];
    }

    /**
     * Get the tenant that owns this contact.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Hito 4 — dominio Training. Contact sigue siendo la única identidad;
     * estas relaciones exponen datos que pertenecen a Training, no a Core.
     */
    public function trainingProfile(): HasOne
    {
        return $this->hasOne(TrainingProfile::class);
    }

    public function workoutSessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    public function trainingAccess(): HasOne
    {
        return $this->hasOne(TrainingAccess::class);
    }

    /**
     * Hito 10 — dominio Reminder. Mismo criterio que el resto: Contact sigue
     * siendo la única identidad, estas relaciones exponen datos que
     * pertenecen a Reminder, no a Core ni a Training en sí.
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function reminderSuggestions(): HasMany
    {
        return $this->hasMany(ReminderSuggestion::class);
    }

    /**
     * Hito 8 — dominio Payments. Igual que Training: Contact sigue siendo la
     * única identidad, esta relación expone datos que pertenecen a
     * Payments, no a Core.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Mark the contact as processed.
     */
    public function markAsProcessed(): void
    {
        $this->update(['is_processed' => true]);
    }

    /**
     * Check if the contact has been processed.
     */
    public function isProcessed(): bool
    {
        return $this->is_processed === true;
    }

    /**
     * Get all unprocessed contacts for a tenant.
     */
    public static function unprocessed($tenantId)
    {
        return static::where('tenant_id', $tenantId)
            ->where('is_processed', false)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
