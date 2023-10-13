<?php

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalInboundChannelAdapterBuilder;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\ComponentTestBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * @internal
 */
class ChannelAdapterTest extends DbalMessagingTestCase
{
    public function test_sending_and_receiving_message()
    {
        $queueName = Uuid::uuid4()->toString();
        $requestChannelName = Uuid::uuid4()->toString();
        $requestChannel = QueueChannel::create();
        $timeoutInMilliseconds = 1;
        $componentTestBuilder = ComponentTestBuilder::create()
            ->withChannel($requestChannelName, $requestChannel)
            ->withReference(DbalConnectionFactory::class, $this->getConnectionFactory());

        $inboundChannelAdapter = $componentTestBuilder
            ->build(DbalInboundChannelAdapterBuilder::createWith(
                Uuid::uuid4()->toString(),
                $queueName,
                $requestChannelName)
                ->withReceiveTimeout($timeoutInMilliseconds));

        $outboundChannelAdapter = $componentTestBuilder->build(DbalOutboundChannelAdapterBuilder::create($queueName));

        $payload = 'some';
        $outboundChannelAdapter->handle(MessageBuilder::withPayload($payload)->build());

        $receivedMessage = $this->receiveMessage($inboundChannelAdapter, $requestChannel, $timeoutInMilliseconds);
        $this->assertNotNull($receivedMessage, 'Not received message');
        $this->assertEquals($payload, $receivedMessage->getPayload());

        $this->assertNull($this->receiveMessage($inboundChannelAdapter, $requestChannel, $timeoutInMilliseconds), 'Received message twice instead of one');
    }

    /**
     * @param \Ecotone\Messaging\Endpoint\ConsumerLifecycle $inboundChannelAdapter
     * @param QueueChannel $requestChannel
     * @return \Ecotone\Messaging\Message|null
     */
    private function receiveMessage(TaskExecutor $inboundChannelAdapter, QueueChannel $requestChannel, int $timeout)
    {
        $inboundChannelAdapter->execute(PollingMetadata::create(Uuid::uuid4()->toString())
            ->setExecutionTimeLimitInMilliseconds($timeout));
        $receivedMessage = $requestChannel->receive();
        return $receivedMessage;
    }
}
