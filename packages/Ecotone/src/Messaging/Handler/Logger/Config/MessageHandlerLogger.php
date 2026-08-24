<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Logger\Config;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Interceptor\Around;
use Ecotone\Api\Attribute\Interceptor\Presend;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\ServiceActivator;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class MessageHandlerLogger
{
    #[Presend(precedence: 1000, pointcut: Asynchronous::class)]
    public function asynchronous(Message $message, #[Reference] LoggingGateway $loggingGateway, Asynchronous $asynchronous, ?IdentifiedAnnotation $identifiedAnnotation = null): void
    {
        $loggingGateway->info(
            sprintf('Message Handler%s is Asynchronous, sending Message to `%s` Message Channel', $identifiedAnnotation ? ' with endpoint id ' . $identifiedAnnotation->getEndpointId() : '', implode(',', $asynchronous->getChannelName())),
            $message
        );
    }

    #[Around(precedence: 1000, pointcut: CommandHandler::class)]
    public function aroundCommandHandler(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $loggingGateway): mixed
    {
        $loggingGateway->info(
            'Executing Command Handler ' . $methodInvocation->getName(),
            $message
        );

        return $methodInvocation->proceed();
    }

    #[Around(precedence: 1000, pointcut: EventHandler::class)]
    public function aroundEventHandler(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $loggingGateway): mixed
    {
        $loggingGateway->info(
            'Executing Event Handler ' . $methodInvocation->getName(),
            $message
        );

        return $methodInvocation->proceed();
    }

    #[Around(precedence: 1000, pointcut: QueryHandler::class)]
    public function aroundQueryHandler(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $loggingGateway): mixed
    {
        $loggingGateway->info(
            'Executing Query Handler ' . $methodInvocation->getName(),
            $message
        );

        return $methodInvocation->proceed();
    }

    #[Around(precedence: 1000, pointcut: InternalHandler::class)]
    public function aroundMessageHandler(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $loggingGateway): mixed
    {
        $loggingGateway->info(
            'Executing Message Handler ' . $methodInvocation->getName(),
            $message
        );

        return $methodInvocation->proceed();
    }

    //    #[Around(pointcut: ServiceActivator::class)]
    //    public function aroundServiceActivator(MethodInvocation $methodInvocation, Message $message, #[Reference] LoggingGateway $loggingGateway): mixed
    //    {
    //        $loggingGateway->info(
    //            'Executing Service Activator ' . $methodInvocation->getName(),
    //            $message
    //        );
    //
    //        return $methodInvocation->proceed();
    //    }
}
