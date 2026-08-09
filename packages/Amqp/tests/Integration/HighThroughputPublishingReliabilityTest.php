<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpPublisherConfirmations;
use Ecotone\Amqp\Publisher\AmqpMessagePublisherConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PublishingFailedException;
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
final class HighThroughputPublishingReliabilityTest extends AmqpMessagingTestCase
{
    public function test_nacked_message_fails_delivery_confirmation_over_amqp_lib(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueueRejectingOverflow($libConnectionFactory);
        $publisher = $this->bootstrapPublisher($libConnectionFactory, $queueName);

        $this->expectException(PublishingFailedException::class);

        $publisher->publishDeferred(
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

        $this->expectException(PublishingFailedException::class);

        $publisher->publishDeferred(
            BatchMessage::constructEmpty()
                ->append('first message fills the queue')
                ->append('second message overflows and gets nacked')
        )->resolve();
    }

    public function test_ext_publisher_confirmations_track_outstanding_until_all_confirmed(): void
    {
        $confirmations = new AmqpPublisherConfirmations();

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
        $confirmations = new AmqpPublisherConfirmations();

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

        $this->expectException(PublishingFailedException::class);

        $publisher->publishDeferred('order that routes nowhere')->resolve();
    }

    public function test_unroutable_message_fails_delivery_confirmation_over_amqp_ext(): void
    {
        $extConnectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $publisher = $this->bootstrapPublisher($extConnectionFactory, Uuid::v7()->toRfc4122());

        $this->expectException(PublishingFailedException::class);

        $publisher->publishDeferred('order that routes nowhere')->resolve();
    }

    public function test_each_future_reports_outcome_of_its_own_message_when_sharing_channel(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueue($libConnectionFactory);
        $publisher = $this->bootstrapPublisherWithRoutingKeyFromHeader($libConnectionFactory);

        $routableFuture = $publisher->publishDeferred('order that reaches the queue', metadata: ['routingKey' => $queueName]);
        $unroutableFuture = $publisher->publishDeferred('order that routes nowhere', metadata: ['routingKey' => Uuid::v7()->toRfc4122()]);

        $routableFuture->resolve();

        $this->expectException(PublishingFailedException::class);

        $unroutableFuture->resolve();
    }

    public function test_each_future_reports_outcome_of_its_own_message_when_sharing_channel_over_amqp_ext(): void
    {
        $extConnectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueue($extConnectionFactory);
        $publisher = $this->bootstrapPublisherWithRoutingKeyFromHeader($extConnectionFactory);

        $routableFuture = $publisher->publishDeferred('order that reaches the queue', metadata: ['routingKey' => $queueName]);
        $unroutableFuture = $publisher->publishDeferred('order that routes nowhere', metadata: ['routingKey' => Uuid::v7()->toRfc4122()]);

        $routableFuture->resolve();

        $this->expectException(PublishingFailedException::class);

        $unroutableFuture->resolve();
    }

    public function test_nack_arriving_during_other_future_await_fails_only_nacked_future_over_amqp_lib(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $normalQueue = $this->declareQueue($libConnectionFactory);
        $overflowQueue = $this->declareQueueRejectingOverflow($libConnectionFactory);
        $publisher = $this->bootstrapPublisherWithRoutingKeyFromHeader($libConnectionFactory);

        $publisher->publishDeferred('filler order', metadata: ['routingKey' => $overflowQueue])->resolve();

        $deliveredFuture = $publisher->publishDeferred('delivered order', metadata: ['routingKey' => $normalQueue]);
        $nackedFuture = $publisher->publishDeferred('nacked order', metadata: ['routingKey' => $overflowQueue]);

        $deliveredFuture->resolve();

        $this->expectException(PublishingFailedException::class);

        $nackedFuture->resolve();
    }

    public function test_nack_arriving_during_other_future_await_fails_only_nacked_future_over_amqp_ext(): void
    {
        $extConnectionFactory = new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $normalQueue = $this->declareQueue($extConnectionFactory);
        $overflowQueue = $this->declareQueueRejectingOverflow($extConnectionFactory);
        $publisher = $this->bootstrapPublisherWithRoutingKeyFromHeader($extConnectionFactory);

        $publisher->publishDeferred('filler order', metadata: ['routingKey' => $overflowQueue])->resolve();

        $deliveredFuture = $publisher->publishDeferred('delivered order', metadata: ['routingKey' => $normalQueue]);
        $nackedFuture = $publisher->publishDeferred('nacked order', metadata: ['routingKey' => $overflowQueue]);

        $deliveredFuture->resolve();

        $this->expectException(PublishingFailedException::class);

        $nackedFuture->resolve();
    }

    public function test_only_failing_message_from_batch_is_reported_with_per_message_granularity(): void
    {
        $libConnectionFactory = new AmqpLibConnection(['dsn' => getenv('RABBIT_HOST') ?: 'amqp://guest:guest@localhost:5672/%2f']);
        $queueName = $this->declareQueue($libConnectionFactory);
        $publisher = $this->bootstrapPublisherWithRoutingKeyFromHeader($libConnectionFactory);

        $future = $publisher->publishDeferred(
            BatchMessage::constructEmpty()
                ->append('first delivered order', ['routingKey' => $queueName])
                ->append('order that routes nowhere', ['routingKey' => Uuid::v7()->toRfc4122()])
                ->append('second delivered order', ['routingKey' => $queueName])
        );

        try {
            $future->resolve();
            $this->fail('Expected unroutable batch entry to fail the delivery');
        } catch (PublishingFailedException $exception) {
            $failedDeliveries = $exception->getFailedDeliveries();
            $this->assertCount(1, $failedDeliveries);
            $this->assertSame('order that routes nowhere', $failedDeliveries[0]->getMessage()->getPayload());
            $this->assertStringContainsString('NO_ROUTE', $failedDeliveries[0]->getFailureReason());
        }

        $context = $libConnectionFactory->createContext();
        $consumer = $context->createConsumer($context->createQueue($queueName));
        $this->assertNotNull($consumer->receive(2000));
        $this->assertNotNull($consumer->receive(2000));
    }

    public function test_ext_confirmations_reset_while_awaiting_is_detectable_through_epoch(): void
    {
        $confirmations = new AmqpPublisherConfirmations();
        $epochBeforeReset = $confirmations->getEpoch();
        $confirmations->recordPublishedMessage();

        $confirmations->reset();

        $this->assertNotSame($epochBeforeReset, $confirmations->getEpoch());
        $this->assertFalse($confirmations->hasOutstandingConfirmations());
    }

    private function declareQueue(AmqpLibConnection|AmqpConnectionFactory $connectionFactory): string
    {
        $queueName = Uuid::v7()->toRfc4122();
        $context = $connectionFactory->createContext();
        $queue = $context->createQueue($queueName);
        $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        $context->declareQueue($queue);

        return $queueName;
    }

    private function bootstrapPublisherWithRoutingKeyFromHeader(AmqpLibConnection|AmqpConnectionFactory $connectionFactory): MessagePublisher
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
                        ->withRoutingKeyFromHeader('routingKey')
                        ->withHighThroughputPublishing(confirmationTimeoutInMilliseconds: 3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
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
                        ->withHighThroughputPublishing(confirmationTimeoutInMilliseconds: 3000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }
}
