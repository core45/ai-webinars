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
 * AI content rewrite service for Webinar Parts.
 *
 * Extends AbstractContentRewriteService to provide WebinarPart-specific implementations
 * for content rewriting and translation.
 */
class WebinarPartContentRewriteService extends AbstractContentRewriteService
{
    protected array $touchableModelClasses = [];

    // ═══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process a webinar part - rewrite/translate all content fields.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function processWebinarPart(Model&Translatable $webinarPart): array
    {
        return $this->processModel($webinarPart);
    }

    /**
     * Translate all translatable fields of a webinar part from one locale to another.
     *
     * @return array{model_id: int, processed_fields: array<string>, errors: array<string>}
     */
    public function translateToLocale(Model $part, string $sourceLocale, string $targetLocale, bool $overwrite = false): array
    {
        $results = [
            'model_id' => $part->id, // @phpstan-ignore property.notFound
            'processed_fields' => [],
            'errors' => [],
        ];

        if (!$part instanceof Translatable) {
            $results['errors'][] = 'Model is not translatable';

            return $results;
        }

        $sourceTranslation = $part->translate($sourceLocale);
        if ($sourceTranslation === null || empty($sourceTranslation->name)) {
            $results['errors'][] = "No source content found for part #{$part->id} locale {$sourceLocale}"; // @phpstan-ignore property.notFound

            return $results;
        }

        $allFields = [
            'name' => 'plain',
            'excerpt' => 'plain',
            'description' => 'rich',
        ];

        foreach ($allFields as $field => $type) {
            if ($this->isCancelled()) {
                break;
            }

            $sourceValue = (string) ($sourceTranslation->{$field} ?? '');
            if ($sourceValue === '') {
                continue;
            }

            $targetTranslation = $part->translate($targetLocale);
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
                $this->saveTranslation($part, $field, $targetLocale, $translated);
                $results['processed_fields'][] = "{$field}:{$targetLocale}";
            } catch (AiBudgetExceededException $e) {
                // Budget exhaustion is tenant-global — fail fast so the job
                // fails immediately instead of burning retries (audit §6).
                throw $e;
            } catch (AiOperationAlreadyCompletedException $e) {
                // A retry re-sent a field a prior attempt already translated under the
                // same deterministic id — reconcile as done, not failed.
                $this->reconcileAlreadyCompletedField($part, $field, $targetLocale, $results, $e);
            } catch (\Exception $e) {
                $results['errors'][] = "{$field}:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
                $this->logError($this->getServiceName().' - translateToLocale', $e);
            }
        }

        if (in_array("name:{$targetLocale}", $results['processed_fields'], true)) {
            try {
                $part->refresh();
                $translatedName = $part->translate($targetLocale)->name ?? '';
                if ($translatedName !== '') {
                    $slug = $this->generateUniqueSlug($translatedName, $part->id, $targetLocale); // @phpstan-ignore property.notFound
                    $this->saveTranslation($part, 'slug', $targetLocale, $slug);
                    $results['processed_fields'][] = "slug:{$targetLocale}";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "slug:{$targetLocale} - ".$this->sanitizeErrorMessage($e);
            }
        }

        $part->refresh();

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

    /**
     * WebinarPart doesn't have SEO fields.
     */
    protected function getSeoFields(): array
    {
        return [];
    }

    protected function getModelClass(): string
    {
        // ModelRegistry::getModelClassOrFail() is typed `string`, not
        // `class-string<Model>` (§3 seam lives in ai-core, out of scope here).
        return app(ModelRegistry::class)->getModelClassOrFail('webinar_part');
    }

    protected function getTranslationClass(): string
    {
        return app(ModelRegistry::class)->getModelClassOrFail('webinar_part_translation');
    }

    protected function getServiceName(): string
    {
        return 'WebinarPartContentRewrite';
    }

    protected function getContentTypeLabel(): string
    {
        return 'webinar parts (sections within a webinar)';
    }

    /**
     * The `content_type` discriminator phasea's `rewrite.field_rewrite` and
     * `rewrite.field_generate` templates select this subclass's field
     * descriptions with (v2.6 design §2.5). It replaces the already-resolved
     * `field_description` string plan 48-05 sent as a temporary shape.
     */
    protected function getContentTypeKey(): string
    {
        return 'webinar_part';
    }

    protected function getPromptOverrideKey(): string
    {
        return 'webinar_part_rewrite_system';
    }

    /**
     * WebinarPart doesn't have SEO fields, but we need to return something.
     */
    protected function getSeoPromptOverrideKey(): string
    {
        return 'webinar_part_seo_system';
    }

    /**
     * @param  Model&Translatable  $model
     */
    protected function buildModelContext(Model $model): array
    {
        $model->load(['webinar', 'elements']);

        $context = [
            'webinar_part_id' => $model->id, // @phpstan-ignore property.notFound
            'active' => $model->active, // @phpstan-ignore property.notFound
            'webinar_name' => $model->webinar->name ?? null,
            'elements_count' => $model->elements()->count(), // @phpstan-ignore method.notFound
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
            'excerpt' => 'a concise webinar part excerpt (2-3 sentences describing what this section covers)',
            'description' => 'a detailed description of this webinar section (well-structured with learning objectives and key topics)',
            default => "content for the {$field} field",
        };
    }

    /**
     * WebinarPart doesn't have SEO fields, so this returns minimal context.
     *
     * @param  Model&Translatable  $model
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildSeoContext(Model $model, string $locale, array $context): array
    {
        return [];
    }

    /*
     * v2.6 design §2.5: the `buildSeoFieldPrompt()` override was deleted here.
     * It was ORPHANED — plan 48-06 removed the abstract declaration, leaving
     * this `return ''` body with no declaration to satisfy and no caller. It
     * held no instruction text, so there is no phasea variant to register for
     * this content type and none was invented.
     *
     * This class deliberately has NO `getSeoContentTypeKey()`: `getSeoFields()`
     * returns `[]`, so `generateSeoField()` is never reached and there is no
     * discriminator to send. The base implementation throws, which is asserted
     * by `SeoPromptOwnershipTest::test_an_unmigrated_subclass_has_no_seo_content_type_key`.
     */

    /**
     * Handle name/slug generation for a webinar part.
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
        $translationClass = app(ModelRegistry::class)->getModelClassOrFail('webinar_part_translation');

        return $translationClass::where('slug', $slug)
            ->where('locale', $locale)
            ->where('webinar_part_id', '!=', $excludeId)
            ->exists();
    }

    /**
     * WebinarPart doesn't support content archetypes.
     */
    protected function resolveArchetype(Model $model): ?Model
    {
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  Model&Translatable  $webinarPart
     * @param  array<string, mixed>  $context
     */
    private function generateOrTranslateName(Model $webinarPart, string $locale, array $context): string
    {
        $sourceLocale = $this->findSourceLocale($webinarPart, 'name');

        if ($sourceLocale && $sourceLocale !== $locale) {
            $sourceTranslation = $webinarPart->translate($sourceLocale);
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
        $parts[] = 'Webinar Part';

        if (!empty($context['webinar_name'])) {
            $parts[] = $context['webinar_name'];
        }

        $parts[] = '#'.$context['webinar_part_id'];

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
