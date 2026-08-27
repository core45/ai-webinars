<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests;

use Core45\AiCore\Support\PromptKeyRegistry;
use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `composer require core45/ai-webinars` gives you, before the host wires
 * anything.
 *
 * This package declares no contracts and no bindings of its own — its provider
 * only registers prompt keys. Everything it resolves comes from `ai-core`, so
 * on paper it inherits ai-core's defaults for free. "On paper" is the problem:
 * that is an argument, and the fifteen-unbound-contract bug this suite exists
 * to catch was invisible for exactly as long as nobody asked the container.
 *
 * So this asks. It does not carry a hand-written list of contracts, because a
 * hand-written list goes stale the moment someone adds an `app()` call. It
 * SCANS this package's own `src/` for container resolutions and requires every
 * one of them to be satisfiable — a gate that grows itself.
 *
 * Unlike ai-products there is no exception list here: this package never
 * resolves `GeneratesContentMedia`, so every resolution it makes must be
 * satisfiable outright. If that ever stops being true the scan will say so.
 */
class FreshInstallBindingsTest extends TestCase
{
    #[Test]
    public function every_container_resolution_in_this_package_is_satisfiable(): void
    {
        $resolutions = $this->scanForContainerResolutions(__DIR__.'/../src');

        $this->assertNotSame(
            [],
            $resolutions,
            'The scanner found no container resolutions at all. That is a broken scanner, '
            .'not a clean package — this gate would then pass while measuring nothing.',
        );

        // Pins the `->make()` form specifically. The first version of this
        // scanner matched only `app()` and `resolve()`, so this package's
        // `$this->app->make(PromptKeyRegistry::class)` was silently dropped and
        // the gate reported success over a partial scan. `assertNotSame([])`
        // above cannot catch that — it only sees a scan that found nothing.
        $this->assertArrayHasKey(
            PromptKeyRegistry::class,
            $resolutions,
            'The scanner no longer sees `$this->app->make()`, so its coverage is partial again.',
        );

        foreach ($resolutions as $class => $site) {
            try {
                $this->assertNotNull(app($class), $class);
            } catch (BindingResolutionException $e) {
                $this->fail(
                    "A fresh install cannot resolve {$class}, resolved at {$site}. "
                    .'Bind a default in a package provider. Container said: '.$e->getMessage(),
                );
            }
        }
    }

    /**
     * Map every container resolution in the tree to the FQCN its file
     * imported, so the assertions above run against real class names rather
     * than short ones.
     *
     * Three forms are matched, and the third was missing at first: `app()`,
     * `resolve()`, and `->make()`. Both of these packages resolve
     * `PromptKeyRegistry` through `$this->app->make()` in their service
     * providers, so the original two-form scanner silently dropped a real
     * resolution — and a scanner that silently drops resolutions is the exact
     * failure the `assertNotSame([], ...)` canary above cannot catch, because
     * that canary only sees a scan that found NOTHING, never one that found
     * some. Aliased (`use X as Y;`) and grouped (`use A\{B, C};`) imports are
     * resolved for the same reason.
     *
     * @return array<class-string, string> FQCN => the file:line it was found at
     */
    private function scanForContainerResolutions(string $dir): array
    {
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());

            $aliases = $this->importedClassNames($source);

            $lines = explode("\n", $source);
            foreach ($lines as $index => $line) {
                $pattern = '/(?:\b(?:app|resolve)\(|->make\()\s*([A-Za-z0-9_\\\\]+)::class/';

                if (preg_match_all($pattern, $line, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $shortName) {
                    $fqcn = $aliases[$shortName] ?? $shortName;
                    if (interface_exists($fqcn) || class_exists($fqcn)) {
                        $found[$fqcn] = basename($file->getPathname()).':'.($index + 1);
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Short name => FQCN for one file's `use` statements, covering plain,
     * aliased and grouped imports.
     *
     * @return array<string, string>
     */
    private function importedClassNames(string $source): array
    {
        $aliases = [];

        // use A\B\C;  and  use A\B\C as D;
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/mi', $source, $plain, PREG_SET_ORDER);
        foreach ($plain as $match) {
            $fqcn = $match[1];
            $short = $match[2] ?? substr((string) strrchr('\\'.$fqcn, '\\'), 1);
            $aliases[$short] = $fqcn;
        }

        // use A\B\{C, D as E};
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+\\\\)\{([^}]+)\}\s*;/mi', $source, $grouped, PREG_SET_ORDER);
        foreach ($grouped as $group) {
            foreach (explode(',', $group[2]) as $member) {
                if (preg_match('/^\s*([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*$/i', $member, $parts) !== 1) {
                    continue;
                }

                $fqcn = $group[1].$parts[1];
                $short = $parts[2] ?? substr((string) strrchr('\\'.$fqcn, '\\'), 1);
                $aliases[$short] = $fqcn;
            }
        }

        return $aliases;
    }
}
