<?php

namespace AlexanderGabriel\FilamentOauth2;

use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentOauth2ServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-oauth2';

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         *
         * The routes are registered for each panel by FilamentOauth2Plugin.
         */
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('alexandergabriel/filament-oauth2');
            });
    }
}
