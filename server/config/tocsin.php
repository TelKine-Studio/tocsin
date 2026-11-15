<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tocsin
    |--------------------------------------------------------------------------
    |
    | Almost every option of Tocsin is configured from the administration UI
    | and stored in the database. The few values below only describe the
    | runtime environment and usually never need to be changed.
    |
    */

    'version' => env('TOCSIN_VERSION', '0.1.0'),

    'publisher' => 'Telkine',

    'publisher_url' => 'https://github.com/TelKine-Studio',

    'repository' => env('TOCSIN_REPOSITORY', 'https://github.com/TelKine-Studio/tocsin'),

    /*
    | Default push relay. Instances that do not have their own Apple Push key
    | deliver notifications to the official Tocsin app through this relay,
    | operated by Telkine. It can be changed from the administration UI.
    */
    /*
    | Relay operator mode: selling the push relay to other instances (plans,
    | payments, client instances). Only the instance operated by Telkine
    | needs it; self-hosted instances never see these screens.
    */
    'relay_operator' => (bool) env('TOCSIN_RELAY_OPERATOR', false),

    'relay' => [
        'url' => env('TOCSIN_RELAY_URL', 'https://push.telkine.net'),
    ],

    /*
    | Public address of the WebSocket endpoint given to clients. By default it
    | is derived from the application URL (the bundled Docker image proxies
    | "/app" to the realtime server on the same host and port). Override it
    | only when the realtime server is exposed somewhere else, e.g. during
    | local development where Reverb listens directly on port 8080.
    */
    'realtime' => [
        'host' => env('TOCSIN_WS_HOST'),
        'port' => env('TOCSIN_WS_PORT'),
        'scheme' => env('TOCSIN_WS_SCHEME'),
        'path' => env('TOCSIN_WS_PATH', ''),
    ],

];
