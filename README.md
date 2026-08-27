# core45/ai-webinars

Webinar-domain AI content: `WebinarContentRewriteService`,
`WebinarPartContentRewriteService`, `WebinarPartElementContentRewriteService`,
and the `TranslateWebinarToLocale` job.

Developed in the `core45/ai-packages` monorepo; this repository is a read-only
split of it. The monorepo README describes how the packages relate to each
other.

## Host coupling

The drop-in refactor put every host model behind `Core45\AiCore\ModelRegistry`
and the seam contracts in `ai-core`'s `Contracts/` — this package imports zero
`App\*` classes. `tests/PackageWiringTest.php` asserts that count is exactly
zero (`ALLOWED_HOST_IMPORTS = []`); it may only stay at zero or shrink further,
never widen without a documented reason recorded alongside the change.

That does not make the package pluggable with no host work: it still expects
seven host models registered under specific `ModelRegistry` keys and three
seam contracts bound — see "Wiring it up in a fresh app" below.

## Requirements

PHP 8.4+, Laravel 13, `core45/ai-core: ^0.1`, `filament/filament` (admin
notifications raised by the translation job), `ramsey/uuid`.

## What this package provides

- Three rewrite services extending
  `Core45\AiCore\Services\AbstractContentRewriteService`, one per level of the
  webinar → part → part-element hierarchy.
- `Jobs\TranslateWebinarToLocale` — queued per-locale translation of a webinar
  and its nested parts and elements, with `TranslationLock` guarding concurrent
  runs and scoped operation IDs so each nested unit bills once.

Ships services and jobs only (D7): the host keeps the Filament UI and resolves
these classes from the container.

**No tables, no config, no routes, no views** — the provider has nothing to
register. The `ai_*` schema ships with `core45/ai-core`.

## Host requirements

The consuming app must define its own `Webinar`, `WebinarPart`,
`WebinarPartElement` and their three translation models (plus `User`) with the
translation relationships `AbstractContentRewriteService` expects
(`astrotomic/laravel-translatable`), register them with `ModelRegistry`, and
bind ai-core's seam contracts. Full detail below.

## Wiring it up in a fresh app

Three call sites: what the host must provide, the config, and how a caller
actually reaches the services and job. Every snippet here is copy-pasteable
into an app that has never seen phaseb — nothing below references a phaseb
class by anything other than name-as-example.

### (a) What the host must provide

**Models.** `Webinar`, `WebinarPart`, `WebinarPartElement` and their
translation models must implement
`Astrotomic\Translatable\Contracts\Translatable` — the three services declare
it via the `Model&Translatable` intersection type on their public entry
points (`WebinarContentRewriteService.php:33`,
`WebinarPartContentRewriteService.php:33`,
`WebinarPartElementContentRewriteService.php:33`), and their `translateToLocale()`
overloads guard the same contract at runtime
(`WebinarContentRewriteService.php:54`, `WebinarPartContentRewriteService.php:51`,
`WebinarPartElementContentRewriteService.php:51`).

`Webinar` must additionally answer `resolveContentArchetype(): ?Model` —
called **without** a `method_exists()` guard
(`WebinarContentRewriteService.php:353`), so a `Webinar` model missing that
method fatals the first time content is rewritten. `WebinarPart` and
`WebinarPartElement` don't support archetypes at all — both services hard-code
`resolveArchetype()` to return `null`
(`WebinarPartContentRewriteService.php:315`,
`WebinarPartElementContentRewriteService.php:315`), so nothing is required of
those two models on this front.

`Webinar` also needs a `parts` relation, and `WebinarPart` an `elements`
relation, both eager-loaded by the translation job
(`TranslateWebinarToLocale.php:124-125,141`: `$this->webinar->load('parts.elements')`
then `foreach ($this->webinar->parts as $part)` / `foreach ($part->elements as
$element)`).

**`config('ai.models')` keys**, resolved via
`app(ModelRegistry::class)->getModelClassOrFail($key)`
(`WebinarContentRewriteService.php:168,173`,
`WebinarPartContentRewriteService.php:154,159`,
`WebinarPartElementContentRewriteService.php:154,159`,
`TranslateWebinarToLocale.php:65,297`):

| Key | Expected class |
| --- | --- |
| `webinar` | Your `Webinar` model |
| `webinar_translation` | Your `WebinarTranslation` model |
| `webinar_part` | Your `WebinarPart` model |
| `webinar_part_translation` | Your `WebinarPartTranslation` model |
| `webinar_part_element` | Your `WebinarPartElement` model |
| `webinar_part_element_translation` | Your `WebinarPartElementTranslation` model |
| `translation_lock` | `Core45\AiCore\Models\TranslationLock` — ships with ai-core, just register it |
| `user` | Your `User` model |

**Seam contracts.** All three services inherit `processModel()` from
`AbstractContentRewriteService`, which calls `app(GeneratesSlugs::class)`,
`app(StoresFocusKeyphrases::class)` and `app(EnrichesCitations::class)`
**unconditionally** (`AbstractContentRewriteService.php:2024,530,450`); none of
the three has a default binding in `ai-core`'s service provider, so a fresh
app without these bound gets a "target is not instantiable" container
exception the first time it rewrites or translates a webinar, part or
element. `ChecksContentSimilarity` is only resolved if a caller opts in via
`withSimilarityChecking()` (`AbstractContentRewriteService.php:324`) — none of
the three webinar services call it themselves.

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarPart;
use App\Models\WebinarPartElement;
use App\Models\WebinarPartElementTranslation;
use App\Models\WebinarPartTranslation;
use App\Models\WebinarTranslation;
use App\Services\AI\CitationEnrichmentService; // your GEO/citation lookup, or a no-op stub
use App\Services\AI\FocusKeyphraseStore;        // writes to your SEO-indexable table
use App\Services\AI\SlugGenerator;              // wraps Illuminate\Support\Str::slug() + uniqueness
use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\Models\TranslationLock;
use Illuminate\Support\ServiceProvider;

class AiSeamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Slug generation for translated names (unconditional — see above).
        $this->app->bind(GeneratesSlugs::class, SlugGenerator::class);

        // Focus-keyphrase persistence for SEO-indexable models (unconditional).
        $this->app->bind(StoresFocusKeyphrases::class, FocusKeyphraseStore::class);

        // GEO citation enrichment; a no-op implementation (supports() => false)
        // is fine if you don't run GEO citation enrichment.
        $this->app->bind(EnrichesCitations::class, CitationEnrichmentService::class);
    }

    public function boot(): void
    {
        config([
            'ai.models' => array_merge(config('ai.models', []), [
                'webinar' => Webinar::class,
                'webinar_translation' => WebinarTranslation::class,
                'webinar_part' => WebinarPart::class,
                'webinar_part_translation' => WebinarPartTranslation::class,
                'webinar_part_element' => WebinarPartElement::class,
                'webinar_part_element_translation' => WebinarPartElementTranslation::class,
                'translation_lock' => TranslationLock::class,
                'user' => User::class,
            ]),
        ]);
    }
}
```

### (b) Config

The rewrite pipeline reads these keys (all under the `ai-core`-owned `ai`
namespace) with the shown fallbacks
(`WebinarContentRewriteService.php:270`,
`AbstractContentRewriteService.php:774,778,1258,1907,1953`):

```php
// config/ai.php
return [
    // ... 'models' from the service provider above are merged in too ...

    'assistant' => [
        'content' => [
            'brand_voice' => 'Acme Learning Co — clear, encouraging, expert-led.',
            'seo_context_limit' => 5000,
        ],
        'translation' => [
            'server_side_chunking' => false,
            'chunk_threshold' => 12000,
        ],
        'circuit_breaker' => [
            'max_ai_calls_per_job' => 150,
        ],
        'timeout' => 300,
    ],
];
```

`Phase 3c` of `processModel()` also reads `config('geo.citation.enabled')` and
`config('geo.citation.in_rewrite_pipeline')` to decide whether to call the
bound `EnrichesCitations` implementation (`AbstractContentRewriteService.php:450`).
No package publishes a `config/geo.php`, so on a fresh app both are `null`
(falsy) and citation enrichment is simply skipped — but `EnrichesCitations`
still has to be bound, because `app(EnrichesCitations::class)` runs before
that check.

### (c) Actually calling it

Resolving the service directly and rewriting one webinar (and, separately,
one of its parts):

```php
use App\Models\Webinar;
use Core45\AiWebinars\Services\WebinarContentRewriteService;
use Core45\AiWebinars\Services\WebinarPartContentRewriteService;

/** @var Webinar $webinar */
$webinar = Webinar::with('parts.elements')->findOrFail($id);

$result = app(WebinarContentRewriteService::class)->processWebinar($webinar);
// $result: ['model_id' => int, 'processed_fields' => string[], 'errors' => string[]]

foreach ($webinar->parts as $part) {
    app(WebinarPartContentRewriteService::class)->processWebinarPart($part);
}
```

Dispatching the queued translation job — it walks the whole `Webinar` →
`WebinarPart` → `WebinarPartElement` tree itself, so only the top-level model
needs to be passed in (`TranslateWebinarToLocale.php:39-48,124-155`):

```php
use App\Models\Webinar;
use Core45\AiWebinars\Jobs\TranslateWebinarToLocale;
use Illuminate\Support\Facades\Auth;

TranslateWebinarToLocale::dispatch(
    webinar: Webinar::findOrFail($id),
    sourceLocale: 'en',
    targetLocale: 'es',
    userId: Auth::id(),
);
```

The job runs on the `ai-bulk` queue, retries up to 10 times with a `[10, 30,
60]`-second backoff, and notifies the dispatching user (via the bound
`NotifiesOperator` — `ai-core` defaults this to a log-only implementation, see
the ai-core README) once the webinar and every nested part/element has been
translated (`TranslateWebinarToLocale.php:28-48,201-210`).

A Filament bulk action wiring the job the same way `ai-posts-filament` wires
its own rewrite action (`ai-posts-filament/src/Actions/AiRewriteContentAction.php`):

```php
use Core45\AiWebinars\Jobs\TranslateWebinarToLocale;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

BulkAction::make('translate_to_spanish')
    ->label(__('Translate to Spanish'))
    ->icon(Heroicon::OutlinedLanguage)
    ->requiresConfirmation()
    ->action(function (Collection $records): void {
        foreach ($records as $webinar) {
            TranslateWebinarToLocale::dispatch($webinar, 'en', 'es', Auth::id());
        }
    });
```

## Testing

```bash
vendor/bin/phpunit -c packages/ai-webinars/phpunit.xml.dist
```

Orchestra Testbench, ai-core + ai-webinars only.
