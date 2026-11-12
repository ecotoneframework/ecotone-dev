<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\GatheringConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupOperations;
use Ecotone\Messaging\Handler\Gathering\GatheringConsoleCommands;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStoreRegistration;
use Ecotone\Messaging\Handler\Gathering\GatheringPartCompletion;
use Ecotone\Messaging\Handler\Gathering\GatheringTimeouts;
use Ecotone\Messaging\Handler\Gathering\InMemoryGatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\NullPartCompletion;
use Ecotone\Messaging\Handler\Gathering\PartCompletion;
use Ecotone\Messaging\Handler\Gathering\UngatheredSplitterProcessor;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;

#[ModuleAnnotation]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 */
final class GatheringModule extends NoExternalConfigurationModule
{
    public function __construct(private array $gatherChannelNames, private AnnotationFinder $annotationFinder)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $gatherChannelNames = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(Splitter::class) as $registration) {
            $splitter = $registration->getAnnotationForMethod();
            if ($splitter->gatherChannelName !== '') {
                $gatherChannelNames[] = $splitter->gatherChannelName;
            }
        }

        return new self($gatherChannelNames, $annotationRegistrationService);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $this->assertLicence($messagingConfiguration);
        $this->validateWiring($interfaceToCallRegistry);
        $this->refuseStaticallyUngatheredSplitters();
        $this->registerPartCompletion($messagingConfiguration);
        $this->registerStore($messagingConfiguration, $extensionObjects, $interfaceToCallRegistry);
    }

    private function assertLicence(Configuration $messagingConfiguration): void
    {
        if ($this->gatherChannelNames !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            $gatherChannelName = $this->gatherChannelNames[0];
            throw LicensingException::create("Splitter gather channel '{$gatherChannelName}' requires an Ecotone Enterprise licence. Provide a licence key through EcotoneLite::bootstrapFlowTesting(licenceKey: ...), or remove gatherChannelName from #[Splitter].");
        }
    }

    private function validateWiring(InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $gatheredChannels = [];
        foreach ($this->annotationFinder->findAnnotatedMethods(Gathered::class) as $registration) {
            $interface = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            if (array_filter($interface->getMethodAnnotations(), static fn (object $annotation): bool => $annotation instanceof InternalHandler || $annotation instanceof CommandHandler) === []) {
                $method = $registration->getMethodName();
                throw ConfigurationException::create("Method '{$method}' is marked #[Gathered] but has no ordinary message handler attribute. Stack it on #[InternalHandler] or #[CommandHandler], or remove #[Gathered].");
            }
            foreach ($interface->getMethodAnnotations() as $annotation) {
                if ($annotation instanceof InternalHandler || $annotation instanceof CommandHandler) {
                    $gatheredChannels[] = $annotation->getInputChannelName();
                    if (! $interface->hasFirstParameter() || ! $interface->getFirstParameter()->getTypeDescriptor()->equals(Type::create(GatheredParts::class))) {
                        $channel = $annotation->getInputChannelName();
                        throw ConfigurationException::create("Gathered handler '{$channel}' must receive Ecotone\\Api\\Messaging\\GatheredParts as its first payload parameter. Change that parameter type to GatheredParts, or remove #[Gathered] and the splitter's gatherChannelName.");
                    }
                }
                if (($annotation instanceof InternalHandler || $annotation instanceof CommandHandler) && ! in_array($annotation->getInputChannelName(), $this->gatherChannelNames, true)) {
                    $channel = $annotation->getInputChannelName();
                    throw ConfigurationException::create("Gathered handler '{$channel}' has no splitter pointing to it. Set gatherChannelName: '{$channel}' on a #[Splitter], or remove #[Gathered] from this handler.");
                }
                if (($annotation instanceof InternalHandler || $annotation instanceof CommandHandler) && ! $interface->hasAnnotation(Asynchronous::class)) {
                    $channel = $annotation->getInputChannelName();
                    throw ConfigurationException::create("Gathered handler '{$channel}' requires an asynchronous channel. Add #[Asynchronous('channelName')] to the handler, or remove #[Gathered] and the splitter's gatherChannelName.");
                }
            }
        }
        foreach ($this->gatherChannelNames as $channel) {
            if (! in_array($channel, $gatheredChannels, true)) {
                throw ConfigurationException::create("Splitter gather channel '{$channel}' must target a gathered handler. Define an #[InternalHandler] or #[CommandHandler] on '{$channel}' with #[Gathered] and #[Asynchronous], or remove gatherChannelName from #[Splitter].");
            }
        }
    }

    private function registerPartCompletion(Configuration $messagingConfiguration): void
    {
        $messagingConfiguration->registerServiceDefinition(NullPartCompletion::class, new Definition(NullPartCompletion::class));
        $messagingConfiguration->registerServiceDefinition(GatheringPartCompletion::class, new Definition(GatheringPartCompletion::class, [new Reference(ChannelResolver::class), new Reference(MessageBuilder::class)]));
        $messagingConfiguration->registerServiceDefinition(PartCompletion::class, LicenceDecider::prepareDefinition(PartCompletion::class, NullPartCompletion::class, GatheringPartCompletion::class));
    }

    private function registerStore(Configuration $messagingConfiguration, array $extensionObjects, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if ($this->gatherChannelNames !== []) {
            $configuration = ExtensionObjectResolver::resolveUnique(GatheringConfiguration::class, $extensionObjects, GatheringConfiguration::createWithDefaults());
            $timeouts = [];
            foreach ($this->annotationFinder->findAnnotatedMethods(Gathered::class) as $registration) {
                $gathered = $registration->getAnnotationForMethod();
                $interface = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName())->getMethodAnnotations();
                foreach ($interface as $annotation) {
                    if ($annotation instanceof InternalHandler || $annotation instanceof CommandHandler) {
                        $timeouts[$annotation->getInputChannelName()] = $gathered->timeout?->toMilliseconds();
                    }
                }
            }
            $messagingConfiguration->registerServiceDefinition(GatheringTimeouts::class, new Definition(GatheringTimeouts::class, [$timeouts]));
            $store = ExtensionObjectResolver::resolveUnique(GatheredGroupStoreRegistration::class, $extensionObjects, new GatheredGroupStoreRegistration(InMemoryGatheredGroupStore::class));
            $messagingConfiguration->registerServiceDefinition(InMemoryGatheredGroupStore::class, new Definition(InMemoryGatheredGroupStore::class, [new Reference(EcotoneClockInterface::class)]));
            $messagingConfiguration->registerServiceDefinition(GatheredGroupStore::class, new Reference($store->serviceReference));
            $messagingConfiguration->registerServiceDefinition(GatheredGroups::class, new Definition(GatheredGroupOperations::class, [new Reference(GatheredGroupStore::class), new Reference(EcotoneClockInterface::class), $configuration->retention->toMilliseconds(), new Reference(ChannelResolver::class), new Reference(MessageBuilder::class)]));
            $messagingConfiguration
                ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod(GatheredGroups::class, 'cleanupClosedGroups')->withInputChannelName('ecotone.gathering.cleanup'))
                ->registerConsoleCommand(ConsoleCommandConfiguration::create('ecotone.gathering.cleanup', 'ecotone:gathering:cleanup', [], 'Removes closed gathering groups after their retention period'));
            $this->registerConsoleCommands($messagingConfiguration, $interfaceToCallRegistry);
        }
    }

    private function registerConsoleCommands(Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $configuration->registerServiceDefinition(GatheringConsoleCommands::class, new Definition(GatheringConsoleCommands::class, [new Reference(GatheredGroups::class)]));
        foreach ([
            'listGroups' => ['list', 'Lists gathering group progress and late arrivals'],
            'releaseNow' => ['release', 'Requests release on the gathered handler asynchronous channel'],
            'discard' => ['discard', 'Discards a group without invoking its gathered handler'],
        ] as $method => [$commandName, $description]) {
            [$handler, $command] = ConsoleCommandModule::prepareConsoleCommandForReference(
                reference: new Reference(GatheringConsoleCommands::class),
                interfaceToCallReference: new InterfaceToCallReference(GatheringConsoleCommands::class, $method),
                commandName: 'ecotone:gathering:' . $commandName,
                discoverableByConsoleCommandAttribute: false,
                interfaceToCallRegistry: $interfaceToCallRegistry,
                description: $description,
            );
            $configuration->registerMessageHandler($handler)->registerConsoleCommand($command);
        }
    }

    private function refuseStaticallyUngatheredSplitters(): void
    {
        if ($this->gatherChannelNames === []) {
            return;
        }
        $outputs = [];
        $splitters = [];
        foreach ([Splitter::class, InternalHandler::class, CommandHandler::class, Transformer::class] as $attribute) {
            foreach ($this->annotationFinder->findAnnotatedMethods($attribute) as $registration) {
                $endpoint = $registration->getAnnotationForMethod();
                $outputs[$endpoint->getInputChannelName()][] = $endpoint->getOutputChannelName();
                if ($endpoint instanceof Splitter) {
                    $splitters[$endpoint->getInputChannelName()][] = $endpoint;
                }
            }
        }
        foreach ($this->annotationFinder->findAnnotatedMethods(Splitter::class) as $registration) {
            $splitter = $registration->getAnnotationForMethod();
            if ($splitter->gatherChannelName === '') {
                continue;
            }
            $pending = [$splitter->getOutputChannelName()];
            $visited = [];
            while ($pending !== []) {
                $channel = array_pop($pending);
                if ($channel === '' || isset($visited[$channel])) {
                    continue;
                }
                $visited[$channel] = true;
                foreach ($splitters[$channel] ?? [] as $nestedSplitter) {
                    if ($nestedSplitter->gatherChannelName === '') {
                        throw UngatheredSplitterProcessor::refusal($nestedSplitter->getEndpointId());
                    }
                }
                $pending = [...$pending, ...($outputs[$channel] ?? [])];
            }
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
