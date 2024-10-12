<?php

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @TODO Ecotone 2.0 - make use only ServiceContext for setting up ServiceConfiguration and remove Symfony and Laravel global configuration
 */
/**
 * licence Apache-2.0
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ecotone');

        $treeBuilder
            ->getRootNode()
                ->children()
                    ->scalarNode('serviceName')
                        ->defaultNull()
                    ->end()

                    ->booleanNode('cacheConfiguration')
                        ->defaultFalse()
                    ->end()

                    ->booleanNode('failFast')
                        ->defaultFalse()
                    ->end()

                    ->booleanNode('test')
                        ->defaultFalse()
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

                    ->scalarNode('licenceKey')
                        ->defaultNull()
                    ->end()

                    ->arrayNode('skippedModulePackageNames')
                        ->scalarPrototype()
                    ->end()
                ->end()
        ;

        return $treeBuilder;
    }
}
