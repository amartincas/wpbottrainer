<?php

namespace App\Training\Support;

class TrainingAccessAdministrationException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Training access administration error: {$reason}");
    }
}
