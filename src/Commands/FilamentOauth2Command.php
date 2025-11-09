<?php

namespace AlexanderGabriel\FilamentOauth2\Commands;

use Illuminate\Console\Command;

class FilamentOauth2Command extends Command
{
    public $signature = 'filament-oauth2';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
