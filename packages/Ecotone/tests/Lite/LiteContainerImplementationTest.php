<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\LiteContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use PHPUnit\Framework\TestCase;

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