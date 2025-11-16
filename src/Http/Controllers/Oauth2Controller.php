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
    private GenericProvider $oauth2Provider;

    private Model $user;

    private $accessToken;

    private $oauth2User;

    public function __construct()
    {
        // https://oauth2-client.thephpleague.com/usage/
        $this->oauth2Provider = new GenericProvider([
            'clientId' => config('filament-oauth2.clientId'),    // The client ID assigned to you by the provider
            'clientSecret' => config('filament-oauth2.clientSecret'),    // The client password assigned to you by the provider
            'redirectUri' => route('filament-oauth2.handleCallback'),
            'urlAuthorize' => config('filament-oauth2.urlAuthorize'),
            'urlAccessToken' => config('filament-oauth2.urlAccessToken'),
            'urlResourceOwnerDetails' => config('filament-oauth2.urlResourceOwnerDetails'),
            'scopes' => config('filament-oauth2.scopes'),
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
            $this->oauth2User = $this->oauth2Provider->getResourceOwner($this->accessToken)->toArray();

            // Create the user if it does not exist
            $this->user = User::firstOrCreate([
                'email' => $this->oauth2User['email'],
            ], [
                'name' => $this->oauth2User['name'],
                // Todo -> is there a better way?
                'password' => 'nonsense',
            ]);

            // Update user data if different from Oauth2-Server
            if ($this->user->name != $this->oauth2User['name']) {
                $this->user->name = $this->oauth2User['name'];
                $this->user->save();
            }

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

    protected function handleRoleMapping(): void
    {
        if (config('filament-oauth2.updateRoles') != false) {
            try {
                $roles = $this->user->roles();
                if ($roles) {
                    // Are there roles in the Token?
                    $this->accessToken = explode('.', $this->accessToken);
                    if (isset($this->accessToken[1])) {
                        $this->accessToken = json_decode(base64_decode($this->accessToken[1]));
                        $clientId = config('filament-oauth2.clientId');
                        if (isset($this->accessToken->resource_access) && isset($this->accessToken->resource_access->$clientId)) {
                            // Roles are defined. Maybe empty to remove all Roles from user
                            // TODO: test this without roles
                            if (! isset($this->accessToken->resource_access->$clientId->roles)) {
                                $roles = [];
                            } else {
                                $roles = $this->accessToken->resource_access->$clientId->roles;
                            }
                            $userRoles = $this->user->roles();
                            // Disconnect roles not in the access token any more
                            foreach ($userRoles as $userRole) {
                                if (! in_array($userRole, $roles)) {
                                    $this->user->roles()->detach($userRole);
                                }
                            }
                            // Connect or create roles
                            foreach ($roles as $role) {
                                $existingRole = Role::first('name', '=', $role);
                                if ($existingRole) {
                                    $this->user->roles()->attach($existingRole->id);
                                } else {
                                    $newRole = Role::create(['name' => $role]);
                                    // needed?
                                    $newRole->save();
                                    $this->user->roles()->attach($newRole);
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // No Roles. Nothing to do
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
        $logoutUrl = config('filament-oauth2.urlLogout') . '?client_id=filamentphp';
        if (config('filament-oauth2.urlAfterlogout') != url('/')) {
            $logoutUrl .= '&post_logout_redirect_uri=' . config('filament-oauth2.urlAfterlogout');
        }

        return redirect($logoutUrl);
    }
}
