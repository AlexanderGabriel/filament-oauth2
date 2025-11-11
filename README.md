# This is my package filament-oauth2

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alexandergabriel/filament-oauth2.svg?style=flat-square)](https://packagist.org/packages/alexandergabriel/filament-oauth2)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/alexandergabriel/filament-oauth2/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/alexandergabriel/filament-oauth2/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/alexandergabriel/filament-oauth2/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/alexandergabriel/filament-oauth2/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/alexandergabriel/filament-oauth2.svg?style=flat-square)](https://packagist.org/packages/alexandergabriel/filament-oauth2)

> !!!  
> This Plugin is still under development and only tested with Keycloak.  
> This is my first FilamentPHP-Plugin.  
> Did not write any tests, not published to packagist yet...  
> Feedback welcome.  
> !!!  

To be able to install you have to add/change this to/in your composer.json:
```json
{
    "minimum-stability": "dev",
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/AlexanderGabriel/filament-oauth2"
        }
    ]
}
```

This Plugin enables OAuth2-Login for [FilamentPHP](https://filamentphp.com) Panels.  
Login and logout is done by OAuth2-Server.  
If the OAuth2-Server provides roles for your client, they will be mapped to the App\Models\Role-Model  
Non-existing Roles will be created.
Users will be detached to roles not in the access token any more.

## Installation

You can install the package via composer:

```bash
composer require alexandergabriel/filament-oauth2
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="filament-oauth2-config"
```
This is the contents of the published config file:

```php
return [
    'clientId' => env("OAUTH2_CLIENT_ID"),
    'clientSecret' => env("OAUTH2_CLIENT_SECRET"),
    'baseUrl' => env("OAUTH2_BASE_URL"), // https://DOMAIN/realms/REALM/protocol/openid-connect
    'urlAuthorize' => env("OAUTH2_URL_AUTHORIZE", env("OAUTH2_BASE_URL")."/auth"),
    'urlAccessToken' => env("OAUTH2_URL_ACCESS_TOKEN", env("OAUTH2_BASE_URL")."/token"),
    'urlResourceOwnerDetails' => env("OAUTH2_URL_RESOURCE_OWNER_DETAILS", env("OAUTH2_BASE_URL")."/userinfo"),
    'urlLogout' => env("OAUTH2_URL_LOGOUT", env("OAUTH2_BASE_URL")."/logout"),
    'urlAfterlogout' => env("OAUTH2_URL_AFTER_LOGOUT", url('/')),
    'scopes' => env("OAUTH2_SCOPES", "profile email openid"),
    'updateRoles' => env("OAUTH2_UPDATE_ROLES", false)
];
```

## Usage

Load Plugin in your PanelProvider under filament-oauth2-demo/app/Providers/Filament:
```php
class YOURPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->plugin(
                new FilamentOauth2Plugin()
            )
```


### To configure, add some config to your .env:

- OAUTH2_CLIENT_ID*
    - OAuth2 client id, mandatory
- OAUTH2_CLIENT_SECRET*
    - OAuth2 client secret, mandatory
- OAUTH2_BASE_URL*
    - Base url to OAuth2 authentication server
    - must include realm: https://DOMAIN/realms/REALM/protocol/openid-connect
- OAUTH2_URL_AUTHORIZE
    - authorization url
    - defaults to OAUTH2_BASE_URL+/auth
- OAUTH2_URL_ACCESS_TOKEN
    - token url
    - defaults to OAUTH2_BASE_URL+/token
- OAUTH2_URL_RESOURCE_OWNER_DETAILS
    - resource owner details url
    - defaults to OAUTH2_BASE_URL+/userinfo
    - todo: needed?
- OAUTH2_URL_LOGOUT
    - logout url
    - defaults to OAUTH2_BASE_URL+/logout
- OAUTH2_URL_AFTER_LOGOUT
    - post_logout_redirect_uri
    - defaults to base url of Laravel app (without panel)
- OAUTH2_SCOPES
    - scopes
    - defaults to "profile email openid"
- OAUTH2_UPDATE_ROLES
    - look for roles in token and update/create and map them
    - defaults to false

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- To all helping developing and keeping alive FilamentPHP, PHP, OAuth2 and the OpenSource Ecosystem!
- [Alexander Gabriel](https://github.com/AlexanderGabriel)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
