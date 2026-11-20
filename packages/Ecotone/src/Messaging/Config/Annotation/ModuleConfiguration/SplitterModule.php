<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\Splitter\SplitterBuilder;

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
            || $registration->hasMethodAnnotation(InternalHandler::class);
    }

    public static function registerHandlerOutput(AnnotatedFinding $registration, Configuration $configuration): string
    {
        $handler = $registration->getAnnotationForMethod();
        if (! $registration->hasMethodAnnotation(Splitter::class)) {
            return $handler->getOutputChannelName();
        }
        $channel = 'ecotone.splitter.' . $handler->getEndpointId();
        $configuration->registerMessageHandler(
            SplitterBuilder::createMessagePayloadSplitter()
                ->withInputChannelName($channel)
                ->withEndpointId($handler->getEndpointId() . '.splitter')
                ->withOutputMessageChannel($handler->getOutputChannelName())
        );
        return $channel;
    }

    /**
     * @inheritDoc
     */
    public static function createMessageHandlerFrom(AnnotatedFinding $annotationRegistration, InterfaceToCallRegistry $interfaceToCallRegistry): MessageHandlerBuilderWithParameterConverters
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

    /**
     * @inheritDoc
     */
    public static function getMessageHandlerAnnotation(): string
    {
        return Splitter::class;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
