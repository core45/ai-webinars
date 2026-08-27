<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Services;

use Astrotomic\Translatable\Contracts\Translatable;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Services\AbstractContentRewriteService;
use Illuminate\Database\Eloquent\Model;

/**
 * AI content rewrite service for Webinars.
 *
 * Extends AbstractContentRewriteService to provide Webinar-specific implementations
 * for content rewriting, translation, and SEO generation.
 */
class WebinarContentRewriteService extends AbstractContentRewriteService
{
    protected array $touchableModelClasses = [];

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process a webinar - rewrite/translate all content and SEO fields.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function processWebinar(Model&Translatable $webinar): array
    {
        return $this->processModel($webinar);
    }

    /**
     * Translate all translatable fields of a webinar from one locale to another.
     *
     * Translates name, slug, excerpt, description, and SEO fields using AI.
     * Skips fields that already have content in the target locale unless $overwrite is true.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function translateToLocale(Model $webinar, string $sourceLocale, string $targetLocale, bool $overwrite = false): array
    {
        $results = [
            'model_id' => $webinar->id, // @phpstan-ignore property.notFound
            'processed_fields' => [],
            'errors' => [],
        ];

        if (!$webinar instanceof Translatable) {
            $results['errors'][] = 'Model is not translatable';

            return $results;
        }

        $this->logRequest($this->getServiceName().' - translateToLocale', [
            'model_id' => $webinar->id, // @phpstan-ignore property.notFound
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'overwrite' => $overwrite,
        ]);

        $sourceTranslation = $webinar->translate($sourceLocale);
        if ($sourceTranslation === null || empty($sourceTranslation->name)) {
            $results['errors'][] = "No source content found for locale {$sourceLocale}";

            return $results;
        }

        $allFields = array_merge(
            ['name' => 'plain', 'excerpt' => 'plain'],
            ['description' => 'rich'],
            $this->getSeoFields(),
        );

        foreach ($allFields as $field => $type) {
            if ($this->isCancelled()) {
                break;
            }

            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $webinar->translate($targetLocale);
            if (!$overwrite && $targetTranslation !== null && !empty($targetTranslation->{$field})) {
                continue;
            }

            try {
                // Deterministic per-field operation id derived from the job's
                // operationId (audit 5.4) — retries replay the same id so
                // phasea's usage metering does not re-bill already-translated
                // fields. Falls back to per-call UUIDs when no id was threaded.
                /** @var string $translated */
                $translated = $this->withScopedOperationId(
                    "field:{$field}",
                    fn (): string => $this->translateText($sourceValue, $sourceLocale, $targetLocale, $type),
                );
                $this->saveTranslation($webinar, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — fail fast so the job
                // fails immediately instead of burning retries (audit §6).
                throw $e;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A retry re-sent a field a prior attempt already translated under the
                // same deterministic id — reconcile as done, not failed.
                $this->reconcileAlreadyCompletedField($webinar, $field, $targetLocale, $results, $e);
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateToLocale', $e);
            }
        }

        // Generate slug from translated name
        if (in_array("name:{$targetLocale}", $results['processed_fields'], true)) {
            try {
                $webinar->refresh();
                $translatedName = $webinar->translate($targetLocale)->name ?? '';
                if ($translatedName !== '') {
                    $slug = $this->generateUniqueSlug($translatedName, $webinar->id, $targetLocale); // @phpstan-ignore property.notFound
                    $this->saveTranslation($webinar, 'slug', $targetLocale, $slug);
                    $results['processed_fields'][] = "slug:{$targetLocale}";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "slug:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
            }
        }

        $webinar->refresh();

        $this->logResponse($this->getServiceName().' - translateToLocale', $results);

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ABSTRACT METHOD IMPLEMENTATIONS
    // ═══════════════════════════════════════════════════════════════════════

    protected function getContentFields(): array
    {
        return [
            'excerpt' => 'plain',
            'description' => 'rich',
        ];
    }

    protected function getSeoFields(): array
    {
        return [
            'seo_title' => 'plain',
            'seo_description' => 'plain',
            'seo_keywords' => 'plain',
        ];
    }

    protected function getModelClass(): string
    {
        // ModelRegistry::getModelClassOrFail() is typed `string`, not
        // `class-string<Model>` (§3 seam lives in ai-core, out of scope here).
        return app(ModelRegistry::class)->getModelClassOrFail('webinar');
    }

    protected function getTranslationClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('webinar_translation');
    }

    protected function getServiceName(): string
    {
        return 'WebinarContentRewrite';
    }

    protected function getContentTypeLabel(): string
    {
        return 'webinars and online courses';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5). It replaces the already-resolved
     * `field_description` string plan 48-05 sent as a temporary shape.
     */
    protected function getContentTypeKey(): string
    {
        return 'webinar';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'webinar_rewrite_system';
    }

    protected function getSeoPromptOverrideKey(): string
    {
        return 'webinar_seo_system';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.seo_field` template
     * selects this subclass's instruction with (v2.6 design §2.5). It replaces
     * `buildSeoFieldPrompt()`, whose three branches were transcribed verbatim
     * into that template's variant table.
     */
    protected function getSeoContentTypeKey(): string
    {
        return 'webinar';
    }

    /**
     * @param  Model&Translatable  $model
     */
    protected function buildModelContext(Model $model): array
    {
        $model->load(['product', 'parts.elements']);

        $context = [
            'webinar_id' => $model->id, // @phpstan-ignore property.notFound
            'active' => $model->active, // @phpstan-ignore property.notFound
            'parts_count' => $model->parts()->count(), // @phpstan-ignore method.notFound
            'elements_count' => $model->elements()->count(), // @phpstan-ignore method.notFound
            'related_products' => $model->product->map(fn ($p) => $p->name)->toArray(), // @phpstan-ignore property.notFound
            'translations' => [],
        ];

        // Collect existing translations for context
        foreach ($this->getLocales() as $locale) {
            $translation = $model->translate($locale);
            if ($translation) {
                $context['translations'][$locale] = [
                    'name' => $translation->name, // @phpstan-ignore property.notFound
                    'excerpt' => $translation->excerpt, // @phpstan-ignore property.notFound
                    'description' => $this->truncateForContext($translation->description), // @phpstan-ignore property.notFound
                ];
            }
        }

        return $context;
    }

    protected function getFieldDescription(string $field): string
    {
        return match ($field) {
            'excerpt' => 'a concise webinar excerpt (2-3 sentences highlighting key learning points and value)',
            'description' => 'a detailed webinar description (well-structured with learning objectives, topics covered, and who should attend)',
            'seo_title' => 'an SEO title (max 60 characters, include main keyword)',
            'seo_description' => 'an SEO meta description (max 160 characters, compelling call-to-action)',
            'seo_keywords' => 'SEO keywords (5-10 relevant keywords, comma-separated)',
            default => "content for the {$field} field",
        };
    }

    /**
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSeoContext(Model $model, string $locale, array $context): array
    {
        $translation = $model->translate($locale);

        $seoContextLimit = (int) config('ai.assistant.content.seo_context_limit', 5000);
        $description = $translation->description ?? '';
        if (mb_strlen($description) > $seoContextLimit) {
            $description = mb_substr($description, 0, $seoContextLimit).'...';
        }

        // Get related products for context (name is a translated attribute)
        $relatedProducts = $model->product->map(fn ($p) => $p->name)->toArray(); // @phpstan-ignore property.notFound

        return [
            'name' => $translation->name ?? '',
            'excerpt' => $translation->excerpt ?? '',
            'description' => strip_tags($description),
            'parts_count' => $model->parts()->count(), // @phpstan-ignore method.notFound
            'elements_count' => $model->elements()->count(), // @phpstan-ignore method.notFound
            'related_products' => implode(', ', $relatedProducts),
        ];
    }

    /**
     * Handle name/slug generation for a webinar.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     */
    protected function handleNameSlug(Model $model, string $locale, array $context): void
    {
        $existingTranslation = $model->translate($locale);
        $hasName = $existingTranslation && !empty($existingTranslation->name);
        $hasSlug = $existingTranslation && !empty($existingTranslation->slug);

        if ($hasName && $hasSlug) {
            return;
        }

        $name = $hasName ? $existingTranslation->name : $this->generateOrTranslateName($model, $locale, $context);

        $slug = $hasSlug ? $existingTranslation->slug : $this->generateUniqueSlug($name, $model->id, $locale); // @phpstan-ignore property.notFound

        // Save both name and slug atomically to satisfy NOT NULL constraints
        $translation = $model->translateOrNew($locale);
        $translation->name = $name; // @phpstan-ignore property.notFound
        $translation->slug = $slug; // @phpstan-ignore property.notFound
        $translation->save();
    }

    /**
     * @param  Model&Translatable  $model
     */
    protected function findSourceLocale(Model $model, string $field): ?string
    {
        foreach ($this->getLocales() as $locale) {
            $translation = $model->translate($locale);
            if ($translation && !empty($translation->{$field})) {
                return $locale;
            }
        }

        return null;
    }

    protected function slugExistsForLocale(string $slug, int $excludeId, string $locale): bool
    {
        $translationClass = app(ModelRegistry::class)->getModelClassOrFail('webinar_translation');

        return $translationClass::where('slug', $slug)
            ->where('locale', $locale)
            ->where('webinar_id', '!=', $excludeId)
            ->exists();
    }

    /**
     * Resolve the content archetype for a webinar, or null when the host model
     * does not carry one.
     *
     * `resolveContentArchetype()` comes from the host's `HasContentArchetype`
     * trait, which this package cannot see: there is no package-visible
     * contract for it, so the call has to be dynamic. That makes it OPTIONAL —
     * a consuming app is free to have a Webinar model without the trait, and
     * archetypes are an enhancement, not a requirement.
     *
     * This used to call the method unconditionally, with a phpstan ignore for
     * `method.notFound` suppressing the warning — which is exactly the bug the
     * ignore was hiding: on a host model without the trait the result is a
     * fatal "Call to undefined method", not a missing archetype. The two
     * equivalent
     * methods in ai-products already guard this way
     * (`ProductContentRewriteService::resolveArchetype()` and its
     * category counterpart); ai-webinars was the odd one out.
     */
    protected function resolveArchetype(Model $model): ?Model
    {
        if (!method_exists($model, 'resolveContentArchetype')) {
            return null;
        }

        $archetype = $model->resolveContentArchetype();

        return $archetype instanceof Model ? $archetype : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  Model&Translatable  $webinar
     * @param  array<string, mixed>  $context
     */
    private function generateOrTranslateName(Model $webinar, string $locale, array $context): string
    {
        $sourceLocale = $this->findSourceLocale($webinar, 'name');

        if ($sourceLocale && $sourceLocale !== $locale) {
            $sourceTranslation = $webinar->translate($sourceLocale);
            $sourceName = $sourceTranslation->name; // @phpstan-ignore property.notFound

            return $this->translateText($sourceName, $sourceLocale, $locale);
        }

        return $this->generateNameFromContext($locale, $context);
    }

    /**
     * Generate name from context when no source exists.
     *
     * @param  array<string, mixed>  $context
     */
    private function generateNameFromContext(string $locale, array $context): string
    {
        // Generate a basic name from context
        $parts = [];
        $parts[] = 'Webinar';

        if (!empty($context['related_products'])) {
            $parts[] = $context['related_products'][0] ?? '';
        }

        $parts[] = '#'.$context['webinar_id'];

        $baseName = implode(' - ', array_filter($parts));

        // If we have source content in any locale, use that for name generation
        foreach ($context['translations'] as $localeData) {
            if (!empty($localeData['name'])) {
                return $this->translateText($localeData['name'], 'en', $locale);
            }
        }

        return $baseName;
    }
}
