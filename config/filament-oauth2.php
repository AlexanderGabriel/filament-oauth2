<?php

// config for AlexanderGabriel/FilamentOauth2

return [

    'clientId' => env("OAUTH2_CLIENT_ID"),
    'clientSecret' => env("OAUTH2_CLIENT_SECRET"),
    'baseUrl' => env("OAUTH2_BASE_URL"), // https://DOMAIN/realms/REALM/protocol/openid-connect
    'urlAuthorize' => env("OAUTH2_URL_AUTHORIZE", env("OAUTH2_BASE_URL")."/auth"),
    'urlAccessToken' => env("OAUTH2_URL_ACCESS_TOKEN", env("OAUTH2_BASE_URL")."/token"),
    'urlResourceOwnerDetails' => env("OAUTH2_URL_RSOURCE_OWNER_DETAILS", env("OAUTH2_BASE_URL")."/userinfo"),
    'scopes' => env("OAUTH2_SCOPES", "profile email openid"),
    'updateRoles' => env("OAUTH2_UPDATE_ROLES", false)

];
