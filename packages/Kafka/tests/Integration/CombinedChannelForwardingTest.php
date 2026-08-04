<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\CombinedMessageChannel;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class CombinedChannelForwardingTest extends TestCase
{
    public function test_kafka_source_channel_keeps_one_message_per_handled_message(): void
    {
        $orderService = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.register', endpointId: 'orderRegisterEndpoint')]
            public function register(string $order): void
            {
            }
        };

        $uniqueId = Uuid::v7()->toRfc4122();
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), $orderService],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    CombinedMessageChannel::create('orders', ['kafkaOutbox', 'orderProcessing']),
                    KafkaMessageChannelBuilder::create('kafkaOutbox', topicName: $uniqueId, messageGroupId: $uniqueId),
                    SimpleMessageChannelBuilder::createQueueChannel('orderProcessing'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->sendCommandWithRoutingKey('order.register', 'espresso');
        $messaging->sendCommandWithRoutingKey('order.register', 'latte');
        $messaging->sendCommandWithRoutingKey('order.register', 'cappuccino');

        $messaging->run('kafkaOutbox', ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 30000));

        $this->assertCount(1, $this->receiveAllFrom($messaging->getMessageChannel('orderProcessing')));
    }

    /**
     * @return Message[]
     */
    private function receiveAllFrom(PollableChannel $channel): array
    {
        $messages = [];
        while ($message = $channel->receive()) {
            $messages[] = $message;
        }

        return $messages;
    }
}
