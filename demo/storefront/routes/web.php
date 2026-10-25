<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home', [
    'pulseUrl' => config('demo.pulse_url'),
    'jaegerUrl' => config('demo.jaeger_url') . '/search?service=storefront',
]));
