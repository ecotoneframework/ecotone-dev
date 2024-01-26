<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Psr\Container\ContainerInterface;

#[ModuleAnnotation]
final class MultiTenantConnectionFactoryModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);

        foreach ($multiTenantConfigurations as $multiTenantConfig) {
            $messagingConfiguration->registerServiceDefinition(
                $multiTenantConfig->getReferenceName(),
                new Definition(
                    HeaderBasedMultiTenantConnectionFactory::class,
                    [
                        $multiTenantConfig->getTenantHeaderName(),
                        $multiTenantConfig->getTenantToConnectionMapping(),
                        Reference::to(MessagingEntrypoint::class),
                        Reference::to(ContainerInterface::class),
                        new Definition(
                            RoundRobinReceivingStrategy::class,
                            [
                                array_keys($multiTenantConfig->getTenantToConnectionMapping())
                            ]
                        ),
                        $multiTenantConfig->getDefaultConnectionName()
                    ]
                )
            );
            if (count($multiTenantConfigurations) === 1) {
                $messagingConfiguration->registerServiceAlias(
                    MultiTenantConnectionFactory::class,
                    new Reference($multiTenantConfig->getReferenceName())
                );
            }

            $interfaceToCall = $interfaceToCallRegistry->getFor(HeaderBasedMultiTenantConnectionFactory::class, 'enablePollingConsumerPropagation');
            $messagingConfiguration->registerBeforeMethodInterceptor(MethodInterceptor::create(
                $multiTenantConfig->getReferenceName() . '.' . 'enablePollingConsumerPropagation',
                $interfaceToCall,
                ServiceActivatorBuilder::create($multiTenantConfig->getReferenceName(), $interfaceToCall),
                0,
                MessageHeadersPropagatorInterceptor::class . '::' . 'enablePollingConsumerPropagation'
            ));

            $interfaceToCall = $interfaceToCallRegistry->getFor(HeaderBasedMultiTenantConnectionFactory::class, 'disablePollingConsumerPropagation');
            $messagingConfiguration->registerBeforeMethodInterceptor(MethodInterceptor::create(
                $multiTenantConfig->getReferenceName() . '.' . 'disablePollingConsumerPropagation',
                $interfaceToCall,
                ServiceActivatorBuilder::create($multiTenantConfig->getReferenceName(), $interfaceToCall),
                0,
                MessageHeadersPropagatorInterceptor::class . '::' . 'disablePollingConsumerPropagation'
            ));
        }
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof MultiTenantConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}