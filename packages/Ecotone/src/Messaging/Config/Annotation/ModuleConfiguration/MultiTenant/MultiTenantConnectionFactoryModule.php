<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\MultiTenant;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConfiguration;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Modelling\QueryBus;
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
        $messagingConfiguration->registerMessageChannel(
            SimpleMessageChannelBuilder::createPublishSubscribeChannel(HeaderBasedMultiTenantConnectionFactory::TENANT_ACTIVATED_CHANNEL_NAME)
        );
        $messagingConfiguration->registerMessageChannel(
            SimpleMessageChannelBuilder::createPublishSubscribeChannel(HeaderBasedMultiTenantConnectionFactory::TENANT_DEACTIVATED_CHANNEL_NAME)
        );

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
                        Reference::to(LoggingGateway::class),
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
            /** Register interceptors to enable tenant when polling (no metadata context available) */
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

            /** Register interceptor to propagate current active tenant */
            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    $multiTenantConfig->getReferenceName(),
                    $interfaceToCallRegistry->getFor(
                        HeaderBasedMultiTenantConnectionFactory::class,
                        'propagateTenant'
                    ),
                    Precedence::BETWEEN_INSTANT_RETRY_AND_TRANSACTION_PRECEDENCE,
                    CommandBus::class . '||' . EventBus::class . '||' . QueryBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . PropagateHeaders::class . '||' . MessagingEntrypointWithHeadersPropagation::class,
                )
            );
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