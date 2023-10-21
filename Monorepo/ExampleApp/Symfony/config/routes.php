<?php

use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Common\UI\OrderControllerWithoutMessaging;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->add('place_order', '/place-order')
        ->controller([OrderController::class, 'placeOrder']);
    $routes->add('place_order', '/place-order-without-messaging')
        ->controller([OrderControllerWithoutMessaging::class, 'placeOrder']);
};