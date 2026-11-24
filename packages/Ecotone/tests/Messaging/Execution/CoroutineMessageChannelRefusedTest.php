<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Environment;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CoroutineMessageChannelRefusedTest extends TestCase
{
    public function test_channel_is_refused_without_an_enterprise_licence(): void
    {
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` requires an Ecotone Enterprise licence. Supply the licenceKey: argument of EcotoneLite::bootstrap() or bootstrapFlowTesting(), or licenceKey in your framework\'s Ecotone configuration; alternatively, register another message channel builder under `async`.');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [CoroutineMessageChannelBuilder::create('async')]);
    }
    public function test_channel_is_refused_without_a_coroutine_extension(): void
    {
        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $this->markTestSkipped();
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` requires the swoole or openswoole extension. Install either extension for the PHP running the application. For tests on PHP without it, list every environment that runs on Swoole or OpenSwoole on the #[ServiceContext] method returning this builder and leave out only the test environment, for example #[Environment([\'prod\', \'dev\'])] (Laravel names them \'production\' and \'local\'). In the test environment register a queue channel under the same name and drive it from the test: bootstrapFlowTesting(environment: \'test\', extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel(\'async\')]), then the flow test support\'s run(\'async\').');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_signal_handling_requested_for_the_channel_is_refused(): void
    {
        if (extension_loaded('pcntl')) {
            $this->markTestSkipped();
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` cannot use PollingMetadata::withSignalInterceptors(): it is consumed inside a worker process it does not own, so it never handles process signals. Remove this setting, or use a channel consumed with ecotone:run.');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [CoroutineMessageChannelBuilder::create('async'), PollingMetadata::create('async')->withSignalInterceptors(true)], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_a_channel_requires_at_least_one_coroutine(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Coroutine message channel `async` cannot run on 0 coroutines: it needs at least one. Pass a positive number to CoroutineMessageChannelBuilder::withCoroutines(), or leave it out for the default of 10.');

        CoroutineMessageChannelBuilder::create('async')->withCoroutines(0);
    }

    public function test_a_production_coroutine_channel_can_be_replaced_with_a_queue_in_tests(): void
    {
        $handler = new class () {
            public array $handled = [];

            #[ServiceContext]
            #[Environment(['prod', 'dev'])]
            public function channel(): CoroutineMessageChannelBuilder
            {
                return CoroutineMessageChannelBuilder::create('async');
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'test.queue.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $flow = EcotoneLite::bootstrapFlowTesting([], [$handler], extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async')], environment: 'test');

        $flow->sendCommandWithRouting('handle', 'payload');
        $this->assertSame([], $handler->handled);
        $flow->run('async');
        $this->assertSame(['payload'], $handler->handled);
    }

}
