<?php

declare(strict_types=1);

namespace Test\Ecotone\SymfonyContainer;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Ecotone\Test\StubLogger;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Lite\ContainerImplementationTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
class SymfonyContainerImplementationTest extends ContainerImplementationTestCase
{
    protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        return EcotoneSymfonyContainerFactory::build($builder, ServiceCacheConfiguration::noCache(), $externalContainer);
    }

    public function test_it_resolves_references_from_external_container(): void
    {
        $logger = StubLogger::create();
        $externalContainer = InMemoryPSRContainer::createFromAssociativeArray([
            'externallyDefined' => $logger,
        ]);
        $container = self::buildContainerFromDefinitions(['aReference' => new Reference('externallyDefined')], $externalContainer);

        self::assertSame($logger, $container->get('aReference'));
    }
}
