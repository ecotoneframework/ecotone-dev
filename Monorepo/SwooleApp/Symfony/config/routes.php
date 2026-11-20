<?php

use Monorepo\SwooleApp\Symfony\ProbeController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    $routes->add('probe', '/{action}')->controller(ProbeController::class);
};
