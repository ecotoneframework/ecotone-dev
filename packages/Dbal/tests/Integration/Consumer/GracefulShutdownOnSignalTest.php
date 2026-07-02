<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Consumer;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * Reproduces https://github.com/ecotoneframework/ecotone-dev/issues/440
 *
 * Mirrors the exact repro steps from the issue: start a consumer with a large
 * executionTimeLimit (e.g. `--executionTimeLimit 3600000`), send it SIGTERM while
 * it's idle-polling an empty queue, and expect it to shut down promptly instead of
 * ignoring the signal until the execution time limit elapses.
 *
 * @internal
 */
final class GracefulShutdownOnSignalTest extends DbalMessagingTestCase
{
    public function test_consumer_stops_promptly_on_sigterm_despite_large_execution_time_limit(): void
    {
        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required to send a real termination signal.');
        }

        $channelName = Uuid::v7()->toRfc4122();

        // Spawn an independent OS process (not a fork) to deliver the signal - forking
        // this PHP process would duplicate the already-open DB connection's socket fd
        // (opened in DbalMessagingTestCase::setUp()) into the child and corrupt it.
        $parentPid = posix_getpid();
        exec(sprintf('(sleep 0.3 && kill -TERM %d) > /dev/null 2>&1 &', $parentPid));

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

        $startedAt = microtime(true);

        // Same execution time limit as used in the issue's reproduction steps
        $ecotoneLite->run(
            $channelName,
            ExecutionPollingMetadata::createWithDefaults()
                ->withExecutionTimeLimitInMilliseconds(3_600_000)
        );

        $elapsedInMilliseconds = (microtime(true) - $startedAt) * 1000;

        $this->assertLessThan(
            2000,
            $elapsedInMilliseconds,
            "Consumer kept running for {$elapsedInMilliseconds}ms after receiving SIGTERM instead of shutting down promptly - it was blocked inside a single poll call sized off the 3600000ms executionTimeLimit."
        );
    }
}
