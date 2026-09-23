<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // 範例：允許目前請求的主機。
        // 範例設定：Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | 設定說明
    |--------------------------------------------------------------------------
    |
    | 此區為 Laravel 預設設定，請依實際部署環境與需求調整。
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
