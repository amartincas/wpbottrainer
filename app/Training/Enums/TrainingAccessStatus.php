<?php

namespace App\Training\Enums;

enum TrainingAccessStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
