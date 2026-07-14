<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// 此段說明相鄰程式碼的用途與預期行為。
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// 此段說明相鄰程式碼的用途與預期行為。
require __DIR__.'/../vendor/autoload.php';

// 此段說明相鄰程式碼的用途與預期行為。
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
