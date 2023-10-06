<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use DI\ContainerBuilder;
use DI\Factory\RequestedEntry;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;

class PhpDiContainerBuilder implements ContainerImplementation
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

        foreach ($definitions as $id => $definition) {
            $phpDiDefinitions[$id] = $this->resolveArgument($definition);
        }

        foreach ($externalReferences as $id => $reference) {
            if ($reference instanceof ChannelReference) {
                $phpDiDefinitions[$id] = static function (ContainerInterface $c, RequestedEntry $entry) {
                    $channelName = substr($entry->getName(), 8); // remove `channel-` prefix
                    return $c->get(ChannelResolver::class)->resolve($channelName);
                };
            } else {
                $phpDiDefinitions[$id] = static function (ContainerInterface $c, RequestedEntry $entry) {
                    return $c->get("external_reference_search_service")->get($entry->getName());
                };
            }
        }

        $this->containerBuilder->addDefinitions($phpDiDefinitions);
    }

    private function resolveArgument($argument): mixed
    {
        if ($argument instanceof Definition) {
            return $this->convertDefinition($argument);
        } else if (\is_array($argument)) {
            $resolvedArguments = [];
            foreach ($argument as $index =>$value) {
                $resolvedArguments[$index] = $this->resolveArgument($value);
            }
            return $resolvedArguments;
        } else if ($argument instanceof Reference) {
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
        return $phpdi;
    }

}