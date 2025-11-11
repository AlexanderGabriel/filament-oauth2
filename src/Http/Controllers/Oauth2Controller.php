<?php

namespace AlexanderGabriel\FilamentOauth2\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Exception;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Request;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;

class Oauth2Controller extends Controller
{
    private GenericProvider $oauth2Provider;

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
            $accessToken = $this->oauth2Provider->getAccessToken('authorization_code', ['code' => $request->input('code')]);
            $oauth2User = $this->oauth2Provider->getResourceOwner($accessToken)->toArray();
            
            $user = User::firstOrCreate([
                'email' =>  $oauth2User['email'],
            ],[
                'name' =>  $oauth2User['name'],
                'password' =>  'nonsense'
            ]);
            if($user->name != $oauth2User['name']) 
            {
                $user->name = $oauth2User['name'];
                $user->save();
            }

            Filament::auth()->loginUsingId($user->id, false);

            if (
                ($user instanceof FilamentUser) &&
                (! $user->canAccessPanel(Filament::getCurrentPanel()))
            ) {
                Filament::auth()->logout();

                $this->throwFailureValidationException();
            }

            session()->regenerate();

            //Should i update Roles and are there roles?
            $hasRoles = false;
            try {
                $roles = $user->roles();
                if($roles) $hasRoles = true;
            }
            catch (Exception $e) {
                //No Roles. Nothing to do
            }
            if($hasRoles && config('filament-oauth2.updateRoles') != false) {
                //Are there roles in the Token?
                $accessToken = explode(".", $accessToken);
                if(isset($accessToken[1])) {
                    $accessToken = json_decode(base64_decode($accessToken[1]));
                    $clientId = config('filament-oauth2.clientId');
                    if(isset($accessToken->resource_access) && isset($accessToken->resource_access->$clientId)) {
                        // Roles are defined. Maybe empty to remove all Roles from user
                        // TODO: test
                        if(!isset($accessToken->resource_access->$clientId->roles)) $roles = [];
                        else $roles = $accessToken->resource_access->$clientId->roles;

                        $userRoles = $user->roles();

                        //disconnect roles
                        foreach ($userRoles as $userRole) {
                            if(!in_array($userRole, $roles)) {
                                $user->roles()->detach($userRole);
                            }
                        }

                        // connect or create roles
                        foreach($roles as $role) {
                            $existingRole = Role::first('name', '=', $role);
                            if($existingRole) {
                                $user->roles()->attach($existingRole->id);
                            } else {
                                $newRole = Role::create(['name' => $role]);
                                $newRole->save();
                                $user->roles()->attach($newRole);
                            }
                        }

                    }
                }
            }

            return app(LoginResponse::class);

        } catch (IdentityProviderException $e) {
            dd($e->getMessage());
        }
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }

    public function handleLogout(Request $request)
    {
        session()->invalidate();
        session()->regenerateToken();
        Filament::auth()->logout();
        $logoutUrl = config('filament-oauth2.urlLogout').'?client_id=filamentphp';
        return redirect($logoutUrl);
    }
}
