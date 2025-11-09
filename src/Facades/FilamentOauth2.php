<?php

namespace AlexanderGabriel\FilamentOauth2\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AlexanderGabriel\FilamentOauth2\FilamentOauth2
 */
class FilamentOauth2 extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \AlexanderGabriel\FilamentOauth2\FilamentOauth2::class;
    }
}
