<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
