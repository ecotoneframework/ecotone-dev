<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\NonProxyGateway;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class PollingConsumerTaskExecutor
 * @package Ecotone\Messaging\Endpoint\PollingConsumer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class PollerTaskExecutor implements TaskExecutor
{
    public function __construct(private string $pollableChannelName, private PollableChannel $pollableChannel, private NonProxyGateway $entrypointGateway)
    {
    }

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $message = $pollingMetadata->getExecutionTimeLimitInMilliseconds()
            ? $this->pollableChannel->receiveWithTimeout($pollingMetadata->getExecutionTimeLimitInMilliseconds())
            : $this->pollableChannel->receive();

        if ($message) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $this->pollableChannelName)
                ->build();

            $this->entrypointGateway->execute([$message]);
        }
    }
}
