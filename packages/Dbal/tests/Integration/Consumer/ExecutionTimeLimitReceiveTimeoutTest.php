<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Consumer;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\PollableChannel;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * Reproduces https://github.com/ecotoneframework/ecotone-dev/issues/440
 *
 * @internal
 */
final class ExecutionTimeLimitReceiveTimeoutTest extends DbalMessagingTestCase
{
    public function test_execution_time_limit_should_not_override_the_configured_receive_timeout_on_empty_queue(): void
    {
        $channelName = Uuid::v7()->toRfc4122();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE]))
                ->withExtensionObjects([
                    DbalBackedMessageChannelBuilder::create($channelName)
                        ->withReceiveTimeout(200),
                ])
        );

        /** @var PollableChannel $messageChannel */
        $messageChannel = $ecotoneLite->getMessageChannel($channelName);

        $startedAt = microtime(true);

        $receivedMessage = $messageChannel->receiveWithTimeout(
            PollingMetadata::create('consumer')
                ->setExecutionTimeLimitInMilliseconds(5000)
        );

        $elapsedInMilliseconds = (microtime(true) - $startedAt) * 1000;

        $this->assertNull($receivedMessage);
        $this->assertLessThan(
            2000,
            $elapsedInMilliseconds,
            "Receiving blocked for {$elapsedInMilliseconds}ms - the channel's own receiveTimeout (200ms) was overridden by executionTimeLimit (5000ms), preventing the outer consumer loop from checking termination signals / TimeLimitInterceptor in a timely manner."
        );
    }
}
