<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineMessageChannelTest extends TestCase
{
    public function test_handler_runs_on_a_coroutine_of_the_sending_process(): void
    {
        $handler = new class {
            public array $handled = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = [$payload, Coroutine::getCid(), getmypid()];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);
        $sender = 0;

        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$sender): void {
            $sender = Coroutine::getCid();
            $ecotone->sendCommandWithRouting('handle', 'payload');
        });

        $this->assertCount(1, $handler->handled);
        $this->assertSame('payload', $handler->handled[0][0]);
        $this->assertNotSame($sender, $handler->handled[0][1]);
        $this->assertSame(getmypid(), $handler->handled[0][2]);
    }
}
