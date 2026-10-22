<?php

// config for AlexanderGabriel/FilamentOauth2
// All settings except verifySsl can be overridden per panel, see FilamentOauth2Plugin.

return [
    'clientId' => env('OAUTH2_CLIENT_ID'),
    'clientSecret' => env('OAUTH2_CLIENT_SECRET'),
    'baseUrl' => env('OAUTH2_BASE_URL'), // https://DOMAIN/realms/REALM/protocol/openid-connect
    'urlAuthorize' => env('OAUTH2_URL_AUTHORIZE', env('OAUTH2_BASE_URL') . '/auth'),
    'urlAccessToken' => env('OAUTH2_URL_ACCESS_TOKEN', env('OAUTH2_BASE_URL') . '/token'),
    'urlResourceOwnerDetails' => env('OAUTH2_URL_RESOURCE_OWNER_DETAILS', env('OAUTH2_BASE_URL') . '/userinfo'),
    'urlLogout' => env('OAUTH2_URL_LOGOUT', env('OAUTH2_BASE_URL') . '/logout'),
    // Not set: the Oauth2-Server shows its own logout page
    'urlAfterlogout' => env('OAUTH2_URL_AFTER_LOGOUT'),
    'scopes' => env('OAUTH2_SCOPES', 'profile email openid'),
    'updateRoles' => env('OAUTH2_UPDATE_ROLES', false),
    // Comma separated list of roles taken from the access token, empty: all roles
    'allowedRoles' => array_filter(array_map('trim', explode(',', (string) env('OAUTH2_ALLOWED_ROLES', '')))),
    // Used if Filament Shield is not installed
    'roleModel' => env('OAUTH2_ROLE_MODEL', 'App\Models\Role'),
    // Disabling is only allowed outside of production (self-signed certificates in dev/test)
    'verifySsl' => env('OAUTH2_VERIFY_SSL', true),
];
