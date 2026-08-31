<?php

namespace App\Training\Enums;

enum WorkoutSessionStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
