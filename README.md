# Filament OAuth2

[![Latest Version on Packagist](https://img.shields.io/packagist/v/alexandergabriel/filament-oauth2.svg?style=flat-square)](https://packagist.org/packages/alexandergabriel/filament-oauth2)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/alexandergabriel/filament-oauth2/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/alexandergabriel/filament-oauth2/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/alexandergabriel/filament-oauth2/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/alexandergabriel/filament-oauth2/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/alexandergabriel/filament-oauth2.svg?style=flat-square)](https://packagist.org/packages/alexandergabriel/filament-oauth2)

> !!!  
> This Plugin is still under development and only tested with Keycloak.  
> This is my first FilamentPHP-Plugin.  
> Feedback welcome.  
> !!!  

This Plugin enables OAuth2-Login for [FilamentPHP](https://filamentphp.com) Panels (Filament 4 and 5).  
Login and logout is done by the OAuth2-Server.  
Users are created on their first login and linked to the OAuth2-Server by their email address.  
If the OAuth2-Server provides roles for your client, they are synced to the roles of the user:
non-existing roles are created, roles not in the access token any more are removed from the user.

### Filament Shield

If [Filament Shield](https://github.com/bezhanSalleh/filament-shield) is installed and your user model uses
Shields `HasRoles`-trait, the roles from the access token are synced to the Shield/spatie roles instead of the
role model. Roles are created with the auth guard of the panel, so they show up in Shields
role resource and can be given permissions there.

## Installation

You can install the package via composer:

```bash
composer require alexandergabriel/filament-oauth2
```

You can publish the [config file](config/filament-oauth2.php) with:

```bash
php artisan vendor:publish --tag="filament-oauth2-config"
```

## Usage

Add the plugin to your PanelProvider:

```php
use AlexanderGabriel\FilamentOauth2\FilamentOauth2Plugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ...
            ->plugin(FilamentOauth2Plugin::make());
    }
}
```

The plugin replaces the login page and the logout of the panel. It uses the user model of the auth guard of
the panel. The OAuth2-Server has to redirect to `https://YOUR-APP/PANEL-PATH/filament-oauth2/handleCallback`,
add it to the *Valid Redirect URIs* of your client.

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
    - resource owner details (userinfo) url, provides email and name of the user
    - defaults to OAUTH2_BASE_URL+/userinfo
- OAUTH2_URL_LOGOUT
    - logout url
    - defaults to OAUTH2_BASE_URL+/logout
- OAUTH2_URL_AFTER_LOGOUT
    - post_logout_redirect_uri, relative urls are made absolute
    - must be allowed for your client in the OAuth2-Server (Keycloak: *Valid post logout redirect URIs*)
    - not set by default: the OAuth2-Server shows its own logout page
- OAUTH2_SCOPES
    - scopes
    - defaults to "profile email openid"
- OAUTH2_UPDATE_ROLES
    - look for roles in token and update/create and map them
    - uses Filament Shield if it is installed, the role model otherwise
    - defaults to false
- OAUTH2_ALLOWED_ROLES
    - comma separated list of roles taken from the access token, e.g. `editor,viewer`
    - all other roles in the token are ignored (neither created nor assigned)
    - defaults to all roles
- OAUTH2_ROLE_MODEL
    - role model used if Filament Shield is not installed
    - the user model needs a `roles()`-relation to it, roles are found by their `name`
    - defaults to `App\Models\Role`
- OAUTH2_VERIFY_SSL
    - disable or enable ssl-verification of keycloak-server (for test/dev-env)
    - `false` is refused in production (`APP_ENV=production`)
    - defaults to true

### Configuration per panel

All settings except `verifySsl` can be set on the plugin, overriding the config file. This allows panels
using different clients:

```php
FilamentOauth2Plugin::make()
    ->clientId('backoffice')
    ->clientSecret(config('services.backoffice.client_secret'))
    ->urlAfterLogout('/')
    ->updateRoles()
    ->allowedRoles(['editor', 'viewer'])
    ->roleModel(\App\Models\Role::class);
```

Available: `clientId`, `clientSecret`, `urlAuthorize`, `urlAccessToken`, `urlResourceOwnerDetails`,
`urlLogout`, `urlAfterLogout`, `scopes`, `updateRoles`, `allowedRoles`, `roleModel`.

## Security

- Login uses the `state` parameter and PKCE (S256). Enable PKCE for your client in the OAuth2-Server
  (Keycloak: *Advanced → Proof Key for Code Exchange Code Challenge Method: S256*) and configure the
  *Valid Redirect URIs* exactly.
- Users are linked to the OAuth2-Server by their email address. An existing user with the same email
  address is taken over, so the OAuth2-Server must only provide verified email addresses. Logins with
  `email_verified = false` are rejected.
- Roles are synced before the access to the panel is checked, so removing a role in the OAuth2-Server
  takes effect with the next login. Use `OAUTH2_ALLOWED_ROLES` to restrict which roles may be granted
  by the OAuth2-Server.
- Logout is a POST request (protected by CSRF).
- `OAUTH2_VERIFY_SSL=false` is only meant for dev- and test-environments with self-signed certificates.

## Testing

```bash
composer test
composer analyse
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
