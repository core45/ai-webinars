<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests;

use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiWebinars\Services\WebinarContentRewriteService;
use Core45\AiWebinars\Tests\Fixtures\FakeAiClient;
use Core45\AiWebinars\Tests\Fixtures\Product;
use Core45\AiWebinars\Tests\Fixtures\Webinar;
use Core45\AiWebinars\Tests\Fixtures\WebinarTranslation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * THE END-TO-END PROOF, mirrored from ai-posts'
 * `HostAppRewritesAPostEndToEndTest`.
 *
 * A bare Laravel application — the Testbench harness boots `ai-core` and
 * `ai-webinars` and nothing else — resolves `WebinarContentRewriteService`
 * from the container and rewrites a real Webinar, persisting the result to a
 * real sqlite database through real Eloquent models and the packages' own
 * migrations.
 *
 * ONE thing is faked, and only one: the AI client, which would otherwise make
 * an HTTP call to the phasea service.
 *
 * WHAT A BARE HOST MUST SUPPLY: the `Webinar` fixture and its migrations. The
 * service (and the abstract base in ai-core) reaches for the following, all of
 * which the fixture provides:
 *
 *  1. `config('ai.models')` with `webinar` and `webinar_translation` keys, set
 *     in `getEnvironmentSetUp()`. `ModelRegistry` is `scoped()`, so it reads
 *     the map once per request — without these keys `getModelClassOrFail()`
 *     fatals with `No model registered for key "webinar"`.
 *  2. `config('translatable.locales')`. Astrotomic's own `Locales` throws
 *     `LocalesNotDefinedException` before `getLocales()`'s
 *     `config('translatable.locales', ['en'])` default can ever apply.
 *  3. A binding for the CONCRETE `RemoteAiClient`, not the `AiClient` contract —
 *     see `setUp()`. `AiClientFactory::for()` resolves the concrete class, so
 *     overriding the contract binding is inert on this path.
 *  4. A `product` relation returning a collection whose members expose `name`,
 *     eager-loaded by `buildModelContext()` and plucked into the AI context.
 *  5. `parts()` and `elements()` relations (returning Builders, so
 *     `->count()` works), also read by `buildModelContext()` and
 *     `buildSeoContext()`.
 *  6. An `active` attribute on the webinar, read by `buildModelContext()`.
 *  7. A `webinar_translations` row with `name` AND `slug` preset, so
 *     `handleNameSlug()`'s slug branch is skipped entirely and never makes an
 *     AI call for the name.
 *
 * Nothing else had to be bound. `GeneratesSlugs`, `StoresFocusKeyphrases`, the
 * similarity service and the citation enricher all resolve through ai-core
 * defaults or are gated off by config in this bare app.
 */
class HostAppRunsWebinarPipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $aiClient;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai.models', [
            'webinar' => Webinar::class,
            'webinar_translation' => WebinarTranslation::class,
        ]);

        // Astrotomic's own `Locales` throws `LocalesNotDefinedException` before
        // `getLocales()`'s `config('translatable.locales', ['en'])` default can
        // ever apply.
        $app['config']->set('translatable.locales', ['en']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('webinars', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(false);
            // Present for parity with a real host schema; this service sets
            // `$touchableModelClasses = []`, so no `touch()` runs.
            $table->timestamps();
        });

        Schema::create('webinar_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id');
            $table->string('locale')->index();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->unique(['webinar_id', 'locale']);
        });

        Schema::create('webinar_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id');
        });

        Schema::create('webinar_elements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id');
        });

        // `product` is a `BelongsToMany` on the webinar; the pivot is read by
        // `buildModelContext()` to pluck related product names into the context.
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('product_webinar', function (Blueprint $table): void {
            $table->foreignId('webinar_id');
            $table->foreignId('product_id');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiClient = new FakeAiClient;

        // NOT `AiClient::class`. `AiClientFactory::for()` resolves the CONCRETE
        // `RemoteAiClient` (AiClientFactory.php), so overriding the documented
        // contract binding is INERT on this path — swapping this line for
        // `bind(AiClient::class, …)` leaves the real client in place and fires
        // real HTTP requests at `ai.base_url`. The real factory therefore stays
        // in the loop; only the outermost network client is replaced.
        $this->app->bind(RemoteAiClient::class, fn (): FakeAiClient => $this->aiClient);
    }

    /**
     * SCOPE, stated so this is not read as more than it is: proved for SQLITE
     * ONLY. sqlite does not enforce a foreign key against a table that does not
     * exist at `CREATE TABLE` time, so the trap the README documents under
     * "Foreign-key assumptions" — package migrations creating keys on literal
     * `webinars`/`users`, ordered by basename across all paths — CANNOT fail in
     * this harness. A MySQL host whose `webinars` or `users` is created later
     * still hits it. Green here is not evidence against that.
     */
    #[Test]
    public function the_package_migrations_run_in_the_bare_app(): void
    {
        $this->assertTrue(Schema::hasTable('ai_prompt_overrides'), 'ai-core migrations did not run.');
    }

    #[Test]
    public function a_bare_app_rewrites_and_persists_a_webinar(): void
    {
        $productId = DB::table('products')->insertGetId(['name' => 'Warehouse systems']);
        $webinar = Webinar::create(['active' => true]);
        $webinar->product()->attach($productId);

        DB::table('webinar_translations')->insert([
            'webinar_id' => $webinar->id,
            'locale' => 'en',
            'name' => 'Original name',
            'slug' => 'original-name',
            'excerpt' => 'Original excerpt.',
            'description' => '<p>Original body.</p>',
        ]);

        $results = app(WebinarContentRewriteService::class)->processWebinar($webinar->fresh());

        $this->assertSame([], $results['errors'], 'Rewrite reported errors: '.json_encode($results['errors']));

        $row = DB::table('webinar_translations')
            ->where('webinar_id', $webinar->id)
            ->where('locale', 'en')
            ->first();

        $this->assertStringContainsString('AI EXCERPT', (string) $row->excerpt);
        $this->assertStringContainsString('AI DESCRIPTION', (string) $row->description);
        $this->assertSame('AI SEO TITLE', $row->seo_title);
        $this->assertSame('AI SEO DESCRIPTION', $row->seo_description);
        $this->assertSame('ai, seo, keywords', $row->seo_keywords);

        // `name` and `slug` were preset, so `handleNameSlug()` is a no-op and
        // makes no AI call. Five real AI calls remain: two content fields and
        // three SEO fields.
        $this->assertSame(
            [
                ['template' => 'rewrite.field_rewrite', 'field' => 'excerpt'],
                ['template' => 'rewrite.field_rewrite', 'field' => 'description'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_title'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_description'],
                ['template' => 'rewrite.seo_field', 'field' => 'seo_keywords'],
            ],
            $this->aiClient->calls,
        );

        // The host-model context really reached the wire — including the related
        // product name, which `buildModelContext()` plucks.
        $this->assertSame(
            ['Warehouse systems'],
            $this->aiClient->parameterSets[0]['context']['related_products'],
        );
    }
}
