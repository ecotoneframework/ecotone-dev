<?php

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Ecotone\Messaging\Config\FrameworkConfigurationKeys;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

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
                    ->booleanNode('test')
                        ->defaultFalse()
                    ->end()

                    ->booleanNode('loadSrcNamespaces')
                        ->defaultTrue()
                    ->end()

                    ->arrayNode('namespaces')
                        ->scalarPrototype()
                        ->end()
                    ->end()

                    ->scalarNode('licenceKey')
                        ->defaultNull()
                    ->end()

                    ->arrayNode(FrameworkConfigurationKeys::ENABLED_MODULE_PACKAGE_NAMES)
                        ->scalarPrototype()
                    ->end()
                ->end()
        ;

        return $treeBuilder;
    }

    /**
     * @return string[]
     */
    public function getRecognisedKeys(): array
    {
        $tree = $this->getConfigTreeBuilder()->buildTree();

        return $tree instanceof ArrayNode ? array_keys($tree->getChildren()) : [];
    }
}
