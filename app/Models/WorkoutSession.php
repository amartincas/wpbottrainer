<?php

namespace App\Models;

use App\Training\Enums\WorkoutSessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'contact_id',
    'status',
    'scheduled_at',
    'completed_at',
    'generated_by',
])]
class WorkoutSession extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkoutSessionStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('order');
    }
}
