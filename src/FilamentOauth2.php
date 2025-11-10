<?php

namespace AlexanderGabriel\FilamentOauth2;

use AlexanderGabriel\FilamentOauth2\Http\Controllers\Oauth2Controller;

class FilamentOauth2 {

    public static function getLoginRouteAction(): array
    {
        return [Oauth2Controller::class, 'redirectToOauth2Server'];
    }
}
