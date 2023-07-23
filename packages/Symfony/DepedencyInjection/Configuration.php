<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @TODO Ecotone 2.0 - make use only ServiceContext for setting up ServiceConfiguration and remove Symfony and Laravel global configuration
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('ecotone');


        return $treeBuilder
            ->getRootNode()
                ->children()
                    ->scalarNode('serviceName')
                        ->defaultNull()
                    ->end()

                    ->booleanNode('failFast')
                    ->defaultTrue()
                    ->end()

                    ->booleanNode('loadSrcNamespaces')
                    ->defaultTrue()
                    ->end()

                    ->scalarNode('defaultSerializationMediaType')
                    ->defaultNull()
                    ->end()

                    ->scalarNode('defaultErrorChannel')
                    ->defaultNull()
                    ->end()


                    ->arrayNode('namespaces')
                        ->scalarPrototype()
                        ->end()
                    ->end()

                    ->integerNode('defaultMemoryLimit')
                    ->defaultNull()
                    ->end()

                    ->arrayNode('defaultConnectionExceptionRetry')
                        ->children()
                            ->integerNode('initialDelay')
                            ->isRequired()
                            ->end()

                            ->integerNode('maxAttempts')
                            ->isRequired()
                            ->end()

                            ->integerNode('multiplier')
                            ->isRequired()
                            ->end()
                            ->end()
                    ->end()

                    ->arrayNode('skippedModulePackageNames')
                        ->scalarPrototype()
                        ->end()
                    ->end()

                ->end()
            ->end();
    }
}
