<?php

namespace App\Filament\Resources\Exercises\Tables;

use App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator;
use App\ExerciseCatalog\ProviderRegistry;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Training\Enums\Equipment;
use App\Training\Enums\ExperienceLevel;
use App\Training\Enums\MovementPattern;
use App\Training\Enums\MuscleFocus;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Hito 9.3 — el listado del catálogo interno. `provider` se filtra con
 * las claves REALES de `ProviderRegistry::knownKeys()` — nunca una lista
 * fija ni un enum — así que un proveedor nuevo aparece aquí solo con
 * registrarlo en config/exercise_providers.php, sin tocar este archivo.
 */
class ExercisesTable
{
    public static function configure(Table $table): Table
    {
        $providerOptions = array_combine(
            app(ProviderRegistry::class)->knownKeys(),
            app(ProviderRegistry::class)->knownKeys(),
        );

        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge()
                    ->placeholder('manual'),
                TextColumn::make('provider_exercise_id')
                    ->label('Provider ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
                TextColumn::make('difficulty_level')
                    ->label('Difficulty')
                    ->placeholder('—'),
                TextColumn::make('primary_muscle')
                    ->label('Primary focus')
                    ->formatStateUsing(fn (?MuscleFocus $state) => $state?->value)
                    ->placeholder('—'),
                TextColumn::make('secondary_muscles')
                    ->label('Secondary focus')
                    ->formatStateUsing(fn (?array $state) => $state !== null ? implode(', ', $state) : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('movement_pattern')
                    ->label('Movement')
                    ->formatStateUsing(fn (?MovementPattern $state) => $state?->value)
                    ->placeholder('—'),
                IconColumn::make('provider_has_video')
                    ->label('Has video')
                    // Tri-estado explícito, nunca ->boolean() a secas: null
                    // (proveedor no informa el concepto / ejercicio manual)
                    // es un valor real y distinto de false, no un vacío.
                    ->icon(fn (?bool $state) => match ($state) {
                        true => 'heroicon-o-video-camera',
                        false => 'heroicon-o-video-camera-slash',
                        null => 'heroicon-o-minus',
                    })
                    ->color(fn (?bool $state) => match ($state) {
                        true => 'success',
                        false => 'danger',
                        null => 'gray',
                    }),
                TextColumn::make('review_status')
                    ->label('Status')
                    ->state(fn (Exercise $record) => $record->reviewStatus())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'pending_review' => 'warning',
                        'inactive' => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str($state)->headline()),
                IconColumn::make('name_es')
                    ->label('ES')
                    ->boolean()
                    ->state(fn (Exercise $record) => $record->name_es !== null)
                    ->tooltip(fn (Exercise $record) => $record->content_translated_at?->diffForHumans() ?? 'Sin traducir todavía'),
                IconColumn::make('video_validated')
                    ->label('Video validado')
                    ->boolean()
                    // Hito 9.3 (post-deploy) — deliberadamente NO
                    // provider_has_video (lo que el proveedor DICE tener):
                    // nuestro propio registro (ExerciseVideoAccess) de
                    // resoluciones EXITOSAS, la única fuente de verdad
                    // real de "esto ya se probó y funcionó".
                    ->state(fn (Exercise $record) => $record->videoValidated())
                    ->tooltip(fn (Exercise $record) => $record->videoAccesses()->latest('resolved_at')->first()?->resolved_at?->diffForHumans() ?? 'Nunca resuelto con éxito'),
                TextColumn::make('active_coverage')
                    ->label('Cobertura activa (mismo foco)')
                    ->tooltip('Cuántos ejercicios ya ACTIVOS comparten este primary_muscle — útil para priorizar candidatos que diversifiquen el catálogo (números bajos = mayor prioridad).')
                    ->state(fn (Exercise $record) => $record->primary_muscle === null
                        ? '—'
                        : Exercise::where('is_active', true)->where('primary_muscle', $record->primary_muscle->value)->count())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('synced_at')
                    ->label('Synced')
                    ->since()
                    ->sortable()
                    ->placeholder('never (manual)'),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options($providerOptions),
                // Hito 9.3 (post-deploy) — tri-estado real (pending_review/
                // active/inactive), no solo el booleano de is_active.
                // Nunca reimplementa la regla: delega en
                // Exercise::scopeWithReviewStatus(), la misma que usa
                // reviewStatus() para calcular el badge de "Status".
                SelectFilter::make('review_status')
                    ->label('Review status')
                    ->options([
                        'pending_review' => 'Pending Review',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->withReviewStatus($data['value'])
                        : $query),
                TernaryFilter::make('provider_has_video')
                    ->label('Has video (según el proveedor)')
                    ->placeholder('All'),
                // Hito 9.3 (post-deploy) — deliberadamente separado del
                // filtro anterior: "el proveedor dice que tiene video" vs.
                // "nosotros ya comprobamos que funciona" son preguntas
                // distintas (ver Exercise::videoValidated()).
                TernaryFilter::make('video_validated')
                    ->label('Video validado (nuestro registro)')
                    ->placeholder('All')
                    ->queries(
                        true: fn ($query) => $query->whereHas('videoAccesses'),
                        false: fn ($query) => $query->whereDoesntHave('videoAccesses'),
                    ),
                SelectFilter::make('difficulty_level')
                    ->label('Difficulty')
                    ->options(fn () => collect(ExperienceLevel::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                SelectFilter::make('primary_muscle')
                    ->label('Primary focus')
                    ->options(fn () => collect(MuscleFocus::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                SelectFilter::make('movement_pattern')
                    ->label('Movement pattern')
                    ->options(fn () => collect(MovementPattern::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)])),
                // Hito 9.3 (post-deploy) — equipment_needed ya está
                // normalizado al vocabulario cerrado Equipment (ver D041/
                // este mismo hito); un array JSON necesita whereJsonContains,
                // nunca el where() por igualdad que SelectFilter usa por defecto.
                SelectFilter::make('equipment_needed')
                    ->label('Equipment')
                    ->options(fn () => collect(Equipment::cases())->mapWithKeys(fn ($c) => [$c->value => Str::headline($c->value)]))
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->whereJsonContains('equipment_needed', $data['value'])
                        : $query),
                // Hito 9.3 (curación de lotes) — filtro genérico y
                // reutilizable, deliberadamente SIN ningún id hardcodeado:
                // sirve para acotar la tabla a cualquier lote puntual de
                // curación (ej. los candidatos aprobados de una ronda) sin
                // mezclarlo con el resto del catálogo pending_review, y sin
                // introducir una columna/tabla nueva de "batch" — el propio
                // administrador pega los ids que le interesan en cada
                // sesión. No persiste nada; se pierde al recargar sin el
                // parámetro de filtro.
                Filter::make('specific_ids')
                    ->label('IDs específicos')
                    ->schema([
                        Textarea::make('ids')
                            ->label('IDs a incluir (separados por coma, espacio o salto de línea)')
                            ->helperText('Útil para revisar un lote de curación puntual sin mezclarlo con el resto del catálogo pendiente.')
                            ->rows(2),
                    ])
                    ->query(function ($query, array $data) {
                        $raw = $data['ids'] ?? null;

                        // Texto vacío/en blanco: filtro no aplicado, se
                        // muestra el catálogo normal.
                        if (blank($raw)) {
                            return $query;
                        }

                        $ids = collect(preg_split('/[\s,]+/', trim($raw)))
                            ->filter(fn ($value) => ctype_digit($value))
                            ->map(fn ($value) => (int) $value)
                            ->values()
                            ->all();

                        // Texto no vacío pero sin ningún id numérico válido:
                        // NUNCA debe devolver el catálogo sin acotar — eso
                        // ampliaría accidentalmente el conjunto sobre el que
                        // se trabaja, justo lo que este filtro existe para
                        // evitar. `whereIn('id', [])` compila a `0 = 1`
                        // (comportamiento nativo de Laravel, ver
                        // Grammar::whereIn()) — cero resultados garantizados,
                        // nunca "sin filtro". El indicador de abajo deja
                        // explícito por qué la tabla quedó vacía.
                        return $query->whereIn('id', $ids);
                    })
                    ->indicateUsing(function (array $data) {
                        $raw = $data['ids'] ?? null;

                        if (blank($raw)) {
                            return [];
                        }

                        $hasValidIds = collect(preg_split('/[\s,]+/', trim($raw)))
                            ->contains(fn ($value) => ctype_digit($value));

                        return $hasValidIds
                            ? ['specific_ids' => 'IDs específicos: '.Str::limit(trim($raw), 60)]
                            : ['specific_ids' => '⚠️ Ningún ID numérico válido en el texto ingresado — no se muestra ningún ejercicio.'];
                    }),
            ])
            ->defaultSort('synced_at', 'desc')
            ->recordActions([
                EditAction::make(),

                // Hito 9.3 (curación de seguridad, post-validación de UX) —
                // ANTES esta acción guardaba contraindications Y activaba en
                // el mismo clic, con el modal pre-rellenando `null` como `[]`
                // (ver TagsInput::setUp() en el vendor — afterStateHydrated
                // fuerza [] para cualquier estado no-array). Eso hacía
                // trivial confirmar sin haber revisado nada de verdad.
                // Separada ahora en dos acciones: esta SOLO guarda
                // contraindications, nunca activa — la decisión de
                // seguridad queda deliberadamente aislada de la decisión de
                // publicar. Nunca usa IA, nunca asume `[]` por defecto: el
                // checkbox `reviewed_consciously` (regla `accepted`) bloquea
                // el guardado entero si el administrador no confirma
                // explícitamente, sea cual sea el contenido de
                // contraindications — así la coerción del vendor a `[]` en
                // el campo de tags nunca llega a persistirse sola.
                Action::make('reviewSafety')
                    ->label('Revisar seguridad')
                    ->color('warning')
                    ->icon('heroicon-o-shield-exclamation')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin && ! $record->is_active)
                    ->schema([
                        Placeholder::make('current_status')
                            ->label('Estado actual')
                            ->content(fn (Exercise $record): string => match (true) {
                                $record->contraindications === null => '⚠️ Pendiente de revisión de seguridad — nunca se ha decidido para este ejercicio.',
                                $record->contraindications === [] => '✅ Revisado — sin contraindicaciones registradas.',
                                default => '✅ Revisado — contraindicaciones registradas: '.implode(', ', $record->contraindications),
                            }),
                        TagsInput::make('contraindications')
                            ->label('Contraindicaciones')
                            ->helperText('Añade una por cada contraindicación real. Si tras revisar no encuentras ninguna, déjalo vacío — deberás confirmarlo abajo igualmente.')
                            ->placeholder('Ej. hernia discal'),
                        Checkbox::make('reviewed_consciously')
                            ->label('Confirmo que revisé este ejercicio y que las contraindicaciones de arriba (o la ausencia de ellas) reflejan mi decisión, no una casilla vacía sin revisar.')
                            ->default(false)
                            ->required()
                            ->rule('accepted'),
                    ])
                    ->fillForm(fn (Exercise $record): array => [
                        // Deliberadamente SIN "?? []" — si el modelo trae
                        // null, que llegue null al formulario. El Placeholder
                        // de arriba ya deja claro el estado real
                        // independientemente de cómo el componente de tags
                        // lo renderice internamente.
                        'contraindications' => $record->contraindications,
                        'reviewed_consciously' => false,
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Esto SOLO guarda la revisión de seguridad — el ejercicio sigue pendiente de activación. Usa "Activar" por separado después.')
                    ->action(function (Exercise $record, array $data): void {
                        $record->update(['contraindications' => $data['contraindications']]);

                        Notification::make()
                            ->title('Revisión de seguridad guardada')
                            ->body('El ejercicio sigue pendiente de activación.')
                            ->success()
                            ->send();
                    }),

                // La regla de negocio (qué bloquea activar) vive ÚNICAMENTE
                // en Exercise::activate() — esta acción nunca la
                // reimplementa, solo delega. Ya no expone ni edita
                // contraindications: eso es responsabilidad exclusiva de
                // "Revisar seguridad", de ahí que solo sea visible cuando ya
                // se decidió (contraindications !== null).
                Action::make('activate')
                    ->label('Activar')
                    ->color('success')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin
                        && $record->reviewStatus() !== 'active'
                        && $record->contraindications !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Activa el ejercicio con las contraindicaciones ya guardadas. Para cambiarlas, usa "Revisar seguridad" primero.')
                    ->action(function (Exercise $record): void {
                        try {
                            /** @var User $user */
                            $user = Auth::user();
                            $record->activate($user);

                            Notification::make()->title('Ejercicio activado')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->title('No se pudo activar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('deactivate')
                    ->label('Desactivar')
                    ->color('gray')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin && $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (Exercise $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()->title('Ejercicio desactivado')->success()->send();
                    }),

                // Hito 9.3 (fix post-E2E) — genera name_es/instructions_es/
                // important_points_es mediante IA. Solo disponible para
                // ejercicios todavía en revisión: nunca reescribe contenido
                // ya activo sin que un admin lo desactive primero a
                // propósito. NUNCA activa el ejercicio ni toca is_active —
                // ver App\ExerciseCatalog\Curation\ExerciseSpanishContentGenerator.
                //
                // Hito 9.3 (curación de seguridad) — hallazgo real durante
                // la curación manual (ID 55): la condición ANTES exigía
                // reviewStatus()==='pending_review', que además de
                // is_active===false también requiere contraindications===
                // null. En cuanto se guardaba una revisión de seguridad
                // (aunque fuera []), reviewStatus() pasaba a 'inactive' —
                // NUNCA 'pending_review' de nuevo, ni desactivando el
                // ejercicio — dejando la traducción bloqueada para siempre.
                // Seguridad y traducción son procesos independientes (ver
                // docs/DECISIONS.md): la única condición real, coherente con
                // el comentario de arriba y con reviewSafety, es no estar
                // activo — sin importar el estado de contraindications.
                Action::make('generateSpanishContent')
                    ->label('Generar contenido en español')
                    ->color('info')
                    ->icon('heroicon-o-language')
                    ->visible(fn (Exercise $record): bool => Auth::user()?->is_super_admin
                        && ! $record->is_active)
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Credenciales de IA a usar (por tenant)')
                            ->helperText('El sistema todavía no tiene una configuración de IA independiente de un tenant — se reutiliza la de un tenant existente. Ver informe Hito 9.3.')
                            ->options(fn () => Tenant::whereNotNull('ai_api_key')->pluck('name', 'id'))
                            ->native(false)
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Genera name/instructions/important_points en español mediante IA, a partir del contenido original. Nunca sobreescribe el original ni activa el ejercicio — queda editable en la página de edición.')
                    ->action(function (Exercise $record, array $data): void {
                        $tenant = Tenant::find($data['tenant_id']);

                        try {
                            $result = app(ExerciseSpanishContentGenerator::class)->generate($record, $tenant);

                            $record->update([
                                'name_es' => $result->name,
                                'instructions_es' => $result->instructions,
                                'important_points_es' => $result->importantPoints,
                                'content_translated_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Contenido en español generado')
                                ->body('Revísalo y edítalo si hace falta antes de activar el ejercicio.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo generar el contenido en español')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
