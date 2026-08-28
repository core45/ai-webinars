<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A related product. `buildModelContext()` plucks `name` off each related
 * product into the AI context, but does NOT require it to be translatable.
 */
class Product extends Model
{
    protected $table = 'products';

    public $timestamps = false;

    protected $guarded = [];
}
