<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplateBuilder;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Redis\RedisContext;
use Test\Ecotone\Redis\ConnectionTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingReliabilityTest extends ConnectionTestCase
{
    private const CHANNEL_NAME = 'partiallyFailingChannel';

    public function setUp(): void
    {
        $redis = $this->redis();
        $redis->del(self::CHANNEL_NAME);
        $redis->del(self::CHANNEL_NAME . ':delayed');
    }

    public function test_mid_batch_failure_reports_only_unpushed_entries_so_retry_does_not_duplicate_delivered_ones(): void
    {
        $this->makeDelayedStorageRejectWrites();
        $messaging = $this->bootstrapEcotoneWithRetryingChannel();
        $channel = $messaging->getMessageChannel(self::CHANNEL_NAME);

        $caughtException = null;
        try {
            $channel->send(MessageBuilder::withPayload(
                BatchMessage::constructEmpty()
                    ->append('delivered order')
                    ->append('order for broken delayed storage', [MessageHeaders::DELIVERY_DELAY => 60000])
            )->build());
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(PublishingFailedException::class, $caughtException);
        $this->assertCount(1, $caughtException->getFailedDeliveries());
        $this->assertSame('order for broken delayed storage', $caughtException->getFailedDeliveries()[0]->getMessage()->getPayload());
        $this->assertSame(1, $this->queueLength());
    }

    private function makeDelayedStorageRejectWrites(): void
    {
        $this->redis()->lpush(self::CHANNEL_NAME . ':delayed', 'occupying delayed storage with wrong type');
    }

    private function bootstrapEcotoneWithRetryingChannel(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [RedisConnectionFactory::class => $this->getConnectionFactory()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    PollableChannelConfiguration::create(self::CHANNEL_NAME, RetryTemplateBuilder::fixedBackOff(1)->maxRetryAttempts(1)->build()),
                ]),
            enableAsynchronousProcessing: [
                RedisBackedMessageChannelBuilder::create(self::CHANNEL_NAME)->withBatchedNonBlockingDelivery(),
            ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function queueLength(): int
    {
        return (int) $this->redis()->eval('return redis.call("llen", KEYS[1])', [self::CHANNEL_NAME], []);
    }

    private function redis(): \Enqueue\Redis\Redis
    {
        /** @var RedisContext $context */
        $context = $this->getConnectionFactory()->createContext();

        return $context->getRedis();
    }
}
