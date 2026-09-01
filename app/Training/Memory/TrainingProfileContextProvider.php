<?php

namespace App\Training\Memory;

use App\Core\Memory\ContextFragment;
use App\Core\Memory\ContextProviderInterface;
use App\Core\Messaging\ExecutionContext;
use App\Models\Contact;

/**
 * First real ContextProvider (Hito 5) — recovers only the current
 * TrainingProfile of the user sending the message, nothing else. Resolves
 * its own Contact from tenant + from, exactly as the Hito 3 design
 * documented (ExecutionContext deliberately carries no pre-resolved
 * Contact).
 *
 * `data` is a plain array (opaque to Core), meant to be interpolated into an
 * LLM prompt — never the live Eloquent model. Persistence/mutation of
 * TrainingProfile stays the responsibility of TrainingHandler/TrainingEngine,
 * never of this provider or of whatever consumes its fragment.
 */
class TrainingProfileContextProvider implements ContextProviderInterface
{
    public function provide(ExecutionContext $context): ContextFragment
    {
        $contact = Contact::where('tenant_id', $context->tenant->id)
            ->where('customer_phone', $context->message->from)
            ->first();

        $profile = $contact?->trainingProfile;

        if ($profile === null) {
            return new ContextFragment(
                label: 'training_profile',
                data: null,
                source: 'db',
                confidence: 'unknown',
            );
        }

        return new ContextFragment(
            label: 'training_profile',
            data: [
                // Hito 8.3: el nombre vive en Contact, no en TrainingProfile
                // — se incluye aquí de todos modos porque este fragmento es
                // "lo que ya sabemos del usuario", no "columnas de esta tabla".
                'name' => $contact->customer_name,
                'goal' => $profile->goal?->value,
                'experience_level' => $profile->experience_level?->value,
                // Hito 8.4: se exponen para que la IA de onboarding no
                // vuelva a preguntar una zona ya declarada — nunca se
                // muestran al usuario tal cual (son vocabulario interno).
                'primary_focus' => $profile->primary_focus,
                'secondary_focus' => $profile->secondary_focus,
                'training_location' => $profile->training_location?->value,
                'restrictions' => $profile->restrictions,
                'available_equipment' => $profile->available_equipment,
                'equipment_fully_equipped' => $profile->equipment_fully_equipped,
                'sessions_per_week' => $profile->sessions_per_week,
                'age' => $profile->age,
                'sex' => $profile->sex?->value,
                'weight_kg' => $profile->weight_kg !== null ? (float) $profile->weight_kg : null,
                'height_cm' => $profile->height_cm,
            ],
            source: 'db',
            confidence: 'confirmed',
        );
    }
}
