<?php

namespace AlexanderGabriel\FilamentOauth2\Tests;

use AlexanderGabriel\FilamentOauth2\FilamentOauth2ServiceProvider;
use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\AdminPanelProvider;
use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\OtherPanelProvider;
use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\Role;
use AlexanderGabriel\FilamentOauth2\Tests\Fixtures\User;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id');
            $table->foreignId('user_id');
        });
    }

    protected function getPackageProviders($app)
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            AdminPanelProvider::class,
            OtherPanelProvider::class,
            FilamentOauth2ServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        config()->set('database.default', 'testing');
        config()->set('auth.providers.users.model', User::class);
        config()->set('filament-oauth2.roleModel', Role::class);

        config()->set('filament-oauth2.clientId', 'filament');
        config()->set('filament-oauth2.clientSecret', 'secret');
        config()->set('filament-oauth2.urlAuthorize', 'https://idp.test/auth');
        config()->set('filament-oauth2.urlAccessToken', 'https://idp.test/token');
        config()->set('filament-oauth2.urlResourceOwnerDetails', 'https://idp.test/userinfo');
        config()->set('filament-oauth2.urlLogout', 'https://idp.test/logout');
        config()->set('filament-oauth2.urlAfterlogout', 'https://app.test/bye');
        config()->set('filament-oauth2.scopes', 'openid');
    }
}
