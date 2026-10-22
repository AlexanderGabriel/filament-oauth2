<?php

namespace AlexanderGabriel\FilamentOauth2\Http\Controllers;

use AlexanderGabriel\FilamentOauth2\FilamentOauth2Plugin;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;
use Throwable;

class Oauth2Controller extends Controller
{
    /**
     * Filament Shield is an optional dependency, so it is only referenced by name.
     */
    private const SHIELD_UTILS = '\BezhanSalleh\FilamentShield\Support\Utils';

    private const SESSION_STATE = 'filament-oauth2.state';

    private const SESSION_PKCE_CODE = 'filament-oauth2.pkceCode';

    /**
     * Created on use, because the current panel is not known when the
     * controller is constructed (before the middleware ran).
     */
    protected function oauth2Provider(): GenericProvider
    {
        // https://oauth2-client.thephpleague.com/usage/
        // The httpClient collaborator is passed explicitly (instead of a
        // 'verify' option) because AbstractProvider only allows 'verify'
        // through to Guzzle when a 'proxy' option is also set.
        return app()->make(GenericProvider::class, ['options' => [
            'clientId' => $this->config('clientId'),    // The client ID assigned to you by the provider
            'clientSecret' => $this->config('clientSecret'),    // The client password assigned to you by the provider
            'redirectUri' => Filament::getCurrentOrDefaultPanel()->route('filament-oauth2.handleCallback'),
            'urlAuthorize' => $this->config('urlAuthorize'),
            'urlAccessToken' => $this->config('urlAccessToken'),
            'urlResourceOwnerDetails' => $this->config('urlResourceOwnerDetails'),
            'scopes' => $this->config('scopes'),
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], 'collaborators' => [
            'httpClient' => new HttpClient(['verify' => $this->verifySsl()]),
        ]]);
    }

    /**
     * Setting of the current panel, see FilamentOauth2Plugin.
     */
    protected function config(string $key): mixed
    {
        return FilamentOauth2Plugin::get()->getConfig($key);
    }

    /**
     * Disabling the ssl verification is only meant for dev- and test-environments
     * with self-signed certificates, so it is refused in production.
     */
    protected function verifySsl(): bool
    {
        $verifySsl = filter_var(config('filament-oauth2.verifySsl', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        if (! $verifySsl && app()->isProduction()) {
            throw new RuntimeException('OAUTH2_VERIFY_SSL=false is not allowed in production.');
        }

        return $verifySsl;
    }

    public function redirectToOauth2Server(): RedirectResponse
    {
        $oauth2Provider = $this->oauth2Provider();

        // State and PKCE code are only known after the authorization url is built
        $authorizationUrl = $oauth2Provider->getAuthorizationUrl();

        session()->put(self::SESSION_STATE, $oauth2Provider->getState());
        session()->put(self::SESSION_PKCE_CODE, $oauth2Provider->getPkceCode());

        return redirect($authorizationUrl);
    }

    public function handleCallback(Request $request): LoginResponse
    {
        // State and PKCE code are only valid for one callback
        $state = session()->pull(self::SESSION_STATE);
        $pkceCode = session()->pull(self::SESSION_PKCE_CODE);

        // The Oauth2-Server reports errors (e.g. access_denied) by the error parameter
        if ($request->has('error') || ! $request->filled('code')) {
            $this->abortLogin();
        }

        // Protect against login-CSRF
        if (! is_string($state) || ! is_string($request->query('state')) || ! hash_equals($state, $request->query('state'))) {
            $this->abortLogin();
        }

        $oauth2Provider = $this->oauth2Provider();

        try {
            $oauth2Provider->setPkceCode($pkceCode);
            $accessToken = $oauth2Provider->getAccessToken('authorization_code', ['code' => $request->query('code')]);
            $oauth2User = $oauth2Provider->getResourceOwner($accessToken)->toArray();
        } catch (IdentityProviderException $e) {
            report($e);
            $this->abortLogin();
        }

        $claims = $this->decodeAccessToken((string) $accessToken);

        if (empty($oauth2User['email'])) {
            $this->abortLogin();
        }

        // Users are linked by email, so it must not be an unverified one.
        // Some Oauth2-Servers send the flag as string ("false").
        if (isset($oauth2User['email_verified']) && ! filter_var($oauth2User['email_verified'], FILTER_VALIDATE_BOOL)) {
            $this->abortLogin();
        }

        $user = $this->findOrCreateUser($oauth2User, $claims);

        // Roles are mapped before the access check, so it is based on the current roles
        try {
            $this->handleRoleMapping($user, $claims);
        } catch (Throwable $e) {
            report($e);
            $this->abortLogin();
        }

        // Taken from original LoginClass...
        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
        ) {
            $this->abortLogin();
        }

        /** @var Model&Authenticatable $user */
        Filament::auth()->login($user);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * The user model of the auth guard of the panel.
     */
    protected function findOrCreateUser(array $oauth2User, ?object $claims): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = Filament::auth()->getProvider()->getModel(); // @phpstan-ignore method.notFound (session guards have a user provider)

        $user = $userModel::firstOrNew(['email' => $oauth2User['email']]);
        if (! $user->exists) {
            // Login is only done by the Oauth2-Server, so nobody may know the password
            $user->setAttribute('password', Hash::make(Str::random(64)));
        }

        // Update user data if different from Oauth2-Server, save() only writes changes.
        // The schema is checked, because a new user has no attributes to look at yet.
        if (isset($claims->preferred_username) && $user->getConnection()->getSchemaBuilder()->hasColumn($user->getTable(), 'username')) {
            $user->setAttribute('username', $claims->preferred_username);
        }
        $user->setAttribute('name', $oauth2User['name'] ?? $user->getAttribute('name') ?? $oauth2User['email']);
        $user->save();

        return $user;
    }

    /**
     * The payload of the access token, if it is a JWT.
     *
     * The signature is not verified: the token is received directly from the
     * token endpoint of the Oauth2-Server, secured by TLS.
     */
    protected function decodeAccessToken(string $accessToken): ?object
    {
        $payload = explode('.', $accessToken)[1] ?? null;
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')));

        return is_object($decoded) ? $decoded : null;
    }

    protected function handleRoleMapping(Model $user, ?object $claims): void
    {
        if (! $this->config('updateRoles')) {
            return;
        }

        $roles = $this->getRolesFromClaims($claims);
        // No roles claim at all: leave the roles of the user untouched
        if ($roles === null) {
            return;
        }

        if ($this->usesFilamentShield($user)) {
            $this->syncShieldRoles($user, $roles);
        } else {
            $this->syncRoles($user, $roles);
        }

        // The access check has to see the synced roles
        $user->unsetRelation('roles');
    }

    /**
     * Roles the Oauth2-Server provides for this client.
     *
     * Returns null if the token does not contain a roles claim for the client,
     * an (possibly empty) array of role names otherwise. An empty array removes
     * all roles from the user. If allowedRoles is configured, all other roles
     * are ignored.
     *
     * @return array<string>|null
     */
    protected function getRolesFromClaims(?object $claims): ?array
    {
        $clientId = $this->config('clientId');
        $resourceAccess = $claims->resource_access ?? null;

        if (! isset($resourceAccess->$clientId)) {
            return null;
        }

        $roles = array_values(array_filter((array) ($resourceAccess->$clientId->roles ?? []), 'is_string'));

        $allowedRoles = $this->config('allowedRoles');
        if (! empty($allowedRoles)) {
            $roles = array_values(array_intersect($roles, $allowedRoles));
        }

        return $roles;
    }

    /**
     * Filament Shield keeps its roles in the spatie/laravel-permission models,
     * so its role handling has to be used instead of the plain roles()-relation.
     */
    protected function usesFilamentShield(Model $user): bool
    {
        return class_exists(self::SHIELD_UTILS) && method_exists($user, 'syncRoles');
    }

    /**
     * @param  array<string>  $roles
     */
    protected function syncShieldRoles(Model $user, array $roles): void
    {
        $shield = self::SHIELD_UTILS;

        // Non-existing roles are created, so they can be given permissions in Shield.
        // Shield takes care of the guard configured for the panel.
        $roles = array_map(
            fn (string $role) => $shield::createRole($role),
            $roles
        );

        // Roles not in the access token any more are removed from the user
        $user->syncRoles($roles); // @phpstan-ignore method.notFound (HasRoles-trait of Shield, see usesFilamentShield())
    }

    /**
     * @param  array<string>  $roles
     */
    protected function syncRoles(Model $user, array $roles): void
    {
        if (! method_exists($user, 'roles')) {
            return;
        }

        /** @var class-string<Model> $roleModel */
        $roleModel = $this->config('roleModel');

        // Non-existing roles are created
        $roleIds = array_map(
            fn (string $role) => $roleModel::firstOrCreate(['name' => $role])->getKey(),
            $roles
        );

        // Roles not in the access token any more are removed from the user
        $user->roles()->sync($roleIds);
    }

    /**
     * Redirecting back to the login would loop through the Oauth2-Server,
     * so a failed login ends here.
     */
    protected function abortLogin(): never
    {
        abort(403, __('filament-panels::auth/pages/login.messages.failed'));
    }

    public function handleLogout(Request $request): RedirectResponse
    {
        // https://openid.net/specs/openid-connect-rpinitiated-1_0.html
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        $query = ['client_id' => $this->config('clientId')];
        if (filled($this->config('urlAfterlogout'))) {
            $query['post_logout_redirect_uri'] = url($this->config('urlAfterlogout'));
        }

        return redirect($this->config('urlLogout') . '?' . http_build_query($query));
    }
}
