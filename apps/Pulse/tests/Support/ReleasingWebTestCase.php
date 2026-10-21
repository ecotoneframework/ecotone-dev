<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Settings\PulseSettings;
use App\Storage\InternalDatabase;
use Closure;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use ReflectionObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ReleasingWebTestCase extends WebTestCase
{
    private const OPEN_DESCRIPTORS_LIMIT = 300;

    protected function tearDown(): void
    {
        $this->closeKernelConnections();
        OpenedConnections::closeAll();
        parent::tearDown();
        self::ensureKernelShutdown();

        foreach ((new ReflectionObject($this))->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadOnly() || str_starts_with($property->class, 'PHPUnit\\') || str_starts_with($property->class, 'Symfony\\')) {
                continue;
            }
            Closure::bind(function () use ($property): void {
                unset($this->{$property->name});
            }, $this, $property->class)();
        }
        gc_collect_cycles();

        self::assertLessThan(self::OPEN_DESCRIPTORS_LIMIT, count(scandir('/proc/self/fd')) - 2);
    }

    private function closeKernelConnections(): void
    {
        if (static::$kernel === null) {
            return;
        }

        $container = static::getContainer();
        $container->get(InternalDatabase::class)->connection()->close();
        foreach ($container->get(PulseSettings::class)->connections as $connection) {
            $reference = $connection->referenceName();
            if ($reference === null || ! $container->has($reference)) {
                continue;
            }
            $transport = $container->get($reference);
            if ($transport instanceof DbalConnectionFactory) {
                $transport->createContext()->getDbalConnection()->close();
            }
        }
    }
}
