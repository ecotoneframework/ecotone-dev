<?php

namespace Test\Ecotone\Messaging\Unit\Config\Container;

use Ecotone\Messaging\Config\Container\Compiler\ResolveDefinedObjectsPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Unit\Config\Container\Fixtures\ComplexDefinedObject;
use Test\Ecotone\Messaging\Unit\Config\Container\Fixtures\SimpleDefinedObject;

/**
 * @internal
 */
class ResolveDefinedObjectsPassTest extends TestCase
{
    public function test_it_resolves_simple_defined_objects()
    {
        $builder = new ContainerBuilder();
        $builder->register('someId', new SimpleDefinedObject(1, 'aString'));
        $builder->addCompilerPass(new ResolveDefinedObjectsPass());
        $builder->compile();

        self::assertEquals([
            'someId' => new Definition(SimpleDefinedObject::class, [1, 'aString']),
        ], $builder->getDefinitions(), 'Defined objects should be resolved to definitions');
    }

    public function test_it_resolves_complex_defined_objects()
    {
        $builder = new ContainerBuilder();
        $builder->register('someId', new ComplexDefinedObject(1, new SimpleDefinedObject(1, 'aString'), ['a', new SimpleDefinedObject(2, 'anotherString')]));
        $builder->addCompilerPass(new ResolveDefinedObjectsPass());
        $builder->compile();

        self::assertEquals([
            'someId' => new Definition(ComplexDefinedObject::class, [
                1,
                new Definition(SimpleDefinedObject::class, [
                    1,
                    'aString',
                ]),
                [
                    'a',
                    new Definition(SimpleDefinedObject::class, [
                        2,
                        'anotherString',
                    ]),
                ],
            ]),
        ], $builder->getDefinitions(), 'Nested defined objects should be resolved to definitions');
    }

    public function test_it_resolves_reference_to_same_object()
    {
        $builder = new ContainerBuilder();
        $aSingleton = new SimpleDefinedObject(1, 'aSingleton');
        $differentReferenceToEqualObject = new SimpleDefinedObject(1, 'aSingleton');
        $builder->register('someId', new ComplexDefinedObject(1, $aSingleton, [$aSingleton]));
        $builder->register('anotherId', new ComplexDefinedObject(1, $differentReferenceToEqualObject, [$aSingleton]));
        $builder->addCompilerPass(new ResolveDefinedObjectsPass());
        $builder->compile();

        $aSingletonId = ResolveDefinedObjectsPass::referenceForDefinedObject($aSingleton)->getId();
        self::assertEquals([
            $aSingletonId => new Definition(SimpleDefinedObject::class, [1, 'aSingleton']),
            'someId' => new Definition(ComplexDefinedObject::class, [1, Reference::to($aSingletonId), [Reference::to($aSingletonId)]]),
            'anotherId' => new Definition(ComplexDefinedObject::class, [1, $differentReferenceToEqualObject->getDefinition(), [Reference::to($aSingletonId)]]),
        ], $builder->getDefinitions(), 'Same instance of defined objects should be registered as singletons');
    }
}
