<?php

namespace AlexanderGabriel\FilamentOauth2\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

    private GenericProvider $oauth2Provider;

    private Model $user;

    private $accessToken;

    private $accessTokenDecoded;

    private $oauth2User;

    public function __construct()
    {
        // https://oauth2-client.thephpleague.com/usage/
        // The httpClient collaborator is passed explicitly (instead of a
        // 'verify' option) because AbstractProvider only allows 'verify'
        // through to Guzzle when a 'proxy' option is also set.
        $this->oauth2Provider = app()->make(GenericProvider::class, ['options' => [
            'clientId' => config('filament-oauth2.clientId'),    // The client ID assigned to you by the provider
            'clientSecret' => config('filament-oauth2.clientSecret'),    // The client password assigned to you by the provider
            'redirectUri' => route('filament-oauth2.handleCallback'),
            'urlAuthorize' => config('filament-oauth2.urlAuthorize'),
            'urlAccessToken' => config('filament-oauth2.urlAccessToken'),
            'urlResourceOwnerDetails' => config('filament-oauth2.urlResourceOwnerDetails'),
            'scopes' => config('filament-oauth2.scopes'),
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], 'collaborators' => [
            'httpClient' => new HttpClient(['verify' => $this->verifySsl()]),
        ]]);
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

    public function redirectToOauth2Server()
    {
        // State and PKCE code are only known after the authorization url is built
        $authorizationUrl = $this->oauth2Provider->getAuthorizationUrl();

        session()->put(self::SESSION_STATE, $this->oauth2Provider->getState());
        session()->put(self::SESSION_PKCE_CODE, $this->oauth2Provider->getPkceCode());

        return redirect($authorizationUrl);
    }

    public function handleCallback(Request $request)
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

        try {
            $this->oauth2Provider->setPkceCode($pkceCode);
            $this->accessToken = $this->oauth2Provider->getAccessToken('authorization_code', ['code' => $request->query('code')]);
            $this->accessTokenDecoded = $this->decodeAccessToken();
            $this->oauth2User = $this->oauth2Provider->getResourceOwner($this->accessToken)->toArray();
        } catch (IdentityProviderException $e) {
            report($e);
            $this->abortLogin();
        }

        if (empty($this->oauth2User['email'])) {
            $this->abortLogin();
        }

        // Users are linked by email, so it must not be an unverified one
        if (($this->oauth2User['email_verified'] ?? null) === false) {
            $this->abortLogin();
        }

        // Create the user if it does not exist
        $this->user = User::firstOrCreate([
            'email' => $this->oauth2User['email'],
        ], [
            'name' => $this->oauth2User['name'] ?? $this->oauth2User['email'],
            // Login is only done by the Oauth2-Server, so nobody may know the password
            'password' => Hash::make(Str::random(64)),
        ]);
        $saveUser = false;
        if ($this->user->hasAttribute('username') && isset($this->accessTokenDecoded->preferred_username) && $this->user->username != $this->accessTokenDecoded->preferred_username) {
            $this->user->username = $this->accessTokenDecoded->preferred_username;
            $saveUser = true;
        }

        // Update user data if different from Oauth2-Server
        if (isset($this->oauth2User['name']) && $this->user->name != $this->oauth2User['name']) {
            $this->user->name = $this->oauth2User['name'];
            $saveUser = true;
        }
        if ($saveUser) {
            $this->user->save();
        }

        // Roles are mapped before the access check, so it is based on the current roles
        try {
            $this->handleRoleMapping();
        } catch (Throwable $e) {
            report($e);
            $this->abortLogin();
        }

        // Taken from original LoginClass...
        if (
            ($this->user instanceof FilamentUser) &&
            (! $this->user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
        ) {
            $this->abortLogin();
        }

        // Login User by id
        Filament::auth()->loginUsingId($this->user->id, false);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * The payload of the access token, if it is a JWT.
     *
     * The signature is not verified: the token is received directly from the
     * token endpoint of the Oauth2-Server, secured by TLS.
     */
    protected function decodeAccessToken(): ?object
    {
        $payload = explode('.', (string) $this->accessToken)[1] ?? null;
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')));

        return is_object($decoded) ? $decoded : null;
    }

    protected function handleRoleMapping(): void
    {
        if (config('filament-oauth2.updateRoles') == false) {
            return;
        }

        $roles = $this->getRolesFromAccessToken();
        // No roles claim at all: leave the roles of the user untouched
        if ($roles === null) {
            return;
        }

        if ($this->usesFilamentShield()) {
            $this->syncShieldRoles($roles);
        } else {
            $this->syncRoles($roles);
        }

        // The access check has to see the synced roles
        $this->user->unsetRelation('roles');
    }

    /**
     * Roles the Oauth2-Server provides for this client.
     *
     * Returns null if the token does not contain a roles claim for the client,
     * an (possibly empty) array of role names otherwise. An empty array removes
     * all roles from the user. If allowedRoles is configured, all other roles
     * are ignored.
     */
    protected function getRolesFromAccessToken(): ?array
    {
        $clientId = config('filament-oauth2.clientId');
        $resourceAccess = $this->accessTokenDecoded->resource_access ?? null;

        if (! isset($resourceAccess->$clientId)) {
            return null;
        }

        $roles = array_values(array_filter((array) ($resourceAccess->$clientId->roles ?? []), 'is_string'));

        $allowedRoles = config('filament-oauth2.allowedRoles', []);
        if (! empty($allowedRoles)) {
            $roles = array_values(array_intersect($roles, $allowedRoles));
        }

        return $roles;
    }

    /**
     * Filament Shield keeps its roles in the spatie/laravel-permission models,
     * so its role handling has to be used instead of the plain roles()-relation.
     */
    protected function usesFilamentShield(): bool
    {
        return class_exists(self::SHIELD_UTILS) && method_exists($this->user, 'syncRoles');
    }

    protected function syncShieldRoles(array $roles): void
    {
        $shield = self::SHIELD_UTILS;

        // Non-existing roles are created, so they can be given permissions in Shield.
        // Shield takes care of the guard configured for the panel.
        $roles = array_map(
            fn (string $role) => $shield::createRole($role),
            $roles
        );

        // Roles not in the access token any more are removed from the user
        $this->user->syncRoles($roles);
    }

    protected function syncRoles(array $roles): void
    {
        $userRoles = $this->user->roles();
        if (! $userRoles) {
            return;
        }

        // Disconnect roles not in the access token any more
        foreach ($userRoles->get()->pluck('name')->toArray() as $userRole) {
            if (! in_array($userRole, $roles)) {
                $this->user->roles()->detach(Role::where('name', $userRole)->first()->id);
            }
        }
        // Connect or create roles
        foreach ($roles as $role) {
            if (! in_array($role, $this->user->roles->pluck('name')->toArray())) {
                $existingRole = Role::where('name', $role)->exists();
                if ($existingRole) {
                    $this->user->roles()->attach(Role::where('name', $role)->first());
                } else {
                    $newRole = Role::create(['name' => $role]);
                    // needed?
                    $newRole->save();
                    $this->user->roles()->attach($newRole);
                }
            }
        }
    }

    /**
     * Redirecting back to the login would loop through the Oauth2-Server,
     * so a failed login ends here.
     */
    protected function abortLogin(): never
    {
        abort(403, __('filament-panels::auth/pages/login.messages.failed'));
    }

    public function handleLogout(Request $request)
    {
        // https://openid.net/specs/openid-connect-rpinitiated-1_0.html
        Filament::auth()->logout();
        session()->invalidate();
        session()->regenerateToken();

        $query = ['client_id' => config('filament-oauth2.clientId')];
        if (config('filament-oauth2.urlAfterlogout') != url('/')) {
            $query['post_logout_redirect_uri'] = config('filament-oauth2.urlAfterlogout');
        }

        return redirect(config('filament-oauth2.urlLogout') . '?' . http_build_query($query));
    }
}
