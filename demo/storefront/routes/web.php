<?php

declare(strict_types=1);

use App\Web\OrderController;
use App\Web\RushController;
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
Route::get('/concerts/{concertId}/taken-seats.json', [StorefrontController::class, 'takenSeats']);
Route::post('/concerts/{concertId}/rushes', [RushController::class, 'start']);
Route::get('/rushes/{rushId}/state.json', [RushController::class, 'state']);
Route::get('/account', [StorefrontController::class, 'account']);
Route::post('/concerts/{concertId}/orders', [OrderController::class, 'start']);
Route::post('/orders/{orderId}/seats', [OrderController::class, 'requestSeat']);
Route::post('/orders/{orderId}/seats/release', [OrderController::class, 'releaseSeat']);
Route::get('/orders/{orderId}/state.json', [OrderController::class, 'state']);
Route::get('/orders/{orderId}/checkout', [OrderController::class, 'checkout']);
Route::post('/orders/{orderId}/checkout', [OrderController::class, 'pay']);
Route::get('/orders/{orderId}', [OrderController::class, 'show']);
