<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;

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
final class CollectingModule extends NoExternalConfigurationModule
{
    public function __construct(private AnnotationFinder $annotationFinder)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->annotationFinder->findAnnotatedMethods(Collected::class) as $registration) {
            $interface = $interfaceToCallRegistry->getFor($registration->getClassName(), $registration->getMethodName());
            $handlers = array_values(array_filter($interface->getMethodAnnotations(), static fn (object $attribute): bool => $attribute instanceof InternalHandler || $attribute instanceof CommandHandler || $attribute instanceof EventHandler));
            $channel = $handlers === [] ? $registration->getMethodName() : ($handlers[0] instanceof EventHandler ? $handlers[0]->listenTo : $handlers[0]->getInputChannelName());
            if ($interface->hasMethodAnnotation(Gathered::class)) {
                throw ConfigurationException::create("Collected handler '{$channel}' also has #[Gathered]: collecting ordinary messages and gathering split parts cannot share a handler. Remove #[Gathered] to collect ordinary messages, or remove #[Collected] to gather split parts.");
            }
            if ($interface->hasClassAnnotation(Aggregate::class)) {
                throw ConfigurationException::create("Collected handler '{$channel}' belongs to an aggregate or saga, but a batch has no single instance to load. Move #[Collected] to a service #[CommandHandler], #[EventHandler] or #[InternalHandler], or remove #[Collected].");
            }
            if ($interface->hasAnnotation(QueryHandler::class)) {
                $method = $registration->getMethodName();
                throw ConfigurationException::create("Method '{$method}' is marked #[Collected] but is not an allowed message handler: a query caller waits for an answer. Stack #[Collected] on a service #[CommandHandler], #[EventHandler] or #[InternalHandler] with #[Asynchronous], or remove #[Collected].");
            }
            if ($handlers === []) {
                $method = $registration->getMethodName();
                throw ConfigurationException::create("Method '{$method}' is marked #[Collected] but has no ordinary message handler attribute. Stack it on a service #[CommandHandler], #[EventHandler] or #[InternalHandler], or remove #[Collected].");
            }
            foreach ($handlers as $handler) {
                if ($handler instanceof CommandHandler && $handler->getInputChannelName() === '') {
                    $endpointId = $handler->getEndpointId();
                    throw ConfigurationException::create("Collected handler '{$endpointId}' cannot infer its subscription from the batch parameter. Set routingKey on #[CommandHandler], or remove #[Collected].");
                }
                if ($handler instanceof EventHandler && $handler->listenTo === '') {
                    $endpointId = $handler->getEndpointId();
                    throw ConfigurationException::create("Collected handler '{$endpointId}' cannot infer its subscription from the batch parameter. Set listenTo on #[EventHandler], or remove #[Collected].");
                }
            }
            if (! $interface->hasAnnotation(Asynchronous::class)) {
                throw ConfigurationException::create("Collected handler '{$channel}' requires an asynchronous channel. Add #[Asynchronous('channelName')] to the handler, or remove #[Collected].");
            }
            $collected = $registration->getAnnotationForMethod();
            if ($collected->maxSize === null && $collected->maxWait === null) {
                throw ConfigurationException::create("Collected handler '{$channel}' needs a release limit. Set maxSize or maxWait on #[Collected], or remove #[Collected].");
            }
            if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
                throw LicensingException::create("Collected handler '{$channel}' requires an Ecotone Enterprise licence. Provide licenceKey in the framework configuration or EcotoneLite, start a trial at https://ecotone.tech/pricing, or remove #[Collected].");
            }
            if (! $interface->hasFirstParameter() || ! $interface->getFirstParameter()->getTypeDescriptor()->equals(Type::create(CollectedMessages::class))) {
                throw ConfigurationException::create("Collected handler '{$channel}' must receive Ecotone\\Api\\Messaging\\CollectedMessages as its first payload parameter. Change that parameter type to CollectedMessages, or remove #[Collected].");
            }
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
