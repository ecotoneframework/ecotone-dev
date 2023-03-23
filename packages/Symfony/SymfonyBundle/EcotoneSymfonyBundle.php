<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\LazyConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\EcotoneCompilerPass;
use Ecotone\SymfonyBundle\DepedencyInjection\EcotoneExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class IntegrationMessagingBundle
 * @package Ecotone\SymfonyBundle
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class EcotoneSymfonyBundle extends Bundle
{
    public const CONFIGURED_MESSAGING_SYSTEM                 = ConfiguredMessagingSystem::class;
    public const APPLICATION_CONFIGURATION_CONTEXT   = 'messaging_system_application_context';

    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new EcotoneCompilerPass());

        $this->setUpExpressionLanguage($container);

        $definition = new Definition();
        $definition->setClass(LazyConfiguredMessagingSystem::class);
        $definition->addArgument(new Reference('service_container'));
        $definition->setPublic(true);
        $container->setDefinition(ConfiguredMessagingSystem::class, $definition);

        $definition = new Definition();
        $definition->setClass(ConfiguredMessagingSystem::class);
        $definition->setSynthetic(true);
        $definition->setPublic(true);
        $container->setDefinition(LazyConfiguredMessagingSystem::class, $definition);
    }

    /**
     * @param ContainerBuilder $container
     * @return void
     */
    private function setUpExpressionLanguage(ContainerBuilder $container): void
    {
        $expressionLanguageAdapter = ExpressionEvaluationService::REFERENCE . '_adapter';
        $definition = new Definition();
        $definition->setClass(ExpressionLanguage::class);

        $container->setDefinition($expressionLanguageAdapter, $definition);

        $definition = new Definition();
        $definition->setClass(SymfonyExpressionEvaluationAdapter::class);
        $definition->setFactory([SymfonyExpressionEvaluationAdapter::class, 'createWithExternalExpressionLanguage']);
        $definition->addArgument(new Reference($expressionLanguageAdapter));
        $definition->setPublic(true);
        $container->setDefinition(ExpressionEvaluationService::REFERENCE, $definition);
    }

    public function boot()
    {
        $configuration = EcotoneCompilerPass::getMassagingConfiguration($this->container, true);

        $messagingSystem = $configuration->buildMessagingSystemFromConfiguration($this->container->get('symfonyReferenceSearchService'));

        $this->container->set(LazyConfiguredMessagingSystem::class, $messagingSystem);
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new EcotoneExtension();
    }
}
