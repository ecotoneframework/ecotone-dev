<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use DI\ContainerBuilder;
use DI\Factory\RequestedEntry;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\FactoryDefinition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;

use function is_array;

use Psr\Container\ContainerInterface;
use ReflectionMethod;

class PhpDiContainerImplementation implements ContainerImplementation
{
    public function __construct(private ContainerBuilder $containerBuilder)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(array $definitions, array $externalReferences): void
    {
        $phpDiDefinitions = [];

        //        $phpDiDefinitions['channelResolver_legacy'] = static function (ContainerInterface $c) {
        //            return $c->get("external_reference_search_service")->get(ChannelResolver::class);
        //        };

        foreach ($definitions as $id => $definition) {
            $phpDiDefinitions[$id] = $this->resolveArgument($definition);
        }

        foreach ($externalReferences as $id => $reference) {
            //            if ($reference instanceof ChannelReference) {
            //                if (!isset($phpDiDefinitions[$id])) {
            //                    $phpDiDefinitions[$id] = static function (ContainerInterface $c, RequestedEntry $entry) {
            //                        $channelName = substr($entry->getName(), 8); // remove `channel-` prefix
            //                        return $c->get("channelResolver_legacy")->resolve($channelName);
            //                    };
            //                }
            //            } else {
            //                $phpDiDefinitions[$id] = static function (ContainerInterface $c, RequestedEntry $entry) {
            //                    return $c->get("external_reference_search_service")->get($entry->getName());
            //                };
            //            }
        }

        $this->containerBuilder->addDefinitions($phpDiDefinitions);
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } elseif ($argument instanceof FactoryDefinition) {
            return $this->convertFactory($argument);
        } elseif (is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index => $value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } elseif ($argument instanceof Reference) {
            return \DI\get($argument->getId());
        } else {
            return $argument;
        }
    }

    public function convertDefinition(Definition $definition)
    {
        $phpdi = \DI\create($definition->getClassName())
            ->constructor(...$this->resolveArgument($definition->getConstructorArguments()));
        if ($definition->islLazy()) {
            $phpdi->lazy();
        }
        foreach ($definition->getMethodCalls() as $methodCall) {
            $phpdi->method($methodCall->getMethodName(), ...$this->resolveArgument($methodCall->getArguments()));
        }
        return $phpdi;
    }

    private function convertFactory(FactoryDefinition $definition)
    {
        $factory = \DI\factory($definition->getFactory());
        [$class, $method] = $definition->getFactory();
        // Transform indexed factory to named factory
        $reflector = new ReflectionMethod($class, $method);
        $parameters = $reflector->getParameters();
        foreach ($definition->getArguments() as $index => $argument) {
            $p = $parameters[$index] ?? null;
            $factory->parameter($p ? $p->name : $index, $this->resolveArgument($argument));
        }
        return $factory;
    }

}
