<?php

declare(strict_types=1);

namespace Ecotone\Modelling\Config\Routing;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use ReflectionAttribute;
use ReflectionClass;

/**
 * licence Apache-2.0
 */
final class MissingHandlerMessage
{
    /**
     * @param class-string<EndpointAnnotation> $handlerAttribute
     */
    public static function forFlowTesting(string $messageKind, string $routingKey, string $handlerAttribute): string
    {
        $handler = (new UnregisteredHandlerFinder())->find($routingKey, $handlerAttribute);
        if ($handler !== null) {
            [$handlerClass, $handlerMethod] = $handler;
            $handlerNamespace = substr($handlerClass, 0, (int) strrpos($handlerClass, '\\'));

            $notRegistered = "Can't send {$messageKind} to {$routingKey}. It is handled by {$handlerClass}::{$handlerMethod}(), which is not registered in this Ecotone Lite bootstrap.";
            $loadNamespace = "or load its namespace with EcotoneLite::bootstrapFlowTesting(..., namespaces: ['{$handlerNamespace}']).";

            if ((new ReflectionClass($handlerClass))->getAttributes(Aggregate::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                return "{$notRegistered} Add {$handlerClass} to the classesToResolve of EcotoneLite::bootstrapFlowTesting(), {$loadNamespace}";
            }

            return "{$notRegistered} Pass an instance of {$handlerClass} in the containerOrAvailableServices of EcotoneLite::bootstrapFlowTesting(), add {$handlerClass} to its classesToResolve when the service comes from a container passed there, {$loadNamespace}";
        }

        $attributeShortName = substr($handlerAttribute, (int) strrpos($handlerAttribute, '\\') + 1);
        $handlerDeclaration = class_exists($routingKey)
            ? "#[{$attributeShortName}] to a method taking {$routingKey}"
            : "#[{$attributeShortName}('{$routingKey}')] to a method";
        $kindName = ucfirst($messageKind);

        return "Can't send {$messageKind} to {$routingKey}. No {$kindName} Handler is registered for it in this Ecotone Lite bootstrap. Add {$handlerDeclaration} and pass an instance of its class in the containerOrAvailableServices of EcotoneLite::bootstrapFlowTesting(). When the method is on an aggregate or a saga, or the service comes from a container passed there, add its class to the classesToResolve instead.";
    }
}
