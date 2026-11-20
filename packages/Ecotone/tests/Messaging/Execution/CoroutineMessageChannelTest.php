<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Execution;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use RuntimeException;
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
    public function test_a_failed_sender_starts_no_handler(): void
    {
        $handler = new class {
            public array $handled = [];

            #[CommandHandler('send')]
            public function send(string $payload, #[Reference] CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('handle', $payload);
                throw new RuntimeException('sender failed');
            }

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(string $payload): void
            {
                $this->handled[] = $payload;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], extensionObjects: [CoroutineMessageChannelBuilder::create('async')], licenceKey: LicenceTesting::VALID_LICENCE);

        $failure = null;
        CoroutineTesting::runAtOnce(static function () use ($ecotone, &$failure): void {
            try {
                $ecotone->sendCommandWithRouting('send', 'payload');
            } catch (RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
        });

        $this->assertSame('sender failed', $failure);
        $this->assertSame([], $handler->handled);
    }

    public function test_concurrency_is_limited_and_waiting_messages_start_in_order(): void
    {
        $handler = new class {
            public int $active = 0;
            public int $maximum = 0;
            public array $started = [];
            public array $finished = [];

            #[Asynchronous('async')]
            #[CommandHandler('handle', endpointId: 'handle.endpoint')]
            public function handle(int $payload): void
            {
                $this->started[] = $payload;
                $this->active++;
                $this->maximum = max($this->maximum, $this->active);
                usleep(10_000);
                $this->active--;
                $this->finished[] = $payload;
            }
        };
        $default = CoroutineMessageChannelBuilder::create('async');
        $limited = $default->withCoroutines(2);
        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], extensionObjects: [$limited], licenceKey: LicenceTesting::VALID_LICENCE);

        CoroutineTesting::runAtOnce(static function () use ($ecotone): void {
            foreach (range(1, 8) as $number) {
                $ecotone->sendCommandWithRouting('handle', $number);
            }
        });

        $this->assertSame(10, $default->coroutines);
        $this->assertSame(2, $handler->maximum);
        $this->assertSame(range(1, 8), $handler->started);
        $this->assertCount(8, $handler->finished);
    }

}
