<?php

namespace AlexanderGabriel\FilamentOauth2;

use AlexanderGabriel\FilamentOauth2\Facades\FilamentOauth2;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentOauth2Plugin implements Plugin
{
    public function getId(): string
    {
        return 'filament-oauth2';
    }

    public function register(Panel $panel): void
    {
        $panel->login(FilamentOauth2::getLoginRouteAction());
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
