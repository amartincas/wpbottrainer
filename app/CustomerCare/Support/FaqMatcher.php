<?php

namespace App\CustomerCare\Support;

use App\CustomerCare\Models\Faq;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Hito 14 — recuperación determinista de candidatos ("candidate
 * retrieval") + saneamiento de la salida estructurada de la IA. Sin
 * embeddings, sin RAG, sin vector DB — `LIKE` sobre `question`/`answer` con
 * términos normalizados, ranking en código por cantidad de coincidencias.
 * El ranking reduce candidatos; la selección semántica final de cuál FAQ
 * responde la pregunta es exclusivamente de la IA (ver docs/DECISIONS.md).
 */
class FaqMatcher
{
    private const MAX_CANDIDATES = 5;

    private const STOPWORDS = [
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'de', 'del', 'al',
        'que', 'y', 'o', 'a', 'en', 'por', 'para', 'con', 'es', 'son', 'me', 'mi',
        'tu', 'su', 'lo', 'se', 'si', 'no', 'ya', 'muy', 'este', 'esta', 'ese', 'esa',
    ];

    /**
     * @return Collection<int, Faq>
     */
    public function retrieveCandidates(Tenant $tenant, string $userMessage): Collection
    {
        $terms = $this->significantTerms($userMessage);

        if ($terms === []) {
            // Sin señal de contenido para filtrar -> ningún candidato.
            // Nunca se sustituye por "devolver todo el catálogo" — eso
            // reintroduciría, por otra vía, el límite arbitrario ya
            // descartado explícitamente (ver docs/DECISIONS.md).
            return collect();
        }

        $candidates = Faq::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $query->orWhere('question', 'like', "%{$term}%")
                        ->orWhere('answer', 'like', "%{$term}%");
                }
            })
            ->get(['id', 'question', 'answer']);

        return $candidates
            ->sortByDesc(fn (Faq $faq) => $this->matchScore($faq, $terms))
            ->take(self::MAX_CANDIDATES)
            ->values();
    }

    /**
     * Descarta TODA la salida relacionada con FAQ/Customer Service de la
     * IA (incluido cualquier `customer_service_message` ya redactado) si
     * `faq_match_id` no pertenece exactamente al conjunto de candidatos
     * que se le mostró a la IA este turno — una salida estructurada
     * inválida provoca una degradación totalmente determinista, nunca se
     * conserva parte de ella. Validación 100% en memoria — sin BD.
     *
     * @param  iterable  $offeredCandidates  Faq[] o CoachFaqCandidate[] — cualquier iterable de objetos con ->id
     */
    public function sanitize(array $result, iterable $offeredCandidates): array
    {
        $faqMatchId = $result['faq_match_id'] ?? null;

        if ($faqMatchId === null) {
            return $result;
        }

        $valid = collect($offeredCandidates)->contains(fn ($candidate) => $candidate->id === $faqMatchId);

        if ($valid) {
            return $result;
        }

        return array_merge($result, [
            'faq_match_id' => null,
            'faq_response_text' => null,
            'customer_service_needed' => true,
            'customer_service_message' => null,
        ]);
    }

    private function matchScore(Faq $faq, array $terms): int
    {
        $haystack = mb_strtolower($faq->question.' '.$faq->answer);

        return collect($terms)->filter(fn ($term) => str_contains($haystack, $term))->count();
    }

    /**
     * @return array<int, string>
     */
    private function significantTerms(string $message): array
    {
        $normalized = strtr(mb_strtolower(trim($message)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $words = preg_split('/[^a-z0-9]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->filter(fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }
}
