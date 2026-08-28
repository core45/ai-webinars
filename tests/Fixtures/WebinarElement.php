<?php

declare(strict_types=1);

namespace Core45\AiWebinars\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class WebinarElement extends Model
{
    protected $table = 'webinar_elements';

    public $timestamps = false;

    protected $guarded = [];
}
