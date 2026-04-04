<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnection;
use Exception;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * @internal
 */
final class DynamicStreamChannelRetryTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        if (getenv('AMQP_IMPLEMENTATION') !== 'lib') {
            $this->markTestSkipped('Stream tests require AMQP lib');
        }
    }

    public function test_resend_works_for_dynamic_channel_wrapping_amqp_stream_channels(): void
    {
        $queueTenantA = 'stream_queue_tenant_a_' . Uuid::v7()->toRfc4122();
        $queueTenantB = 'stream_queue_tenant_b_' . Uuid::v7()->toRfc4122();
        $handler = new DynamicStreamRetryHandler();

        $ecotoneLite = $this->bootstrapForTesting(
            [DynamicStreamRetryHandler::class],
            [
                $handler,
                ...$this->getConnectionFactoryReferences(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->withExtensionObjects([
                    AmqpQueue::createStreamQueue($queueTenantA),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'async_tenant_a',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueTenantA,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RESEND),
                    AmqpQueue::createStreamQueue($queueTenantB),
                    AmqpStreamChannelBuilder::create(
                        channelName: 'async_tenant_b',
                        startPosition: 'first',
                        amqpConnectionReferenceName: AmqpLibConnection::class,
                        queueName: $queueTenantB,
                    )->withFinalFailureStrategy(FinalFailureStrategy::RESEND),
                    DynamicMessageChannelBuilder::createRoundRobin(
                        thisMessageChannelName: 'async',
                        channelNames: ['async_tenant_a', 'async_tenant_b'],
                    ),
                ]),
        );

        $ecotoneLite->getCommandBus()->sendWithRouting('execute.dynamic_stream', 'test_message');

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: false)->withExecutionTimeLimitInMilliseconds(5000));

        self::assertTrue($handler->failedOnce, 'Handler should have failed on first attempt');

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithFinishWhenNoMessages(failAtError: true)->withExecutionTimeLimitInMilliseconds(5000));

        self::assertTrue($handler->succeeded, 'Handler should have succeeded on retry after resend');
    }
}

class DynamicStreamRetryHandler
{
    public bool $failedOnce = false;
    public bool $succeeded = false;

    #[Asynchronous('async')]
    #[CommandHandler('execute.dynamic_stream', 'dynamic_stream_endpoint')]
    public function handle(string $command): void
    {
        if (! $this->failedOnce) {
            $this->failedOnce = true;
            throw new Exception('Simulated failure to trigger resend');
        }
        $this->succeeded = true;
    }
}
