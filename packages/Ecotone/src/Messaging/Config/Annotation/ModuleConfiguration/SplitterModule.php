<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ChangingHeaders;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;
use Ecotone\Messaging\Handler\Splitter\DirectMessageSplitter;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionResolver;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class SplitterModule extends NoExternalConfigurationModule
{
    private function __construct(private array $standaloneSplitters)
    {
    }

    public static function create(AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $splitters = [];
        foreach ($annotationFinder->findAnnotatedMethods(Splitter::class) as $registration) {
            if (self::isStacked($registration)) {
                continue;
            }
            $splitters[] = self::createMessageHandlerFrom($registration, $interfaceToCallRegistry)
                ->withMethodParameterConverters(ParameterConverterAnnotationFactory::create()->createParameterWithDefaults(
                    $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName())
                ));
        }
        return new self($splitters);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->standaloneSplitters as $splitter) {
            $messagingConfiguration->registerMessageHandler($splitter);
        }
    }

    public static function isStacked(AnnotatedFinding $registration): bool
    {
        return $registration->hasMethodAnnotation(CommandHandler::class)
            || $registration->hasMethodAnnotation(EventHandler::class)
            || $registration->hasMethodAnnotation(InternalHandler::class)
            || $registration->hasMethodAnnotation(QueryHandler::class);
    }

    public static function validate(AnnotatedFinding $registration, InterfaceToCallRegistry $registry): void
    {
        $name = $registration->getClassName() . '::' . $registration->getMethodName();
        if ($registration->hasMethodAnnotation(QueryHandler::class)) {
            throw ConfigurationException::create("Split handler {$name} is a query handler whose caller expects one reply. Use #[CommandHandler], #[EventHandler] or #[InternalHandler] with outputChannelName, move #[Splitter] to a separate method, or remove #[Splitter].");
        }
        if ($registration->hasClassAnnotation(Projection::class)) {
            throw ConfigurationException::create("Split handler {$name} belongs to a projection whose events are replayed during rebuild and backfill. Move the split to a separate event handler outside the projection, or remove #[Splitter].");
        }
        $splitter = $registration->getAnnotationForMethod();
        if ($splitter->getInputChannelName() !== '') {
            $parameters = [];
            foreach ($registration->getMethodAnnotations() as $handler) {
                $parameter = match (true) {
                    $handler instanceof CommandHandler => 'routingKey on #[CommandHandler]',
                    $handler instanceof EventHandler => 'listenTo on #[EventHandler]',
                    $handler instanceof InternalHandler => 'inputChannelName on #[InternalHandler]',
                    default => null,
                };
                if ($parameter !== null) {
                    $parameters[] = $parameter;
                }
            }
            $parameters = implode(' and ', array_unique($parameters));
            throw ConfigurationException::create("Split handler {$name} sets inputChannelName on #[Splitter]. Remove inputChannelName from #[Splitter] and configure {$parameters}, or move #[Splitter] to a separate method.");
        }
        foreach ([
            'endpointId' => ! $splitter->isEndpointIdGenerated(),
            'outputChannelName' => $splitter->getOutputChannelName() !== '',
        ] as $setting => $isSet) {
            if ($isSet) {
                throw ConfigurationException::create("Split handler {$name} sets {$setting} on #[Splitter]. Remove {$setting} from #[Splitter] and configure it on the handler, or move #[Splitter] to a separate method.");
            }
        }

        if ($splitter->getRequiredInterceptorNames() !== []) {
            throw ConfigurationException::create("Split handler {$name} sets requiredInterceptorNames on #[Splitter]. Move the names to the handler attribute requiredInterceptorNames so they run once around the handler, or move #[Splitter] to a separate method.");
        }
        foreach ($registration->getMethodAnnotations() as $handler) {
            if ($registration->hasMethodAnnotation(ChangingHeaders::class) || ($handler instanceof InternalHandler && $handler->changingHeaders)) {
                throw ConfigurationException::create("Split handler {$name} changes headers instead of returning output messages. Remove #[ChangingHeaders] or set changingHeaders: false, move the split to a separate handler, or remove #[Splitter].");
            }
        }
        $interface = $registry->getFor($registration->getClassName(), $registration->getMethodName());
        if ($registration->hasClassAnnotation(Aggregate::class) && $interface->isFactoryMethod()) {
            throw ConfigurationException::create("Split handler {$name} is an aggregate or saga factory whose output contains saved identifiers. Split from an action method, use a separate splitter handler receiving the factory output, or remove #[Splitter].");
        }
        if ($registration->hasClassAnnotation(Aggregate::class)
            && AggregateDefinitionResolver::resolve($registration->getClassName(), $registry)->isPureEventSourcedAggregate()) {
            throw ConfigurationException::create("Split handler {$name} returns events that Ecotone records for an event-sourced aggregate or saga. Record events with #[AggregateEvents] and return an independent iterable result, use a separate splitter handler, or remove #[Splitter].");
        }
        if (! $interface->doesItReturnIterable()) {
            throw ConfigurationException::create("Split handler {$name} does not declare an iterable return type. Return an array or iterable of output messages, move #[Splitter] to a separate iterable handler, or remove #[Splitter].");
        }
        foreach ($registration->getMethodAnnotations() as $handler) {
            if (! $handler instanceof CommandHandler && ! $handler instanceof EventHandler && ! $handler instanceof InternalHandler) {
                continue;
            }
            $attribute = match (true) {
                $handler instanceof CommandHandler => 'CommandHandler',
                $handler instanceof EventHandler => 'EventHandler',
                default => 'InternalHandler',
            };
            $route = $handler instanceof EventHandler ? $handler->listenTo : $handler->getInputChannelName();
            $description = "#[{$attribute}('{$route}')]";
            if ($splitter->gatherChannelName !== '' && $handler->isEndpointIdGenerated()) {
                throw ConfigurationException::create("Split handler {$name} uses gatherChannelName without an explicit endpointId on {$description}. Set endpointId on every handler attribute, or remove gatherChannelName from #[Splitter].");
            }
            if ($handler->getOutputChannelName() === '') {
                throw ConfigurationException::create("Split handler {$name} has no outputChannelName on {$description}. Set outputChannelName on every handler attribute, or remove #[Splitter].");
            }
        }
    }

    public static function outputChannelOf(AnnotatedFinding $registration): string
    {
        $handler = $registration->getAnnotationForMethod();
        return $registration->hasMethodAnnotation(Splitter::class)
            ? 'ecotone.splitter.' . $handler->getEndpointId()
            : $handler->getOutputChannelName();
    }

    public static function registerHiddenSplitterOf(AnnotatedFinding $registration, Configuration $configuration): void
    {
        $handler = $registration->getAnnotationForMethod();
        if (! $registration->hasMethodAnnotation(Splitter::class)) {
            return;
        }
        $splitter = $registration->getMethodAnnotationsWithType(Splitter::class)[0];
        $channel = self::outputChannelOf($registration);
        $configuration->registerMessageHandler(
            SplitterBuilder::createWithDefinition(DirectMessageSplitter::class, 'splitIterable')
                ->withoutInheritedTypeId()
                ->withoutMethodInterceptors()
                ->withGatherChannelName($splitter->gatherChannelName)
                ->withInputChannelName($channel)
                ->withEndpointId($handler->getEndpointId() . '.splitter')
                ->withOutputMessageChannel($handler->getOutputChannelName())
        );
    }

    private static function createMessageHandlerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): MessageHandlerBuilderWithParameterConverters
    {
        /** @var Splitter $annotation */
        $annotation = $annotationRegistration->getAnnotationForMethod();

        return
            SplitterBuilder::create(
                AnnotatedDefinitionReference::getReferenceFor($annotationRegistration),
                $interfaceToCallRegistry->getFor($annotationRegistration->getClassName(), $annotationRegistration->getMethodName())
            )
                ->withGatherChannelName($annotation->gatherChannelName)
                ->withEndpointId($annotation->getEndpointId())
                ->withInputChannelName($annotation->getInputChannelName())
                ->withOutputMessageChannel($annotation->getOutputChannelName())
                ->withRequiredInterceptorNames($annotation->getRequiredInterceptorNames());
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
