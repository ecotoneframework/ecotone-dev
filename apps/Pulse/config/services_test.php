<?php

declare(strict_types=1);

use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()->set('pulse.clock', MockClock::class)->args(['2026-10-20 12:00:00'])->public();
};
