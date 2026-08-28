<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarPart extends Model
{
    protected $table = 'webinar_parts';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<WebinarElement, $this> */
    public function elements(): HasMany
    {
        return $this->hasMany(WebinarElement::class);
    }
}
