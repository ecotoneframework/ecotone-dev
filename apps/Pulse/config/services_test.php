<?php

declare(strict_types=1);

use Ecotone\Test\StaticPsrClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Log\Logger;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('pulse.graph_default_node_limit', 40);
    $container->services()->set('pulse.clock', MockClock::class)->args(['2026-10-20 12:00:00'])->public();
    $container->services()->set('ecotone.clock', StaticPsrClock::class)->args(['now'])->public();
    $container->services()->set('logger', Logger::class)->args([LogLevel::ERROR, 'php://stderr']);
    $container->services()->alias(ClockInterface::class, 'ecotone.clock')->public();
};
