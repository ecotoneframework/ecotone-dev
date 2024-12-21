<?php

namespace Ecotone\Modelling\Config;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Attribute\InputOutputEndpointAnnotation;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\StreamBasedSource;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\PriorityBasedOnType;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Bridge\BridgeBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\Transformer\TransformerBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\ChangingHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventSourcingExecutor\EnterpriseAggregateMethodInvoker;
use Ecotone\Modelling\EventSourcingExecutor\OpenCoreAggregateMethodInvoker;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class ServiceHandlerModule implements AnnotationModule
{
    private ParameterConverterAnnotationFactory $parameterConverterAnnotationFactory;
    /**
     * @var AnnotatedFinding[]
     */
    private array $serviceCommandHandlers;
    /**
     * @var AnnotatedFinding[]
     */
    private array $serviceQueryHandlers;
    /**
     * @var AnnotatedFinding[]
     */
    private array $serviceEventHandlers;

    private function __construct(
        array                               $serviceCommandHandlersRegistrations,
        array                               $serviceQueryHandlerRegistrations,
        array                               $serviceEventHandlers,
    )
    {
        $this->serviceCommandHandlers = $serviceCommandHandlersRegistrations;
        $this->serviceQueryHandlers = $serviceQueryHandlerRegistrations;
        $this->serviceEventHandlers = $serviceEventHandlers;
    }

    /**
     * In here we should provide messaging component for module
     *
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            array_filter(
                $annotationRegistrationService->findAnnotatedMethods(CommandHandler::class),
                function (AnnotatedFinding $annotatedFinding) {
                    return !$annotatedFinding->hasClassAnnotation(Aggregate::class);
                }
            ),
            array_filter(
                $annotationRegistrationService->findAnnotatedMethods(QueryHandler::class),
                function (AnnotatedFinding $annotatedFinding) {
                    return !$annotatedFinding->hasClassAnnotation(Aggregate::class);
                }
            ),
            array_filter(
                $annotationRegistrationService->findAnnotatedMethods(EventHandler::class),
                function (AnnotatedFinding $annotatedFinding) {
                    return !$annotatedFinding->hasClassAnnotation(Aggregate::class);
                }
            ),
        );
    }

    public static function getHandlerChannel(AnnotatedFinding $registration): string
    {
        /** @var EndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        return $annotationForMethod->getEndpointId() . '.target';
    }

    public static function getPayloadClassIfAny(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): ?string
    {
        $type = TypeDescriptor::create(AggregrateHandlerModule::getMessagePayloadTypeFor($registration, $interfaceToCallRegistry));

        if ($type->isClassOrInterface() && !$type->isClassOfType(TypeDescriptor::create(Message::class))) {
            return $type->toString();
        }

        return null;
    }

    public static function getEventPayloadClasses(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $type = TypeDescriptor::create(AggregrateHandlerModule::getMessagePayloadTypeFor($registration, $interfaceToCallRegistry));
        if ($type->isClassOrInterface() && !$type->isClassOfType(TypeDescriptor::create(Message::class))) {
            if ($type->isUnionType()) {
                return array_map(fn(TypeDescriptor $type) => $type->toString(), $type->getUnionTypes());
            }

            return [$type->toString()];
        }

        return [];
    }

    public static function hasMessageNameDefined(AnnotatedFinding $registration): bool
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        } else {
            $inputChannelName = $annotationForMethod->getInputChannelName();
        }

        return $inputChannelName ? true : false;
    }

    public static function getNamedMessageChannelForEventHandler(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): string
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        $inputChannelName = null;
        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        }

        if (!$inputChannelName) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            if ($interfaceToCall->hasNoParameters()) {
                throw ConfigurationException::create("Missing command class or listen routing for {$registration}.");
            }
            $inputChannelName = $interfaceToCall->getFirstParameterTypeHint();
        }

        return $inputChannelName;
    }

    public static function getNamedMessageChannelFor(AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry): string
    {
        /** @var InputOutputEndpointAnnotation $annotationForMethod */
        $annotationForMethod = $registration->getAnnotationForMethod();

        if ($annotationForMethod instanceof EventHandler) {
            $inputChannelName = $annotationForMethod->getListenTo();
        } else {
            $inputChannelName = $annotationForMethod->getInputChannelName();
        }

        if (!$inputChannelName) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            if ($interfaceToCall->hasNoParameters()) {
                throw ConfigurationException::create("Missing class type hint or routing key for {$registration}.");
            }
            if ($interfaceToCall->getFirstParameter()->getTypeDescriptor()->isUnionType()) {
                throw ConfigurationException::create("Query and Command handlers can not be registered with union Command type in {$registration}");
            }
            $inputChannelName = $interfaceToCall->getFirstParameterTypeHint();
        }

        return $inputChannelName;
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $moduleExtensions, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            $messagingConfiguration->registerServiceDefinition(\Ecotone\Messaging\Config\Container\Reference::to(EnterpriseAggregateMethodInvoker::class), new Definition(EnterpriseAggregateMethodInvoker::class));
        } else {
            $messagingConfiguration->registerServiceDefinition(\Ecotone\Messaging\Config\Container\Reference::to(OpenCoreAggregateMethodInvoker::class), new Definition(OpenCoreAggregateMethodInvoker::class));
        }

        foreach ($this->serviceCommandHandlers as $registration) {
            $this->registerServiceHandler(self::getNamedMessageChannelFor($registration, $interfaceToCallRegistry), $messagingConfiguration, $registration, $interfaceToCallRegistry, false);
        }
        foreach ($this->serviceQueryHandlers as $registration) {
            $this->registerServiceHandler(self::getNamedMessageChannelFor($registration, $interfaceToCallRegistry), $messagingConfiguration, $registration, $interfaceToCallRegistry, false);
        }
        foreach ($this->serviceEventHandlers as $registration) {
            $this->registerServiceHandler(self::getNamedMessageChannelForEventHandler($registration, $interfaceToCallRegistry), $messagingConfiguration, $registration, $interfaceToCallRegistry, $registration->hasClassAnnotation(StreamBasedSource::class));
        }
    }

    private function registerServiceHandler(string $inputChannelName, Configuration $configuration, AnnotatedFinding $registration, InterfaceToCallRegistry $interfaceToCallRegistry, bool $isStreamBasedSource): void
    {
        /** @var QueryHandler|CommandHandler|EventHandler $methodAnnotation */
        $methodAnnotation = $registration->getAnnotationForMethod();
        $endpointInputChannel = self::getHandlerChannel($registration);
        $parameterConverterAnnotationFactory = ParameterConverterAnnotationFactory::create();

        $relatedClassInterface = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
        $parameterConverters = $parameterConverterAnnotationFactory->createParameterWithDefaults($relatedClassInterface);

        $configuration->registerDefaultChannelFor(SimpleMessageChannelBuilder::createPublishSubscribeChannel($inputChannelName));
        /**
         * We want to connect Event Handler directly to Event Bus channel only if it's not fetched from Stream Based Source.
         * This allows to connecting Event Handlers via Projection Event Handler that lead the way.
         */
        if (!$isStreamBasedSource) {
            $configuration->registerMessageHandler(
                BridgeBuilder::create()
                    ->withInputChannelName($inputChannelName)
                    ->withOutputMessageChannel($endpointInputChannel)
                    ->withEndpointAnnotations([PriorityBasedOnType::fromAnnotatedFinding($registration)->toAttributeDefinition()])
            );
        }

        $handler = $registration->hasMethodAnnotation(ChangingHeaders::class)
            ? TransformerBuilder::create(AnnotatedDefinitionReference::getReferenceFor($registration), $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName()))
            : ServiceActivatorBuilder::create(AnnotatedDefinitionReference::getReferenceFor($registration), $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName()));

        $configuration->registerMessageHandler(
            $handler
                ->withInputChannelName($endpointInputChannel)
                ->withOutputMessageChannel($methodAnnotation->getOutputChannelName())
                ->withEndpointId($methodAnnotation->getEndpointId())
                ->withMethodParameterConverters($parameterConverters)
                ->withRequiredInterceptorNames($methodAnnotation->getRequiredInterceptorNames())
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}