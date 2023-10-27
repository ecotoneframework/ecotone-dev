<?php

use Monorepo\ExampleApp\Common\UI\OrderController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->add('place_order', '/place-order')
        ->controller([OrderController::class, 'placeOrder'])
        ->methods(['POST']);
};