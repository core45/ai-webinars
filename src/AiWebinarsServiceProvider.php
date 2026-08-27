<?php

declare(strict_types=1);

namespace Core45\AiWebinars;

use Illuminate\Support\ServiceProvider;

/**
 * ai-webinars package provider (D11): the webinar-domain AI services and
 * translation job. Ships services + jobs only (D7) — the host keeps all
 * Filament/Livewire UI for the first release, resolves the services out of
 * the container by class name, and owns every webinar model these services
 * operate on (see README, "Host requirements").
 *
 * This package owns no schema, no config, no routes and no views, so there is
 * deliberately nothing to register or boot.
 */
class AiWebinarsServiceProvider extends ServiceProvider
{
    //
}
