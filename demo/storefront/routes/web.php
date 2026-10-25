<?php

declare(strict_types=1);

use App\Web\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home', [
    'pulseUrl' => config('demo.pulse_url'),
    'jaegerUrl' => config('demo.jaeger_url') . '/search?service=storefront',
]));
Route::get('/whoami', [StorefrontController::class, 'whoami']);
Route::post('/whoami', [StorefrontController::class, 'chooseCustomer']);
Route::get('/concerts', [StorefrontController::class, 'concerts']);
Route::get('/concerts/{concertId}', [StorefrontController::class, 'concert']);
Route::get('/account', [StorefrontController::class, 'account']);
