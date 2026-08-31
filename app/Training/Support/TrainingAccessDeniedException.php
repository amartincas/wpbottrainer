<?php

namespace App\Training\Support;

class TrainingAccessDeniedException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Training access denied: {$reason}");
    }
}
