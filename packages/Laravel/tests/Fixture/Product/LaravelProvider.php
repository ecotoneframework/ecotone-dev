<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Product;

use Illuminate\Support\ServiceProvider;

/**
 * licence Apache-2.0
 */
final class LaravelProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ProductService::class, ProductService::class);
    }
}
