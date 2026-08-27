<?php

declare(strict_types=1);

namespace Core45\AiWebinars;

use Core45\AiCore\Support\PromptKeyRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ai-webinars package provider (D11): the webinar-domain AI services and
 * translation job. Ships services + jobs only (D7) — the host keeps all
 * Filament/Livewire UI for the first release, resolves the services out of the
 * container by class name, and owns every webinar model these services operate
 * on (see README, "Host requirements").
 *
 * This package owns no schema, no config, no routes and no views. What it does
 * own is its six prompt keys, which is why this provider is no longer empty:
 * `ai-core` used to enumerate them, and that made the package everything else
 * depends on aware of the webinar taxonomy (plan §9 D14).
 */
class AiWebinarsServiceProvider extends ServiceProvider
{
    /**
     * The prompt keys this package's rewrite services ask for, from the
     * `getPromptOverrideKey()` / `getSeoPromptOverrideKey()` implementations in
     * `WebinarContentRewriteService`, `WebinarPartContentRewriteService` and
     * `WebinarPartElementContentRewriteService`.
     *
     * Three levels because the webinar domain is three levels deep, and each
     * level is rewritten independently — a part element is not reachable
     * through its webinar's prompt.
     */
    public function boot(): void
    {
        $registry = $this->app->make(PromptKeyRegistry::class);

        $registry->registerMany([
            'webinar_rewrite_system' => [
                'label' => 'Webinar Content Rewrite System Prompt',
                'template' => 'rewrite.field_rewrite',
            ],
            'webinar_part_rewrite_system' => [
                'label' => 'Webinar Part Content Rewrite System Prompt',
                'template' => 'rewrite.field_rewrite',
            ],
            'webinar_part_element_rewrite_system' => [
                'label' => 'Webinar Part Element Content Rewrite System Prompt',
                'template' => 'rewrite.field_rewrite',
            ],
            'webinar_seo_system' => [
                'label' => 'Webinar SEO Generation System Prompt',
                'template' => 'rewrite.seo_field',
            ],
            'webinar_part_seo_system' => [
                'label' => 'Webinar Part SEO Generation System Prompt',
                'template' => 'rewrite.seo_field',
            ],
            'webinar_part_element_seo_system' => [
                'label' => 'Webinar Part Element SEO Generation System Prompt',
                'template' => 'rewrite.seo_field',
            ],
        ]);
    }
}
