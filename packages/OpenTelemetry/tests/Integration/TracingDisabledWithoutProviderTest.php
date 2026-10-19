<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class TracingDisabledWithoutProviderTest extends TestCase
{
    public function test_the_installed_package_traces_nothing_until_a_tracer_provider_is_registered(): void
    {
        $users = new class () {
            public array $headers = [];

            #[Asynchronous('async_channel')]
            #[CommandHandler('user.register', endpointId: 'user.registration')]
            public function register(string $userId, #[Headers] array $headers): void
            {
                $this->headers = $headers;
            }
        };

        EcotoneLite::bootstrapFlowTesting(
            [$users::class],
            [$users],
            [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommandWithRouting('user.register', '1')
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertNotSame([], $users->headers);
        $this->assertArrayNotHasKey(MessageHeaders::TRACEPARENT, $users->headers);
    }
}
