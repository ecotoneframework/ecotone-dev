<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;

/**
 * licence Enterprise
 */
final class DecisionModelConverter implements ParameterConverter
{
    public function __construct(
        private readonly string $parameterName,
        private readonly DecisionModelBatchLoader $batchLoader,
        private readonly DecisionModelLoadedInstancesCollector $collector,
    ) {
    }

    public function getArgumentFrom(Message $message): ?object
    {
        $this->batchLoader->ensureLoaded($message);

        return $this->collector->consume($message->getHeaders()->getMessageId(), $this->parameterName);
    }
}
