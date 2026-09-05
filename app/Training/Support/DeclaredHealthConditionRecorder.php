<?php

namespace App\Training\Support;

use App\Models\Contact;
use App\Models\DeclaredHealthCondition;
use App\Models\TrainingRestriction;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Training\Enums\BodyRegion;
use App\Training\Enums\HealthConditionCategory;
use App\Training\Enums\HealthConditionStatus;
use App\Training\Enums\RestrictionSource;
use App\Training\Enums\RestrictionStatus;
use App\Training\Enums\RestrictionType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Hito de seguridad de restricciones (Bloque 2) — único punto de escritura
 * de `DeclaredHealthCondition` y del único camino de creación de
 * `TrainingRestriction` a partir de una declaración.
 *
 * Separación estricta y no negociable (ver docs/DECISIONS.md):
 * - `declare()` registra un HECHO ("el usuario dijo X") — nunca decide
 *   nada, nunca crea ni modifica una TrainingRestriction, sin importar la
 *   categoría ni si el texto coincide con el catálogo de
 *   BodyRegionCanonicalMapper. Que un texto sea reconocible como una
 *   CONDICIÓN conocida (ej. "lesión de hombro") no equivale a que el
 *   usuario haya declarado una LIMITACIÓN FUNCIONAL explícita — confundir
 *   ambas cosas fue un error de diseño corregido antes de implementar
 *   este bloque.
 * - `resolveWithRestriction()` es el único camino de creación de
 *   TrainingRestriction en este bloque, siempre con un revisor humano
 *   explícito y un `RestrictionSource` explícito (nunca derivado de
 *   `category` — el origen real de la restricción lo decide quien
 *   resuelve, no una tabla automática).
 * - El auto-confirmado determinista de una restricción a partir de una
 *   limitación funcional ya explícita (`source=user_explicit`,
 *   `status=confirmed`, sin revisor humano) queda completamente fuera de
 *   este bloque — es responsabilidad del futuro `HealthScreeningRequirement`
 *   (Bloque 4), que decidirá su propio mecanismo sin que este Recorder
 *   necesite cambiar.
 *
 * Garantías de integridad (verificación posterior a la aprobación
 * funcional del bloque):
 * - `resolveWithRestriction()` es atómica (DB::transaction): si falla
 *   cualquiera de sus dos escrituras, ninguna queda persistida.
 * - `declare()` valida que `source_message_id`, cuando se provee,
 *   pertenezca al MISMO `tenant_id` + `customer_phone` que el `Contact`
 *   que declara — una FK por sí sola solo garantiza que el ID exista, no
 *   que corresponda al contacto correcto.
 */
class DeclaredHealthConditionRecorder
{
    public function __construct(
        private readonly BodyRegionCanonicalMapper $mapper,
        private readonly FunctionalLimitationCanonicalMapper $functionalMapper,
    ) {}

    /**
     * Registra una declaración. SIEMPRE queda `pending_review` — nunca
     * crea ni modifica ninguna `TrainingRestriction`, sin importar
     * `category` ni si el texto (de condición o funcional) es reconocido
     * por alguno de los dos catálogos.
     *
     * Bloque 5: `$functionalLimitationText`, cuando se provee, se preserva
     * LITERAL (nunca se resume) y se usa ÚNICAMENTE para calcular una
     * sugerencia de `BodyRegion` adicional — vía
     * `FunctionalLimitationCanonicalMapper`, un catálogo cerrado DISTINTO
     * de `BodyRegionCanonicalMapper` — cuando el texto de la condición por
     * sí solo no arrojó ninguna. Esa sugerencia sigue siendo solo una
     * ETIQUETA para acelerar la revisión humana: jamás crea ni confirma
     * una `TrainingRestriction` — ese camino sigue siendo exclusivamente
     * `resolveWithRestriction()`, con revisor humano obligatorio.
     */
    public function declare(
        Contact $contact,
        string $originalText,
        HealthConditionCategory $category,
        ?int $sourceMessageId = null,
        ?string $functionalLimitationText = null,
    ): DeclaredHealthCondition {
        $this->assertSourceMessageBelongsToContact($contact, $sourceMessageId);

        $suggestedRegion = $this->suggestBodyRegion($originalText, $functionalLimitationText);

        return DeclaredHealthCondition::create([
            'contact_id' => $contact->id,
            'original_text' => $originalText,
            'functional_limitation_text' => $functionalLimitationText,
            'source_message_id' => $sourceMessageId,
            'category' => $category,
            'suggested_body_region' => $suggestedRegion,
            'status' => HealthConditionStatus::PendingReview,
            'declared_at' => now(),
        ]);
    }

    /**
     * Único camino de creación de TrainingRestriction en este bloque.
     * `$source` es SIEMPRE explícito — nunca se deriva de
     * `$condition->category`. `$reviewer` es obligatorio: no existe una
     * variante de este método sin revisor humano en este bloque.
     */
    public function resolveWithRestriction(
        DeclaredHealthCondition $condition,
        BodyRegion $bodyRegion,
        RestrictionSource $source,
        User $reviewer,
        ?string $note = null,
    ): TrainingRestriction {
        // Ambas escrituras (crear la restricción + actualizar la
        // declaración con el enlace bidireccional) deben ser atómicas: si
        // la segunda falla, la primera no debe quedar persistida — nunca
        // una TrainingRestriction "huérfana" ni un enlace unidireccional.
        return DB::transaction(function () use ($condition, $bodyRegion, $source, $reviewer, $note) {
            $restriction = TrainingRestriction::create([
                'contact_id' => $condition->contact_id,
                'body_region' => $bodyRegion,
                'restriction_type' => RestrictionType::ExcludeExercise,
                'source' => $source,
                'status' => RestrictionStatus::Confirmed,
                'original_text' => $condition->original_text,
                'declared_health_condition_id' => $condition->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            $condition->update([
                'status' => HealthConditionStatus::ResolvedRestrictionCreated,
                'related_restriction_id' => $restriction->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $restriction;
        });
    }

    /**
     * Un humano revisó la declaración y determinó que no corresponde
     * ninguna restricción. Nunca crea ni toca ninguna TrainingRestriction.
     */
    public function resolveWithoutRestriction(DeclaredHealthCondition $condition, User $reviewer, string $note): void
    {
        $condition->update([
            'status' => HealthConditionStatus::ResolvedNoRestriction,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }

    /**
     * Marca la declaración como reemplazada por una posterior sobre el
     * mismo tema — siempre una acción humana explícita, nunca automática.
     */
    public function supersede(DeclaredHealthCondition $condition, User $reviewer): void
    {
        $condition->update([
            'status' => HealthConditionStatus::Superseded,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * SOLO una etiqueta determinista, nunca una decisión. `null` si ningún
     * texto (condición o funcional) coincide exactamente con su catálogo
     * respectivo — nunca se aproxima. El texto de condición tiene
     * prioridad; el funcional es un respaldo cuando el de condición no
     * arroja nada, nunca al revés (evita que un texto funcional "gane" a
     * una condición ya reconocida).
     */
    private function suggestBodyRegion(string $originalText, ?string $functionalLimitationText): ?BodyRegion
    {
        $matches = $this->mapper->mapMany([$originalText]);

        if ($matches !== []) {
            return $matches[0];
        }

        if ($functionalLimitationText !== null) {
            return $this->functionalMapper->map($functionalLimitationText);
        }

        return null;
    }

    /**
     * Protección mínima de integridad: `WhatsAppMessage` se identifica por
     * `tenant_id + customer_phone`, no por `contact_id` (esa columna no
     * existe en ese modelo) — una FK simple garantizaría que el ID exista,
     * pero NO que pertenezca al mismo contacto que está declarando. Sin
     * esta verificación, un `source_message_id` de OTRO contacto (incluso
     * de otro tenant) podría enlazarse aquí sin ningún error.
     */
    private function assertSourceMessageBelongsToContact(Contact $contact, ?int $sourceMessageId): void
    {
        if ($sourceMessageId === null) {
            return;
        }

        $message = WhatsAppMessage::find($sourceMessageId);

        $belongsToSameContact = $message !== null
            && (int) $message->tenant_id === (int) $contact->tenant_id
            && $message->customer_phone === $contact->customer_phone;

        if (! $belongsToSameContact) {
            throw new InvalidArgumentException(
                'source_message_id must reference a WhatsAppMessage belonging to the same tenant and customer_phone as the declaring Contact.'
            );
        }
    }
}
