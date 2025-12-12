<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant\Module;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Modelling\QueryBus;
use Psr\Container\ContainerInterface;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
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
                                array_keys($multiTenantConfig->getTenantToConnectionMapping()),
                            ]
                        ),
                        $multiTenantConfig->getDefaultConnectionName(),
                    ]
                )
            );
            if (count($multiTenantConfigurations) === 1) {
                $messagingConfiguration->registerServiceDefinition(
                    MultiTenantConnectionFactory::class,
                    new Reference($multiTenantConfig->getReferenceName())
                );
            }

            $interfaceToCall = $interfaceToCallRegistry->getFor(HeaderBasedMultiTenantConnectionFactory::class, 'enablePollingConsumerPropagation');
            /** Register interceptors to enable tenant when polling (no metadata context available) */
            $messagingConfiguration->registerBeforeMethodInterceptor(MethodInterceptorBuilder::create(
                Reference::to($multiTenantConfig->getReferenceName()),
                $interfaceToCall,
                0,
                MessageHeadersPropagatorInterceptor::class . '::' . 'enablePollingConsumerPropagation'
            ));

            $interfaceToCall = $interfaceToCallRegistry->getFor(HeaderBasedMultiTenantConnectionFactory::class, 'disablePollingConsumerPropagation');
            $messagingConfiguration->registerBeforeMethodInterceptor(MethodInterceptorBuilder::create(
                Reference::to($multiTenantConfig->getReferenceName()),
                $interfaceToCall,
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
                    CommandBus::class . '||' .
                    QueryBus::class . '||' .
                    EventBus::class . '||' .
                    AsynchronousRunningEndpoint::class  . '||' .
                    PropagateHeaders::class  . '||' .
                    MessagingEntrypointWithHeadersPropagation::class . '||' .
                    MessageGateway::class
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
        return ModulePackageList::DBAL_PACKAGE;
    }
}
