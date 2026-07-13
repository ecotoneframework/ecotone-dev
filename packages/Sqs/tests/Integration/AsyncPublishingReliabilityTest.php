<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Sqs\Configuration\SqsMessagePublisherConfiguration;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\Sqs\SqsConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingReliabilityTest extends ConnectionTestCase
{
    public function test_broker_rejected_batch_fails_async_publish_on_future_resolve(): void
    {
        $messaging = $this->bootstrapPublisher(Uuid::v7()->toRfc4122());
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $future = $publisher->asyncPublish(
            BatchMessage::constructEmpty()
                ->append('valid order')
                ->append(str_repeat('x', 300_000))
        );

        $this->expectException(AsyncPublishingFailedException::class);

        $future->resolve();
    }

    public function test_broker_rejected_batch_sent_without_active_scope_throws_immediately(): void
    {
        $queueName = Uuid::v7()->toRfc4122();
        $messaging = $this->bootstrapChannel($queueName);

        $this->expectException(AsyncPublishingFailedException::class);

        $messaging->getMessageChannel($queueName)->send(
            MessageBuilder::withPayload(
                BatchMessage::constructEmpty()->append(str_repeat('x', 300_000))
            )->build()
        );
    }

    public function test_one_failing_batch_among_many_published_in_command_handler_fails_before_commit(): void
    {
        $commandHandler = new class () {
            #[CommandHandler('order.placeAllBatches')]
            public function handle(string $order, #[Reference(MessagePublisher::class)] MessagePublisher $publisher): void
            {
                $publisher->asyncPublish(BatchMessage::constructEmpty()->append($order . ' first valid order'));
                $publisher->asyncPublish(BatchMessage::constructEmpty()->append(str_repeat('x', 300_000)));
                $publisher->asyncPublish(BatchMessage::constructEmpty()->append($order . ' third valid order'));
            }
        };
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [$commandHandler::class],
            [SqsConnectionFactory::class => $this->getConnectionFactory(), $commandHandler],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsMessagePublisherConfiguration::create(queueName: Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(timeoutInMilliseconds: 10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(AsyncPublishingFailedException::class);

        $messaging->sendCommandWithRoutingKey('order.placeAllBatches', 'espresso');
    }

    private function bootstrapPublisher(string $queueName): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [SqsConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsMessagePublisherConfiguration::create(queueName: $queueName)
                        ->withAsyncPublishing(timeoutInMilliseconds: 10000),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapChannel(string $channelName): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [SqsConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsBackedMessageChannelBuilder::create($channelName)
                        ->withAsyncPublishing(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
