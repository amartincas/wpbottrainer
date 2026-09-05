<?php

namespace App\Training\Onboarding\Requirements;

use App\Core\Alerts\Alert;
use App\Core\Alerts\AlertService;
use App\Core\Alerts\AlertSeverity;
use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingProfile;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Onboarding\OnboardingRequirement;
use App\Training\Onboarding\QuestionContext;
use App\Training\Support\DeclaredHealthConditionRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Bloque 5 (ver docs/DECISIONS.md D048) — REEMPLAZA a `RestrictionsRequirement`
 * en el `OnboardingRequirementRegistry` (Decisión A, aprobada): una sola
 * pregunta de screening, nunca dos preguntas independientes sobre lo mismo.
 * `TrainingProfile.restrictions` queda como mecanismo LEGACY DE SOLO
 * LECTURA — este requirement NUNCA escribe ahí. La fuente de verdad nueva
 * es exclusivamente `DeclaredHealthCondition` (vía `DeclaredHealthConditionRecorder`,
 * sin cambios de invariantes) + `TrainingRestriction` + `SafetyRestrictionResolver`.
 *
 * SEPARACIÓN CRÍTICA, no negociable (Decisión B, aprobada):
 * `apply()` JAMÁS confirma una `TrainingRestriction` — ni siquiera cuando el
 * texto de limitación funcional es explícito, claro, y coincide con
 * `FunctionalLimitationCanonicalMapper`. SIEMPRE crea (a través de
 * `DeclaredHealthConditionRecorder::declare()`) una `DeclaredHealthCondition`
 * en `pending_review`. El único camino a `TrainingRestriction confirmed`
 * sigue siendo `resolveWithRestriction()`, con un `User $reviewer` humano
 * obligatorio — invariante de Bloque 2, intacto.
 *
 * DOS ESTADOS DISTINTOS, que NO deben confundirse (documentado explícitamente
 * a pedido del usuario):
 * - `isSatisfied()` responde SOLO si la CONVERSACIÓN de screening ya se
 *   cerró (se preguntó lo necesario, sin importar si lo declarado sigue
 *   pendiente de revisión).
 * - Si técnicamente se puede generar la primera rutina lo responde
 *   ÚNICAMENTE `TrainingAccessGate` (vía `DeclaredHealthCondition::hasPendingReviewFor()`).
 * Es CORRECTO y ESPERADO que `isSatisfied() === true` mientras
 * `TrainingAccessGate` deniegue — el screening ya no tiene preguntas
 * pendientes, pero la primera rutina sigue bloqueada hasta revisión humana.
 *
 * CICLO CONVERSACIONAL (1 o 2 turnos, nunca más — ver `apply()`):
 * turno 1 → pregunta inicial → ¿"ninguna"? sí: cierra (caso A).
 *                                no: crea DeclaredHealthCondition.
 *                                    ¿vino con limitación funcional explícita
 *                                    en el mismo mensaje? sí: cierra (caso C).
 *                                                          no: NO cierra
 *                                                              (caso B, sigue
 *                                                              pendiente).
 * turno 2 (solo si hizo falta) → pregunta de SEGUIMIENTO (¿questionContext()
 *   detecta una DeclaredHealthCondition pending_review sin seguimiento aún?)
 *   → cualquier respuesta (con o sin detalle funcional, o vaga) → registra
 *     otra declaración si hay texto → cierra SIEMPRE (casos C tardío o D) —
 *     nunca se insiste una tercera vez.
 *
 * `health_screening_asked` es el ÚNICO campo persistente nuevo — significa
 * "conversación cerrada", no "se preguntó una vez". NO existe un segundo
 * campo `health_screening_followup_asked`: la distinción "pregunta inicial
 * vs. seguimiento" se deriva enteramente de
 * `DeclaredHealthCondition::hasPendingReviewFor()`, infraestructura del
 * Bloque 2 ya existente — ver docs/DECISIONS.md D048.
 *
 * IDEMPOTENCIA (precisión final aprobada antes de implementar): este
 * requirement NO implementa ningún mecanismo propio de deduplicación de
 * turnos. Se apoya en la deduplicación de `wamid` YA EXISTENTE en
 * `WhatsAppController` (verificada: `tests/Feature/WhatsAppWebhookTest.php`,
 * "does not dispatch the job twice for a retried WAMID") — el mismo mensaje
 * de WhatsApp nunca llega dos veces a `ProcessWhatsAppMessage`, por lo que
 * `apply()` nunca se ejecuta dos veces para el mismo evento real del
 * usuario. No se agrega una abstracción nueva para un problema ya resuelto
 * en una capa anterior.
 */
class HealthScreeningRequirement implements OnboardingRequirement
{
    private const INITIAL_QUESTION = 'Antes de comenzar, quiero asegurarme de adaptar bien tu entrenamiento. '
        .'¿Tienes actualmente alguna lesión, dolor, molestia o condición que debamos tener en cuenta?';

    private const FOLLOWUP_QUESTION = '¿Hay algún movimiento específico que te cause molestia o que debas evitar?';

    public function __construct(
        private readonly DeclaredHealthConditionRecorder $recorder,
        private readonly AlertService $alerts,
    ) {}

    public function key(): string
    {
        return 'health_screening';
    }

    public function extractedKeys(): array
    {
        return ['health_declaration_category', 'health_condition_text', 'functional_limitation_text'];
    }

    public function isBlocking(TrainingProfile $profile, Contact $contact): bool
    {
        return true;
    }

    /**
     * SOLO screening conversacional — ver docblock de la clase. Nunca
     * consulta si hay una revisión pendiente; eso es responsabilidad
     * exclusiva de `TrainingAccessGate`.
     */
    public function isSatisfied(TrainingProfile $profile, Contact $contact): bool
    {
        return $profile->health_screening_asked === true;
    }

    public function validate(array $rawValues): array
    {
        return $rawValues;
    }

    /**
     * Único lugar que decide si la conversación de screening se cierra
     * este turno. NUNCA crea ni confirma una `TrainingRestriction` — ver
     * docblock de la clase.
     */
    public function apply(Contact $contact, TrainingProfile $profile, array $validatedValues): void
    {
        $conditionText = $validatedValues['health_condition_text'] ?? null;

        if ($conditionText === '') {
            // Caso A: negación explícita — nada que declarar.
            $profile->update(['health_screening_asked' => true]);

            return;
        }

        if ($conditionText === null) {
            // El mensaje no abordó la pregunta de screening este turno —
            // no cierra nada, se vuelve a preguntar.
            return;
        }

        $wasAlreadyPending = DeclaredHealthCondition::hasPendingReviewFor($contact->id);
        $category = $this->mapCategory($validatedValues['health_declaration_category'] ?? null);
        $functionalText = $validatedValues['functional_limitation_text'] ?? null;

        $condition = $this->recorder->declare(
            contact: $contact,
            originalText: $conditionText,
            category: $category,
            functionalLimitationText: $functionalText,
        );

        $this->emitPendingReviewAlert($contact, $condition);

        // Cierra la conversación si: (a) esta declaración ya vino con
        // limitación funcional explícita (caso C, sin necesidad de
        // preguntar más), o (b) ya existía una declaración pendiente de un
        // turno anterior — esta respuesta ES el seguimiento, se cierre con
        // o sin detalle (casos C tardío / D). Nunca se pregunta una tercera
        // vez.
        if ($functionalText !== null || $wasAlreadyPending) {
            $profile->update(['health_screening_asked' => true]);
        }
    }

    public function onAsked(Contact $contact, TrainingProfile $profile): void
    {
        // No-op deliberado: a diferencia de PhysicalStatsRequirement, aquí
        // es apply() quien decide cuándo cerrar la conversación, porque
        // depende del CONTENIDO de la respuesta, no de si se presentó la
        // pregunta.
    }

    public function questionContext(TrainingProfile $profile, Contact $contact): QuestionContext
    {
        $isFollowUp = DeclaredHealthCondition::hasPendingReviewFor($contact->id);

        return new QuestionContext(
            key: $this->key(),
            purpose: $isFollowUp
                ? 'identificar si existe un movimiento específico que deba evitarse'
                : 'detectar cualquier lesión, dolor, molestia o condición relevante antes de la primera rutina',
            blocking: $this->isBlocking($profile, $contact),
            expectedType: 'compound', // health_declaration_category + health_condition_text + functional_limitation_text
            validOptions: null,
            knownContext: [],
            fallbackQuestion: $isFollowUp ? self::FOLLOWUP_QUESTION : self::INITIAL_QUESTION,
        );
    }

    /**
     * La IA solo CLASIFICA (nunca decide una restricción) — el valor ya
     * viene validado contra el vocabulario cerrado de HealthConditionCategory
     * en OnboardingConversationService::parseCombinedJson() (mismos valores
     * reales del enum: possible_injury/possible_recovery/professional_indication).
     * `null`/valor no reconocido cae en PossibleInjury por defecto (la
     * categoría más conservadora: siempre requiere revisión, nunca asume
     * recuperación ni indicación profesional sin que la IA lo haya
     * distinguido con éxito).
     */
    private function mapCategory(?string $rawCategory): HealthConditionCategory
    {
        return HealthConditionCategory::tryFrom($rawCategory ?? '') ?? HealthConditionCategory::PossibleInjury;
    }

    /**
     * Reutiliza la infraestructura de alertas YA EXISTENTE (misma usada
     * por Payments para pagos pendientes y por TrainingHandler para
     * señales de seguridad) — ningún canal ni servicio nuevo. Un fallo de
     * entrega nunca se propaga (garantía ya provista por AlertService), y
     * el try/catch aquí es una defensa adicional: emitir la alerta nunca
     * debe impedir que la declaración quede registrada.
     */
    private function emitPendingReviewAlert(Contact $contact, DeclaredHealthCondition $condition): void
    {
        try {
            $this->alerts->send(new Alert(
                category: 'health_screening',
                severity: AlertSeverity::Warning,
                message: 'Nueva declaración de salud pendiente de revisión (declared_health_condition_id: '.$condition->id.').',
                context: [
                    'tenant_id' => $contact->tenant_id,
                    'contact_id' => $contact->id,
                    'customer_phone' => $contact->customer_phone,
                    'declared_health_condition_id' => $condition->id,
                    'category' => $condition->category->value,
                ],
            ));
        } catch (\Throwable $e) {
            Log::error('HEALTH_SCREENING_ALERT_EMIT_FAILED', [
                'contact_id' => $contact->id,
                'declared_health_condition_id' => $condition->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
