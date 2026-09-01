<?php

namespace App\Payments\Enums;

/**
 * Ciclo de vida de un Payment (Hito 8). Deliberadamente sin un estado
 * "duplicate" propio — un duplicado es una RAZÓN de rechazo
 * (Payment.review_note/validation_flags), no un estado del ciclo de vida.
 * Ver docs/DECISIONS.md.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
