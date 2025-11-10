<?php

// https://github.com/filamentphp/actions/blob/4.x/routes/web.php

use AlexanderGabriel\FilamentOauth2\Http\Controllers\Oauth2Controller;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

$panel = Filament::getCurrentPanel();

Route::prefix($panel->getPath())
    ->middleware($panel->getMiddleware())
    ->group(function () {
        Route::get('login', [Oauth2Controller::class, 'redirectToOauth2Server'])->name('redirectToOauth2Server');
        Route::name('filament-oauth2.')
            ->prefix('filament-oauth2')
            ->group(function () {
                Route::post('handleLogout', [Oauth2Controller::class, 'handleLogout'])->name('handleLogout');
                Route::get('redirectToOauth2Server', [Oauth2Controller::class, 'redirectToOauth2Server'])
                    ->name('redirectToOauth2Server');
                Route::get('handleCallback', [Oauth2Controller::class, 'handleCallback'])
                    ->name('handleCallback');
            });

});
