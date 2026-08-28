<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests\Fixtures;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The minimum host `Webinar` `WebinarContentRewriteService` can run against.
 *
 * Everything on it is here because the service (or the abstract base in
 * ai-core) actually reaches for it: `buildModelContext()` eager-loads
 * `product`, `parts` and `parts.elements`, reads `active`, counts `parts()`
 * and `elements()`, and plucks `product.*.name` into the AI context.
 */
class Webinar extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'webinars';

    protected $translationModel = WebinarTranslation::class;

    protected $translationForeignKey = 'webinar_id';

    /** @var array<int, string> */
    public array $translatedAttributes = [
        'name',
        'slug',
        'excerpt',
        'description',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<WebinarPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(WebinarPart::class);
    }

    /** @return HasMany<WebinarElement, $this> */
    public function elements(): HasMany
    {
        return $this->hasMany(WebinarElement::class);
    }

    /** @return BelongsToMany<Product, $this> */
    public function product(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_webinar');
    }
}
