<?php

namespace AlexanderGabriel\FilamentOauth2;

use AlexanderGabriel\FilamentOauth2\Http\Controllers\Oauth2Controller;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

class FilamentOauth2Plugin implements Plugin
{
    /**
     * Settings of this panel overriding the config file.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    public function getId(): string
    {
        return 'filament-oauth2';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->login([Oauth2Controller::class, 'redirectToOauth2Server'])
            // Registered for each panel, so the routes get its path, middleware and name prefix
            ->routes(fn () => Route::name('filament-oauth2.')
                ->prefix('filament-oauth2')
                ->group(function () {
                    Route::post('handleLogout', [Oauth2Controller::class, 'handleLogout'])
                        ->name('handleLogout');
                    Route::get('redirectToOauth2Server', [Oauth2Controller::class, 'redirectToOauth2Server'])
                        ->name('redirectToOauth2Server');
                    Route::get('handleCallback', [Oauth2Controller::class, 'handleCallback'])
                        ->middleware('throttle:60,1')
                        ->name('handleCallback');
                }))
            ->userMenuItems([
                // Keeps the POST of Filaments logout action, only the url is replaced
                'logout' => fn (Action $action) => $action->url($panel->route('filament-oauth2.handleLogout')),
            ]);
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

    /**
     * The setting of this panel, the one of the config file otherwise.
     */
    public function getConfig(string $key): mixed
    {
        return $this->config[$key] ?? config("filament-oauth2.{$key}");
    }

    public function clientId(string $clientId): static
    {
        $this->config['clientId'] = $clientId;

        return $this;
    }

    public function clientSecret(string $clientSecret): static
    {
        $this->config['clientSecret'] = $clientSecret;

        return $this;
    }

    public function urlAuthorize(string $url): static
    {
        $this->config['urlAuthorize'] = $url;

        return $this;
    }

    public function urlAccessToken(string $url): static
    {
        $this->config['urlAccessToken'] = $url;

        return $this;
    }

    public function urlResourceOwnerDetails(string $url): static
    {
        $this->config['urlResourceOwnerDetails'] = $url;

        return $this;
    }

    public function urlLogout(string $url): static
    {
        $this->config['urlLogout'] = $url;

        return $this;
    }

    public function urlAfterLogout(string $url): static
    {
        $this->config['urlAfterlogout'] = $url;

        return $this;
    }

    public function scopes(string $scopes): static
    {
        $this->config['scopes'] = $scopes;

        return $this;
    }

    public function updateRoles(bool $updateRoles = true): static
    {
        $this->config['updateRoles'] = $updateRoles;

        return $this;
    }

    /**
     * @param  array<string>  $roles
     */
    public function allowedRoles(array $roles): static
    {
        $this->config['allowedRoles'] = $roles;

        return $this;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function roleModel(string $model): static
    {
        $this->config['roleModel'] = $model;

        return $this;
    }
}
