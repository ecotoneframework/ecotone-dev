<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpExtPublisherConfirmations;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Interop\Amqp\AmqpQueue;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingReliabilityTest extends AmqpMessagingTestCase
{
    public function test_nacked_message_fails_delivery_confirmation_over_amqp_lib(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueueRejectingOverflow($libConnectionFactory);
        $publisher = $this->bootstrapPublisher($libConnectionFactory, $queueName);

        $this->expectException(AsyncPublishingFailedException::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('first message fills the queue')
                ->append('second message overflows and gets nacked')
        )->resolve();
    }

    public function test_nacked_message_fails_delivery_confirmation_over_amqp_ext(): void
    {
        $extConnectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueueRejectingOverflow($extConnectionFactory);
        $publisher = $this->bootstrapPublisher($extConnectionFactory, $queueName);

        $this->expectException(AsyncPublishingFailedException::class);

        $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('first message fills the queue')
                ->append('second message overflows and gets nacked')
        )->resolve();
    }

    public function test_ext_publisher_confirmations_track_outstanding_until_all_confirmed(): void
    {
        $confirmations = new AmqpExtPublisherConfirmations();

        $confirmations->recordPublishedMessage();
        $confirmations->recordPublishedMessage();
        $confirmations->recordPublishedMessage();
        $this->assertTrue($confirmations->hasOutstandingConfirmations());

        $confirmations->recordConfirmation(1, multiple: false);
        $this->assertTrue($confirmations->hasOutstandingConfirmations());

        $confirmations->recordConfirmation(3, multiple: true);
        $this->assertFalse($confirmations->hasOutstandingConfirmations());
    }

    public function test_ext_publisher_confirmations_handle_multiple_flag_covering_individual_confirmations(): void
    {
        $confirmations = new AmqpExtPublisherConfirmations();

        $confirmations->recordPublishedMessage();
        $confirmations->recordPublishedMessage();

        $confirmations->recordConfirmation(2, multiple: false);
        $this->assertTrue($confirmations->hasOutstandingConfirmations());

        $confirmations->recordConfirmation(2, multiple: true);
        $this->assertFalse($confirmations->hasOutstandingConfirmations());
    }

    public function test_unroutable_message_fails_delivery_confirmation_over_amqp_lib(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $publisher = $this->bootstrapPublisher($libConnectionFactory, Uuid::v7()->toRfc4122());

        $this->expectException(AsyncPublishingFailedException::class);

        $publisher->asyncPublish('order that routes nowhere')->resolve();
    }

    public function test_unroutable_message_fails_delivery_confirmation_over_amqp_ext(): void
    {
        $extConnectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $publisher = $this->bootstrapPublisher($extConnectionFactory, Uuid::v7()->toRfc4122());

        $this->expectException(AsyncPublishingFailedException::class);

        $publisher->asyncPublish('order that routes nowhere')->resolve();
    }

    public function test_ext_confirmations_reset_while_awaiting_is_detectable_through_epoch(): void
    {
        $confirmations = new AmqpExtPublisherConfirmations();
        $epochBeforeReset = $confirmations->getEpoch();
        $confirmations->recordPublishedMessage();

        $confirmations->reset();

        $this->assertNotSame($epochBeforeReset, $confirmations->getEpoch());
        $this->assertFalse($confirmations->hasOutstandingConfirmations());
    }

    private function declareQueueRejectingOverflow(AmqpLibConnection|AmqpConnectionFactory $connectionFactory): string
    {
        $queueName = Uuid::v7()->toRfc4122();
        $context = $connectionFactory->createContext();
        $queue = $context->createQueue($queueName);
        $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        $queue->setArguments(['x-max-length' => 1, 'x-overflow' => 'reject-publish']);
        $context->declareQueue($queue);

        return $queueName;
    }

    private function bootstrapPublisher(AmqpLibConnection|AmqpConnectionFactory $connectionFactory, string $queueName): MessagePublisher
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                AmqpConnectionFactory::class => $connectionFactory,
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE]))
                ->withExtensionObjects([
                    AmqpMessagePublisherConfiguration::create()
                        ->withAutoDeclareQueueOnSend(false)
                        ->withDefaultRoutingKey($queueName)
                        ->withAsyncPublishing(timeoutInMilliseconds: 3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }
}
