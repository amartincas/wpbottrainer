<?php

namespace App\Acquisition\Enums;

/**
 * P1-B — vocabulario cerrado de fuentes de adquisición del MVP. Backed enum
 * en vez de tabla — mismo criterio que App\Training\Enums\TrainingGoal:
 * vocabulario pequeño y estable, sin necesidad de administración en
 * caliente todavía. Deliberadamente solo estas 3 — no se anticipan fuentes
 * futuras (ej. google_ads) hasta que exista un caso real que las requiera.
 */
enum AcquisitionSource: string
{
    case MetaAds = 'meta_ads';
    case Referral = 'referral';
    case Organic = 'organic';
}
