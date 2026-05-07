<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant\Module;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantHeaderResolver;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ChannelAdapter;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Support\LicensingException;
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
    /**
     * @param array<int, string> $tenantResolverPlacements
     * @param array<int, string> $invalidTenantResolverPlacements
     */
    private function __construct(
        private array $tenantResolverPlacements,
        private array $invalidTenantResolverPlacements,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $allPlacements = [];
        $invalid = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(WithTenantResolver::class) as $annotatedMethod) {
            $location = $annotatedMethod->getClassName() . '::' . $annotatedMethod->getMethodName();
            $allPlacements[] = $location;

            $isOnInboundAdapter = false;
            foreach ($annotatedMethod->getMethodAnnotations() as $annotation) {
                if ($annotation instanceof ChannelAdapter || $annotation instanceof MessageConsumer) {
                    $isOnInboundAdapter = true;
                    break;
                }
            }
            if (! $isOnInboundAdapter) {
                $invalid[] = $location;
            }
        }

        return new self($allPlacements, $invalid);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->invalidTenantResolverPlacements !== []) {
            throw ConfigurationException::create(sprintf(
                "WithTenantResolver attribute on %s is invalid. WithTenantResolver may only be applied to inbound channel adapter methods (e.g. #[KafkaConsumer], #[AmqpConsumer], #[Scheduled]) where messages may arrive from outside the application without a tenant header. Internal Message Channels — including those used by synchronous and asynchronous CommandHandler / EventHandler / QueryHandler / ServiceActivator handlers — already carry the tenant context propagated from the originating bus call, so there is no header to derive there. If an asynchronous handler is processing externally-arrived messages, attach #[WithTenantResolver] to the inbound channel adapter that produces those messages, not to the handler.",
                implode(', ', $this->invalidTenantResolverPlacements)
            ));
        }

        if ($this->tenantResolverPlacements !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                'WithTenantResolver attribute on %s requires Ecotone Enterprise licence.',
                implode(', ', $this->tenantResolverPlacements)
            ));
        }

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
                        Reference::to(MessagingEntrypointService::class),
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
                    MessageGateway::class
                )
            );

            $resolverReference = 'multi_tenant_header_resolver.' . $multiTenantConfig->getReferenceName();
            $messagingConfiguration->registerServiceDefinition(
                $resolverReference,
                new Definition(
                    MultiTenantHeaderResolver::class,
                    [
                        $multiTenantConfig->getTenantHeaderName(),
                        Reference::to(ExpressionEvaluationService::REFERENCE),
                    ]
                )
            );

            $messagingConfiguration->registerBeforeMethodInterceptor(
                MethodInterceptorBuilder::create(
                    Reference::to($resolverReference),
                    $interfaceToCallRegistry->getFor(MultiTenantHeaderResolver::class, 'resolve'),
                    Precedence::DEFAULT_PRECEDENCE,
                    WithTenantResolver::class,
                    true
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
