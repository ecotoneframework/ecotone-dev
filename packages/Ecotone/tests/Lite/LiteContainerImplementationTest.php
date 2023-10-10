<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\LiteContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Test\Ecotone\Messaging\Unit\Handler\Logger\LoggerExample;

class LiteContainerImplementationTest extends TestCase
{
    public function test_it_resolves_correctly(): void
    {
        $container = InMemoryPSRContainer::createEmpty();
        $containerImplementation = new LiteContainerImplementation($container);
        $definitions["def1"] = new Definition(WithNoDependencies::class);
        $definitions["def2"] = new Definition(WithAStringDependency::class, ["someName"]);
        $definitions["def3"] = new Definition(WithAReferenceDependency::class, [new Reference("def1")]);
        $containerImplementation->process($definitions, []);
        self::assertEquals(new WithNoDependencies(), $container->get("def1"));
        self::assertEquals(new WithAStringDependency("someName"), $container->get("def2"));
        self::assertEquals(new WithAReferenceDependency($container->get("def1")), $container->get("def3"));

        self::assertSame($container->get("def1"), $container->get("def3")->getDependency());
    }

    public function test_it_replace_provided_dependencies(): void
    {
        $container = InMemoryPSRContainer::createEmpty();
        $logger = LoggerExample::create();
        $externalContainer = InMemoryPSRContainer::createFromAssociativeArray([
            "logger" => $logger,
        ]);
        $containerImplementation = new LiteContainerImplementation($container, $externalContainer);

        $definitions["logger"] = new Definition(NullLogger::class);

        $containerImplementation->process($definitions, []);

        self::assertSame($logger, $container->get("logger"));
    }
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