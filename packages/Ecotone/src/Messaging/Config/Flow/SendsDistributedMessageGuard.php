<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\Attribute\Presend;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\Attribute\SendsDistributedMessage;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\ConfigurationException;

final class SendsDistributedMessageGuard
{
    private const SCANNED_ATTRIBUTES = [CommandHandler::class, EventHandler::class, QueryHandler::class, InternalHandler::class, Router::class, Splitter::class, Transformer::class, Orchestrator::class, Scheduled::class, Presend::class, Before::class, Around::class, After::class];

    /**
     * @param object[] $extensionObjects
     */
    public static function check(AnnotationFinder $annotationFinder, array $extensionObjects): void
    {
        $mappedServices = [];
        foreach (ExtensionObjectResolver::resolve(DistributedServiceMap::class, $extensionObjects) as $serviceMap) {
            foreach (array_keys($serviceMap->commandMapping) as $targetService) {
                $mappedServices[$serviceMap->getReferenceName()][$targetService] = true;
            }
        }

        $checked = [];
        foreach ($annotationFinder->findAnnotatedMethods(SendsDistributedMessage::class) as $registration) {
            $method = $registration->getClassName() . '::' . $registration->getMethodName();
            if (isset($checked[$method])) {
                continue;
            }
            $checked[$method] = true;
            self::checkPlacement($registration, $method);
            $declared = [];
            foreach ($registration->getMethodAnnotationsWithType(SendsDistributedMessage::class) as $declaration) {
                self::checkDeclaration($declaration, $method, $mappedServices[$declaration->distributionReference] ?? []);
                $route = serialize([$declaration->targetServiceName, $declaration->routingKey, $declaration->distributionReference]);
                if (isset($declared[$route]) && $declared[$route]->replyRoutingKey !== $declaration->replyRoutingKey) {
                    $replyKeys = implode(' and ', array_map(fn (?string $replyKey) => $replyKey === null ? 'no reply' : "'{$replyKey}'", [$declared[$route]->replyRoutingKey, $declaration->replyRoutingKey]));

                    throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() names two reply keys for the route to '{$declaration->targetServiceName}' with routing key '{$declaration->routingKey}': {$replyKeys}. One sent message is answered on one key, so only one of them can be right. Keep the declaration with the replyRoutingKey the target service answers on, and remove the other.");
                }
                if (isset($declared[$route])) {
                    throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() declares the route to '{$declaration->targetServiceName}' with routing key '{$declaration->routingKey}' twice, so it cannot tell whether a second route was meant. Remove the repeated attribute, or correct the routing key of the one that names a different route.");
                }
                $declared[$route] = $declaration;
            }
        }
    }

    private static function checkPlacement(AnnotatedFinding $registration, string $method): void
    {
        foreach (self::SCANNED_ATTRIBUTES as $scannedAttribute) {
            if ($registration->hasMethodAnnotation($scannedAttribute)) {
                return;
            }
        }
        $methodName = $registration->getMethodName();

        throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() declares what a method sends, but {$methodName}() is not one the message flow graph reads: it has none of #[CommandHandler], #[EventHandler], #[QueryHandler], #[InternalHandler], #[Router], #[Splitter], #[Transformer], #[Orchestrator], #[Scheduled], #[Presend], #[Before], #[Around] or #[After]. Move the attribute to the handler that calls {$methodName}(), or make {$methodName}() a handler.");
    }

    /**
     * @param array<string, bool> $mappedServices
     */
    private static function checkDeclaration(SendsDistributedMessage $declaration, string $method, array $mappedServices): void
    {
        if ($declaration->routingKey === '') {
            throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() has an empty routingKey, but a distributed message is routed by that key, so an empty one names no handler. Pass the routing key the target service handles.");
        }
        if ($declaration->replyRoutingKey === '') {
            throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() has an empty replyRoutingKey, but the reply is routed by that key, so an empty one names no handler. Pass the routing key the reply is handled on, or leave replyRoutingKey out when the target does not reply.");
        }
        if (! isset($mappedServices[$declaration->targetServiceName])) {
            $known = $mappedServices === [] ? 'none' : implode(', ', array_keys($mappedServices));
            $initialize = $declaration->distributionReference === DistributedBus::class ? 'initialize()' : "initialize('{$declaration->distributionReference}')";

            throw ConfigurationException::create("#[SendsDistributedMessage] on {$method}() sends to service '{$declaration->targetServiceName}', but no DistributedServiceMap for {$declaration->distributionReference} maps that service, so DistributedBus::sendMessage() to it would fail at runtime. Mapped services: {$known}. Correct targetServiceName or distributionReference, or map the service in a #[ServiceContext] with DistributedServiceMap::{$initialize}->withCommandMapping('{$declaration->targetServiceName}', '<channel name>').");
        }
    }
}
