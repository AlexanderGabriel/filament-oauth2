<?php

namespace AlexanderGabriel\FilamentOauth2\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Exception;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;

class Oauth2Controller extends Controller
{
    /**
     * Filament Shield is an optional dependency, so it is only referenced by name.
     */
    private const SHIELD_UTILS = '\BezhanSalleh\FilamentShield\Support\Utils';

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
        $this->oauth2Provider = new GenericProvider([
            'clientId' => config('filament-oauth2.clientId'),    // The client ID assigned to you by the provider
            'clientSecret' => config('filament-oauth2.clientSecret'),    // The client password assigned to you by the provider
            'redirectUri' => route('filament-oauth2.handleCallback'),
            'urlAuthorize' => config('filament-oauth2.urlAuthorize'),
            'urlAccessToken' => config('filament-oauth2.urlAccessToken'),
            'urlResourceOwnerDetails' => config('filament-oauth2.urlResourceOwnerDetails'),
            'scopes' => config('filament-oauth2.scopes'),
        ], [
            'httpClient' => new HttpClient(['verify' => config('filament-oauth2.verifySsl', true)]),
        ]);
    }
    public function redirectToOauth2Server()
    {
        return redirect($this->oauth2Provider->getAuthorizationUrl());
    }

    public function handleCallback(Request $request)
    {
        try {
            $this->accessToken = $this->oauth2Provider->getAccessToken('authorization_code', ['code' => $request->input('code')]);
            $this->accessTokenDecoded = $this->decodeAccessToken();
            $this->oauth2User = $this->oauth2Provider->getResourceOwner($this->accessToken)->toArray();

            // Create the user if it does not exist
            $this->user = User::firstOrCreate([
                'email' => $this->oauth2User['email'],
            ], [
                'name' => $this->oauth2User['name'],
                // Todo -> is there a better way?
                'password' => 'nonsense',
            ]);
            $saveUser = false;
            if($this->user->hasAttribute('username') && isset($this->accessTokenDecoded->preferred_username) && $this->user->username != $this->accessTokenDecoded->preferred_username) {
                $this->user->username = $this->accessTokenDecoded->preferred_username;
                $saveUser = true;
            }

            // Update user data if different from Oauth2-Server
            if ($this->user->name != $this->oauth2User['name']) {
                $this->user->name = $this->oauth2User['name'];
                $saveUser = true;
            }
            if($saveUser) $this->user->save();

            // Login User by id
            Filament::auth()->loginUsingId($this->user->id, false);

            // Taken from original LoginClass...
            if (
                ($this->user instanceof FilamentUser) &&
                (! $this->user->canAccessPanel(Filament::getCurrentPanel()))
            ) {
                Filament::auth()->logout();
                $this->throwFailureValidationException();
            }

            session()->regenerate();

            // Handle Role Mapping
            $this->handleRoleMapping();

            return app(LoginResponse::class);

        } catch (IdentityProviderException $e) {
            throw ($e);
        }
    }

    /**
     * The payload of the access token, if it is a JWT.
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

        try {
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
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Roles the Oauth2-Server provides for this client.
     *
     * Returns null if the token does not contain a roles claim for the client,
     * an (possibly empty) array of role names otherwise. An empty array removes
     * all roles from the user.
     */
    protected function getRolesFromAccessToken(): ?array
    {
        $clientId = config('filament-oauth2.clientId');
        $resourceAccess = $this->accessTokenDecoded->resource_access ?? null;

        if (! isset($resourceAccess->$clientId)) {
            return null;
        }

        return (array) ($resourceAccess->$clientId->roles ?? []);
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

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    public function handleLogout(Request $request)
    {
        // https://openid.net/specs/openid-connect-rpinitiated-1_0.html
        session()->invalidate();
        session()->regenerateToken();
        Filament::auth()->logout();
        $logoutUrl = config('filament-oauth2.urlLogout') . '?client_id='.env('OAUTH2_CLIENT_ID');
        if (config('filament-oauth2.urlAfterlogout') != url('/')) {
            $logoutUrl .= '&post_logout_redirect_uri=' . config('filament-oauth2.urlAfterlogout');
        }

        return redirect($logoutUrl);
    }
}
