<?php

/**
 * Symfony without OpenSwoole: the application is booted for every request, as under php-fpm.
 * Served by the PHP built-in server for the benchmark (Monorepo/SwooleApp/Benchmark/http.php).
 */

use Monorepo\SwooleApp\Symfony\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$kernel = new Kernel(getenv('APP_ENV') ?: 'prod', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
