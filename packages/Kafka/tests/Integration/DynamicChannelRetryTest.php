<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Channel\DynamicChannel\DynamicMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Test\LicenceTesting;
use Exception;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class DynamicChannelRetryTest extends TestCase
{
    public function test_resend_works_for_dynamic_channel_wrapping_kafka_channels(): void
    {
        $topicTenantA = 'topic_async_tenant_a_' . Uuid::v7()->toRfc4122();
        $topicTenantB = 'topic_async_tenant_b_' . Uuid::v7()->toRfc4122();
        $handler = new DynamicChannelRetryHandler();

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [DynamicChannelRetryHandler::class],
            [$handler, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: 'async_tenant_a',
                        topicName: $topicTenantA,
                    )
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND)
                        ->withReceiveTimeout(3000),
                    KafkaMessageChannelBuilder::create(
                        channelName: 'async_tenant_b',
                        topicName: $topicTenantB,
                    )
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND)
                        ->withReceiveTimeout(3000),
                    DynamicMessageChannelBuilder::createRoundRobin(
                        thisMessageChannelName: 'async',
                        channelNames: ['async_tenant_a', 'async_tenant_b'],
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.dynamic', 'test_message');

        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: false,
        ));

        self::assertTrue($handler->failedOnce, 'Handler should have failed on first attempt');

        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(
            amountOfMessagesToHandle: 1,
            maxExecutionTimeInMilliseconds: 10000,
            failAtError: true,
        ));

        self::assertTrue($handler->succeeded, 'Handler should have succeeded on retry after resend');
    }
}

class DynamicChannelRetryHandler
{
    public bool $failedOnce = false;
    public bool $succeeded = false;

    #[Asynchronous('async')]
    #[CommandHandler('execute.dynamic', 'dynamic_endpoint')]
    public function handle(string $command): void
    {
        if (! $this->failedOnce) {
            $this->failedOnce = true;
            throw new Exception('Simulated failure to trigger resend');
        }
        $this->succeeded = true;
    }
}
