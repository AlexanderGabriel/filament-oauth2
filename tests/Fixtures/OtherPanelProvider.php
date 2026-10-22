<?php

namespace AlexanderGabriel\FilamentOauth2\Tests\Fixtures;

use AlexanderGabriel\FilamentOauth2\FilamentOauth2Plugin;
use Filament\Panel;

class OtherPanelProvider extends AdminPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel($panel->id('other')->path('other'))
            ->plugins([
                FilamentOauth2Plugin::make()
                    ->clientId('other-client')
                    ->urlAuthorize('https://other-idp.test/auth'),
            ]);
    }
}
