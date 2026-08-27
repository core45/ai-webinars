<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests;

use Core45\AiWebinars\AiWebinarsServiceProvider;
use Core45\AiWebinars\Jobs\TranslateWebinarToLocale;
use Core45\AiWebinars\Services\WebinarContentRewriteService;
use Core45\AiWebinars\Services\WebinarPartContentRewriteService;
use Core45\AiWebinars\Services\WebinarPartElementContentRewriteService;
use PHPUnit\Framework\Attributes\Test;

class PackageWiringTest extends TestCase
{
    /**
     * The host classes this package is ALLOWED to import — the documented
     * limitation from docs/AI_PACKAGES.md in executable form. May only SHRINK.
     *
     * @var list<string>
     */
    private const ALLOWED_HOST_IMPORTS = [
        'App\Models\ContentArchetype',
        'App\Models\TranslationLock',
        'App\Models\User',
        'App\Models\Webinar',
        'App\Models\WebinarPart',
        'App\Models\WebinarPartElement',
        'App\Models\WebinarPartElementTranslation',
        'App\Models\WebinarPartTranslation',
        'App\Models\WebinarTranslation',
    ];

    #[Test]
    public function the_package_boots_standalone(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(AiWebinarsServiceProvider::class));
    }

    #[Test]
    public function every_shipped_class_exists(): void
    {
        foreach ([
            WebinarContentRewriteService::class,
            WebinarPartContentRewriteService::class,
            WebinarPartElementContentRewriteService::class,
            TranslateWebinarToLocale::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "{$class} is missing.");
        }
    }

    #[Test]
    public function host_coupling_does_not_widen_beyond_the_documented_set(): void
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/^use\s+(App\\\\[^\s;]+);/m', (string) file_get_contents($file->getPathname()), $matches);
            $found = array_merge($found, $matches[1]);
        }

        $found = array_values(array_unique($found));
        sort($found);

        $this->assertSame(
            self::ALLOWED_HOST_IMPORTS,
            $found,
            'ai-webinars host coupling changed. Shrinking it is the goal; widening it needs a documented reason in docs/AI_PACKAGES.md.'
        );
    }
}
