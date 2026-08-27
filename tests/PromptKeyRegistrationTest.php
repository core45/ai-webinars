<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests;

use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Support\PromptKeyRegistry;
use PHPUnit\Framework\Attributes\Test;

/**
 * This package registers its own prompt keys (plan §9 D14).
 *
 * Six of them: the webinar domain is three levels deep — webinar, part, part
 * element — and each level is rewritten independently, with a content key and
 * an SEO key apiece. All six used to be enumerated in `ai-core`.
 */
class PromptKeyRegistrationTest extends TestCase
{
    #[Test]
    public function all_three_webinar_levels_register_a_content_key(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertSame('Webinar Content Rewrite System Prompt', $offered['webinar_rewrite_system'] ?? null);
        $this->assertSame('Webinar Part Content Rewrite System Prompt', $offered['webinar_part_rewrite_system'] ?? null);
        $this->assertSame('Webinar Part Element Content Rewrite System Prompt', $offered['webinar_part_element_rewrite_system'] ?? null);
    }

    #[Test]
    public function all_three_webinar_levels_register_an_seo_key(): void
    {
        $offered = AIPromptOverride::availablePromptKeys();

        $this->assertSame('Webinar SEO Generation System Prompt', $offered['webinar_seo_system'] ?? null);
        $this->assertSame('Webinar Part SEO Generation System Prompt', $offered['webinar_part_seo_system'] ?? null);
        $this->assertSame('Webinar Part Element SEO Generation System Prompt', $offered['webinar_part_element_seo_system'] ?? null);
    }

    #[Test]
    public function the_core_keys_survive_alongside_them(): void
    {
        $this->assertArrayHasKey('admin_system', AIPromptOverride::availablePromptKeys());
    }

    #[Test]
    public function no_other_domains_keys_appear(): void
    {
        $offered = array_keys(AIPromptOverride::availablePromptKeys());

        $this->assertNotContains('post_rewrite_system', $offered);
        $this->assertNotContains('product_rewrite_system', $offered);
    }

    #[Test]
    public function each_key_carries_the_template_its_overrides_ride(): void
    {
        $registry = app(PromptKeyRegistry::class);

        foreach (['webinar_rewrite_system', 'webinar_part_rewrite_system', 'webinar_part_element_rewrite_system'] as $key) {
            $this->assertSame('rewrite.field_rewrite', $registry->templateFor($key), $key);
        }

        foreach (['webinar_seo_system', 'webinar_part_seo_system', 'webinar_part_element_seo_system'] as $key) {
            $this->assertSame('rewrite.seo_field', $registry->templateFor($key), $key);
        }
    }
}
