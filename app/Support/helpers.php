<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;

if (! function_exists('settings')) {
    /**
     * Access application settings.
     *
     * Resolved from the container as a singleton so the per-request snapshot
     * is shared: `settings()->getString('site_name')` in a layout and again in
     * a component costs one lookup, not two.
     */
    function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }
}
