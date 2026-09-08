<?php

namespace App\Core\Messaging;

/**
 * The set of conversational intents the Router can classify an inbound
 * message into. This is a Core-level contract — it lists intent
 * *identifiers*, never domain logic.
 *
 * `FallbackChat` represents the general-purpose free-form conversation
 * behaviour that existed before the Router was introduced (Hito 2) — the
 * default when no other classifier recognizes the message.
 *
 * `Training` was added in Hito 5, the first real domain intent. Core does
 * not know what "training" means beyond this identifier — the classification
 * logic and the Handler both live in App\Training\*.
 *
 * `Payment` was added in Hito 8, the second real domain intent — same
 * shape as Training: its own classifier (App\Payments\Support\
 * PaymentIntentClassifier) and its own single Handler (App\Payments\
 * Handlers\PaymentHandler), never mixed into TrainingHandler.
 *
 * `Referral` was added in Hito 13, the third real domain intent — same
 * shape again: its own classifier (App\Referrals\Support\
 * ReferralIntentClassifier) and its own single Handler (App\Referrals\
 * Handlers\ReferralHandler). Attribution itself (recognizing a referral
 * code on a contact's first relevant message) does NOT happen here — that
 * runs earlier, in App\Referrals\Support\ReferralAttributionPreRoutingScreen
 * (PreRoutingScreener, before this Intent is even classified) precisely
 * because it must run regardless of which Intent the message would
 * otherwise resolve to.
 */
enum Intent: string
{
    case FallbackChat = 'fallback_chat';
    case Training = 'training';
    case Payment = 'payment';
    case Referral = 'referral';
}
