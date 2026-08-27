# core45/ai-webinars

Webinar-domain AI content: `WebinarContentRewriteService`,
`WebinarPartContentRewriteService`, `WebinarPartElementContentRewriteService`,
and the `TranslateWebinarToLocale` job.

Part of the phaseb AI extraction. As-built reference: `docs/AI_PACKAGES.md`.

## ⚠️ Not reusable outside phaseb yet

This package imports 9 host classes: `App\Models\Webinar`, `WebinarPart`,
`WebinarPartElement`, their three translation models, `ContentArchetype`,
`TranslationLock`, `User`. It installs in another Laravel app and then fatals as
soon as it touches one of them. Organised as a package, not yet portable — see
`docs/AI_PACKAGES.md`, "Known limitation".

`tests/PackageWiringTest.php` pins that list exactly. The list may only shrink.

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

The consuming app must define the nine models listed above with the translation
relationships `AbstractContentRewriteService` expects
(`astrotomic/laravel-translatable`), and must bind ai-core's seams (see the
ai-core README).

## Testing

```bash
vendor/bin/phpunit -c packages/ai-webinars/phpunit.xml.dist
```

Orchestra Testbench, ai-core + ai-webinars only.
