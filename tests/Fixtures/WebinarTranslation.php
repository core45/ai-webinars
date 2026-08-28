<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The host's own `webinar_translations` row. The package owns no schema, so the
 * test app declares it exactly as a consuming application would.
 */
class WebinarTranslation extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
