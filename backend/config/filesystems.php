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

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
