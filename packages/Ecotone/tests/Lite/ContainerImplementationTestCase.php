<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Test\Ecotone\Messaging\Unit\Handler\Logger\LoggerExample;

abstract class ContainerImplementationTestCase extends TestCase
{
    public function test_it_resolves_correctly(): void
    {
        $container = self::buildContainerFromDefinitions([
            "def1" => new Definition(WithNoDependencies::class),
            "def2" => new Definition(WithAStringDependency::class, ["someName"]),
            "def3" => new Definition(WithAReferenceDependency::class, [new Reference("def1")]),
        ]);


        self::assertEquals(new WithNoDependencies(), $container->get("def1"));
        self::assertEquals(new WithAStringDependency("someName"), $container->get("def2"));
        self::assertEquals(new WithAReferenceDependency($container->get("def1")), $container->get("def3"));

        self::assertSame($container->get("def1"), $container->get("def3")->getDependency());
    }

    public function test_it_replace_provided_dependencies(): void
    {
        $logger = LoggerExample::create();
        $externalContainer = InMemoryPSRContainer::createFromAssociativeArray([
            "logger" => $logger,
        ]);
        $container = self::buildContainerFromDefinitions(["aReference" => new Reference('logger')], $externalContainer);

        self::assertSame($logger, $container->get("aReference"));
    }

    private static function buildContainerFromDefinitions(array $definitions, ?ContainerInterface $externalContainer = null): ContainerInterface
    {
        $builder = new ContainerBuilder();
        foreach ($definitions as $id => $definition) {
            $builder->replace($id, $definition);
        }
        return static::getContainerFrom($builder, $externalContainer);
    }

    abstract protected static function getContainerFrom(ContainerBuilder $builder, ?ContainerInterface $externalContainer = null): ContainerInterface;
}

/**
 * @internal
 */
class WithNoDependencies
{
}

/**
 * @internal
 */
class WithAStringDependency
{
    public function __construct(public string $name)
    {
    }
}

/**
 * @internal
 */
class WithAReferenceDependency
{
    public function __construct(public WithNoDependencies $withNoDependencies)
    {
    }

    public function getDependency(): WithNoDependencies
    {
        return $this->withNoDependencies;
    }
}