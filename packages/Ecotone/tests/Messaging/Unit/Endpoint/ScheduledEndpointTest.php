<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Endpoint;

use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class ScheduledEndpointTest extends TestCase
{
    public function test_scheduled_method_with_no_parameters_is_called_with_no_request_channel_needed(): void
    {
        $service = new NoArgScheduledService();
        $ecotone = EcotoneLite::bootstrapFlowTesting([NoArgScheduledService::class], [$service]);

        $ecotone->run(NoArgScheduledService::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup(1));

        $this->assertTrue($service->wasCalled);
    }

    public function test_scheduled_method_return_value_is_dispatched_to_the_declared_request_channel(): void
    {
        $service = new ReturningScheduledService();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [ReturningScheduledService::class],
            [$service],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel(ReturningScheduledService::CHANNEL),
            ]),
        );

        $ecotone->run(ReturningScheduledService::ENDPOINT_ID, ExecutionPollingMetadata::createWithTestingSetup(1));

        $this->assertSame(ReturningScheduledService::PAYLOAD, $ecotone->receiveMessageFrom(ReturningScheduledService::CHANNEL)->getPayload());
    }

    public function test_a_custom_fixed_rate_and_execution_time_limit_can_be_registered_for_a_scheduled_endpoint(): void
    {
        $service = new ReturningScheduledService();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [ReturningScheduledService::class],
            [$service],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel(ReturningScheduledService::CHANNEL),
                PollingMetadata::create(ReturningScheduledService::ENDPOINT_ID)
                    ->setFixedRateInMilliseconds(1)
                    ->setInitialDelayInMilliseconds(0)
                    ->setExecutionTimeLimitInMilliseconds(100),
            ]),
        );

        $ecotone->run(ReturningScheduledService::ENDPOINT_ID);

        $this->assertSame(ReturningScheduledService::PAYLOAD, $ecotone->receiveMessageFrom(ReturningScheduledService::CHANNEL)->getPayload());
    }

    public function test_bootstrap_throws_when_scheduled_method_declares_a_parameter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EcotoneLite::bootstrapFlowTesting([UnresolvableParameterScheduledService::class], [new UnresolvableParameterScheduledService()]);
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class NoArgScheduledService
{
    public const ENDPOINT_ID = 'noArgSchedule';

    public bool $wasCalled = false;

    #[Scheduled(endpointId: self::ENDPOINT_ID)]
    public function execute(): void
    {
        $this->wasCalled = true;
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class ReturningScheduledService
{
    public const ENDPOINT_ID = 'returningSchedule';
    public const CHANNEL = 'returningSchedule.channel';
    public const PAYLOAD = 'testPayload';

    #[Scheduled(self::CHANNEL, self::ENDPOINT_ID)]
    public function execute(): string
    {
        return self::PAYLOAD;
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class UnresolvableParameterScheduledService
{
    #[Scheduled]
    public function execute(UnresolvableParameterScheduledServiceArgument $argument): void
    {
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class UnresolvableParameterScheduledServiceArgument
{
}
