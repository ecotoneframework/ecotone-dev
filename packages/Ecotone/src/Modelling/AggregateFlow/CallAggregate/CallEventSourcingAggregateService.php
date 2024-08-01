<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\CallAggregate;

use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\CallAggregateService;

/**
 * licence Apache-2.0
 */
final class CallEventSourcingAggregateService implements CallAggregateService
{
    public function __construct(
        private MethodCallProvider $methodCallProvider,
        private PropertyReaderAccessor $propertyReaderAccessor,
        private bool $isFactoryMethod,
        private ?string $aggregateVersionProperty,
    ) {
    }

    public function process(Message $message): ?Message
    {
        $resultMessage = $this->methodCallProvider->getMethodInvocation($message)->proceed();

        $calledAggregate = $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT) ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT) : null;
        $versionBeforeHandling = $message->getHeaders()->containsKey(AggregateMessage::TARGET_VERSION) ? $message->getHeaders()->get(AggregateMessage::TARGET_VERSION) : null;

        if (is_null($versionBeforeHandling) && $this->aggregateVersionProperty) {
            if ($this->isFactoryMethod) {
                $versionBeforeHandling = 0;
            } else {
                $versionBeforeHandling = $this->propertyReaderAccessor->getPropertyValue(PropertyPath::createWith($this->aggregateVersionProperty), $calledAggregate);
                $versionBeforeHandling = is_null($versionBeforeHandling) ? 0 : $versionBeforeHandling;
            }

            $resultMessage = $resultMessage->setHeader(AggregateMessage::TARGET_VERSION, $versionBeforeHandling);
        }

        return $resultMessage->build();
    }
}
