<?php

namespace App\ExerciseCatalog\Curation;

use App\ExerciseCatalog\Curation\DTOs\GeneratedSpanishContent;
use App\ExerciseCatalog\Curation\Exceptions\SpanishContentGenerationException;
use App\Factories\AIServiceFactory;
use App\Models\Exercise;
use App\Models\Tenant;

/**
 * Hito 9.3 (fix post-E2E) — hallazgo real de un test E2E: el contenido
 * técnico de un ejercicio (`name`/`instructions`/`important_points`)
 * llega del proveedor en su idioma original (inglés en YMove) y se
 * entregaba verbatim al usuario final por WhatsApp, sin ninguna capa de
 * traducción.
 *
 * Este servicio SOLO se invoca desde una acción manual de curación en
 * Filament (App\Filament\Resources\Exercises) — NUNCA en el momento de
 * envío de un mensaje. Extract → Decide → Narrate (docs/DECISIONS.md):
 * la IA aquí cumple el rol de "Narrate" (traduce/adapta, nunca inventa
 * contenido nuevo) — el código decide qué se guarda mediante validate(),
 * y un humano decide si el resultado es aceptable y cuándo activar el
 * ejercicio (Exercise::activate() sigue siendo independiente de esto).
 *
 * Deliberadamente 100% agnóstico de proveedor: opera únicamente sobre los
 * campos ya normalizados de `Exercise` (`name`/`instructions`/
 * `important_points`), nunca sobre `provider_metadata` — funciona igual
 * sin importar de qué proveedor (o de ningún proveedor, un Exercise
 * manual) haya salido el contenido original.
 *
 * NOTA ARQUITECTÓNICA (decisión explícita de este fix, ver informe final):
 * `AIServiceFactory::make()` solo existe con alcance por Tenant (lee
 * `Tenant.ai_provider/ai_model/ai_api_key`) — no existe ninguna
 * configuración de IA independiente de un Tenant en todo el sistema
 * (confirmado: `config/ai.php` solo lista modelos disponibles, nunca
 * credenciales). La curación de `Exercise` es una acción GLOBAL, no
 * ligada a ningún Tenant — pero en ausencia de una entidad de
 * configuración de IA a nivel de sistema (fuera de alcance de este fix,
 * ver el gap de "Provider Management" ya identificado), la única vía
 * honesta es que el administrador elija explícitamente de qué Tenant
 * tomar la credencial, en vez de adivinar una en silencio. Por eso este
 * método recibe un `Tenant` explícito — ExerciseResource ofrece un
 * selector, ver Filament\Resources\Exercises\Pages.
 */
class ExerciseSpanishContentGenerator
{
    public function generate(Exercise $exercise, Tenant $tenant): GeneratedSpanishContent
    {
        $ai = AIServiceFactory::make($tenant);

        $raw = $ai->getResponse(
            $this->buildUserPayload($exercise),
            $this->buildSystemPrompt(),
            [],
        );

        return $this->parseAndValidate($raw, $exercise);
    }

    private function buildUserPayload(Exercise $exercise): string
    {
        return json_encode([
            'name' => $exercise->name,
            'instructions' => $exercise->instructions ?? [],
            'important_points' => $exercise->important_points ?? [],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un traductor técnico especializado en fitness, traduciendo contenido de ejercicios del inglés al español neutro (variante colombiana, natural y cercana — el mismo tono que usaría un entrenador personal real escribiendo por WhatsApp, nunca el de un manual técnico ni el de una traducción palabra por palabra).

Recibirás un JSON con el contenido ORIGINAL de un ejercicio: "name" (string), "instructions" (arreglo ordenado de pasos) e "important_points" (arreglo de puntos clave, puede venir vacío).

Reglas estrictas de fidelidad — cada una es obligatoria, sin excepción:
1. NUNCA inventes información que no esté en el original. Si el original es ambiguo, incompleto o poco claro, tu traducción debe preservar exactamente esa misma ambigüedad — nunca la resuelvas, nunca la completes con tu propio criterio.
2. Preserva TODOS los números exactos (repeticiones, series, segundos, grados, distancias, porcentajes) sin redondear ni modificar.
3. Preserva la lateralidad exacta mencionada (izquierda/derecha, "cada lado", alternado) — nunca la generalices ni la omitas.
4. Preserva el ORDEN de los pasos exactamente como en el original — nunca reordenes, nunca fusiones dos pasos en uno, nunca dividas un paso en dos. El arreglo "instructions" traducido debe tener EXACTAMENTE el mismo número de elementos que el original, uno por uno en el mismo orden.
5. Igual regla de conteo y orden para "important_points": mismo número de elementos, mismo orden. Si el original viene vacío, devuélvelo vacío — nunca inventes puntos importantes que no existan.
6. Preserva el significado técnico exacto de cada instrucción biomecánica (ángulos, rango de movimiento, tempo, respiración, agarre) — una traducción que suene más natural pero cambie el significado técnico es un error grave, peor que sonar menos natural.
7. Usa español neutro, natural, cercano y apto para un mensaje de WhatsApp — evita literalismos que suenen a traducción automática, pero sin sacrificar ninguna regla anterior.
8. No agregues advertencias, notas, emojis, ni ningún contenido que no exista en el original.
9. No traduzcas nombres propios de equipo/marcas si no tienen un equivalente natural y comúnmente usado en español.

Responde EXCLUSIVAMENTE con un JSON (sin texto adicional, sin markdown, sin explicación) con esta forma exacta:
{
  "name": "<nombre del ejercicio traducido>",
  "instructions": ["<paso 1 traducido>", "<paso 2 traducido>", ...],
  "important_points": ["<punto importante 1 traducido>", ...]
}
PROMPT;
    }

    /**
     * El resultado de la IA nunca se confía tal cual — se valida contra
     * reglas verificables por código antes de convertirse en un DTO. Un
     * resultado que viole la fidelidad estructural (número de elementos
     * distinto al original) se rechaza por completo: nunca se guarda un
     * resultado parcial o sospechoso.
     */
    private function parseAndValidate(string $raw, Exercise $exercise): GeneratedSpanishContent
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/```$/', '', trim($cleaned)) ?? $cleaned;

        $decoded = json_decode(trim($cleaned), true);

        if (! is_array($decoded)) {
            throw new SpanishContentGenerationException(
                'La IA no devolvió un JSON válido — no se guardó ningún cambio.'
            );
        }

        $name = $decoded['name'] ?? null;
        $instructions = $decoded['instructions'] ?? null;
        $importantPoints = $decoded['important_points'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw new SpanishContentGenerationException(
                'La IA no devolvió un "name" válido — no se guardó ningún cambio.'
            );
        }

        if (! $this->isStringArray($instructions)) {
            throw new SpanishContentGenerationException(
                'La IA no devolvió "instructions" como un arreglo de textos — no se guardó ningún cambio.'
            );
        }

        if (! $this->isStringArray($importantPoints)) {
            throw new SpanishContentGenerationException(
                'La IA no devolvió "important_points" como un arreglo de textos — no se guardó ningún cambio.'
            );
        }

        $originalInstructionsCount = count($exercise->instructions ?? []);
        $originalImportantPointsCount = count($exercise->important_points ?? []);

        if (count($instructions) !== $originalInstructionsCount) {
            throw new SpanishContentGenerationException(
                "La traducción tiene {$this->countLabel($instructions)} pasos, pero el original tiene ".
                "{$this->countLabel($exercise->instructions ?? [])} — se descartó para no perder ni inventar pasos."
            );
        }

        if (count($importantPoints) !== $originalImportantPointsCount) {
            throw new SpanishContentGenerationException(
                'La traducción de "important_points" tiene un número de elementos distinto al original — '.
                'se descartó para no perder ni inventar puntos.'
            );
        }

        return new GeneratedSpanishContent(
            name: trim($name),
            instructions: array_values(array_map('trim', $instructions)),
            importantPoints: array_values(array_map('trim', $importantPoints)),
        );
    }

    /**
     * @param  mixed  $value
     */
    private function isStringArray($value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function countLabel(array $items): string
    {
        return (string) count($items);
    }
}
