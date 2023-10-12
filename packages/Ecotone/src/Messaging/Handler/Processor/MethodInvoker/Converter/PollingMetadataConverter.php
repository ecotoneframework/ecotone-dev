<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;

class PollingMetadataConverter implements ParameterConverter
{
    public function __construct(private PollingConsumerContext $pollingConsumerContext)
    {
    }

    public function getArgumentFrom(Message $message): ?PollingMetadata
    {
        return $this->pollingConsumerContext->get();
    }
}