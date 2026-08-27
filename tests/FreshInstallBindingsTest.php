<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests;

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
     * Map every `app(Foo::class)` / `resolve(Foo::class)` in the tree to the
     * FQCN its file imported, so the assertions above run against real class
     * names rather than short ones.
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

            // Short name => FQCN, from this file's own `use` statements.
            preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $imports);
            $aliases = [];
            foreach ($imports[1] as $fqcn) {
                $aliases[substr((string) strrchr('\\'.$fqcn, '\\'), 1)] = $fqcn;
            }

            $lines = explode("\n", $source);
            foreach ($lines as $index => $line) {
                if (preg_match_all('/\b(?:app|resolve)\(\s*([A-Za-z0-9_\\\\]+)::class/', $line, $matches) === 0) {
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
}
