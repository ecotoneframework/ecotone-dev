<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Symfony\SymfonyMessengerMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfigurationException;
use Fixture\MessengerConsumer\MessengerAsyncCommandHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class SymfonyMessengerConsumerCoroutinesRefusedTest extends WebTestCase
{
    use RestoresGlobalHandlers;

    private const REFUSAL = 'Consumer `messenger_async` is set to consume on 3 coroutines, but it consumes Symfony Messenger channel `messenger_async`, whose transport Symfony keeps one connection of for the whole process: every coroutine would read from that one connection at the same time, which stops the process or mixes their transactions. '
        . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this consumer. '
        . 'To handle more messages at once, start more processes of this consumer, or move its handlers to a channel Ecotone connects to itself (DbalBackedMessageChannelBuilder with a connection registered from a DSN, AmqpBackedMessageChannelBuilder, RedisBackedMessageChannelBuilder or SqsBackedMessageChannelBuilder) and consume that channel on coroutines.';

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
    }

    public function test_consumer_of_a_symfony_messenger_channel_is_refused_to_consume_on_coroutines_passed_when_running_it(): void
    {
        $ecotone = $this->bootstrapMessengerChannel();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::REFUSAL);

        $ecotone->run('messenger_async', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    public function test_consumer_of_a_symfony_messenger_channel_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(self::REFUSAL);

        $this->bootstrapMessengerChannel(PollingMetadata::create('messenger_async')->withCoroutines(3));
    }

    private function bootstrapMessengerChannel(object ...$extensionObjects): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncCommandHandler::class],
            $this->bootKernel()->getContainer(),
            extensionObjects: [SymfonyMessengerMessageChannelBuilder::create('messenger_async'), ...$extensionObjects],
            enabledModules: [],
        );
    }
}
